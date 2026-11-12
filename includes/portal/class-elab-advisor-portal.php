<?php
/**
 * Advisor portal: an advisor signs in with Google and sees their own bookings (form answers,
 * guests, Meet link, attachments in the safe viewer) without a WordPress account.
 *
 * - Sign-in asks Google only who the person is ("openid email"); the account must be the
 *   verified "Advisor Google email" of a published advisor, the same rule as connecting.
 * - The session is a random 256-bit token in an HttpOnly, Secure, SameSite cookie limited to
 *   admin-post.php; only its hash is stored, for 8 hours. It ends early when the advisor's
 *   email changes or the advisor is unpublished.
 * - Every booking and file is checked against the signed-in advisor: nobody sees another
 *   advisor's calls. Pages are never cached, indexed, framed, and run no scripts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Advisor_Portal {
	const ACTION = 'elab_portal';
	const LOGIN = 'elab_portal_login';
	const LOGOUT = 'elab_portal_logout';
	const VIEW = 'elab_portal_view';
	const COOKIE = 'elab_portal';
	const HOURS = 8;
	const SLUG = 'asesores';
	/** Advisor: the last portal sign-ins (time only), newest first. */
	const META_LOG = '_elab_portal_log';

	/** The token of the session created last in this request (lets tests sign in without a browser). */
	public static $last_token = '';

	public function __construct() {
		foreach ( array( self::ACTION => 'page', self::LOGIN => 'login', self::LOGOUT => 'logout', self::VIEW => 'view' ) as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
			add_action( 'admin_post_nopriv_' . $action, array( $this, $method ) );
		}
		add_action( 'init', array( __CLASS__, 'short_address' ), 1 );
	}

	/** The portal, or one booking in it. */
	public static function url( $booking_id = 0 ) {
		$args = array( 'action' => self::ACTION );
		if ( $booking_id ) {
			$args['booking'] = (int) $booking_id;
		}
		return add_query_arg( $args, admin_url( 'admin-post.php', 'https' ) );
	}

	/** desdeelaltillo.com/asesores leads to the portal. */
	public static function short_address() {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared only.
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $path && rtrim( $path, '/' ) === $home . '/' . self::SLUG ) {
			nocache_headers();
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	/* ---------- Session ---------- */

	private static function key( $token ) {
		return 'elab_portal_' . hash( 'sha256', (string) $token );
	}

	/** Open a session for the advisor; returns the token for the cookie. */
	public static function start_session( $advisor_id, $email ) {
		$token = bin2hex( random_bytes( 32 ) );
		set_transient( self::key( $token ), array( 'advisor' => (int) $advisor_id, 'email' => strtolower( $email ), 'csrf' => bin2hex( random_bytes( 16 ) ), 'time' => time() ), self::HOURS * HOUR_IN_SECONDS );
		$log = (array) get_post_meta( $advisor_id, self::META_LOG, true );
		array_unshift( $log, time() );
		update_post_meta( $advisor_id, self::META_LOG, array_slice( array_filter( $log, 'is_int' ), 0, 20 ) );
		self::$last_token = $token;
		return $token;
	}

	/**
	 * The signed-in advisor, or null. Ends when the advisor's email no longer matches the
	 * account that signed in, or the advisor is no longer published.
	 *
	 * @return array{advisor:int,email:string,csrf:string}|null
	 */
	public static function session() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			return null;
		}
		$session = get_transient( self::key( $token ) );
		if ( ! is_array( $session ) || 'publish' !== get_post_status( (int) $session['advisor'] ) || 0 !== strcasecmp( ELAB_Notifications::advisor_email( (int) $session['advisor'] ), (string) $session['email'] ) ) {
			return null;
		}
		return $session;
	}

	private static function set_cookie( $token, $expires ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::COOKIE, $token, array(
			'expires'  => $expires,
			'path'     => wp_parse_url( admin_url( 'admin-post.php' ), PHP_URL_PATH ),
			'secure'   => true,
			'httponly' => true,
			'samesite' => 'Lax',
		) );
	}

	/** The published advisor whose Google email is this verified address. */
	public static function advisor_for( $email ) {
		foreach ( get_posts( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $advisor_id ) {
			$own = ELAB_Notifications::advisor_email( $advisor_id );
			if ( $own && 0 === strcasecmp( $own, $email ) ) {
				return (int) $advisor_id;
			}
		}
		return 0;
	}

	/* ---------- Handlers ---------- */

	public function login() {
		if ( ! ELAB_Google_Calendar::configured() ) {
			self::output( self::message( __( 'The advisor portal is not available yet.', 'elaltillo-bookacall' ), '' ), 503 );
		}
		$booking = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only where to return to.
		wp_redirect( esc_url_raw( ELAB_Google_Calendar::start_sign_in( array( 'booking' => $booking ) ) ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google sign-in.
		exit;
	}

	/** Back from Google (through the shared OAuth callback). */
	public static function finish_login( array $state, $code ) {
		$identity = ELAB_Google_Calendar::identity_from_code( $code, (string) ( $state['verifier'] ?? '' ) );
		if ( is_wp_error( $identity ) ) {
			self::output( self::message( __( 'Google sign-in did not finish', 'elaltillo-bookacall' ), $identity->get_error_message(), true ), 400 );
		}
		$advisor_id = self::advisor_for( $identity['email'] );
		if ( ! $advisor_id ) {
			self::output( self::message( __( 'This account is not an advisor', 'elaltillo-bookacall' ), sprintf( __( '%s is not the Google account of any advisor. Sign in with the account where you receive your bookings.', 'elaltillo-bookacall' ), $identity['email'] ), true ), 403 );
		}
		$token = self::start_session( $advisor_id, $identity['email'] );
		self::set_cookie( $token, time() + self::HOURS * HOUR_IN_SECONDS );
		wp_safe_redirect( self::url( (int) ( $state['booking'] ?? 0 ) ) );
		exit;
	}

	public function page() {
		$session = self::session();
		if ( ! $session ) {
			$booking = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only where to return to.
			self::output( self::sign_in_page( $booking ), 200 );
		}
		$booking = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view, checked against the session.
		if ( $booking ) {
			$html = self::render_detail( $session, $booking );
			self::output( $html ?: self::render_page( $session, __( 'Booking not found', 'elaltillo-bookacall' ), '<p>' . esc_html__( 'This booking does not exist or belongs to another advisor.', 'elaltillo-bookacall' ) . '</p>' ), $html ? 200 : 404 );
		}
		self::output( self::render_list( $session ), 200 );
	}

	public function logout() {
		$session = self::session();
		$sent = isset( $_POST['elab_csrf'] ) ? sanitize_text_field( wp_unslash( $_POST['elab_csrf'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked against the session's own token.
		if ( $session && hash_equals( $session['csrf'], $sent ) ) {
			delete_transient( self::key( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ?? '' ) ) ) );
			self::set_cookie( '', time() - YEAR_IN_SECONDS );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/** An attachment of one of the signed-in advisor's bookings, in the safe viewer. */
	public function view() {
		$session = self::session();
		$booking = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked against the session.
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = self::viewer_page( $session, $booking, $file );
		if ( is_wp_error( $page ) ) {
			self::output( self::message( __( 'File not available', 'elaltillo-bookacall' ), $page->get_error_message(), true ), (int) ( $page->get_error_data()['status'] ?? 404 ) );
		}
		nocache_headers();
		foreach ( $page['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $page['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by the safe viewer.
		exit;
	}

	/** @return array|WP_Error The safe viewer page, only for a file of the advisor's own booking. */
	public static function viewer_page( $session, $booking_id, $file ) {
		if ( ! $session ) {
			return new WP_Error( 'portal_signed_out', __( 'Sign in again to view this file.', 'elaltillo-bookacall' ), array( 'status' => 403 ) );
		}
		if ( ! self::owns( $session, $booking_id ) ) {
			return new WP_Error( 'portal_not_yours', __( 'This booking does not exist or belongs to another advisor.', 'elaltillo-bookacall' ), array( 'status' => 404 ) );
		}
		return ELAB_Document_Viewer::page( $booking_id, $file );
	}

	public static function owns( $session, $booking_id ) {
		return $session && $booking_id && ELAB_Post_Types::BOOKING === get_post_type( $booking_id ) && (int) get_post_meta( $booking_id, '_elab_advisor', true ) === (int) $session['advisor'];
	}

	/* ---------- Views ---------- */

	/** The advisor's calls: upcoming first (soonest first), then the last 50 past ones. */
	public static function render_list( array $session ) {
		$advisor_id = (int) $session['advisor'];
		return ELAB_Notifications::in_locale( ELAB_Notifications::advisor_locale( $advisor_id ), static function () use ( $session, $advisor_id ) {
			$now = gmdate( 'Y-m-d H:i:s' );
			$query = static function ( $compare, $order, $limit ) use ( $advisor_id, $now ) {
				return get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'any', 'numberposts' => $limit, 'fields' => 'ids', 'orderby' => 'meta_value', 'meta_key' => '_elab_start_utc', 'order' => $order, 'meta_query' => array(
					array( 'key' => '_elab_advisor', 'value' => $advisor_id ),
					array( 'key' => '_elab_start_utc', 'value' => $now, 'compare' => $compare, 'type' => 'DATETIME' ),
				) ) );
			};
			$body = '';
			foreach ( array(
				array( __( 'Upcoming calls', 'elaltillo-bookacall' ), $query( '>=', 'ASC', 200 ), __( 'No upcoming calls.', 'elaltillo-bookacall' ) ),
				array( __( 'Past calls', 'elaltillo-bookacall' ), $query( '<', 'DESC', 50 ), __( 'No past calls yet.', 'elaltillo-bookacall' ) ),
			) as $section ) {
				$body .= '<h2>' . esc_html( $section[0] ) . '</h2>';
				if ( ! $section[1] ) {
					$body .= '<p class="empty">' . esc_html( $section[2] ) . '</p>';
					continue;
				}
				$body .= '<ul class="calls">';
				foreach ( $section[1] as $booking_id ) {
					$body .= '<li><a href="' . esc_url( self::url( $booking_id ) ) . '"><span class="when">' . esc_html( self::when( $booking_id, $advisor_id ) ) . '</span><span class="who">' . esc_html( (string) get_post_meta( $booking_id, '_elab_name', true ) ) . '</span>' . self::badge( $booking_id ) . '</a></li>';
				}
				$body .= '</ul>';
			}
			return self::render_page( $session, sprintf( __( 'Calls of %s', 'elaltillo-bookacall' ), get_the_title( $advisor_id ) ), $body );
		} );
	}

	/** One booking with everything the visitor sent; '' when it is not the advisor's. */
	public static function render_detail( array $session, $booking_id ) {
		if ( ! self::owns( $session, $booking_id ) ) {
			return '';
		}
		$advisor_id = (int) $session['advisor'];
		return ELAB_Notifications::in_locale( ELAB_Notifications::advisor_locale( $advisor_id ), static function () use ( $session, $booking_id, $advisor_id ) {
			$meta = static function ( $key ) use ( $booking_id ) {
				return (string) get_post_meta( $booking_id, $key, true );
			};
			$visitor_zone = $meta( '_elab_visitor_timezone' );
			$meet = $meta( '_elab_meet_url' );
			$rows = array(
				__( 'Status', 'elaltillo-bookacall' )          => self::badge( $booking_id ),
				__( 'When', 'elaltillo-bookacall' )            => esc_html( ELAB_Notifications::when( $booking_id, (string) get_post_meta( $advisor_id, '_elab_timezone', true ) ) ),
				__( 'Visitor’s time', 'elaltillo-bookacall' )  => $visitor_zone ? esc_html( ELAB_Notifications::when( $booking_id, $visitor_zone ) ) : '',
				__( 'Length', 'elaltillo-bookacall' )          => esc_html( sprintf( __( '%d minutes', 'elaltillo-bookacall' ), ELAB_Booking_Admin::duration( $booking_id ) ) ),
				__( 'Language', 'elaltillo-bookacall' )        => esc_html( ELAB_Booking_Service::language_label( $meta( '_elab_language' ) ?: ELAB_Booking_Service::DEFAULT_LANGUAGE ) ),
				__( 'Visitor', 'elaltillo-bookacall' )         => esc_html( $meta( '_elab_name' ) ),
				__( 'Visitor email', 'elaltillo-bookacall' )   => is_email( $meta( '_elab_contact_email' ) ) ? '<a href="mailto:' . esc_attr( $meta( '_elab_contact_email' ) ) . '">' . esc_html( $meta( '_elab_contact_email' ) ) . '</a>' : '',
				__( 'Guests', 'elaltillo-bookacall' )          => esc_html( implode( ', ', ELAB_Booking_Sync::guests( $booking_id ) ) ),
				__( 'Sector', 'elaltillo-bookacall' )          => esc_html( $meta( '_elab_sector' ) ),
				__( 'Message', 'elaltillo-bookacall' )         => '' !== $meta( '_elab_message' ) ? '<div class="message">' . esc_html( $meta( '_elab_message' ) ) . '</div>' : '',
				__( 'Video call', 'elaltillo-bookacall' )      => 0 === strpos( $meet, 'https://meet.google.com/' ) && 'cancelled' !== $meta( '_elab_status' ) ? '<a href="' . esc_url( $meet ) . '" rel="noopener noreferrer" target="_blank">' . esc_html( $meet ) . '</a>' : '',
			);
			$files = get_post_meta( $booking_id, '_elab_files', true );
			$links = array();
			foreach ( is_array( $files ) ? $files : array() as $item ) {
				if ( ! empty( $item['stored'] ) ) {
					$links[] = '<li><a href="' . esc_url( add_query_arg( array( 'action' => self::VIEW, 'booking' => (int) $booking_id, 'file' => $item['stored'] ), admin_url( 'admin-post.php', 'https' ) ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) ( $item['name'] ?? $item['stored'] ) ) . '</a></li>';
				}
			}
			$rows[ __( 'Attachments', 'elaltillo-bookacall' ) ] = $links ? '<ul class="files">' . implode( '', $links ) . '</ul><p class="hint">' . esc_html__( 'Files open in a safe view: nothing is downloaded and nothing in them runs.', 'elaltillo-bookacall' ) . '</p>' : '';
			$body = '<p><a class="back" href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'All calls', 'elaltillo-bookacall' ) . '</a></p><dl class="details">';
			foreach ( $rows as $label => $value ) {
				if ( '' !== $value ) {
					$body .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
				}
			}
			$body .= '</dl>';
			return self::render_page( $session, sprintf( __( 'Call with %s', 'elaltillo-bookacall' ), $meta( '_elab_name' ) ?: __( 'Visitor', 'elaltillo-bookacall' ) ), $body );
		} );
	}

	private static function when( $booking_id, $advisor_id ) {
		return ELAB_Notifications::when( $booking_id, (string) get_post_meta( $advisor_id, '_elab_timezone', true ) );
	}

	private static function badge( $booking_id ) {
		$status = (string) get_post_meta( $booking_id, '_elab_status', true );
		$labels = ELAB_Booking_List_Admin::statuses();
		return '<span class="badge badge--' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
	}

	private static function sign_in_page( $booking_id ) {
		return ELAB_Notifications::in_locale( ELAB_Notifications::advisor_locale( 0 ), static function () use ( $booking_id ) {
			$login = add_query_arg( array_filter( array( 'action' => self::LOGIN, 'booking' => $booking_id ) ), admin_url( 'admin-post.php', 'https' ) );
			$body = '<p>' . esc_html__( 'Sign in with the Google account where you receive your bookings to see them, with everything the visitor sent.', 'elaltillo-bookacall' ) . '</p>'
				. '<p><a class="button" href="' . esc_url( $login ) . '">' . esc_html__( 'Sign in with Google', 'elaltillo-bookacall' ) . '</a></p>'
				. '<p class="hint">' . esc_html__( 'Google only tells the site your name and email address. No website account is needed.', 'elaltillo-bookacall' ) . '</p>';
			return self::render_page( null, __( 'Advisor portal', 'elaltillo-bookacall' ), $body );
		} );
	}

	private static function message( $title, $text, $retry = false ) {
		return ELAB_Notifications::in_locale( ELAB_Notifications::advisor_locale( 0 ), static function () use ( $title, $text, $retry ) {
			$body = '' !== $text ? '<p>' . esc_html( $text ) . '</p>' : '';
			if ( $retry ) {
				$body .= '<p><a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to the portal', 'elaltillo-bookacall' ) . '</a></p>';
			}
			return self::render_page( null, $title, $body );
		} );
	}

	/** The page around a view; $body is already escaped. */
	private static function render_page( $session, $title, $body ) {
		$top = '<p class="brand">' . esc_html( ELAB_Mailer::brand() ) . ' · ' . esc_html__( 'Advisor portal', 'elaltillo-bookacall' ) . '</p>';
		if ( $session ) {
			$top .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php', 'https' ) ) . '" class="account"><span>' . esc_html( $session['email'] ) . '</span><input type="hidden" name="action" value="' . esc_attr( self::LOGOUT ) . '"><input type="hidden" name="elab_csrf" value="' . esc_attr( $session['csrf'] ) . '"><button type="submit">' . esc_html__( 'Sign out', 'elaltillo-bookacall' ) . '</button></form>';
		}
		return '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title><style>'
			. ':root{color-scheme:light;--ink:#282C33;--soft:#6b6f76;--line:#e4ddd5;--paper:#FFFAF7;--bg:#f3eee8;--sun:#FFFD88}'
			. '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif}'
			. 'main{max-width:760px;margin:32px auto;padding:0 16px}.card{background:var(--paper);border:1px solid var(--line);border-radius:16px;padding:28px 32px}'
			. 'header{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between;margin:0 0 16px}.brand{margin:0;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#7b7e84}'
			. '.account{display:flex;gap:10px;align-items:center;font-size:13px;color:var(--soft);margin:0}.account button{font:inherit;color:var(--ink);background:none;border:1px solid var(--line);border-radius:999px;padding:4px 12px;cursor:pointer}'
			. 'h1{margin:0 0 12px;font-size:24px;line-height:1.3}h2{margin:28px 0 8px;font-size:15px;color:var(--soft);font-weight:600}a{color:var(--ink)}'
			. '.button{display:inline-block;background:var(--ink);color:var(--paper);text-decoration:none;padding:12px 24px;border-radius:999px;font-weight:600}'
			. '.calls{list-style:none;margin:0;padding:0;border-top:1px solid var(--line)}.calls a{display:flex;flex-wrap:wrap;gap:4px 14px;align-items:center;padding:12px 4px;border-bottom:1px solid var(--line);text-decoration:none}.calls a:hover{background:#fbf5ef}'
			. '.when{flex:1 1 260px}.who{font-weight:600}.badge{font-size:12px;border-radius:999px;padding:2px 10px;background:#ece6df}.badge--confirmed{background:#dcefdc}.badge--pending{background:var(--sun)}.badge--cancelled{background:#f4dcdc}'
			. '.details{display:grid;grid-template-columns:minmax(120px,180px) 1fr;gap:10px 20px;margin:16px 0 0}.details dt{color:var(--soft);font-size:13px;padding-top:2px}.details dd{margin:0;word-break:break-word}.message{white-space:pre-wrap}'
			. '.files{margin:0;padding-left:18px}.hint,.empty{font-size:13px;color:var(--soft)}.back{font-size:14px}'
			. '@media (max-width:560px){.card{padding:20px}.details{grid-template-columns:1fr;gap:2px}.details dd{margin-bottom:10px}}'
			. '</style></head><body><main><header>' . $top . '</header><div class="card"><h1>' . esc_html( $title ) . '</h1>' . $body . '</div></main></body></html>';
	}

	/** Send a portal page: never cached, indexed, framed or scripted. */
	private static function output( $html, $code ) {
		do_action( 'elab_portal_output', $code, $html ); // Lets tests stop here instead of exiting.
		status_header( $code );
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );
			header( 'X-Frame-Options: DENY' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		exit;
	}
}
