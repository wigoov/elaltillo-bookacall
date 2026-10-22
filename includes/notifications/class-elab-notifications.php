<?php
/** Plain-text advisor email for schedule changes and booking updates. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Notifications {
	const META_BOOKING_MAIL = '_elab_advisor_mail_status';
	const META_VISITOR_MAIL = '_elab_visitor_mail_status';
	const META_SCHEDULE_MAIL = '_elab_schedule_mail_status';
	private static $schedule_queue = array();
	private static $schedule_hooked = false;

	public static function queue_schedule_summary( $advisor_id ) {
		self::$schedule_queue[ (int) $advisor_id ] = true;
		if ( ! self::$schedule_hooked ) {
			add_action( 'shutdown', array( __CLASS__, 'send_queued_summaries' ) );
			self::$schedule_hooked = true;
		}
	}

	public static function send_queued_summaries() {
		foreach ( array_keys( self::$schedule_queue ) as $advisor_id ) {
			self::schedule_summary( $advisor_id );
		}
		self::$schedule_queue = array();
	}

	public static function advisor_email( $advisor_id ) {
		$email = sanitize_email( (string) get_post_meta( $advisor_id, '_elab_email', true ) );
		return is_email( $email ) ? $email : '';
	}

	public static function schedule_summary( $advisor_id ) {
		$email = self::advisor_email( $advisor_id );
		if ( ! $email || ! ELAB_Google_Calendar::connected( $advisor_id ) ) {
			return false;
		}
		$timezone = (string) get_post_meta( $advisor_id, '_elab_timezone', true ) ?: 'UTC';
		$duration = (int) get_post_meta( $advisor_id, '_elab_duration', true ) ?: 30;
		$availability = json_decode( (string) get_post_meta( $advisor_id, '_elab_availability', true ), true );
		$availability = is_array( $availability ) ? $availability : array();
		$days = array(
			1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
			5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
		);
		$lines = array(
			sprintf( __( 'Your call availability for %s is:', 'elaltillo-bookacall' ), get_the_title( $advisor_id ) ),
			'',
			sprintf( __( 'Time zone: %s', 'elaltillo-bookacall' ), $timezone ),
			sprintf( __( 'Meeting length: %d minutes', 'elaltillo-bookacall' ), $duration ),
			'',
		);
		foreach ( $days as $number => $day ) {
			$ranges = array();
			foreach ( (array) ( $availability[ $number ] ?? array() ) as $range ) {
				if ( is_array( $range ) && isset( $range[0], $range[1] ) ) {
					$ranges[] = $range[0] . '–' . $range[1];
				}
			}
			$lines[] = $day . ': ' . ( $ranges ? implode( ', ', $ranges ) : __( 'Unavailable', 'elaltillo-bookacall' ) );
		}
		$overrides = get_post_meta( $advisor_id, ELAB_Blackouts::META_GLOBAL_OVERRIDES, true );
		$overrides = is_array( $overrides ) ? $overrides : array();
		$lines[] = '';
		$lines[] = __( 'Global closure exceptions:', 'elaltillo-bookacall' );
		$has_override = false;
		foreach ( ELAB_Blackouts::global_rules() as $rule ) {
			if ( in_array( ELAB_Blackouts::rule_key( $rule ), $overrides, true ) ) {
				$lines[] = '- ' . $rule['start'] . ( $rule['end'] !== $rule['start'] ? ' to ' . $rule['end'] : '' ) . ( ! empty( $rule['annual'] ) ? ' (every year)' : '' );
				$has_override = true;
			}
		}
		if ( ! $has_override ) {
			$lines[] = __( 'None', 'elaltillo-bookacall' );
		}
		$personal = get_post_meta( $advisor_id, ELAB_Blackouts::META, true );
		$lines[] = '';
		$lines[] = __( 'Personal unavailable dates:', 'elaltillo-bookacall' );
		if ( is_array( $personal ) && $personal ) {
			foreach ( $personal as $rule ) {
				$lines[] = '- ' . $rule['start'] . ( $rule['end'] !== $rule['start'] ? ' to ' . $rule['end'] : '' ) . ( ! empty( $rule['annual'] ) ? ' (every year)' : '' );
			}
		} else {
			$lines[] = __( 'None', 'elaltillo-bookacall' );
		}
		$lines[] = '';
		$lines[] = __( 'These hours represent available booking times. They are not calendar events. Confirmed calls are added to your connected Google Calendar automatically.', 'elaltillo-bookacall' );
		$sent = wp_mail( $email, __( 'Your call availability has been updated', 'elaltillo-bookacall' ), implode( "\n", $lines ) );
		update_post_meta( $advisor_id, self::META_SCHEDULE_MAIL, $sent ? 'sent' : 'failed' );
		return $sent;
	}

	public static function booking_notice( $booking_id, $status ) {
		$advisor_id = (int) get_post_meta( $booking_id, '_elab_advisor', true );
		$email = self::advisor_email( $advisor_id );
		if ( ! $email ) {
			update_post_meta( $booking_id, self::META_BOOKING_MAIL, 'failed' );
			return false;
		}
		$timezone_name = (string) get_post_meta( $advisor_id, '_elab_timezone', true ) ?: 'UTC';
		try {
			$zone = new DateTimeZone( $timezone_name );
		} catch ( Exception $exception ) {
			$zone = new DateTimeZone( 'UTC' );
		}
		$start = (string) get_post_meta( $booking_id, '_elab_start_utc', true );
		$local_time = ( new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'l, F j, Y g:i A T' );
		$name = (string) get_post_meta( $booking_id, '_elab_name', true );
		$subject = 'confirmed' === $status ? __( 'New confirmed call', 'elaltillo-bookacall' ) : ( 'cancelled' === $status ? __( 'Call cancelled', 'elaltillo-bookacall' ) : __( 'New call request', 'elaltillo-bookacall' ) );
		$lines = array(
			sprintf( __( 'Advisor: %s', 'elaltillo-bookacall' ), get_the_title( $advisor_id ) ),
			sprintf( __( 'Status: %s', 'elaltillo-bookacall' ), ucfirst( $status ) ),
			sprintf( __( 'When: %s', 'elaltillo-bookacall' ), $local_time ),
			sprintf( __( 'Visitor: %s', 'elaltillo-bookacall' ), $name ),
			sprintf( __( 'Visitor email: %s', 'elaltillo-bookacall' ), get_post_meta( $booking_id, '_elab_contact_email', true ) ),
			__( 'The sector, message and attachments are available to site administrators in the private booking record.', 'elaltillo-bookacall' ),
		);
		if ( 'confirmed' === $status && get_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT, true ) ) {
			$lines[] = '';
			$lines[] = __( 'This booking is already in your connected Google Calendar.', 'elaltillo-bookacall' );
		}
		$sent = wp_mail( $email, $subject, implode( "\n", $lines ) );
		update_post_meta( $booking_id, self::META_BOOKING_MAIL, $sent ? 'sent' : 'failed' );
		return $sent;
	}

	/** Manual requests need their own visitor confirmation; Google sends event updates itself. */
	public static function visitor_notice( $booking_id, $status ) {
		$email = sanitize_email( (string) get_post_meta( $booking_id, '_elab_contact_email', true ) );
		if ( ! is_email( $email ) ) {
			update_post_meta( $booking_id, self::META_VISITOR_MAIL, 'failed' );
			return false;
		}
		$advisor_id = (int) get_post_meta( $booking_id, '_elab_advisor', true );
		$timezone = (string) get_post_meta( $booking_id, '_elab_visitor_timezone', true ) ?: 'UTC';
		try {
			$zone = new DateTimeZone( $timezone );
		} catch ( Exception $exception ) {
			$zone = new DateTimeZone( 'UTC' );
		}
		$start = new DateTimeImmutable( (string) get_post_meta( $booking_id, '_elab_start_utc', true ), new DateTimeZone( 'UTC' ) );
		$when = $start->setTimezone( $zone )->format( 'l, F j, Y g:i A T' );
		$advisor = get_the_title( $advisor_id );
		$subject = 'confirmed' === $status ? __( 'Your call is confirmed', 'elaltillo-bookacall' ) : __( 'Your call was cancelled', 'elaltillo-bookacall' );
		$lines = array(
			sprintf( __( 'Advisor: %s', 'elaltillo-bookacall' ), $advisor ),
			sprintf( __( 'When: %s', 'elaltillo-bookacall' ), $when ),
		);
		if ( 'confirmed' === $status ) {
			$end = $start->modify( '+' . max( 15, (int) get_post_meta( $advisor_id, '_elab_duration', true ) ) . ' minutes' );
			$lines[] = '';
			$lines[] = __( 'Add this call to Google Calendar:', 'elaltillo-bookacall' );
			$lines[] = add_query_arg( array(
				'action' => 'TEMPLATE',
				'text' => sprintf( __( 'Call with %s', 'elaltillo-bookacall' ), $advisor ),
				'dates' => $start->format( 'Ymd\\THis\\Z' ) . '/' . $end->format( 'Ymd\\THis\\Z' ),
			), 'https://calendar.google.com/calendar/render' );
		}
		$sent = wp_mail( $email, $subject, implode( "\n", $lines ) );
		update_post_meta( $booking_id, self::META_VISITOR_MAIL, $sent ? 'sent' : 'failed' );
		return $sent;
	}
}
