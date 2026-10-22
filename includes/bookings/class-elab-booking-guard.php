<?php
/** Small abuse limits for the public booking API. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_Guard {
	public static function submission( WP_REST_Request $request ) {
		if ( $request->get_param( 'website' ) ) {
			return new WP_Error( 'invalid_booking', __( 'Your request could not be submitted.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		$limited = self::limit( 'booking_ip', self::visitor_ip(), 12, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		return $email ? self::limit( 'booking_email', strtolower( $email ), 5, HOUR_IN_SECONDS ) : true;
	}

	public static function slots() {
		return self::limit( 'slots_ip', self::visitor_ip(), 120, 10 * MINUTE_IN_SECONDS );
	}

	private static function visitor_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}

	private static function limit( $kind, $identity, $maximum, $window ) {
		$key = 'elab_limit_' . hash( 'sha256', $kind . '|' . wp_salt( 'nonce' ) . '|' . $identity );
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
