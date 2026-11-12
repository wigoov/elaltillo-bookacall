<?php
/** When Google fails: the booking is kept, the advisor is told, and the event follows later. */

use ELAB_Test as T;

return array(
	'If Google is down the booking is saved, confirmed and queued' => function () {
		$advisor = T::connect( T::advisor() );
		$time = T::slot_values( $advisor )[0];
		T::$google_down = true;
		$response = T::book( $advisor, $time );
		$booking = T::booking_from( $response );
		T::same( 201, $response->get_status(), 'Booking accepted' );
		T::same( 'confirmed', get_post_meta( $booking, '_elab_status', true ), 'Confirmed in WordPress' );
		T::same( ELAB_Booking_Sync::CREATE, ELAB_Booking_Sync::state( $booking ), 'Queued for Google' );
		T::same( '', $response->get_data()['invited'], 'Not claiming an invitation was sent' );
		T::contains( 'invitation will follow', $response->get_data()['message'], 'Visitor told the invitation will follow' );
		T::check( ! in_array( $time, T::slot_values( $advisor ), true ), 'The time stays reserved' );
	},

	'Retrying while Google is still down keeps waiting' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		ELAB_Booking_Sync::queue_create( $booking, new WP_Error( 'x' ) );
		T::$google_down = true;
		T::same( 1, ELAB_Booking_Sync::run()['waiting'], 'Still waiting' );
		T::same( ELAB_Booking_Sync::CREATE, ELAB_Booking_Sync::state( $booking ), 'Still queued' );
	},

	'When Google answers again the event is created once and the advisor is told' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		ELAB_Booking_Sync::queue_create( $booking, new WP_Error( 'x' ) );
		T::same( 1, ELAB_Booking_Sync::run()['created'], 'Created' );
		T::same( 0, ELAB_Booking_Sync::run()['created'], 'Nothing on the next run' );
		T::same( 1, count( T::requests( '#^POST .*/events\?#' ) ), 'One insert in total' );
		T::same( '', ELAB_Booking_Sync::state( $booking ), 'Queue cleared' );
		T::same( 1, count( T::mail_with_subject( 'Call added to your calendar' ) ), 'Advisor told' );
	},

	'A disconnected advisor’s calls wait without calling Google' => function () {
		$advisor = T::connect( T::advisor() );
		update_post_meta( $advisor, ELAB_Google_Calendar::META_REVOKED, time() );
		$booking = T::booking( $advisor );
		ELAB_Booking_Sync::queue_create( $booking, new WP_Error( 'x' ) );
		T::same( 1, ELAB_Booking_Sync::run()['waiting'], 'Waiting' );
		T::same( array(), T::$http, 'No request' );
		delete_post_meta( $advisor, ELAB_Google_Calendar::META_REVOKED );
		T::same( 1, ELAB_Booking_Sync::run( $advisor )['created'], 'Created after reconnecting' );
	},

	'Cancelled and past calls leave the queue without an event' => function () {
		$advisor = T::connect( T::advisor() );
		$cancelled = T::booking( $advisor, array( '_elab_status' => 'cancelled' ) );
		$past = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ) ) );
		ELAB_Booking_Sync::queue_create( $cancelled, new WP_Error( 'x' ) );
		ELAB_Booking_Sync::queue_create( $past, new WP_Error( 'x' ) );
		ELAB_Booking_Sync::run();
		T::same( array( '', '' ), array( ELAB_Booking_Sync::state( $cancelled ), ELAB_Booking_Sync::state( $past ) ), 'Both cleared' );
		T::same( array(), T::requests( '#^POST .*/events#' ), 'No event created' );
	},

	'A revoked connection during booking: saved, queued, advisor asked to reconnect' => function () {
		$advisor = T::connect( T::advisor() );
		T::$revoke = true;
		$booking = T::booking_from( T::book( $advisor, T::slot_values( $advisor )[0] ) );
		T::same( ELAB_Booking_Sync::CREATE, ELAB_Booking_Sync::state( $booking ), 'Queued' );
		$action = T::mail_with_subject( 'Action needed: new call waiting for Google Calendar' );
		T::same( 1, count( $action ), 'Action-needed email' );
		T::contains( 'Reconnect Google Calendar', $action[0]['message'], 'Reconnect button' );
		T::lacks( 'Add it to my calendar myself', $action[0]['message'], 'No manual step: reconnecting is the only way' );
	},

	'Never two events: create_for skips when an event exists or another request holds the lock' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		ELAB_Booking_Sync::create_for( $booking );
		T::same( array( 'skipped' => 'exists' ), ELAB_Booking_Sync::create_for( $booking ), 'Existing event' );
		$other = T::booking( $advisor );
		add_option( 'elab_sync_lock_' . $other, time(), '', 'no' );
		T::error( 'google_busy', ELAB_Booking_Sync::create_for( $other ), 'Locked' );
		delete_option( 'elab_sync_lock_' . $other );
		T::same( 1, count( T::requests( '#^POST .*/events\?#' ) ), 'Only the first one reached Google' );
	},

	'A stale lock (crashed request) does not block forever' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		add_option( 'elab_sync_lock_' . $booking, time() - 2 * MINUTE_IN_SECONDS, '', 'no' );
		T::check( ! is_wp_error( ELAB_Booking_Sync::create_for( $booking ) ), 'Created after the stale lock' );
	},

	'Cancelling while Google is down: cancelled now, removed from Google later' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		ELAB_Booking_Sync::create_for( $booking );
		T::$google_down = true;
		T::same( true, ELAB_Booking_Manage::cancel( $booking ), 'Cancel succeeds' );
		T::same( ELAB_Booking_Sync::DELETE, ELAB_Booking_Sync::state( $booking ), 'Removal queued' );
		T::$google_down = false;
		T::same( 1, ELAB_Booking_Sync::run()['deleted'], 'Removed later' );
	},

	'The hourly retry is scheduled and removed on deactivation' => function () {
		ELAB_Booking_Sync::schedule();
		T::check( (bool) wp_next_scheduled( ELAB_Booking_Sync::CRON ), 'Scheduled' );
		ELAB_Booking_Sync::unschedule();
		T::check( ! wp_next_scheduled( ELAB_Booking_Sync::CRON ), 'Unscheduled' );
		ELAB_Booking_Sync::schedule();
	},

	'Admins see what is waiting: list label, record notice, settings and status rows' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		ELAB_Booking_Sync::queue_create( $booking, new WP_Error( 'x' ) );
		T::same( 'Waiting for Google', ELAB_Booking_Sync::label( $booking ), 'List label' );
		$rows = array_values( array_filter( ELAB_System_Status::checks(), static function ( $check ) { return 'Calls waiting for Google' === $check[1]; } ) );
		T::same( 'warn', $rows[0][2] ?? '', 'System status warns' );
	},
);
