<?php
/** The Google event of a booking: where it goes, what it says, who is invited, what may be deleted. */

use ELAB_Test as T;

$event_for = static function ( array $meta = array() ) {
	$advisor = T::connect( T::advisor( array(), 'Marta Advisor' ) );
	$booking = T::booking( $advisor, $meta + array( '_elab_name' => 'Ana Visitor' ) );
	$event = ELAB_Booking_Sync::create_for( $booking );
	T::check( ! is_wp_error( $event ) && ! isset( $event['skipped'] ), 'Event created' );
	return array( $advisor, $booking );
};

return array(
	'The event goes into the site’s own calendar, never the main one' => function () use ( $event_for ) {
		list( , $booking ) = $event_for();
		T::contains( '/calendars/test-cal%40group.calendar.google.com/events?sendUpdates=all', T::$event_path, 'App calendar path' );
		T::same( 'test-cal@group.calendar.google.com', get_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, true ), 'Booking remembers the calendar' );
		T::same( array(), T::requests( '#/calendars/primary|freeBusy|calendarList|/users/me#' ), 'No request to the main calendar, free/busy or calendar list' );
	},

	'The title names the site, visitor and advisor; the event is private' => function () use ( $event_for ) {
		$event_for();
		T::contains( 'Ana Visitor', T::$event_body['summary'], 'Visitor in title' );
		T::contains( 'Marta Advisor', T::$event_body['summary'], 'Advisor in title' );
		T::same( 'private', T::$event_body['visibility'], 'Private event' );
	},

	'The visitor and every guest are invited; guests cannot change or forward the event' => function () use ( $event_for ) {
		$event_for( array( '_elab_contact_email' => 'ana@example.test', '_elab_guests' => array( 'g1@example.test', 'g2@example.test' ) ) );
		T::same( array( 'ana@example.test', 'g1@example.test', 'g2@example.test' ), array_column( T::$event_body['attendees'], 'email' ), 'Attendees' );
		T::same( array( false, false, false ), array( T::$event_body['guestsCanInviteOthers'], T::$event_body['guestsCanSeeOtherGuests'], T::$event_body['guestsCanModify'] ), 'Guest permissions off' );
	},

	'The description has the cancel link but none of the private details' => function () use ( $event_for ) {
		$event_for();
		T::contains( 'elab_token=', T::$event_body['description'], 'Cancel link' );
		T::lacks( 'Private message', T::$event_body['description'], 'No message' );
		T::lacks( 'Private sector', T::$event_body['description'], 'No sector' );
	},

	'A Google Meet link is added unless turned off' => function () use ( $event_for ) {
		list( , $booking ) = $event_for();
		T::contains( 'conferenceDataVersion=1', T::$event_path, 'Meet requested' );
		T::same( 'https://meet.google.com/tst-test-call', get_post_meta( $booking, '_elab_meet_url', true ), 'Meet link stored' );
		update_option( 'elab_google_meet', '0' );
		$event_for();
		T::check( empty( T::$event_body['conferenceData'] ), 'No Meet when off' );
	},

	'A timed-out insert that did reach Google is recovered, not duplicated' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		T::$insert_times_out = true;
		$event = ELAB_Booking_Sync::create_for( $booking );
		T::check( ! is_wp_error( $event ), 'Recovered through a lookup of the same event ID' );
		T::same( 1, count( T::requests( '#^POST .*/events\?#' ) ), 'Only one insert' );
		T::same( ELAB_Google_Calendar::event_id_for( $booking ), get_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, true ), 'Stable plugin event ID' );
	},

	'Only that booking’s own event can ever be deleted' => function () use ( $event_for ) {
		list( $advisor, $booking ) = $event_for();
		$before = count( T::$http );
		T::error( 'google_event_not_ours', ELAB_Google_Calendar::delete_event( $advisor, 'personal-event', $booking ), 'Foreign ID' );
		T::error( 'google_event_not_ours', ELAB_Google_Calendar::delete_event( $advisor, ELAB_Google_Calendar::event_id_for( $booking + 1 ), $booking ), 'Another booking’s ID' );
		update_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, 'personal-event' );
		T::error( 'google_event_not_ours', ELAB_Google_Calendar::delete_event( $advisor, 'personal-event', $booking ), 'Altered stored ID' );
		T::same( $before, count( T::$http ), 'None of these reached Google' );
	},

	'Deleting targets the site’s calendar and notifies the guests' => function () use ( $event_for ) {
		list( , $booking ) = $event_for();
		T::same( 'deleted', ELAB_Booking_Sync::remove_event( $booking ), 'Deleted' );
		T::same( 1, count( T::requests( '#^DELETE https://www\.googleapis\.com/calendar/v3/calendars/test-cal%40group\.calendar\.google\.com/events/elab[0-9a-f]{32}\?sendUpdates=all$#' ) ), 'Exact DELETE request' );
	},

	'An old event in the main calendar is never touched; it is flagged for the advisor' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		update_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, ELAB_Google_Calendar::event_id_for( $booking ) );
		T::same( 'manual', ELAB_Booking_Sync::remove_event( $booking ), 'Manual removal' );
		T::same( array(), T::$http, 'No request' );
		T::check( (bool) get_post_meta( $booking, ELAB_Booking_Sync::META_MANUAL, true ), 'Flag stored' );
	},

	'The event is written in the visitor’s language' => function () {
		if ( ! in_array( 'es_CO', get_available_languages(), true ) ) {
			return;
		}
		$advisor = T::connect( T::advisor( array(), 'Marta' ) );
		$booking = T::booking( $advisor, array( '_elab_locale' => 'es_CO', '_elab_name' => 'Ana' ) );
		ELAB_Booking_Sync::create_for( $booking );
		T::contains( 'Ana y Marta', T::$event_body['summary'], 'Spanish title' );
		T::contains( 'Llamada agendada a través de', T::$event_body['description'], 'Spanish description' );
		T::same( 'en_US', determine_locale(), 'Language restored afterwards' );
	},

	'An event already deleted in Google (410 Gone) counts as removed' => function () use ( $event_for ) {
		list( , $booking ) = $event_for();
		T::$event_delete_code = 410;
		T::same( 'deleted', ELAB_Booking_Sync::remove_event( $booking ), 'Treated as deleted' );
		T::same( '', ELAB_Booking_Sync::state( $booking ), 'Nothing left in the queue' );
	},

	'A removal Google keeps refusing stays queued' => function () use ( $event_for ) {
		list( , $booking ) = $event_for();
		T::$event_delete_code = 500;
		T::same( 'queued', ELAB_Booking_Sync::remove_event( $booking ), 'Queued' );
		T::$event_delete_code = 410;
		T::same( 1, ELAB_Booking_Sync::run()['deleted'], 'Cleared once Google says it is gone' );
	},

	'If the advisor deleted the site’s calendar, it is created again and the event goes there' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		T::$calendar_gone = true;
		$event = ELAB_Booking_Sync::create_for( $booking );
		T::check( ! is_wp_error( $event ), 'Event created after recreating the calendar' );
		T::same( 1, count( T::$calendar_posts ), 'One new calendar' );
		T::same( 'test-cal-1@group.calendar.google.com', get_post_meta( $advisor, ELAB_Google_Calendar::META_CALENDAR, true ), 'Advisor points to the new calendar' );
		T::same( 'test-cal-1@group.calendar.google.com', get_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, true ), 'Booking remembers it' );
	},
);
