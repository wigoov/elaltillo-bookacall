<?php
/** Per-advisor Google Calendar OAuth, free/busy and event operations. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Google_Calendar {
	const META_REFRESH = '_elab_google_refresh';
	const META_CLIENT_HASH = '_elab_google_client_hash';
	const META_ACCOUNT_EMAIL = '_elab_google_account_email';
	const META_ACCOUNT_SUB = '_elab_google_account_sub';
	const META_EVENT = '_elab_google_event_id';
	const META_BUSY_VERSION = '_elab_busy_version';
	const OPTION_CLIENT_ID = 'elab_google_client_id';
	const OPTION_CLIENT_SECRET = 'elab_google_client_secret';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API_URL = 'https://www.googleapis.com/calendar/v3';
	const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

	public function __construct() {
		add_action( 'admin_post_elab_google_connect', array( $this, 'connect' ) );
		add_action( 'admin_post_elab_google_callback', array( $this, 'callback' ) );
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
		echo '<h2>' . esc_html__( 'Your advisor calendars', 'elaltillo-bookacall' ) . '</h2>';
		if ( isset( $_GET['elab_google'] ) && 'connected' === sanitize_key( wp_unslash( $_GET['elab_google'] ) ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Your Google Calendar is connected and its account and primary calendar were verified.', 'elaltillo-bookacall' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Connect the Google account shown for your advisor profile. We verify the account email and primary calendar before enabling bookings. Your weekly availability is emailed to you; only confirmed calls become calendar events.', 'elaltillo-bookacall' ) . '</p><table class="form-table" role="presentation"><tbody>';
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
				if ( ! $connected && get_post_meta( $advisor->ID, self::META_REFRESH, true ) ) {
					echo '<p class="description">' . esc_html__( 'The previous connection needs to be authorized again.', 'elaltillo-bookacall' ) . '</p>';
				}
				echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $connected ? __( 'Disconnect Google Calendar', 'elaltillo-bookacall' ) : __( 'Connect Google Calendar', 'elaltillo-bookacall' ) ) . '</a>';
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

	public static function connected( $advisor_id ) {
		$stored_hash = (string) get_post_meta( $advisor_id, self::META_CLIENT_HASH, true );
		$account_email = self::account_email( $advisor_id );
		$expected_email = ELAB_Notifications::advisor_email( $advisor_id );
		return self::configured() && (bool) get_post_meta( $advisor_id, self::META_REFRESH, true ) && '' !== $stored_hash && hash_equals( hash( 'sha256', self::client_id() ), $stored_hash ) && (bool) get_post_meta( $advisor_id, self::META_ACCOUNT_SUB, true ) && $expected_email && 0 === strcasecmp( $account_email, $expected_email );
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
		$state = wp_generate_password( 48, false, false );
		set_transient( 'elab_google_state_' . hash( 'sha256', $state ), array( 'advisor' => $advisor_id, 'user' => get_current_user_id() ), 10 * MINUTE_IN_SECONDS );
		$url = add_query_arg( array(
			'client_id' => self::client_id(),
			'redirect_uri' => self::redirect_uri(),
			'response_type' => 'code',
			'access_type' => 'offline',
			'prompt' => 'consent',
			'scope' => 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy',
			'state' => $state,
		), 'https://accounts.google.com/o/oauth2/v2/auth' );
		wp_redirect( esc_url_raw( $url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google OAuth endpoint.
		exit;
	}

	public function callback() {
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$data = $state ? get_transient( 'elab_google_state_' . hash( 'sha256', $state ) ) : false;
		delete_transient( 'elab_google_state_' . hash( 'sha256', $state ) );
		if ( ! is_array( $data ) || ! is_user_logged_in() || (int) $data['user'] !== get_current_user_id() || ! self::can_connect( (int) $data['advisor'] ) ) {
			wp_die( esc_html__( 'Invalid or expired Google authorization.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( ! $code ) {
			wp_die( esc_html__( 'Google authorization was cancelled.', 'elaltillo-bookacall' ), '', array( 'response' => 400 ) );
		}
		$tokens = self::token_request( array( 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirect_uri() ) );
		if ( is_wp_error( $tokens ) || empty( $tokens['refresh_token'] ) ) {
			wp_die( esc_html__( 'Google did not provide a refresh token. Reconnect the advisor and grant calendar access.', 'elaltillo-bookacall' ), '', array( 'response' => 400 ) );
		}
		$required_scopes = array( 'https://www.googleapis.com/auth/calendar.events', 'https://www.googleapis.com/auth/calendar.freebusy' );
		$granted_scopes = isset( $tokens['scope'] ) ? explode( ' ', trim( (string) $tokens['scope'] ) ) : array();
		if ( $granted_scopes && array_diff( $required_scopes, $granted_scopes ) ) {
			wp_die( esc_html__( 'Grant both requested Google Calendar permissions and try again.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		$identity = self::account_identity( $tokens['access_token'] ?? '' );
		if ( is_wp_error( $identity ) ) {
			wp_die( esc_html( $identity->get_error_message() ), '', array( 'response' => 503 ) );
		}
		$expected_email = ELAB_Notifications::advisor_email( (int) $data['advisor'] );
		if ( ! $expected_email || 0 !== strcasecmp( $identity['email'], $expected_email ) ) {
			wp_die( esc_html( sprintf( __( 'You connected %1$s, but this advisor expects %2$s. Choose the correct Google account or ask an administrator to update the advisor email.', 'elaltillo-bookacall' ), $identity['email'], $expected_email ) ), '', array( 'response' => 403 ) );
		}
		$calendar_check = self::verify_calendar_access( $tokens['access_token'] );
		if ( is_wp_error( $calendar_check ) ) {
			wp_die( esc_html( $calendar_check->get_error_message() ), '', array( 'response' => 503 ) );
		}
		$encrypted = self::encrypt( $tokens['refresh_token'] );
		if ( ! $encrypted ) {
			wp_die( esc_html__( 'Could not protect the Google credentials on this server.', 'elaltillo-bookacall' ), '', array( 'response' => 500 ) );
		}
		update_post_meta( (int) $data['advisor'], self::META_REFRESH, $encrypted );
		update_post_meta( (int) $data['advisor'], self::META_CLIENT_HASH, hash( 'sha256', self::client_id() ) );
		update_post_meta( (int) $data['advisor'], self::META_ACCOUNT_EMAIL, $identity['email'] );
		update_post_meta( (int) $data['advisor'], self::META_ACCOUNT_SUB, $identity['sub'] );
		delete_transient( 'elab_google_access_' . (int) $data['advisor'] );
		ELAB_Notifications::schedule_summary( (int) $data['advisor'] );
		$destination = current_user_can( 'manage_options' ) ? get_edit_post_link( (int) $data['advisor'], 'raw' ) : admin_url( 'profile.php' );
		wp_safe_redirect( add_query_arg( 'elab_google', 'connected', $destination ) );
		exit;
	}

	public function disconnect() {
		$advisor_id = isset( $_GET['advisor'] ) ? absint( $_GET['advisor'] ) : 0;
		check_admin_referer( 'elab_google_disconnect_' . $advisor_id );
		if ( ! self::can_connect( $advisor_id ) || ELAB_Post_Types::ADVISOR !== get_post_type( $advisor_id ) ) {
			wp_die( esc_html__( 'You cannot edit this advisor.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		delete_post_meta( $advisor_id, self::META_REFRESH );
		delete_post_meta( $advisor_id, self::META_CLIENT_HASH );
		delete_post_meta( $advisor_id, self::META_ACCOUNT_EMAIL );
		delete_post_meta( $advisor_id, self::META_ACCOUNT_SUB );
		delete_transient( 'elab_google_access_' . $advisor_id );
		wp_safe_redirect( current_user_can( 'manage_options' ) ? get_edit_post_link( $advisor_id, 'raw' ) : admin_url( 'profile.php' ) );
		exit;
	}

	private static function token_request( $fields ) {
		if ( ! self::configured() ) {
			return new WP_Error( 'google_not_configured', __( 'Google Calendar is not configured.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		$response = wp_remote_post( self::TOKEN_URL, array( 'timeout' => 15, 'body' => array_merge( array( 'client_id' => self::client_id(), 'client_secret' => self::client_secret() ), $fields ) ) );
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

	private static function verify_calendar_access( $access_token ) {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$response = wp_remote_post( self::API_URL . '/freeBusy', array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( array(
				'timeMin' => $now->format( DATE_RFC3339 ),
				'timeMax' => $now->modify( '+1 hour' )->format( DATE_RFC3339 ),
				'items' => array( array( 'id' => 'primary' ) ),
			) ),
		) );
		$data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$calendar = is_array( $data ) ? self::primary_freebusy( $data ) : null;
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $calendar ) || ! isset( $calendar['busy'] ) || ! is_array( $calendar['busy'] ) || ! empty( $calendar['errors'] ) ) {
			return new WP_Error( 'google_calendar_unavailable', __( 'The connected Google account could not access its primary calendar. Grant Calendar access and try again.', 'elaltillo-bookacall' ) );
		}
		return true;
	}

	private static function primary_freebusy( $data ) {
		if ( ! isset( $data['calendars'] ) || ! is_array( $data['calendars'] ) || 1 !== count( $data['calendars'] ) ) {
			return null;
		}
		return reset( $data['calendars'] );
	}

	private static function encrypt( $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}
		$iv = random_bytes( 12 );
		$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $cipher ? false : base64_encode( $iv . $tag . $cipher );
	}

	private static function decrypt( $encoded ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}
		$bytes = base64_decode( $encoded, true );
		if ( false === $bytes || strlen( $bytes ) < 29 ) {
			return false;
		}
		return openssl_decrypt( substr( $bytes, 28 ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $bytes, 0, 12 ), substr( $bytes, 12, 16 ) );
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
		if ( is_wp_error( $tokens ) || empty( $tokens['access_token'] ) ) {
			return new WP_Error( 'google_auth_failed', __( 'Google Calendar needs to be reconnected.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		set_transient( 'elab_google_access_' . $advisor_id, $tokens['access_token'], max( 60, min( 3300, (int) ( $tokens['expires_in'] ?? 3600 ) - 120 ) ) );
		return $tokens['access_token'];
	}

	private static function request( $advisor_id, $method, $path, $body = null ) {
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
		if ( 'DELETE' === $method && 404 === $code ) {
			return array();
		}
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'google_calendar_failed', __( 'Google Calendar could not complete this action. Please try again.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Busy periods from the advisor's primary calendar.
	 *
	 * The window is widened to 15-minute boundaries so repeated public requests share a
	 * short-lived cache entry. Pass $fresh to bypass it right before a booking is saved.
	 */
	public static function busy( $advisor_id, DateTimeImmutable $start, DateTimeImmutable $end, $fresh = false ) {
		$step = 15 * MINUTE_IN_SECONDS;
		$from = (int) floor( $start->getTimestamp() / $step ) * $step;
		$to = (int) ceil( $end->getTimestamp() / $step ) * $step;
		$cache_key = 'elab_busy_' . md5( $advisor_id . '|' . (int) get_post_meta( $advisor_id, self::META_BUSY_VERSION, true ) . '|' . $from . '|' . $to );
		if ( ! $fresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$data = self::request( $advisor_id, 'POST', '/freeBusy', array(
			'timeMin' => gmdate( 'Y-m-d\TH:i:s\Z', $from ),
			'timeMax' => gmdate( 'Y-m-d\TH:i:s\Z', $to ),
			'items' => array( array( 'id' => 'primary' ) ),
		) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$calendar = self::primary_freebusy( $data );
		if ( ! is_array( $calendar ) || ! empty( $calendar['errors'] ) || ! isset( $calendar['busy'] ) || ! is_array( $calendar['busy'] ) ) {
			return new WP_Error( 'google_calendar_failed', __( 'Google Calendar availability is unavailable.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		$busy = array_values( array_filter( $calendar['busy'], 'is_array' ) );
		set_transient( $cache_key, $busy, 2 * MINUTE_IN_SECONDS );
		return $busy;
	}

	/** Invalidate cached busy periods after this site changes the advisor's calendar. */
	public static function flush_busy_cache( $advisor_id ) {
		update_post_meta( $advisor_id, self::META_BUSY_VERSION, (int) get_post_meta( $advisor_id, self::META_BUSY_VERSION, true ) + 1 );
	}

	public static function create_event( $advisor_id, $booking_id, DateTimeImmutable $start, $duration, $email ) {
		$end = $start->modify( '+' . absint( $duration ) . ' minutes' );
		$event_id = 'elab' . md5( $booking_id . '|' . wp_salt( 'nonce' ) );
		$event = self::request( $advisor_id, 'POST', '/calendars/primary/events?sendUpdates=all', array(
			'id' => $event_id,
			'summary' => __( 'Private call', 'elaltillo-bookacall' ),
			'visibility' => 'private',
			'start' => array( 'dateTime' => $start->format( DATE_RFC3339 ) ),
			'end' => array( 'dateTime' => $end->format( DATE_RFC3339 ) ),
			'attendees' => array( array( 'email' => $email ) ),
		) );
		// A timed-out insert can still have created the event. Look up its stable ID before failing.
		if ( is_wp_error( $event ) || empty( $event['id'] ) ) {
			$existing = self::request( $advisor_id, 'GET', '/calendars/primary/events/' . rawurlencode( $event_id ) );
			if ( ! is_wp_error( $existing ) && isset( $existing['id'] ) && $event_id === $existing['id'] ) {
				$event = $existing;
			} elseif ( ! is_wp_error( $event ) ) {
				$event = new WP_Error( 'google_calendar_failed', __( 'Google Calendar did not confirm the new event. Please try again.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
			}
		}
		if ( ! is_wp_error( $event ) ) {
			update_post_meta( $booking_id, self::META_EVENT, $event['id'] );
			self::flush_busy_cache( $advisor_id );
		}
		return $event;
	}

	public static function delete_event( $advisor_id, $event_id ) {
		$result = self::request( $advisor_id, 'DELETE', '/calendars/primary/events/' . rawurlencode( $event_id ) . '?sendUpdates=all' );
		if ( ! is_wp_error( $result ) ) {
			self::flush_busy_cache( $advisor_id );
		}
		return $result;
	}
}
