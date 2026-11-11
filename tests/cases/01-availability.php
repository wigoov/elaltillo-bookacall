<?php
/** Availability: the calendar the visitor sees. WordPress only, never Google. */

use ELAB_Test as T;

return array(
	'Slots are shown in the visitor’s time zone and on the visitor’s day' => function () {
		$advisor = T::advisor();
		$response = T::slots( $advisor );
		$slots = $response->get_data();
		T::same( 200, $response->get_status(), 'Slots answer 200' );
		T::check( count( $slots ) >= 3, 'There are open times on a working day' );
		T::same( T::day(), ( new DateTimeImmutable( $slots[0]['value'] ) )->setTimezone( new DateTimeZone( 'America/Bogota' ) )->format( 'Y-m-d' ), 'First slot falls on the requested Bogotá day' );
	},

	'Each slot names the advisor who is free' => function () {
		$advisor = T::advisor();
		$slots = T::slots( $advisor )->get_data();
		T::same( $advisor, $slots[0]['advisors'][0]['id'], 'Slot lists the advisor' );
	},

	'The month view counts the same times as the day view' => function () {
		$advisor = T::advisor();
		$day = T::day();
		$month = T::month( $advisor, substr( $day, 0, 7 ) )->get_data();
		T::same( count( T::slot_values( $advisor ) ), $month['days'][ $day ] ?? -1, 'Month count equals day count' );
	},

	'Daylight saving in Lisbon moves the UTC time of the same local slot' => function () {
		$advisor = T::advisor();
		$change = ( new DateTimeImmutable( gmdate( 'Y' ) . '-04-01', new DateTimeZone( 'Europe/Lisbon' ) ) )->modify( 'last sunday' );
		if ( $change->modify( '-3 days' )->getTimestamp() < time() ) {
			$change = ( new DateTimeImmutable( ( (int) gmdate( 'Y' ) + 1 ) . '-04-01', new DateTimeZone( 'Europe/Lisbon' ) ) )->modify( 'last sunday' );
		}
		$before = T::slot_values( $advisor, $change->modify( '-2 days' )->format( 'Y-m-d' ) );
		$after = T::slot_values( $advisor, $change->modify( '+1 day' )->format( 'Y-m-d' ) );
		T::same( '09', substr( $before[0] ?? '', 11, 2 ), '09:00 Lisbon is 09:00 UTC in winter' );
		T::same( '08', substr( $after[0] ?? '', 11, 2 ), '09:00 Lisbon is 08:00 UTC in summer' );
	},

	'Start times can be offered every 15 minutes' => function () {
		$advisor = T::advisor( array( '_elab_slot_interval' => 15 ) );
		$values = T::slot_values( $advisor );
		T::same( 15 * MINUTE_IN_SECONDS, strtotime( $values[1] ) - strtotime( $values[0] ), 'Consecutive starts are 15 minutes apart' );
		$last = new DateTimeImmutable( end( $values ) );
		T::same( '11:30', $last->setTimezone( new DateTimeZone( 'Europe/Lisbon' ) )->format( 'H:i' ), 'The last 30-minute call still ends by 12:00' );
	},

	'Without an interval, starts follow the call length' => function () {
		$advisor = T::advisor();
		T::same( 0, ELAB_Availability::rules( $advisor )['interval'], 'Interval defaults to 0' );
		T::same( 6, count( T::slot_values( $advisor ) ), 'Six 30-minute calls fit 09:00–12:00' );
		T::same( 5, ELAB_Booking_Defaults::clamp( 'interval', 3 ), 'A non-zero interval is at least 5 minutes' );
	},

	'The booking window hides times beyond the horizon' => function () {
		$advisor = T::advisor( array( ELAB_Availability::META_HORIZON => 1 ) );
		T::same( array(), ELAB_Availability::advisor_starts( $advisor, time() + 3 * DAY_IN_SECONDS, time() + 10 * DAY_IN_SECONDS ), 'Nothing beyond one day' );
	},

	'Minimum notice hides times that start too soon' => function () {
		$advisor = T::advisor( array( ELAB_Availability::META_NOTICE => 720 ) );
		$starts = ELAB_Availability::advisor_starts( $advisor, time(), time() + 40 * DAY_IN_SECONDS );
		T::check( ! $starts || min( $starts ) >= time() + 720 * HOUR_IN_SECONDS, 'No start within 720 hours' );
	},

	'Overlapping weekly ranges are merged' => function () {
		$merged = T::call( new ELAB_Advisor_Admin(), 'sanitize_availability', array( 1 => array( array( '11:00', '14:00' ), array( '09:00', '12:00' ), array( '15:00', '16:00' ) ) ) );
		T::same( array( 1 => array( array( '09:00', '14:00' ), array( '15:00', '16:00' ) ) ), $merged, 'Ranges merged and sorted' );
	},

	'Global closures, advisor exceptions and personal days off' => function () {
		$advisor = T::advisor();
		$day = new DateTimeImmutable( 'next tuesday', new DateTimeZone( 'Europe/Lisbon' ) );
		$rule = array( 'start' => $day->format( 'Y-m-d' ), 'end' => $day->format( 'Y-m-d' ), 'label' => 'Closure', 'annual' => false );
		update_option( ELAB_Blackouts::OPTION, array( $rule ), false );
		T::check( ELAB_Blackouts::is_blocked( $advisor, $day ), 'A global closure blocks the advisor' );
		update_post_meta( $advisor, ELAB_Blackouts::META_GLOBAL_OVERRIDES, array( ELAB_Blackouts::rule_key( $rule ) ) );
		T::check( ! ELAB_Blackouts::is_blocked( $advisor, $day ), 'An advisor exception reopens it' );
		update_post_meta( $advisor, ELAB_Blackouts::META, array( $rule ) );
		T::check( ELAB_Blackouts::is_blocked( $advisor, $day ), 'Personal time off wins over the exception' );
	},

	'A booked time disappears and comes back when cancelled' => function () {
		$advisor = T::advisor();
		$first = T::slot_values( $advisor )[0];
		$booking = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( $first ) ) ) );
		T::check( ! in_array( $first, T::slot_values( $advisor ), true ), 'Booked time is hidden' );
		update_post_meta( $booking, '_elab_status', 'pending' );
		T::check( ! in_array( $first, T::slot_values( $advisor ), true ), 'A pending request also holds the time' );
		update_post_meta( $booking, '_elab_status', 'cancelled' );
		T::check( in_array( $first, T::slot_values( $advisor ), true ), 'Cancelled time is free again' );
	},

	'A shorter call length never overlaps a longer existing booking' => function () {
		$advisor = T::advisor();
		$first = T::slot_values( $advisor )[0];
		T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( $first ) ), '_elab_duration' => 30 ) );
		update_post_meta( $advisor, '_elab_duration', 20 );
		$end = strtotime( $first ) + 30 * MINUTE_IN_SECONDS;
		T::check( ! array_filter( T::slot_values( $advisor ), static function ( $value ) use ( $end ) { return strtotime( $value ) < $end; } ), 'No 20-minute slot starts inside the 30-minute booking' );
	},

	'The break between calls hides the adjacent time' => function () {
		$advisor = T::advisor();
		$values = T::slot_values( $advisor );
		T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( $values[0] ) ) ) );
		T::check( in_array( $values[1], T::slot_values( $advisor ), true ), 'Without a break the next time stays' );
		update_post_meta( $advisor, ELAB_Availability::META_BUFFER, 30 );
		T::check( ! in_array( $values[1], T::slot_values( $advisor ), true ), 'A 30-minute break hides it' );
	},

	'Site defaults apply unless the advisor has a value, and stay within limits' => function () {
		$advisor = T::advisor();
		delete_post_meta( $advisor, ELAB_Availability::META_BUFFER );
		delete_option( ELAB_Booking_Defaults::OPTION );
		T::same( 0, ELAB_Availability::rules( $advisor )['buffer'], 'Break defaults to 0' );
		update_option( ELAB_Booking_Defaults::OPTION, array( 'buffer' => 30 ) );
		T::same( 30, ELAB_Availability::rules( $advisor )['buffer'], 'Advisor without a value follows the site default' );
		update_post_meta( $advisor, ELAB_Availability::META_BUFFER, 0 );
		T::same( 0, ELAB_Availability::rules( $advisor )['buffer'], 'The advisor’s own value wins' );
		update_option( ELAB_Booking_Defaults::OPTION, array( 'buffer' => 999, 'duration' => 5 ) );
		$defaults = ELAB_Booking_Defaults::get();
		T::same( array( 120, 15 ), array( $defaults['buffer'], $defaults['duration'] ), 'Defaults are clamped' );
	},

	'Availability never calls Google, even for a connected advisor' => function () {
		$advisor = T::connect( T::advisor() );
		T::slots( $advisor );
		T::month( $advisor, substr( T::day(), 0, 7 ) );
		T::same( array(), T::$http, 'No outbound request' );
	},

	'Who is bookable: published, with hours and an email; linked to Google once it is set up' => function () {
		$advisor = T::advisor();
		T::check( ELAB_Availability::is_bookable( $advisor ), 'Complete advisor is bookable without Google' );
		T::configure_google();
		T::check( ! ELAB_Availability::is_bookable( $advisor ), 'With Google set up, an advisor who never connected is hidden' );
		T::connect( $advisor, false );
		T::check( ELAB_Availability::is_bookable( $advisor ), 'A linked advisor stays bookable even if the connection needs attention' );
		update_post_meta( $advisor, '_elab_availability', wp_json_encode( array() ) );
		T::check( (bool) ELAB_Advisor_Admin::booking_issues( $advisor ), 'No weekly hours is reported as an issue' );
		wp_update_post( array( 'ID' => $advisor, 'post_status' => 'draft' ) );
		T::check( ! ELAB_Availability::is_bookable( $advisor ), 'Draft advisors are hidden' );
	},

	'Bad input is rejected' => function () {
		$advisor = T::advisor();
		T::check( 200 !== T::slots( $advisor, T::day(), 'Mars/Olympus' )->get_status(), 'Unknown time zone' );
		T::check( 200 !== T::slots( $advisor, '2026-13-45' )->get_status(), 'Impossible date' );
		T::check( 200 !== T::month( $advisor, 'not-a-month' )->get_status(), 'Invalid month' );
		wp_update_post( array( 'ID' => $advisor, 'post_status' => 'draft' ) );
		T::check( 200 !== T::slots( $advisor )->get_status(), 'An advisor who is not bookable' );
	},

	'Booking API responses are never cached (LiteSpeed included)' => function () {
		$told = 0;
		$spy = static function () use ( &$told ) { $told++; };
		add_action( 'litespeed_control_set_nocache', $spy );
		$request = new WP_REST_Request( 'GET', '/elab/v1/slots' );
		$response = apply_filters( 'rest_post_dispatch', new WP_REST_Response( array() ), rest_get_server(), $request );
		remove_action( 'litespeed_control_set_nocache', $spy );
		T::same( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] ?? '', 'Cache-Control no-store' );
		T::same( 'no-cache', $response->get_headers()['X-LiteSpeed-Cache-Control'] ?? '', 'LiteSpeed header' );
		T::check( $told > 0, 'LiteSpeed API told not to cache' );
	},

	'Too many availability requests from one address are limited' => function () {
		$_SERVER['REMOTE_ADDR'] = '127.9.' . random_int( 1, 254 ) . '.' . random_int( 1, 254 );
		for ( $i = 0; $i < 120; $i++ ) {
			ELAB_Booking_Guard::slots();
		}
		T::error( 'rate_limited', ELAB_Booking_Guard::slots(), 'The 121st request in 10 minutes is refused' );
	},
);
