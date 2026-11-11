<?php
/** Creating a booking through the public API, with and without Google. */

use ELAB_Test as T;

return array(
	'Without Google the booking is a private, pending request' => function () {
		$advisor = T::advisor();
		$response = T::book( $advisor, T::slot_values( $advisor )[0] );
		$booking = T::booking_from( $response );
		T::same( 201, $response->get_status(), 'Created' );
		T::same( 'pending', $response->get_data()['status'], 'Response says pending' );
		T::same( 'private', get_post_status( $booking ), 'Record is private' );
		T::same( 'pending', get_post_meta( $booking, '_elab_status', true ), 'Stored as pending' );
		T::check( empty( $response->get_data()['calendar'] ), 'No calendar data without Google' );
	},

	'The booking stores everything it needs, including consent, guests and language' => function () {
		T::turnstile_on();
		$advisor = T::advisor();
		$booking = T::booking_from( T::book( $advisor, T::slot_values( $advisor )[0], array( 'guests' => 'g1@example.test, g2@example.test', 'locale' => 'en_US', 'language' => 'en' ) ) );
		T::same( 30, (int) get_post_meta( $booking, '_elab_duration', true ), 'Call length' );
		T::same( 'en', get_post_meta( $booking, '_elab_language', true ), 'Meeting language' );
		T::same( 'en_US', get_post_meta( $booking, '_elab_locale', true ), 'Visitor language' );
		T::same( array( 'g1@example.test', 'g2@example.test' ), ELAB_Booking_Sync::guests( $booking ), 'Guests' );
		T::check( (bool) get_post_meta( $booking, '_elab_consent_at', true ), 'Consent time' );
		T::same( 'America/Bogota', get_post_meta( $booking, '_elab_visitor_timezone', true ), 'Visitor time zone' );
	},

	'The same time cannot be booked twice' => function () {
		$advisor = T::advisor();
		$time = T::slot_values( $advisor )[0];
		T::same( 201, T::book( $advisor, $time )->get_status(), 'First booking' );
		T::same( 409, T::book( $advisor, $time )->get_status(), 'Second booking refused' );
	},

	'A time that is not offered cannot be booked' => function () {
		$advisor = T::advisor();
		$night = ( new DateTimeImmutable( 'next monday 03:00', new DateTimeZone( 'Europe/Lisbon' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
		T::same( 409, T::book( $advisor, $night )->get_status(), '03:00 refused' );
	},

	'First available assigns each advisor once, then the time closes' => function () {
		$one = T::advisor( array(), 'Advisor one' );
		$two = T::advisor( array(), 'Advisor two' );
		$time = T::slot_values( $one )[0];
		$first = T::book( 0, $time );
		$second = T::book( 0, $time );
		$names = array( $first->get_data()['booking']['advisor'] ?? '', $second->get_data()['booking']['advisor'] ?? '' );
		sort( $names );
		T::same( array( 'Advisor one', 'Advisor two' ), $names, 'Both advisors assigned' );
		T::same( 409, T::book( 0, $time )->get_status(), 'Third request refused' );
	},

	'The least busy advisor is tried first' => function () {
		$busy = T::advisor( array(), 'Busy' );
		$free = T::advisor( array(), 'Free' );
		T::booking( $busy, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( '+20 days' ) ) ) );
		$time = T::slot_values( $busy )[0];
		T::same( array( $free, $busy ), ELAB_Booking_Service::candidates( 0, strtotime( $time ) ), 'Free advisor first' );
	},

	'Asking for an advisor who is not bookable is refused' => function () {
		$advisor = T::advisor();
		$time = T::slot_values( $advisor )[0];
		wp_update_post( array( 'ID' => $advisor, 'post_status' => 'draft' ) );
		T::same( 400, T::book( $advisor, $time )->get_status(), 'Draft advisor' );
	},

	'The confirmation hands back a manage link without the secret' => function () {
		$advisor = T::advisor();
		$data = T::book( $advisor, T::slot_values( $advisor )[0] )->get_data();
		T::check( ! empty( $data['manage'] ), 'Manage link returned' );
		T::lacks( 'elab_token', $data['manage'], 'No token in it' );
	},

	'The booking page is remembered only when it really holds the calendar' => function () {
		$advisor = T::advisor();
		$page = T::track( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Test page', 'post_content' => '[elaltillo_bookacall]' ) ) );
		$other = T::track( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Other page', 'post_content' => 'Nothing here' ) ) );
		$values = T::slot_values( $advisor );
		$with = T::booking_from( T::book( $advisor, $values[0], array( 'page' => $page ) ) );
		$without = T::booking_from( T::book( $advisor, $values[1], array( 'page' => $other ) ) );
		T::same( $page, (int) get_post_meta( $with, ELAB_Booking_Manage::META_PAGE, true ), 'Calendar page stored' );
		T::same( '', get_post_meta( $without, ELAB_Booking_Manage::META_PAGE, true ), 'Unrelated page ignored' );
	},

	'With Google the booking is confirmed and the visitor and guests are invited' => function () {
		T::turnstile_on();
		$advisor = T::connect( T::advisor() );
		$response = T::book( $advisor, T::slot_values( $advisor )[0], array( 'guests' => 'guest@example.test' ) );
		$booking = T::booking_from( $response );
		T::same( 'confirmed', $response->get_data()['status'], 'Confirmed' );
		T::check( ! empty( $response->get_data()['invited'] ), 'Response names the invited address' );
		T::same( 2, count( T::$event_body['attendees'] ), 'Visitor and guest invited' );
		T::check( (bool) get_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, true ), 'Event ID stored' );
	},

	'One guest address can be invited at most three times a day' => function () {
		T::turnstile_on();
		$advisor = T::advisor();
		$times = T::slot_values( $advisor );
		$target = 'target+' . wp_generate_password( 8, false, false ) . '@example.test';
		for ( $i = 0; $i < 3; $i++ ) {
			T::same( 201, T::book( $advisor, $times[ $i ], array( 'guests' => 1 === $i ? strtoupper( $target ) : $target ) )->get_status(), 'Invitation ' . ( $i + 1 ) );
		}
		$fourth = T::book( $advisor, $times[3], array( 'guests' => $target ) );
		T::same( 429, $fourth->get_status(), 'Fourth invitation to the same address (any capitalisation) refused' );
		T::contains( $target, $fourth->get_data()['message'] ?? '', 'The message names the guest' );
		T::same( 201, T::book( $advisor, $times[3], array( 'guests' => 'other+' . wp_generate_password( 8, false, false ) . '@example.test' ) )->get_status(), 'Other guests are not affected' );
	},

	'A booking that fails does not use up a guest’s invitations' => function () {
		T::turnstile_on();
		$advisor = T::advisor();
		$time = T::slot_values( $advisor )[0];
		T::book( $advisor, $time );
		$spare = 'spare+' . wp_generate_password( 8, false, false ) . '@example.test';
		for ( $i = 0; $i < 3; $i++ ) {
			T::same( 409, T::book( $advisor, $time, array( 'guests' => $spare ) )->get_status(), 'Taken time' );
		}
		T::same( true, ELAB_Booking_Guard::guests_allowed( array( $spare ) ), 'No invitation counted' );
	},
);
