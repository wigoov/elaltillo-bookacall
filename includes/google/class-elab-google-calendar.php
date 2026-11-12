<?php
/**
 * Per-advisor Google connection, used only to place bookings in Google Calendar.
 *
 * Least privilege: the Calendar permissions requested are calendar.app.created, so Google
 * itself limits this site to the calendar it creates in the advisor's account
 * ("El Altillo · Citas"), and calendar.settings.readonly, which only reads settings such as
 * the time zone. The site cannot read, list or change any other calendar or event, and it
 * never asks for free/busy. Availability comes entirely from WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Google_Calendar {
	const META_REFRESH = '_elab_google_refresh';
	const META_CLIENT_HASH = '_elab_google_client_hash';
	const META_ACCOUNT_EMAIL = '_elab_google_account_email';
	const META_ACCOUNT_SUB = '_elab_google_account_sub';
	const META_EVENT = '_elab_google_event_id';
	const META_REVOKED = '_elab_google_revoked';
	/** Advisor: ID of the calendar this site created. Booking: calendar its event lives in. */
	const META_CALENDAR = '_elab_google_calendar';
	/**
	 * Advisor: who owns that calendar (Google account + OAuth client, hashed). Kept on
	 * Disconnect, so connecting the same account again reuses the calendar instead of
	 * piling up copies.
	 */
	const META_CALENDAR_OWNER = '_elab_google_calendar_owner';
	/** Advisor: calendars of this site that were deleted in Google; their calls get restored. */
	const META_GONE_CALENDARS = '_elab_google_gone_calendars';
	/** Advisor: progress of putting calls back in a new calendar (totals and a few for the email). */
	const META_RESTORE = '_elab_google_restore';
	/** Booking: failed attempts to put it back; after RESTORE_TRIES it is left to the administrator. */
	const META_RESTORE_TRIES = '_elab_restore_tries';
	const RESTORE_CRON = 'elab_google_restore';
	const RESTORE_BATCH = 25;
	const RESTORE_SECONDS = 20;
	const RESTORE_TRIES = 5;
	/** Advisor: the permissions Google says this site's access carries (space separated). */
	const META_SCOPES = '_elab_google_scopes';
	/** Advisor: the emailed connection link (encrypted token and expiry). */
	const META_INVITE = '_elab_google_invite';
	const INVITE_DAYS = 14;
	const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';
	const SCOPE = 'https://www.googleapis.com/auth/calendar.app.created';
	/** Read-only Calendar settings (time zone). No events, no calendars. Optional: the advisor may untick it. */
	const SETTINGS_SCOPE = 'https://www.googleapis.com/auth/calendar.settings.readonly';
	/** Advisor: 'google' when the time zone follows their Google Calendar setting. */
	const META_TIMEZONE_SOURCE = '_elab_timezone_source';
	const CALENDAR_NAME = 'El Altillo · Citas';
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
	const OPTION_CLIENT_ID = 'elab_google_client_id';
	const OPTION_CLIENT_SECRET = 'elab_google_client_secret';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API_URL = 'https://www.googleapis.com/calendar/v3';
	const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

	public function __construct() {
		add_action( 'admin_post_elab_google_connect', array( $this, 'connect' ) );
		add_action( 'admin_post_elab_google_callback', array( $this, 'callback' ) );
		// Advisors connect from an emailed link, without a WordPress account or login.
		add_action( 'admin_post_nopriv_elab_google_callback', array( $this, 'callback' ) );
		add_action( 'admin_post_elab_google_invite', array( $this, 'invite' ) );
		add_action( 'admin_post_nopriv_elab_google_invite', array( $this, 'invite' ) );
		add_action( 'admin_post_elab_google_send_invite', array( $this, 'send_invite' ) );
		add_action( 'admin_post_elab_google_disconnect', array( $this, 'disconnect' ) );
		add_action( 'show_user_profile', array( $this, 'render_own_profile' ) );
	}

	public static function can_connect( $advisor_id ) {
		if ( current_user_can( 'manage_options' ) && current_user_can( 'edit_post', $advisor_id ) ) {
			return true;
		}
		return is_user_logged_in() && 'publish' === get_post_status( $advisor_id ) && get_current_user_id() === (int) get_post_meta( $advisor_id, '_elab_wp_user', true );
	}

	public function render_own_profile( $user ) {
		$advisors = get_posts( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_elab_wp_user', 'meta_value' => $user->ID ) );
		if ( ! $advisors ) {
			return;
		}
		echo '<h2 id="elab-google">' . esc_html__( 'Your advisor calendars', 'elaltillo-bookacall' ) . '</h2>';
		if ( isset( $_GET['elab_google'] ) && 'connected' === sanitize_key( wp_unslash( $_GET['elab_google'] ) ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( __( 'Connected. New calls appear in the “%s” calendar of your Google account.', 'elaltillo-bookacall' ), self::CALENDAR_NAME ) ) . '</p></div>';
		}
		echo '<p>' . esc_html( sprintf( __( 'Connect the Google account shown below. This site creates one calendar in it, “%s”, and can only add and remove your booked calls there: it cannot see or change any other calendar or event.', 'elaltillo-bookacall' ), self::CALENDAR_NAME ) ) . '</p><table class="form-table" role="presentation"><tbody>';
		foreach ( $advisors as $advisor ) {
			$connected = self::connected( $advisor->ID );
			$expected_email = ELAB_Notifications::advisor_email( $advisor->ID );
			$action = $connected ? 'elab_google_disconnect' : 'elab_google_connect';
			$url = wp_nonce_url( add_query_arg( array( 'action' => $action, 'advisor' => $advisor->ID ), admin_url( 'admin-post.php' ) ), $action . '_' . $advisor->ID );
			echo '<tr><th scope="row">' . esc_html( get_the_title( $advisor ) ) . '</th><td>';
			if ( ! $expected_email ) {
				echo esc_html__( 'Ask an administrator to set your Google email in the advisor profile.', 'elaltillo-bookacall' );
			} elseif ( self::configured() ) {
				echo '<p>' . esc_html( $connected ? sprintf( __( 'Connected as %s', 'elaltillo-bookacall' ), self::account_email( $advisor->ID ) ) : sprintf( __( 'Expected Google account: %s', 'elaltillo-bookacall' ), $expected_email ) ) . '</p>';
				if ( ! $connected && self::linked( $advisor->ID ) ) {
					echo '<p class="description">' . esc_html__( 'Connect again: until then you do not appear in the booking calendar. Calls already booked are kept.', 'elaltillo-bookacall' ) . '</p>';
				}
				echo '<a class="button ' . ( $connected ? '' : 'button-primary' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $connected ? __( 'Disconnect Google Calendar', 'elaltillo-bookacall' ) : ( self::linked( $advisor->ID ) ? __( 'Reconnect Google Calendar', 'elaltillo-bookacall' ) : __( 'Connect Google Calendar', 'elaltillo-bookacall' ) ) ) . '</a>';
			} else {
				echo esc_html__( 'Google Calendar is awaiting site configuration.', 'elaltillo-bookacall' );
			}
			if ( 'failed' === get_post_meta( $advisor->ID, ELAB_Notifications::META_SCHEDULE_MAIL, true ) ) {
				echo '<p class="notice notice-warning inline">' . esc_html__( 'Your availability email could not be sent. Ask an administrator to check the site mail settings.', 'elaltillo-bookacall' ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function configured() {
		return '' !== self::client_id() && '' !== self::client_secret();
	}

	public static function client_id() {
		return (string) get_option( self::OPTION_CLIENT_ID, '' );
	}

	private static function client_secret() {
		$stored = (string) get_option( self::OPTION_CLIENT_SECRET, '' );
		return $stored ? (string) self::decrypt( $stored ) : '';
	}

	public static function has_saved_secret() {
		return '' !== self::client_secret();
	}

	public static function save_credentials( $client_id, $client_secret ) {
		if ( '' !== $client_secret ) {
			$encrypted = self::encrypt( $client_secret );
			if ( ! $encrypted ) {
				return new WP_Error( 'google_secret_encryption_failed', __( 'Could not encrypt the Google Client Secret on this server.', 'elaltillo-bookacall' ) );
			}
			update_option( self::OPTION_CLIENT_SECRET, $encrypted, false );
		}
		update_option( self::OPTION_CLIENT_ID, $client_id, false );
		return true;
	}

	/** Ready to place bookings now: valid grant, same OAuth client and email, and its own calendar. */
	public static function connected( $advisor_id ) {
		$stored_hash = (string) get_post_meta( $advisor_id, self::META_CLIENT_HASH, true );
		$account_email = self::account_email( $advisor_id );
		$expected_email = ELAB_Notifications::advisor_email( $advisor_id );
		return self::configured() && ! get_post_meta( $advisor_id, self::META_REVOKED, true ) && (bool) get_post_meta( $advisor_id, self::META_REFRESH, true ) && '' !== $stored_hash && hash_equals( hash( 'sha256', self::client_id() ), $stored_hash ) && (bool) get_post_meta( $advisor_id, self::META_ACCOUNT_SUB, true ) && $expected_email && 0 === strcasecmp( $account_email, $expected_email ) && '' !== self::calendar_id( $advisor_id );
	}

	/**
	 * Connected at least once and not disconnected on purpose. Such advisors keep taking
	 * bookings while their connection is broken: calls are saved in WordPress and added to
	 * Google automatically after they reconnect.
	 */
	public static function linked( $advisor_id ) {
		return (bool) get_post_meta( $advisor_id, self::META_ACCOUNT_SUB, true );
	}

	public static function calendar_id( $advisor_id ) {
		return (string) get_post_meta( $advisor_id, self::META_CALENDAR, true );
	}

	public static function account_email( $advisor_id ) {
		return (string) get_post_meta( $advisor_id, self::META_ACCOUNT_EMAIL, true );
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=elab_google_callback', 'https' );
	}

	public function connect() {
		$advisor_id = isset( $_GET['advisor'] ) ? absint( $_GET['advisor'] ) : 0;
		check_admin_referer( 'elab_google_connect_' . $advisor_id );
		if ( ! self::configured() || ! self::can_connect( $advisor_id ) || ELAB_Post_Types::ADVISOR !== get_post_type( $advisor_id ) || ! ELAB_Notifications::advisor_email( $advisor_id ) ) {
			wp_die( esc_html__( 'Google Calendar is not configured or you cannot edit this advisor.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		self::start_oauth( $advisor_id, get_current_user_id(), false );
	}

	/**
	 * Send the advisor to Google's consent screen. $invite marks a flow started from an
	 * emailed link, by someone who is not signed in to WordPress.
	 */
	private static function start_oauth( $advisor_id, $user_id, $invite ) {
		// Replacing a connection that is not in use (old broader permission, expired, other
		// account): withdraw it at Google first, so the new grant starts from nothing and
		// carries only the permission requested below.
		if ( self::linked( $advisor_id ) && ( ! self::connected( $advisor_id ) || self::extra_scopes( $advisor_id ) ) ) {
			$stale = self::decrypt( (string) get_post_meta( $advisor_id, self::META_REFRESH, true ) );
			if ( $stale ) {
				wp_remote_post( self::REVOKE_URL, array( 'timeout' => 10, 'body' => array( 'token' => $stale ) ) );
			}
			delete_transient( 'elab_google_access_' . $advisor_id );
		}
		$state = wp_generate_password( 48, false, false );
		$pkce = self::pkce_pair();
		set_transient( 'elab_google_state_' . hash( 'sha256', $state ), array( 'advisor' => $advisor_id, 'user' => (int) $user_id, 'invite' => (bool) $invite, 'verifier' => $pkce['verifier'] ), 10 * MINUTE_IN_SECONDS );
		$url = add_query_arg( array(
			'client_id' => self::client_id(),
			'redirect_uri' => self::redirect_uri(),
			'response_type' => 'code',
			'access_type' => 'offline',
			'prompt' => 'consent',
			'scope' => 'openid email ' . self::SCOPE . ' ' . self::SETTINGS_SCOPE,
			'state' => $state,
			// Opens Google on the account this advisor must use, so the wrong one is rarely picked.
			'login_hint' => ELAB_Notifications::advisor_email( $advisor_id ),
			'code_challenge' => $pkce['challenge'],
			'code_challenge_method' => 'S256',
		), 'https://accounts.google.com/o/oauth2/v2/auth' );
		wp_redirect( esc_url_raw( $url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google OAuth endpoint.
		exit;
	}

	/**
	 * Advisor portal sign-in: send the browser to Google asking only who they are (no calendar
	 * access, nothing stored at Google's side for this site).
	 */
	public static function start_sign_in( array $state_data, $hint = '' ) {
		$state = wp_generate_password( 48, false, false );
		$pkce = self::pkce_pair();
		set_transient( 'elab_google_state_' . hash( 'sha256', $state ), $state_data + array( 'portal' => true, 'verifier' => $pkce['verifier'] ), 10 * MINUTE_IN_SECONDS );
		$query = array(
			'client_id' => self::client_id(),
			'redirect_uri' => self::redirect_uri(),
			'response_type' => 'code',
			'scope' => 'openid email',
			'prompt' => 'select_account',
			'state' => $state,
			'code_challenge' => $pkce['challenge'],
			'code_challenge_method' => 'S256',
		);
		if ( $hint ) {
			$query['login_hint'] = $hint;
		}
		return add_query_arg( $query, 'https://accounts.google.com/o/oauth2/v2/auth' );
	}

	/**
	 * The verified Google account behind a sign-in code (PKCE-checked).
	 *
	 * @return array{sub:string,email:string}|WP_Error
	 */
	public static function identity_from_code( $code, $verifier ) {
		if ( ! $code ) {
			return new WP_Error( 'google_cancelled', __( 'Google authorization was cancelled.', 'elaltillo-bookacall' ) );
		}
		$tokens = self::token_request( array( 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirect_uri(), 'code_verifier' => (string) $verifier ) );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		$identity = self::account_identity( $tokens['access_token'] ?? '' );
		// Sign-in only: the short access token is withdrawn at once, so nothing stays usable.
		if ( ! empty( $tokens['access_token'] ) ) {
			wp_remote_post( self::REVOKE_URL, array( 'timeout' => 5, 'blocking' => false, 'body' => array( 'token' => $tokens['access_token'] ) ) );
		}
		return $identity;
	}

	/** PKCE (RFC 7636): the authorization code is useless without this request's secret verifier. */
	public static function pkce_pair() {
		$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		return array( 'verifier' => $verifier, 'challenge' => $challenge );
	}

	public function callback() {
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$data = $state ? get_transient( 'elab_google_state_' . hash( 'sha256', $state ) ) : false;
		delete_transient( 'elab_google_state_' . hash( 'sha256', $state ) );
		// "Sign in with Google" for the advisor portal shares this redirect URI.
		if ( is_array( $data ) && ! empty( $data['portal'] ) ) {
			ELAB_Advisor_Portal::finish_login( $data, isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '' );
			return;
		}
		$invite = is_array( $data ) && ! empty( $data['invite'] );
		// From an emailed link nobody is signed in: the link was checked before Google, and
		// below Google must confirm the advisor's own account, so no one else can connect it.
		if ( ! is_array( $data ) || ( ! $invite && ( ! is_user_logged_in() || (int) $data['user'] !== get_current_user_id() || ! self::can_connect( (int) $data['advisor'] ) ) ) || ELAB_Post_Types::ADVISOR !== get_post_type( (int) $data['advisor'] ) ) {
			wp_die( esc_html__( 'Invalid or expired Google authorization.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( ! $code ) {
			wp_die( esc_html__( 'Google authorization was cancelled.', 'elaltillo-bookacall' ), '', array( 'response' => 400 ) );
		}
		$tokens = self::token_request( array( 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirect_uri(), 'code_verifier' => (string) ( $data['verifier'] ?? '' ) ) );
		if ( is_wp_error( $tokens ) || empty( $tokens['refresh_token'] ) ) {
			wp_die( esc_html__( 'Google did not provide a refresh token. Reconnect the advisor and grant calendar access.', 'elaltillo-bookacall' ), '', array( 'response' => 400 ) );
		}
		$granted_scopes = isset( $tokens['scope'] ) ? explode( ' ', trim( (string) $tokens['scope'] ) ) : array();
		if ( $granted_scopes && ! in_array( self::SCOPE, $granted_scopes, true ) ) {
			wp_die( esc_html__( 'Allow the requested Google Calendar permission and try again.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		$identity = self::account_identity( $tokens['access_token'] ?? '' );
		if ( is_wp_error( $identity ) ) {
			wp_die( esc_html( $identity->get_error_message() ), '', array( 'response' => 503 ) );
		}
		$expected_email = ELAB_Notifications::advisor_email( (int) $data['advisor'] );
		if ( ! $expected_email || 0 !== strcasecmp( $identity['email'], $expected_email ) ) {
			wp_die( esc_html( sprintf( __( 'You connected %1$s, but this advisor expects %2$s. Choose the correct Google account or ask an administrator to update the advisor email.', 'elaltillo-bookacall' ), $identity['email'], $expected_email ) ), '', array( 'response' => 403 ) );
		}
		$advisor_id = (int) $data['advisor'];
		$same_owner = self::owns_calendar( $advisor_id, (string) $identity['sub'] );
		$previous = $same_owner ? self::calendar_id( $advisor_id ) : '';
		$calendar = self::ensure_calendar( $advisor_id, $tokens['access_token'], $previous );
		if ( is_wp_error( $calendar ) ) {
			wp_die( esc_html( $calendar->get_error_message() ), '', array( 'response' => 503 ) );
		}
		$encrypted = self::encrypt( $tokens['refresh_token'] );
		if ( ! $encrypted ) {
			wp_die( esc_html__( 'Could not protect the Google credentials on this server.', 'elaltillo-bookacall' ), '', array( 'response' => 500 ) );
		}
		update_post_meta( (int) $data['advisor'], self::META_REFRESH, $encrypted );
		update_post_meta( (int) $data['advisor'], self::META_CLIENT_HASH, hash( 'sha256', self::client_id() ) );
		update_post_meta( (int) $data['advisor'], self::META_ACCOUNT_EMAIL, $identity['email'] );
		update_post_meta( (int) $data['advisor'], self::META_ACCOUNT_SUB, $identity['sub'] );
		update_post_meta( $advisor_id, self::META_CALENDAR, $calendar );
		update_post_meta( $advisor_id, self::META_CALENDAR_OWNER, self::owner_key( (string) $identity['sub'] ) );
		update_post_meta( $advisor_id, self::META_SCOPES, sanitize_text_field( implode( ' ', $granted_scopes ) ) );
		delete_post_meta( (int) $data['advisor'], self::META_REVOKED );
		set_transient( 'elab_google_access_' . $advisor_id, $tokens['access_token'], max( 60, min( 3300, (int) ( $tokens['expires_in'] ?? 3600 ) - 120 ) ) );
		self::sync_timezone( $advisor_id, false ); // The schedule email right below already shows it.
		ELAB_Notifications::schedule_summary( (int) $data['advisor'] );
		// Same account and app, but its calendar was deleted in Google: the new one gets the calls back.
		if ( $previous && $previous !== $calendar ) {
			self::calendar_replaced( $advisor_id, $previous );
		} elseif ( get_post_meta( $advisor_id, self::META_GONE_CALENDARS, true ) ) {
			self::schedule_restore(); // Calls still waiting from before the connection broke.
		}
		// Calls saved while the connection was broken go to Google now.
		ELAB_Booking_Sync::run( $advisor_id );
		if ( $invite ) {
			delete_post_meta( $advisor_id, self::META_INVITE ); // One use only.
			wp_safe_redirect( add_query_arg( array( 'action' => 'elab_google_invite', 'advisor' => $advisor_id, 'done' => 1 ), admin_url( 'admin-post.php' ) ) );
			exit;
		}
		$destination = current_user_can( 'manage_options' ) ? get_edit_post_link( (int) $data['advisor'], 'raw' ) : admin_url( 'profile.php' );
		wp_safe_redirect( add_query_arg( 'elab_google', 'connected', $destination ) );
		exit;
	}

	/* ---------- Connection link sent by email ---------- */

	/**
	 * Personal link that lets the advisor connect Google without a WordPress login. The same
	 * link is reused until it expires or is used, so every email shows a working one.
	 */
	public static function invite_url( $advisor_id ) {
		$invite = get_post_meta( $advisor_id, self::META_INVITE, true );
		$token = is_array( $invite ) && (int) ( $invite['expires'] ?? 0 ) > time() + DAY_IN_SECONDS ? (string) self::decrypt( (string) ( $invite['token'] ?? '' ) ) : '';
		if ( '' === $token ) {
			$token = wp_generate_password( 40, false, false );
			$encrypted = self::encrypt( $token );
			if ( ! $encrypted ) {
				return '';
			}
			update_post_meta( $advisor_id, self::META_INVITE, array( 'token' => $encrypted, 'expires' => time() + self::INVITE_DAYS * DAY_IN_SECONDS ) );
		}
		return add_query_arg( array( 'action' => 'elab_google_invite', 'advisor' => (int) $advisor_id, 'token' => $token ), admin_url( 'admin-post.php' ) );
	}

	/** When the emailed link stops working (0 if there is none). */
	public static function invite_expires( $advisor_id ) {
		$invite = get_post_meta( $advisor_id, self::META_INVITE, true );
		return is_array( $invite ) ? (int) ( $invite['expires'] ?? 0 ) : 0;
	}

	public static function valid_invite( $advisor_id, $token ) {
		$invite = get_post_meta( $advisor_id, self::META_INVITE, true );
		if ( ! is_string( $token ) || '' === $token || ! is_array( $invite ) || (int) ( $invite['expires'] ?? 0 ) <= time() ) {
			return false;
		}
		$saved = (string) self::decrypt( (string) ( $invite['token'] ?? '' ) );
		return '' !== $saved && hash_equals( $saved, $token );
	}

	/**
	 * GET shows what will happen; POST goes to Google. The two steps keep email link
	 * scanners from starting anything.
	 */
	public function invite() {
		// phpcs:disable WordPress.Security.NonceVerification -- The emailed token authenticates this link.
		$advisor_id = isset( $_REQUEST['advisor'] ) ? absint( $_REQUEST['advisor'] ) : 0;
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
		$done = isset( $_GET['done'] );
		// phpcs:enable
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
		}
		$is_advisor = ELAB_Post_Types::ADVISOR === get_post_type( $advisor_id );
		$locale = $is_advisor ? ELAB_Notifications::advisor_locale( $advisor_id ) : '';
		if ( $done ) {
			$connected = $is_advisor && self::connected( $advisor_id );
			self::public_page( $locale, $connected ? 200 : 410, static function () use ( $connected ) {
				return $connected
					? array( __( 'Google Calendar connected', 'elaltillo-bookacall' ), sprintf( __( 'Visitors can book calls with you again. Each booked call appears in the “%s” calendar of your Google account. You can close this page.', 'elaltillo-bookacall' ), self::CALENDAR_NAME ) )
					: array( __( 'This link is not valid', 'elaltillo-bookacall' ), __( 'Ask the site administrator to send you a new connection link.', 'elaltillo-bookacall' ) );
			} );
		}
		if ( ! self::configured() || ! $is_advisor || ! ELAB_Notifications::advisor_email( $advisor_id ) || ! self::valid_invite( $advisor_id, $token ) ) {
			self::public_page( $locale, 403, static function () {
				return array( __( 'This link is not valid', 'elaltillo-bookacall' ), __( 'It may have expired, been used already, or been replaced by a newer one. Ask the site administrator to send you a new connection link.', 'elaltillo-bookacall' ) );
			} );
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::start_oauth( $advisor_id, 0, true );
		}
		$email = ELAB_Notifications::advisor_email( $advisor_id );
		self::public_page( $locale, 200, static function () use ( $email, $advisor_id, $token ) {
			return array(
				__( 'Connect your Google Calendar', 'elaltillo-bookacall' ),
				sprintf( __( 'Sign in with %1$s. %2$s creates one calendar in it, “%3$s”, and can only add and remove your booked calls there: it cannot see or change any other calendar or event.', 'elaltillo-bookacall' ), $email, get_bloginfo( 'name' ), self::CALENDAR_NAME ),
				array( __( 'Continue to Google', 'elaltillo-bookacall' ), add_query_arg( array( 'action' => 'elab_google_invite', 'advisor' => $advisor_id, 'token' => $token ), admin_url( 'admin-post.php' ) ) ),
			);
		} );
	}

	/**
	 * A page for someone without a login, in $locale. $build returns [title, intro, form?]; it
	 * runs inside the language switch so its strings are translated.
	 */
	private static function public_page( $locale, $code, callable $build ) {
		$html = ELAB_Notifications::in_locale( $locale, static function () use ( $build ) {
			$view = $build();
			return ELAB_Mailer::page( array( 'title' => $view[0], 'intro' => $view[1], 'form' => $view[2] ?? null ) );
		} );
		status_header( $code );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ELAB_Mailer.
		exit;
	}

	/** Administrator: email the advisor their connection link. */
	public function send_invite() {
		$advisor_id = isset( $_GET['advisor'] ) ? absint( $_GET['advisor'] ) : 0;
		check_admin_referer( 'elab_google_send_invite_' . $advisor_id );
		if ( ! current_user_can( 'manage_options' ) || ELAB_Post_Types::ADVISOR !== get_post_type( $advisor_id ) || ! self::configured() ) {
			wp_die( esc_html__( 'You cannot edit this advisor.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		$sent = ELAB_Notifications::connect_invite( $advisor_id );
		wp_safe_redirect( add_query_arg( 'elab_google', $sent ? 'invite_sent' : 'invite_failed', get_edit_post_link( $advisor_id, 'raw' ) ) . '#elab-google-connection' );
		exit;
	}

	/** Cron: ask Google about every connected advisor, so a removed grant is noticed (and the advisor told) within minutes. */
	public static function watch_connections() {
		foreach ( get_posts( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $advisor_id ) {
			if ( ! self::connected( $advisor_id ) ) {
				continue;
			}
			// Connected before owners were recorded: record it now, while the connection proves it.
			if ( ! get_post_meta( $advisor_id, self::META_CALENDAR_OWNER, true ) ) {
				update_post_meta( $advisor_id, self::META_CALENDAR_OWNER, self::owner_key( (string) get_post_meta( $advisor_id, self::META_ACCOUNT_SUB, true ) ) );
			}
			if ( ! get_transient( 'elab_timezone_synced_' . $advisor_id ) ) {
				set_transient( 'elab_timezone_synced_' . $advisor_id, 1, HOUR_IN_SECONDS );
				self::sync_timezone( $advisor_id );
			}
			$check = self::check( $advisor_id );
			if ( is_wp_error( $check ) && in_array( (int) ( $check->get_error_data()['http'] ?? 0 ), array( 404, 410 ), true ) ) {
				self::recover_calendar( $advisor_id );
			}
			// Calls still to be put back after a deleted calendar (one batch).
			self::restore_events( $advisor_id );
		}
	}

	public function disconnect() {
		$advisor_id = isset( $_GET['advisor'] ) ? absint( $_GET['advisor'] ) : 0;
		check_admin_referer( 'elab_google_disconnect_' . $advisor_id );
		if ( ! self::can_connect( $advisor_id ) || ELAB_Post_Types::ADVISOR !== get_post_type( $advisor_id ) ) {
			wp_die( esc_html__( 'You cannot edit this advisor.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		$was_linked = self::linked( $advisor_id );
		self::forget( $advisor_id, true );
		if ( $was_linked ) {
			ELAB_Notifications::disconnected( $advisor_id );
		}
		wp_safe_redirect( current_user_can( 'manage_options' ) ? get_edit_post_link( $advisor_id, 'raw' ) : admin_url( 'profile.php' ) );
		exit;
	}

	/**
	 * Remove the advisor's connection; with $revoke also invalidate the token at Google,
	 * so a disconnected site keeps no working access to the advisor's calendar.
	 */
	public static function forget( $advisor_id, $revoke = false ) {
		$refresh = $revoke ? self::decrypt( (string) get_post_meta( $advisor_id, self::META_REFRESH, true ) ) : '';
		if ( $refresh ) {
			wp_remote_post( self::REVOKE_URL, array( 'timeout' => 10, 'body' => array( 'token' => $refresh ) ) );
		}
		foreach ( array( self::META_REFRESH, self::META_CLIENT_HASH, self::META_ACCOUNT_EMAIL, self::META_ACCOUNT_SUB, self::META_REVOKED, self::META_SCOPES ) as $key ) {
			delete_post_meta( $advisor_id, $key );
		}
		delete_transient( 'elab_google_access_' . $advisor_id );
	}

	public static function revoked( $advisor_id ) {
		return (bool) get_post_meta( $advisor_id, self::META_REVOKED, true );
	}

	/** Google answered invalid_grant: the advisor removed access or the Testing-mode grant expired. */
	private static function mark_revoked( $advisor_id ) {
		if ( self::revoked( $advisor_id ) ) {
			return;
		}
		update_post_meta( $advisor_id, self::META_REVOKED, time() );
		delete_transient( 'elab_google_access_' . $advisor_id );
		ELAB_Notifications::queue_connection_lost( $advisor_id );
	}

	private static function token_request( $fields ) {
		if ( ! self::configured() ) {
			return new WP_Error( 'google_not_configured', __( 'Google Calendar is not configured.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		$response = wp_remote_post( self::TOKEN_URL, array( 'timeout' => 15, 'body' => array_merge( array( 'client_id' => self::client_id(), 'client_secret' => self::client_secret() ), $fields ) ) );
		$error = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_wp_error( $response ) && 400 === wp_remote_retrieve_response_code( $response ) && is_array( $error ) && 'invalid_grant' === ( $error['error'] ?? '' ) ) {
			return new WP_Error( 'google_revoked', __( 'Google access for this advisor was removed or has expired.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'google_auth_failed', __( 'Google Calendar authorization failed.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : new WP_Error( 'google_auth_failed', __( 'Invalid Google Calendar response.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
	}

	private static function account_identity( $access_token ) {
		if ( ! $access_token ) {
			return new WP_Error( 'google_identity_failed', __( 'Google did not return an access token.', 'elaltillo-bookacall' ) );
		}
		$response = wp_remote_get( self::USERINFO_URL, array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
		) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'google_identity_failed', __( 'The Google account could not be verified. Please try connecting again.', 'elaltillo-bookacall' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['sub'] ) || empty( $data['email'] ) || true !== ( $data['email_verified'] ?? false ) || ! is_email( $data['email'] ) ) {
			return new WP_Error( 'google_identity_failed', __( 'Google did not return a verified account email.', 'elaltillo-bookacall' ) );
		}
		return array( 'sub' => sanitize_text_field( $data['sub'] ), 'email' => sanitize_email( $data['email'] ) );
	}

	/** One authenticated Calendar API call with an explicit token (used before a connection is saved). */
	private static function api( $access_token, $method, $path, $body = null ) {
		$args = array( 'method' => $method, 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json' ) );
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::API_URL . $path, $args );
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		return array( $code, is_array( $data ) ? $data : array() );
	}

	/**
	 * The calendar this site keeps in the advisor's account. An existing one is reused when it
	 * still answers (reconnecting never piles up calendars); otherwise a new one is created.
	 *
	 * @return string|WP_Error Calendar ID.
	 */
	public static function ensure_calendar( $advisor_id, $access_token, $existing = '' ) {
		if ( $existing ) {
			list( $code, $data ) = self::api( $access_token, 'GET', '/calendars/' . rawurlencode( $existing ) );
			if ( 200 === $code && isset( $data['id'] ) && $existing === $data['id'] ) {
				return $existing;
			}
		}
		$zone = (string) get_post_meta( $advisor_id, '_elab_timezone', true ) ?: 'UTC';
		list( $code, $data ) = self::api( $access_token, 'POST', '/calendars', array(
			'summary'     => self::CALENDAR_NAME,
			'description' => sprintf( __( 'Calls booked through %s. This site can only see and change this calendar.', 'elaltillo-bookacall' ), get_bloginfo( 'name' ) ),
			'timeZone'    => $zone,
		) );
		if ( 200 !== $code || empty( $data['id'] ) || ! is_string( $data['id'] ) ) {
			return new WP_Error( 'google_calendar_unavailable', __( 'Google did not allow creating the bookings calendar. Allow the requested permission and try again.', 'elaltillo-bookacall' ) );
		}
		return $data['id'];
	}

	private static function owner_key( $sub ) {
		return hash( 'sha256', $sub . '|' . self::client_id() );
	}

	/**
	 * Whether this Google account, through this same Google app, created the advisor's saved
	 * calendar. Only then can the site still see it: a different account or app means a
	 * different calendar.
	 */
	private static function owns_calendar( $advisor_id, $sub ) {
		$owner = (string) get_post_meta( $advisor_id, self::META_CALENDAR_OWNER, true );
		if ( '' !== $owner ) {
			return hash_equals( $owner, self::owner_key( $sub ) );
		}
		// Connected before the owner was recorded: the live connection tells the same.
		return hash_equals( (string) get_post_meta( $advisor_id, self::META_ACCOUNT_SUB, true ), $sub )
			&& hash_equals( (string) get_post_meta( $advisor_id, self::META_CLIENT_HASH, true ), hash( 'sha256', self::client_id() ) );
	}

	/**
	 * The advisor deleted the site's calendar in Google. Confirm it is really gone and create it
	 * again. Its upcoming calls are put back in the background, never inside this request.
	 *
	 * @return string|WP_Error The new calendar ID.
	 */
	public static function recover_calendar( $advisor_id ) {
		$missing = self::calendar_id( $advisor_id );
		// At most once every 6 hours: a calendar Google keeps reporting missing must never pile up copies.
		if ( get_transient( 'elab_calendar_recovered_' . (int) $advisor_id ) ) {
			return new WP_Error( 'google_calendar_recently_replaced', __( 'The bookings calendar was created again recently; check the connection before trying again.', 'elaltillo-bookacall' ) );
		}
		$token = self::access_token( $advisor_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		list( $code ) = self::api( $token, 'GET', '/calendars/' . rawurlencode( $missing ) );
		if ( ! in_array( $code, array( 404, 410 ), true ) ) {
			return new WP_Error( 'google_calendar_present', __( 'The bookings calendar is still in Google.', 'elaltillo-bookacall' ) );
		}
		$replacement = self::ensure_calendar( $advisor_id, $token );
		if ( is_wp_error( $replacement ) ) {
			return $replacement;
		}
		update_post_meta( $advisor_id, self::META_CALENDAR, $replacement );
		set_transient( 'elab_calendar_recovered_' . (int) $advisor_id, time(), 6 * HOUR_IN_SECONDS );
		self::calendar_replaced( $advisor_id, $missing );
		return $replacement;
	}

	/**
	 * Remember the deleted calendar and start putting its upcoming calls back. Past calls stay
	 * only in WordPress. With nothing to put back, the advisor and admin are told right away.
	 */
	private static function calendar_replaced( $advisor_id, $missing ) {
		$gone = array_filter( (array) get_post_meta( $advisor_id, self::META_GONE_CALENDARS, true ) );
		$gone[] = $missing;
		update_post_meta( $advisor_id, self::META_GONE_CALENDARS, array_slice( array_values( array_unique( $gone ) ), -10 ) );
		$total = count( self::restore_queue( $advisor_id, -1 ) );
		if ( ! $total ) {
			delete_post_meta( $advisor_id, self::META_GONE_CALENDARS );
			ELAB_Notifications::calendar_restored( $advisor_id, array(), 0 );
			return;
		}
		update_post_meta( $advisor_id, self::META_RESTORE, array( 'total' => $total, 'restored' => 0, 'sample' => array(), 'told' => false ) );
		self::schedule_restore();
	}

	/** Run the next batch soon, in the background (WordPress cron), not in a visitor's request. */
	public static function schedule_restore( $delay = 0 ) {
		if ( ! wp_next_scheduled( self::RESTORE_CRON ) ) {
			wp_schedule_single_event( time() + $delay, self::RESTORE_CRON );
		}
	}

	/** Cron: one batch for every advisor with calls to put back; more batches follow while any remain. */
	public static function run_restores() {
		$more = false;
		foreach ( get_posts( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => self::META_GONE_CALENDARS ) ) as $advisor_id ) {
			$result = self::restore_events( (int) $advisor_id );
			$more = $more || ( ! $result['done'] && self::connected( (int) $advisor_id ) );
		}
		if ( $more ) {
			self::schedule_restore( 2 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Upcoming confirmed calls still in a deleted calendar, nearest first. Past and cancelled
	 * calls are never included: they stay only in WordPress. Calls of an older Google app's
	 * calendar are never included either: that calendar still exists, out of this site's reach.
	 *
	 * @param int  $limit    -1 for all.
	 * @param bool $given_up Only the calls that failed too often (for the final email).
	 * @return int[]
	 */
	private static function restore_queue( $advisor_id, $limit, $given_up = false ) {
		$gone = array_values( array_filter( (array) get_post_meta( $advisor_id, self::META_GONE_CALENDARS, true ) ) );
		if ( ! $gone ) {
			return array();
		}
		$tries = $given_up
			? array( 'key' => self::META_RESTORE_TRIES, 'value' => self::RESTORE_TRIES, 'compare' => '>=', 'type' => 'NUMERIC' )
			: array( 'relation' => 'OR', array( 'key' => self::META_RESTORE_TRIES, 'compare' => 'NOT EXISTS' ), array( 'key' => self::META_RESTORE_TRIES, 'value' => self::RESTORE_TRIES, 'compare' => '<', 'type' => 'NUMERIC' ) );
		return array_map( 'intval', get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'any', 'numberposts' => $limit, 'fields' => 'ids', 'orderby' => 'meta_value', 'meta_key' => '_elab_start_utc', 'order' => 'ASC', 'meta_query' => array(
			array( 'key' => '_elab_advisor', 'value' => (int) $advisor_id ),
			array( 'key' => '_elab_status', 'value' => 'confirmed' ),
			array( 'key' => self::META_CALENDAR, 'value' => $gone, 'compare' => 'IN' ),
			array( 'key' => '_elab_start_utc', 'value' => gmdate( 'Y-m-d H:i:s' ), 'compare' => '>', 'type' => 'DATETIME' ),
			$tries,
		) ) ) );
	}

	/**
	 * One batch: at most RESTORE_BATCH calls and RESTORE_SECONDS seconds, nearest first, same
	 * time, guests and Meet link. Stops early when Google asks to slow down. A call that keeps
	 * failing is left after RESTORE_TRIES attempts and named in the final email.
	 *
	 * @return array{restored:int[],waiting:int,done:bool}
	 */
	public static function restore_events( $advisor_id ) {
		$result = array( 'restored' => array(), 'waiting' => 0, 'done' => true );
		if ( ! get_post_meta( $advisor_id, self::META_GONE_CALENDARS, true ) ) {
			return $result;
		}
		if ( ! self::connected( $advisor_id ) ) {
			$result['done'] = false;
			return $result;
		}
		$began = microtime( true );
		foreach ( self::restore_queue( $advisor_id, self::RESTORE_BATCH ) as $booking_id ) {
			if ( microtime( true ) - $began > self::RESTORE_SECONDS ) {
				break;
			}
			$lock = ELAB_Booking_Sync::lock( $booking_id );
			if ( ! $lock ) {
				continue;
			}
			try {
				$start = date_create_immutable( get_post_meta( $booking_id, '_elab_start_utc', true ), new DateTimeZone( 'UTC' ) );
				$event = $start ? self::create_event( $advisor_id, $booking_id, $start, ELAB_Booking_Admin::duration( $booking_id ), (string) get_post_meta( $booking_id, '_elab_contact_email', true ), true ) : new WP_Error( 'elab_bad_time', 'Unreadable time' );
			} finally {
				delete_option( $lock );
			}
			if ( ! is_wp_error( $event ) ) {
				$result['restored'][] = (int) $booking_id;
				delete_post_meta( $booking_id, self::META_RESTORE_TRIES );
				continue;
			}
			update_post_meta( $booking_id, self::META_RESTORE_TRIES, (int) get_post_meta( $booking_id, self::META_RESTORE_TRIES, true ) + 1 );
			$data = (array) $event->get_error_data();
			// Google is rationing invitations or requests: wait for the next batch.
			if ( 429 === (int) ( $data['http'] ?? 0 ) || ( 403 === (int) ( $data['http'] ?? 0 ) && false !== stripos( (string) ( $data['reason'] ?? '' ), 'limit' ) ) ) {
				break;
			}
		}
		$progress = (array) get_post_meta( $advisor_id, self::META_RESTORE, true ) + array( 'total' => 0, 'restored' => 0, 'sample' => array(), 'told' => false );
		$progress['restored'] += count( $result['restored'] );
		$progress['sample'] = array_slice( array_merge( (array) $progress['sample'], $result['restored'] ), 0, 20 );
		$result['waiting'] = count( self::restore_queue( $advisor_id, -1 ) );
		$result['done'] = 0 === $result['waiting'];
		if ( $result['done'] ) {
			$given_up = self::restore_queue( $advisor_id, -1, true );
			delete_post_meta( $advisor_id, self::META_GONE_CALENDARS );
			delete_post_meta( $advisor_id, self::META_RESTORE );
			ELAB_Notifications::calendar_restored( $advisor_id, $progress['sample'], $progress['restored'], 0, $given_up, ! empty( $progress['told'] ) );
			return $result;
		}
		if ( empty( $progress['told'] ) ) {
			ELAB_Notifications::calendar_restored( $advisor_id, $progress['sample'], $progress['restored'], $result['waiting'] );
			$progress['told'] = true;
		}
		update_post_meta( $advisor_id, self::META_RESTORE, $progress );
		return $result;
	}

	/** The booking's existing Meet, in the form Google accepts to attach it to a new event. */
	private static function meet_copy( $booking_id ) {
		$url = (string) get_post_meta( $booking_id, '_elab_meet_url', true );
		if ( ! preg_match( '#^https://meet\.google\.com/([a-z]+(?:-[a-z]+)+)$#', $url, $match ) ) {
			return null;
		}
		return array(
			'conferenceId'       => $match[1],
			'conferenceSolution' => array( 'key' => array( 'type' => 'hangoutsMeet' ) ),
			'entryPoints'        => array( array( 'entryPointType' => 'video', 'uri' => $url, 'label' => 'meet.google.com/' . $match[1] ) ),
		);
	}

	/** Whether the advisor allowed reading their Google Calendar settings (time zone). */
	public static function reads_settings( $advisor_id ) {
		return in_array( self::SETTINGS_SCOPE, preg_split( '/\s+/', (string) get_post_meta( $advisor_id, self::META_SCOPES, true ), -1, PREG_SPLIT_NO_EMPTY ), true );
	}

	/** The time zone follows the advisor's Google Calendar (so the WordPress field is read-only). */
	public static function timezone_from_google( $advisor_id ) {
		return 'google' === get_post_meta( $advisor_id, self::META_TIMEZONE_SOURCE, true ) && self::connected( $advisor_id ) && self::reads_settings( $advisor_id );
	}

	/**
	 * Take the advisor's time zone from their Google Calendar settings. When it changed, the
	 * site's calendar follows and (with $notify) the advisor gets their schedule again, in the
	 * new time zone.
	 *
	 * @return string|false|WP_Error The new time zone, false when unchanged or not allowed.
	 */
	public static function sync_timezone( $advisor_id, $notify = true ) {
		if ( ! self::reads_settings( $advisor_id ) || ! self::connected( $advisor_id ) ) {
			return false;
		}
		$setting = self::request( $advisor_id, 'GET', '/users/me/settings/timezone' );
		if ( is_wp_error( $setting ) ) {
			return $setting;
		}
		$zone = (string) ( $setting['value'] ?? '' );
		if ( ! in_array( $zone, timezone_identifiers_list(), true ) ) {
			return false;
		}
		update_post_meta( $advisor_id, self::META_TIMEZONE_SOURCE, 'google' );
		if ( $zone === (string) get_post_meta( $advisor_id, '_elab_timezone', true ) ) {
			return false;
		}
		update_post_meta( $advisor_id, '_elab_timezone', $zone );
		self::request( $advisor_id, 'PATCH', '/calendars/' . rawurlencode( self::calendar_id( $advisor_id ) ), array( 'timeZone' => $zone ) );
		if ( $notify ) {
			ELAB_Notifications::schedule_summary( $advisor_id );
		}
		return $zone;
	}

	/** Live check used by the admin tests: the site's own calendar still answers. */
	public static function check( $advisor_id ) {
		$calendar = self::calendar_id( $advisor_id );
		if ( ! $calendar ) {
			return new WP_Error( 'google_not_ready', __( 'The bookings calendar is missing; connect Google Calendar again.', 'elaltillo-bookacall' ) );
		}
		$data = self::request( $advisor_id, 'GET', '/calendars/' . rawurlencode( $calendar ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		self::refresh_scopes( $advisor_id );
		$extra = self::extra_scopes( $advisor_id );
		if ( $extra ) {
			return new WP_Error( 'google_scope_too_broad', sprintf( __( 'Google reports more access than this site needs (%s). Disconnect and connect again so only the site’s own calendar is shared.', 'elaltillo-bookacall' ), implode( ', ', $extra ) ) );
		}
		return true;
	}

	/** Everything this site ever asks Google for. Anything else on a token is too much. */
	public static function allowed_scopes() {
		return array( 'openid', 'email', 'https://www.googleapis.com/auth/userinfo.email', self::SCOPE, self::SETTINGS_SCOPE );
	}

	/** Permissions Google reported for this advisor that go beyond what the site needs. */
	public static function extra_scopes( $advisor_id ) {
		$stored = preg_split( '/\s+/', (string) get_post_meta( $advisor_id, self::META_SCOPES, true ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_map( static function ( $scope ) {
			return str_replace( 'https://www.googleapis.com/auth/', '', $scope );
		}, array_diff( $stored, self::allowed_scopes() ) ) );
	}

	/** True once Google itself has confirmed the access is limited to the site's own calendar. */
	public static function scopes_verified( $advisor_id ) {
		return '' !== (string) get_post_meta( $advisor_id, self::META_SCOPES, true ) && ! self::extra_scopes( $advisor_id );
	}

	/** Ask Google which permissions the current access really carries, and remember the answer. */
	public static function refresh_scopes( $advisor_id ) {
		$token = self::access_token( $advisor_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = wp_remote_post( self::TOKENINFO_URL, array( 'timeout' => 10, 'body' => array( 'access_token' => $token ) ) );
		$data = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['scope'] ) || ! is_string( $data['scope'] ) ) {
			return new WP_Error( 'google_scope_unknown', __( 'Google did not report the permissions of this connection.', 'elaltillo-bookacall' ) );
		}
		update_post_meta( $advisor_id, self::META_SCOPES, sanitize_text_field( $data['scope'] ) );
		return $data['scope'];
	}

	/**
	 * Encryption keys, newest first. Define ELAB_ENCRYPTION_KEY in wp-config.php so stored
	 * secrets survive a salt rotation; values saved with the salt key still decrypt.
	 */
	private static function keys() {
		$keys = array();
		if ( defined( 'ELAB_ENCRYPTION_KEY' ) && is_string( ELAB_ENCRYPTION_KEY ) && strlen( ELAB_ENCRYPTION_KEY ) >= 32 ) {
			$keys[] = hash( 'sha256', 'elab|' . ELAB_ENCRYPTION_KEY, true );
		}
		$keys[] = hash( 'sha256', wp_salt( 'auth' ), true );
		return $keys;
	}

	public static function has_own_key() {
		return count( self::keys() ) > 1;
	}

	/** AES-256-GCM; shared by modules that store secrets. */
	public static function encrypt( $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}
		$iv = random_bytes( 12 );
		$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', self::keys()[0], OPENSSL_RAW_DATA, $iv, $tag );
		return false === $cipher ? false : base64_encode( $iv . $tag . $cipher );
	}

	public static function decrypt( $encoded ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}
		$bytes = base64_decode( $encoded, true );
		if ( false === $bytes || strlen( $bytes ) < 29 ) {
			return false;
		}
		foreach ( self::keys() as $key ) {
			$plain = openssl_decrypt( substr( $bytes, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $bytes, 0, 12 ), substr( $bytes, 12, 16 ) );
			if ( false !== $plain ) {
				return $plain;
			}
		}
		return false;
	}

	private static function access_token( $advisor_id ) {
		if ( ! self::connected( $advisor_id ) ) {
			return new WP_Error( 'google_disconnected', __( 'This advisor has not connected Google Calendar.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		$cached = get_transient( 'elab_google_access_' . $advisor_id );
		if ( $cached ) {
			return $cached;
		}
		$refresh = self::decrypt( get_post_meta( $advisor_id, self::META_REFRESH, true ) );
		if ( ! $refresh ) {
			return new WP_Error( 'google_disconnected', __( 'This advisor has not connected Google Calendar.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		$tokens = self::token_request( array( 'grant_type' => 'refresh_token', 'refresh_token' => $refresh ) );
		if ( is_wp_error( $tokens ) && 'google_revoked' === $tokens->get_error_code() ) {
			self::mark_revoked( $advisor_id );
			return $tokens;
		}
		if ( is_wp_error( $tokens ) || empty( $tokens['access_token'] ) ) {
			return new WP_Error( 'google_auth_failed', __( 'Google Calendar needs to be reconnected.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		set_transient( 'elab_google_access_' . $advisor_id, $tokens['access_token'], max( 60, min( 3300, (int) ( $tokens['expires_in'] ?? 3600 ) - 120 ) ) );
		return $tokens['access_token'];
	}

	private static function request( $advisor_id, $method, $path, $body = null, $retry = true ) {
		$token = self::access_token( $advisor_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array( 'method' => $method, 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ) );
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::API_URL . $path, $args );
		$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		// The saved access stopped working (access removed in Google): get a new one once, so a
		// removed grant is noticed now (and the advisor told) instead of when the cache expires.
		if ( 401 === $code && $retry ) {
			delete_transient( 'elab_google_access_' . $advisor_id );
			return self::request( $advisor_id, $method, $path, $body, false );
		}
		// Already gone in Google (404), or deleted there earlier (410): the removal is done.
		if ( 'DELETE' === $method && in_array( $code, array( 404, 410 ), true ) ) {
			return array();
		}
		if ( $code < 200 || $code >= 300 ) {
			// Google's own reason (e.g. "accessNotConfigured"), for the administrator's status pages.
			$error = is_wp_error( $response ) ? array() : json_decode( wp_remote_retrieve_body( $response ), true );
			$reason = is_wp_error( $response ) ? $response->get_error_message() : trim( (string) ( $error['error']['errors'][0]['reason'] ?? '' ) . ' ' . (string) ( $error['error']['message'] ?? '' ) );
			return new WP_Error( 'google_calendar_failed', __( 'Google Calendar could not complete this action. Please try again.', 'elaltillo-bookacall' ), array( 'status' => 503, 'http' => (int) $code, 'reason' => sanitize_text_field( substr( $reason, 0, 300 ) ) ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array();
	}

	/** Whether confirmed calls get a Google Meet link (on unless an administrator turns it off). */
	public static function meet_enabled() {
		return '0' !== (string) get_option( 'elab_google_meet', '1' );
	}

	/**
	 * @param bool $restore Put an existing call back after its calendar was deleted: keep its
	 *                      Meet link, and never recreate the calendar from here.
	 */
	public static function create_event( $advisor_id, $booking_id, DateTimeImmutable $start, $duration, $email, $restore = false ) {
		if ( ! self::connected( $advisor_id ) ) {
			return new WP_Error( 'google_not_ready', __( 'This advisor needs to connect Google Calendar again.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		$calendar = self::calendar_id( $advisor_id );
		$end = $start->modify( '+' . absint( $duration ) . ' minutes' );
		$event_id = self::event_id_for( $booking_id );
		$advisor = get_the_title( $advisor_id );
		$visitor = (string) get_post_meta( $booking_id, '_elab_name', true );
		// Title and description in the meeting language: everyone in the call reads it, and Meet
		// shows the same title.
		$language = (string) get_post_meta( $booking_id, '_elab_language', true ) ?: ELAB_Booking_Service::DEFAULT_LANGUAGE;
		list( $summary, $description ) = ELAB_Notifications::in_locale( ELAB_Notifications::locale_for_language( $language ), static function () use ( $duration, $visitor, $advisor, $language ) {
			return array(
				/* translators: 1: minutes, 2: advisor, 3: visitor, 4: brand. Also the Google Meet title. */
				sprintf( __( '%1$d-min call with %2$s · %3$s · %4$s', 'elaltillo-bookacall' ), absint( $duration ), $advisor, $visitor ?: __( 'Visitor', 'elaltillo-bookacall' ), ELAB_Mailer::brand() ),
				array(
					sprintf( __( 'Call booked through %s.', 'elaltillo-bookacall' ), ELAB_Mailer::brand() ),
					sprintf( __( 'Length: %d minutes', 'elaltillo-bookacall' ), absint( $duration ) ),
					sprintf( __( 'Meeting language: %s', 'elaltillo-bookacall' ), ELAB_Booking_Service::language_label( $language ) ),
				),
			);
		} );
		// No private cancel link here: Google shows the description to every guest. The visitor
		// gets it by email only.
		// The visitor is an invited guest: Google emails the invitation, nothing to set up on their side.
		// Sector, message and files stay out of the event; it lives only in the site's own calendar.
		$body = array(
			'id' => $event_id,
			'summary' => $summary,
			'description' => implode( "\n", $description ),
			'visibility' => 'private',
			'guestsCanInviteOthers' => false,
			'guestsCanSeeOtherGuests' => false,
			'guestsCanModify' => false,
			'reminders' => array( 'useDefault' => true ),
			'start' => array( 'dateTime' => $start->format( DATE_RFC3339 ) ),
			'end' => array( 'dateTime' => $end->format( DATE_RFC3339 ) ),
			'attendees' => array_map( static function ( $address ) { return array( 'email' => $address ); }, array_merge( array( $email ), ELAB_Booking_Sync::guests( $booking_id ) ) ),
		);
		$path = '/calendars/' . rawurlencode( $calendar ) . '/events?sendUpdates=all';
		$new_meet = array( 'createRequest' => array( 'requestId' => $event_id, 'conferenceSolutionKey' => array( 'type' => 'hangoutsMeet' ) ) );
		$kept_meet = $restore ? self::meet_copy( $booking_id ) : null;
		if ( self::meet_enabled() ) {
			// The event ID doubles as the idempotent request ID, so a retried insert reuses the same Meet.
			$body['conferenceData'] = $kept_meet ?: $new_meet;
			$path .= '&conferenceDataVersion=1';
		}
		$event = self::request( $advisor_id, 'POST', $path, $body );
		$http = is_wp_error( $event ) ? (int) ( $event->get_error_data()['http'] ?? 0 ) : 0;
		// The advisor deleted the site's calendar in Google: make it again (with its calls) and retry once.
		if ( 404 === $http && ! $restore ) {
			$replacement = self::recover_calendar( $advisor_id );
			if ( ! is_wp_error( $replacement ) ) {
				$path = str_replace( '/calendars/' . rawurlencode( $calendar ) . '/', '/calendars/' . rawurlencode( $replacement ) . '/', $path );
				$calendar = $replacement;
				$event = self::request( $advisor_id, 'POST', $path, $body );
			}
		}
		if ( $restore && 400 === $http && isset( $body['conferenceData'] ) && $kept_meet ) {
			// Google would not attach the old Meet: the call gets a new one, sent in Google's invitation.
			$body['conferenceData'] = $new_meet;
			$event = self::request( $advisor_id, 'POST', $path, $body );
			$http = is_wp_error( $event ) ? (int) ( $event->get_error_data()['http'] ?? 0 ) : 0;
		}
		if ( $restore && 409 === $http ) {
			// Google still holds the old ID: same booking, fresh ID in the plugin's own format.
			$event_id = 'elab' . md5( $event_id . '|' . $calendar );
			$body['id'] = $event_id;
			if ( isset( $body['conferenceData']['createRequest'] ) ) {
				$body['conferenceData']['createRequest']['requestId'] = $event_id;
			}
			$event = self::request( $advisor_id, 'POST', $path, $body );
		}
		// A timed-out insert can still have created the event. Look up its stable ID before failing.
		if ( is_wp_error( $event ) || empty( $event['id'] ) ) {
			$existing = self::request( $advisor_id, 'GET', '/calendars/' . rawurlencode( $calendar ) . '/events/' . rawurlencode( $event_id ) );
			if ( ! is_wp_error( $existing ) && isset( $existing['id'] ) && $event_id === $existing['id'] ) {
				$event = $existing;
			} elseif ( ! is_wp_error( $event ) ) {
				$event = new WP_Error( 'google_calendar_failed', __( 'Google Calendar did not confirm the new event. Please try again.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
			}
		}
		if ( ! is_wp_error( $event ) ) {
			update_post_meta( $booking_id, self::META_EVENT, $event['id'] );
			update_post_meta( $booking_id, self::META_CALENDAR, $calendar );
			$meet = isset( $event['hangoutLink'] ) ? esc_url_raw( $event['hangoutLink'] ) : '';
			if ( $meet && 0 === strpos( $meet, 'https://meet.google.com/' ) ) {
				update_post_meta( $booking_id, '_elab_meet_url', $meet );
			}
		}
		return $event;
	}

	/**
	 * What Google says about this booking's event now: deleted, its times, and the visitor's
	 * answer to the invitation.
	 *
	 * @return array{deleted:bool,start:int,end:int,response:string}|WP_Error An error when Google
	 *         cannot tell (including a missing calendar, which is never read as "every call was
	 *         cancelled").
	 */
	public static function event_state( $booking_id ) {
		$advisor_id = (int) get_post_meta( $booking_id, '_elab_advisor', true );
		$event_id = (string) get_post_meta( $booking_id, self::META_EVENT, true );
		$calendar = (string) get_post_meta( $booking_id, self::META_CALENDAR, true );
		if ( ! preg_match( '/^elab[0-9a-f]{32}$/', $event_id ) || '' === $calendar ) {
			return new WP_Error( 'google_event_unknown', __( 'This booking has no event in the site’s calendar.', 'elaltillo-bookacall' ) );
		}
		$event = self::request( $advisor_id, 'GET', '/calendars/' . rawurlencode( $calendar ) . '/events/' . rawurlencode( $event_id ) );
		if ( is_wp_error( $event ) ) {
			if ( ! in_array( (int) ( $event->get_error_data()['http'] ?? 0 ), array( 404, 410 ), true ) ) {
				return $event;
			}
			$exists = self::request( $advisor_id, 'GET', '/calendars/' . rawurlencode( $calendar ) );
			return is_wp_error( $exists ) ? new WP_Error( 'google_calendar_gone', __( 'The bookings calendar is missing in Google.', 'elaltillo-bookacall' ) ) : array( 'deleted' => true, 'start' => 0, 'end' => 0, 'response' => '' );
		}
		$visitor = strtolower( (string) get_post_meta( $booking_id, '_elab_contact_email', true ) );
		$response = '';
		foreach ( (array) ( $event['attendees'] ?? array() ) as $attendee ) {
			if ( is_array( $attendee ) && $visitor && strtolower( (string) ( $attendee['email'] ?? '' ) ) === $visitor ) {
				$response = sanitize_key( (string) ( $attendee['responseStatus'] ?? '' ) );
			}
		}
		return array(
			'deleted'  => 'cancelled' === ( $event['status'] ?? '' ),
			'start'    => isset( $event['start']['dateTime'] ) ? (int) strtotime( (string) $event['start']['dateTime'] ) : 0,
			'end'      => isset( $event['end']['dateTime'] ) ? (int) strtotime( (string) $event['end']['dateTime'] ) : 0,
			'response' => $response,
		);
	}

	/** Whether the advisor deleted this booking's event in Google Calendar. */
	public static function event_deleted( $booking_id ) {
		$state = self::event_state( $booking_id );
		if ( is_wp_error( $state ) ) {
			return 'google_event_unknown' === $state->get_error_code() ? false : $state;
		}
		return $state['deleted'];
	}

	/** The only event ID this plugin ever creates for a booking: "elab" + 32 hex characters. */
	public static function event_id_for( $booking_id ) {
		return 'elab' . md5( (int) $booking_id . '|' . wp_salt( 'nonce' ) );
	}

	/**
	 * Delete the Google event of one booking. Refuses any ID other than the one this
	 * plugin generated for that booking, so nothing else in the advisor's calendar can
	 * ever be removed, even if stored data were altered.
	 */
	public static function delete_event( $advisor_id, $event_id, $booking_id ) {
		// Either the ID derived for this booking, or (after a salt change) the plugin-format
		// ID stored on this very booking. Anything else is never sent to Google.
		$ours = is_string( $event_id ) && (
			hash_equals( self::event_id_for( $booking_id ), $event_id )
			|| ( preg_match( '/^elab[0-9a-f]{32}$/', $event_id ) && hash_equals( (string) get_post_meta( $booking_id, self::META_EVENT, true ), $event_id ) )
		);
		if ( ! $ours ) {
			return new WP_Error( 'google_event_not_ours', __( 'This calendar event was not created for this booking, so it was not changed.', 'elaltillo-bookacall' ), array( 'status' => 409 ) );
		}
		$calendar = (string) get_post_meta( $booking_id, self::META_CALENDAR, true );
		if ( '' === $calendar ) {
			// Created before the site switched to its own calendar: this permission cannot reach it.
			return new WP_Error( 'google_event_legacy', __( 'This event is in the advisor’s main calendar and must be removed there by hand.', 'elaltillo-bookacall' ), array( 'status' => 409 ) );
		}
		return self::request( $advisor_id, 'DELETE', '/calendars/' . rawurlencode( $calendar ) . '/events/' . rawurlencode( $event_id ) . '?sendUpdates=all' );
	}
}
