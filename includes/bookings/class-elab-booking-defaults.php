<?php
/**
 * Site-wide booking defaults. Each advisor uses these unless their profile sets its own value.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_Defaults {
	const OPTION = 'elab_booking_defaults';

	/**
	 * Rule => [built-in default, minimum, maximum, step, advisor meta key].
	 * The built-in break between calls is 0: no gap unless an administrator asks for one.
	 */
	public static function fields() {
		return array(
			'duration' => array( 30, 15, 180, 5, '_elab_duration' ),
			'notice'   => array( 12, 0, 720, 1, '_elab_notice_hours' ),
			'horizon'  => array( 60, 1, 365, 1, '_elab_horizon_days' ),
			'buffer'   => array( 0, 0, 120, 5, '_elab_buffer_minutes' ),
			// 0 = a new start time every call length; otherwise every N minutes.
			'interval' => array( 0, 0, 120, 5, '_elab_slot_interval' ),
		);
	}

	/** Effective site defaults, always complete and within limits. */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$values = array();
		foreach ( self::fields() as $rule => $field ) {
			$values[ $rule ] = isset( $saved[ $rule ] ) && is_numeric( $saved[ $rule ] ) ? self::clamp( $rule, $saved[ $rule ] ) : $field[0];
		}
		return $values;
	}

	public static function clamp( $rule, $value ) {
		$field = self::fields()[ $rule ];
		$value = min( $field[2], max( $field[1], (int) $value ) );
		// A start every few minutes would flood the calendar; non-zero intervals start at 5.
		return 'interval' === $rule && $value > 0 && $value < 5 ? 5 : $value;
	}

	/** True when the advisor has no own value for this rule and follows the site default. */
	public static function inherits( $advisor_id, $rule ) {
		$raw = get_post_meta( $advisor_id, self::fields()[ $rule ][4], true );
		return '' === $raw || ! is_numeric( $raw );
	}
}
