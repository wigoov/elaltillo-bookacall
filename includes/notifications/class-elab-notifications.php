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
		$rules = ELAB_Availability::rules( $advisor_id );
		$duration = $rules['duration'];
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
			sprintf( __( 'Minimum notice: %d hours', 'elaltillo-bookacall' ), $rules['notice'] ),
			sprintf( __( 'Bookable up to: %d days ahead', 'elaltillo-bookacall' ), $rules['horizon'] ),
			sprintf( __( 'Break between calls: %d minutes', 'elaltillo-bookacall' ), $rules['buffer'] ),
			sprintf( __( 'Start times every: %d minutes', 'elaltillo-bookacall' ), $rules['interval'] ?: $duration ),
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
			sprintf( __( 'Meeting language: %s', 'elaltillo-bookacall' ), ELAB_Booking_Service::language_label( get_post_meta( $booking_id, '_elab_language', true ) ?: ELAB_Booking_Service::DEFAULT_LANGUAGE ) ),
			sprintf( __( 'Visitor: %s', 'elaltillo-bookacall' ), $name ),
			sprintf( __( 'Visitor email: %s', 'elaltillo-bookacall' ), get_post_meta( $booking_id, '_elab_contact_email', true ) ),
			__( 'The sector, message and attachments are available to site administrators in the private booking record.', 'elaltillo-bookacall' ),
		);
		if ( 'cancelled' === $status && 'visitor' === get_post_meta( $booking_id, '_elab_cancelled_by', true ) ) {
			$lines[] = __( 'The visitor cancelled this call from their private booking link.', 'elaltillo-bookacall' );
		}
		$meet = (string) get_post_meta( $booking_id, '_elab_meet_url', true );
		if ( $meet && 'cancelled' !== $status ) {
			$lines[] = sprintf( __( 'Video call: %s', 'elaltillo-bookacall' ), $meet );
		}
		if ( 'confirmed' === $status && get_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT, true ) ) {
			$lines[] = '';
			$lines[] = __( 'This booking is already in your connected Google Calendar.', 'elaltillo-bookacall' );
		}
		$sent = wp_mail( $email, $subject, implode( "\n", $lines ) );
		update_post_meta( $booking_id, self::META_BOOKING_MAIL, $sent ? 'sent' : 'failed' );
		return $sent;
	}

	/**
	 * Google stopped accepting the advisor's access: tell the advisor and the site admin
	 * once, because the advisor no longer appears in the booking calendar.
	 */
	public static function connection_lost( $advisor_id ) {
		$name = get_the_title( $advisor_id );
		$lines = array(
			sprintf( __( 'Google Calendar access for %s was removed or has expired, so this advisor no longer appears in the booking calendar.', 'elaltillo-bookacall' ), $name ),
			'',
			__( 'This happens when access is removed in the Google account, the password changes, or the Google app is still in Testing mode (access then expires after 7 days).', 'elaltillo-bookacall' ),
			'',
			__( 'To fix it, connect Google Calendar again:', 'elaltillo-bookacall' ),
			(int) get_post_meta( $advisor_id, '_elab_wp_user', true ) ? admin_url( 'profile.php' ) : get_edit_post_link( $advisor_id, 'raw' ),
		);
		$subject = sprintf( __( 'Reconnect Google Calendar for %s', 'elaltillo-bookacall' ), $name );
		$recipients = array_unique( array_filter( array( self::advisor_email( $advisor_id ), sanitize_email( (string) get_option( 'admin_email' ) ) ) ) );
		foreach ( $recipients as $recipient ) {
			wp_mail( $recipient, $subject, implode( "\n", $lines ) );
		}
	}

	/** Call time in the visitor's zone, in the site's language. */
	private static function visitor_when( $booking_id ) {
		$timezone = (string) get_post_meta( $booking_id, '_elab_visitor_timezone', true ) ?: 'UTC';
		try {
			$zone = new DateTimeZone( $timezone );
		} catch ( Exception $exception ) {
			$zone = new DateTimeZone( 'UTC' );
		}
		$start = strtotime( (string) get_post_meta( $booking_id, '_elab_start_utc', true ) . ' UTC' );
		return wp_date( 'l j F Y, ' . get_option( 'time_format' ) . ' T', $start, $zone ) . ' (' . str_replace( '_', ' ', $zone->getName() ) . ')';
	}

	private static function visitor_lines( $booking_id ) {
		$advisor_id = (int) get_post_meta( $booking_id, '_elab_advisor', true );
		$lines = array(
			sprintf( __( 'When: %s', 'elaltillo-bookacall' ), self::visitor_when( $booking_id ) ),
			sprintf( __( 'Advisor: %s', 'elaltillo-bookacall' ), get_the_title( $advisor_id ) ),
			sprintf( __( 'Length: %d minutes', 'elaltillo-bookacall' ), ELAB_Booking_Admin::duration( $booking_id ) ),
			sprintf( __( 'Meeting language: %s', 'elaltillo-bookacall' ), ELAB_Booking_Service::language_label( get_post_meta( $booking_id, '_elab_language', true ) ?: ELAB_Booking_Service::DEFAULT_LANGUAGE ) ),
		);
		$meet = (string) get_post_meta( $booking_id, '_elab_meet_url', true );
		if ( $meet ) {
			$lines[] = sprintf( __( 'Video call: %s', 'elaltillo-bookacall' ), $meet );
		}
		return $lines;
	}

	private static function manage_lines( $booking_id ) {
		$url = ELAB_Booking_Manage::url( $booking_id );
		return $url ? array( '', __( 'Need to cancel? Use your private link:', 'elaltillo-bookacall' ), $url ) : array();
	}

	private static function send_visitor( $booking_id, $subject, $lines ) {
		$email = sanitize_email( (string) get_post_meta( $booking_id, '_elab_contact_email', true ) );
		if ( ! is_email( $email ) ) {
			update_post_meta( $booking_id, self::META_VISITOR_MAIL, 'failed' );
			return false;
		}
		$sent = wp_mail( $email, $subject, implode( "\n", $lines ) );
		update_post_meta( $booking_id, self::META_VISITOR_MAIL, $sent ? 'sent' : 'failed' );
		return $sent;
	}

	/** Sent right after booking: what was booked and the private link to cancel. */
	public static function visitor_booked( $booking_id ) {
		$confirmed = 'confirmed' === get_post_meta( $booking_id, '_elab_status', true );
		$lines = array_merge(
			array( $confirmed ? __( 'Your call is booked.', 'elaltillo-bookacall' ) : __( 'We received your call request and will confirm it by email.', 'elaltillo-bookacall' ), '' ),
			self::visitor_lines( $booking_id )
		);
		if ( $confirmed && get_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT, true ) ) {
			$lines[] = '';
			$lines[] = __( 'Google Calendar also sends you an invitation for this call.', 'elaltillo-bookacall' );
		}
		return self::send_visitor( $booking_id, $confirmed ? __( 'Your call is booked', 'elaltillo-bookacall' ) : __( 'We received your call request', 'elaltillo-bookacall' ), array_merge( $lines, self::manage_lines( $booking_id ) ) );
	}

	/** Manual requests need their own visitor confirmation; Google sends event updates itself. */
	public static function visitor_notice( $booking_id, $status ) {
		$lines = self::visitor_lines( $booking_id );
		if ( 'confirmed' === $status ) {
			$start = new DateTimeImmutable( (string) get_post_meta( $booking_id, '_elab_start_utc', true ), new DateTimeZone( 'UTC' ) );
			$end = $start->modify( '+' . ELAB_Booking_Admin::duration( $booking_id ) . ' minutes' );
			$lines[] = '';
			$lines[] = __( 'Add this call to Google Calendar:', 'elaltillo-bookacall' );
			$lines[] = add_query_arg( array(
				'action' => 'TEMPLATE',
				'text' => sprintf( __( 'Call with %s', 'elaltillo-bookacall' ), get_the_title( (int) get_post_meta( $booking_id, '_elab_advisor', true ) ) ),
				'dates' => $start->format( 'Ymd\\THis\\Z' ) . '/' . $end->format( 'Ymd\\THis\\Z' ),
			), 'https://calendar.google.com/calendar/render' );
			$lines = array_merge( $lines, self::manage_lines( $booking_id ) );
		}
		return self::send_visitor( $booking_id, 'confirmed' === $status ? __( 'Your call is confirmed', 'elaltillo-bookacall' ) : __( 'Your call was cancelled', 'elaltillo-bookacall' ), $lines );
	}
}
