<?php
/** Small abuse limits for the public booking API. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_Guard {
	/** Invitations one guest address can receive per day, across all bookings. */
	const GUEST_DAILY = 3;

	public static function submission( WP_REST_Request $request ) {
		if ( $request->get_param( 'website' ) ) {
			return new WP_Error( 'invalid_booking', __( 'Your request could not be submitted.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		$limited = self::limit( 'booking_ip', self::visitor_ip(), 12, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( $email ) {
			$limited = self::limit( 'booking_email', strtolower( $email ), 5, HOUR_IN_SECONDS );
			if ( is_wp_error( $limited ) ) {
				return $limited;
			}
		}
		// Checked after the cheap limits so floods cannot make the site call Cloudflare.
		return ELAB_Security::verify_turnstile( $request->get_param( 'cf-turnstile-response' ) );
	}

	/** Attempts to cancel through private links, per visitor address. */
	public static function cancellation() {
		return self::limit( 'cancel_ip', self::visitor_ip(), 20, HOUR_IN_SECONDS );
	}

	public static function slots() {
		return self::limit( 'slots_ip', self::visitor_ip(), 120, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Refuse a booking that would invite an address already invited too often today, so the
	 * guests field cannot be used to send invitations in bulk.
	 *
	 * @return true|WP_Error
	 */
	public static function guests_allowed( array $guests ) {
		foreach ( $guests as $guest ) {
			if ( self::count( 'guest_email', strtolower( $guest ) ) >= self::GUEST_DAILY ) {
				return new WP_Error( 'guest_limited', sprintf( __( '%s has received too many call invitations today. Remove this guest or try again tomorrow.', 'elaltillo-bookacall' ), $guest ), array( 'status' => 429 ) );
			}
		}
		return true;
	}

	/** Count the invitations once the booking is saved. */
	public static function record_guests( array $guests ) {
		foreach ( $guests as $guest ) {
			self::limit( 'guest_email', strtolower( $guest ), PHP_INT_MAX, DAY_IN_SECONDS );
		}
	}

	private static function key( $kind, $identity ) {
		return 'elab_limit_' . hash( 'sha256', $kind . '|' . wp_salt( 'nonce' ) . '|' . $identity );
	}

	/** Current count in the open window, without adding to it. */
	private static function count( $kind, $identity ) {
		$state = get_transient( self::key( $kind, $identity ) );
		return is_array( $state ) && ! empty( $state['expires'] ) && $state['expires'] > time() ? (int) $state['count'] : 0;
	}

	private static function visitor_ip() {
		return ELAB_Security::visitor_ip();
	}

	private static function limit( $kind, $identity, $maximum, $window ) {
		$key = self::key( $kind, $identity );
		$state = get_transient( $key );
		$now = time();
		if ( ! is_array( $state ) || empty( $state['expires'] ) || $state['expires'] <= $now ) {
			$state = array( 'count' => 0, 'expires' => $now + $window );
		}
		if ( $state['count'] >= $maximum ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please wait and try again.', 'elaltillo-bookacall' ), array( 'status' => 429 ) );
		}
		$state['count']++;
		set_transient( $key, $state, max( 1, $state['expires'] - $now ) );
		return true;
	}
}
