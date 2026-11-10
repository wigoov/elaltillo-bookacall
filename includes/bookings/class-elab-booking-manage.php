<?php
/**
 * Visitor self-service: a private link per booking to review or cancel the call.
 *
 * The link carries a random token. The token is stored encrypted (so later emails can
 * repeat the same link) and compared in constant time. Pages showing it are never cached,
 * indexed or sent as a referrer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_Manage {
	const ACTION = 'elab_visitor_cancel';
	const META_TOKEN = '_elab_manage_token';
	const META_PAGE = '_elab_page';
	const COOKIE = 'elab_manage_';
	const COOKIE_DAYS = 60;

	public function __construct() {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_cancel' ) );
		add_action( 'template_redirect', array( $this, 'exchange_token' ), 0 );
		add_action( 'template_redirect', array( $this, 'private_page_headers' ) );
	}

	/**
	 * Keep the token out of every URL that analytics, logs or referrers could record:
	 * it lives in an HttpOnly cookie scoped to this booking.
	 */
	public static function remember( $booking_id, $token ) {
		if ( headers_sent() || ! $token ) {
			return;
		}
		setcookie( self::COOKIE . (int) $booking_id, $token, array(
			'expires'  => time() + self::COOKIE_DAYS * DAY_IN_SECONDS,
			'path'     => COOKIEPATH ?: '/',
			'domain'   => COOKIE_DOMAIN ?: '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
		$_COOKIE[ self::COOKIE . (int) $booking_id ] = $token;
	}

	/** Link without the secret, for pages and screens; the cookie authenticates it. */
	public static function public_url( $booking_id, $args = array() ) {
		return add_query_arg( array_merge( array( 'elab_booking' => (int) $booking_id ), $args ), self::booking_page_url( $booking_id ) );
	}

	/** Emailed links arrive with ?elab_token=…: store it in the cookie and drop it from the URL. */
	public function exchange_token() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The private token authenticates this request.
		if ( empty( $_GET['elab_booking'] ) || ! isset( $_GET['elab_token'] ) ) {
			return;
		}
		$id = absint( $_GET['elab_booking'] );
		$token = sanitize_text_field( wp_unslash( $_GET['elab_token'] ) );
		// phpcs:enable
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		if ( self::verify( $id, $token ) ) {
			self::remember( $id, $token );
		}
		wp_safe_redirect( self::public_url( $id ) );
		exit;
	}

	/** Create (or rotate) the booking's private token and return it in plain text. */
	public static function issue_token( $booking_id ) {
		$token = wp_generate_password( 40, false, false );
		$encrypted = ELAB_Google_Calendar::encrypt( $token );
		if ( ! $encrypted ) {
			return '';
		}
		update_post_meta( $booking_id, self::META_TOKEN, $encrypted );
		return $token;
	}

	private static function token( $booking_id ) {
		$stored = (string) get_post_meta( $booking_id, self::META_TOKEN, true );
		return $stored ? (string) ELAB_Google_Calendar::decrypt( $stored ) : '';
	}

	public static function verify( $booking_id, $token ) {
		$expected = self::token( $booking_id );
		return '' !== $expected && is_string( $token ) && hash_equals( $expected, $token ) && ELAB_Post_Types::BOOKING === get_post_type( $booking_id );
	}

	/** The page that hosts the calendar for this booking, falling back to any published one. */
	public static function booking_page_url( $booking_id = 0 ) {
		$page = (int) get_post_meta( $booking_id, self::META_PAGE, true );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			return get_permalink( $page );
		}
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE %s ORDER BY ID ASC LIMIT 1", '%' . $wpdb->esc_like( '[elaltillo_bookacall' ) . '%' ) );
		return $found ? get_permalink( (int) $found ) : home_url( '/' );
	}

	/** Private link to the booking, or '' when no token can be produced. */
	public static function url( $booking_id ) {
		$token = self::token( $booking_id ) ?: self::issue_token( $booking_id );
		return $token ? add_query_arg( array( 'elab_booking' => (int) $booking_id, 'elab_token' => rawurlencode( $token ) ), self::booking_page_url( $booking_id ) ) : '';
	}

	public static function can_cancel( $booking_id ) {
		$status = (string) get_post_meta( $booking_id, '_elab_status', true );
		$start = strtotime( get_post_meta( $booking_id, '_elab_start_utc', true ) . ' UTC' );
		return in_array( $status, array( 'pending', 'confirmed' ), true ) && $start && $start > time();
	}

	/**
	 * Cancel a booking: remove its Google event (Google notifies the visitor), mark it
	 * cancelled and email the advisor, or email the visitor when there was no event.
	 *
	 * @return true|WP_Error
	 */
	public static function cancel( $booking_id, $by = 'visitor' ) {
		if ( ! self::can_cancel( $booking_id ) ) {
			return new WP_Error( 'cannot_cancel', __( 'This call can no longer be cancelled online.', 'elaltillo-bookacall' ), array( 'status' => 409 ) );
		}
		$advisor_id = (int) get_post_meta( $booking_id, '_elab_advisor', true );
		$event_id = (string) get_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT, true );
		if ( $event_id ) {
			$deleted = ELAB_Google_Calendar::delete_event( $advisor_id, $event_id );
			if ( is_wp_error( $deleted ) ) {
				return new WP_Error( 'cancel_failed', __( 'We could not cancel the calendar event right now. Please try again in a few minutes or reply to your invitation.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
			}
			delete_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT );
		}
		update_post_meta( $booking_id, '_elab_status', 'cancelled' );
		update_post_meta( $booking_id, '_elab_cancelled_by', sanitize_key( $by ) );
		update_post_meta( $booking_id, '_elab_cancelled_at', gmdate( 'Y-m-d H:i:s' ) );
		ELAB_Notifications::booking_notice( $booking_id, 'cancelled' );
		if ( ! $event_id ) {
			ELAB_Notifications::visitor_notice( $booking_id, 'cancelled' );
		}
		return true;
	}

	private static function cookie_token( $booking_id ) {
		$name = self::COOKIE . (int) $booking_id;
		return isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
	}

	public static function requested_booking() {
		$id = isset( $_GET['elab_booking'] ) ? absint( $_GET['elab_booking'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Cookie-authenticated read.
		return $id ? array( $id, self::cookie_token( $id ) ) : null;
	}

	/** Never cache, index or leak the private link through the Referer header. */
	public function private_page_headers() {
		if ( ! self::requested_booking() ) {
			return;
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'elab private booking link' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache API.
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
	}

	public function handle_cancel() {
		$id = isset( $_POST['booking'] ) ? absint( $_POST['booking'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The private token authenticates this request.
		$token = self::cookie_token( $id );
		$limited = ELAB_Booking_Guard::cancellation();
		if ( is_wp_error( $limited ) ) {
			wp_die( esc_html( $limited->get_error_message() ), '', array( 'response' => 429 ) );
		}
		if ( ! $id || ! self::verify( $id, $token ) ) {
			wp_die( esc_html__( 'This booking link is not valid.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		$result = self::cancel( $id, 'visitor' );
		$back = self::public_url( $id, array( 'elab_result' => is_wp_error( $result ) ? $result->get_error_code() : 'cancelled' ) );
		wp_safe_redirect( $back );
		exit;
	}
}
