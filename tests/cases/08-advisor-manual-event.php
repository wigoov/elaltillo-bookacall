<?php
/**
 * The advisor adds a waiting call to their own calendar by hand. Whatever the order of events
 * (advisor first, site first, both at once, button pressed twice), there is only one event.
 */

use ELAB_Test as T;

$waiting = static function ( array $meta = array() ) {
	$advisor = T::connect( T::advisor() );
	update_post_meta( $advisor, ELAB_Google_Calendar::META_REVOKED, time() );
	$booking = T::booking( $advisor, $meta );
	ELAB_Booking_Sync::queue_create( $booking, new WP_Error( 'google_revoked' ) );
	return array( $advisor, $booking );
};
$sig = static function ( $url ) {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	return $query['sig'] ?? '';
};

return array(
	'The link is signed for that booking only' => function () use ( $waiting, $sig ) {
		list( , $booking ) = $waiting();
		$url = ELAB_Booking_Sync::advisor_url( $booking );
		T::check( ELAB_Booking_Sync::valid_link( $booking, $sig( $url ) ), 'Valid for its booking' );
		T::check( ! ELAB_Booking_Sync::valid_link( $booking, 'forged' ), 'Forged signature refused' );
		$other = T::booking( (int) get_post_meta( $booking, '_elab_advisor', true ) );
		T::check( ! ELAB_Booking_Sync::valid_link( $other, $sig( $url ) ), 'Not valid for another booking' );
	},

	'Opening the link changes nothing (email scanners are harmless)' => function () use ( $waiting ) {
		list( , $booking ) = $waiting();
		$page = ELAB_Booking_Sync::advisor_page( $booking );
		T::same( 'Add this call to your calendar', $page[0], 'Explains the step' );
		T::check( ! empty( $page[4] ), 'Offers a POST button' );
		T::same( ELAB_Booking_Sync::CREATE, ELAB_Booking_Sync::state( $booking ), 'Still queued' );
		T::same( 0, ELAB_Booking_Sync::by_advisor( $booking ), 'Not claimed' );
	},

	'Confirming hands the call to the advisor and takes it off the queue' => function () use ( $waiting ) {
		list( , $booking ) = $waiting();
		T::same( 'claimed', ELAB_Booking_Sync::claim( $booking ), 'Claimed' );
		T::check( ELAB_Booking_Sync::by_advisor( $booking ) > 0, 'Marked as added by the advisor' );
		T::same( '', ELAB_Booking_Sync::state( $booking ), 'Off the queue' );
		T::same( 'Added by the advisor', ELAB_Booking_Sync::label( $booking ), 'List label' );
	},

	'After the advisor added it, the site never creates it — even once Google is back' => function () use ( $waiting ) {
		list( $advisor, $booking ) = $waiting();
		ELAB_Booking_Sync::claim( $booking );
		delete_post_meta( $advisor, ELAB_Google_Calendar::META_REVOKED );
		ELAB_Booking_Sync::queue_create( $booking, new WP_Error( 'x' ) ); // Even if something queued it again.
		ELAB_Booking_Sync::run();
		T::same( array( 'skipped' => 'advisor' ), ELAB_Booking_Sync::create_for( $booking ), 'create_for refuses' );
		T::same( array(), T::requests( '#^POST .*/events#' ), 'No event created by the site' );
	},

	'If the site created it first, the advisor is told not to add it' => function () use ( $waiting ) {
		list( $advisor, $booking ) = $waiting();
		delete_post_meta( $advisor, ELAB_Google_Calendar::META_REVOKED );
		ELAB_Booking_Sync::run();
		T::same( 'exists', ELAB_Booking_Sync::claim( $booking ), 'Claim refused' );
		T::same( 0, ELAB_Booking_Sync::by_advisor( $booking ), 'Not marked' );
		$page = ELAB_Booking_Sync::advisor_page( $booking );
		T::same( 'Already in your calendar', $page[0], 'Page says it is already there' );
		T::check( empty( $page[3] ) && empty( $page[4] ), 'No button to add it again' );
	},

	'Both at once: the lock lets only one side win' => function () use ( $waiting ) {
		list( , $booking ) = $waiting();
		add_option( 'elab_sync_lock_' . $booking, time(), '', 'no' );
		T::same( 'busy', ELAB_Booking_Sync::claim( $booking ), 'Advisor waits while the site works' );
		delete_option( 'elab_sync_lock_' . $booking );
	},

	'Pressing the button twice keeps the first time and warns before adding again' => function () use ( $waiting ) {
		list( , $booking ) = $waiting();
		ELAB_Booking_Sync::claim( $booking );
		update_post_meta( $booking, ELAB_Booking_Sync::META_BY_ADVISOR, 1000 );
		T::same( 'claimed', ELAB_Booking_Sync::claim( $booking ), 'Second claim is harmless' );
		T::same( 1000, ELAB_Booking_Sync::by_advisor( $booking ), 'First time kept' );
		$page = ELAB_Booking_Sync::advisor_page( $booking );
		T::same( 'You already took care of this call', $page[0], 'Warning page' );
		T::contains( 'Only open Google Calendar again if you did not save', $page[1], 'Explains when to add again' );
	},

	'Cancelled or past calls cannot be claimed' => function () use ( $waiting ) {
		list( , $cancelled ) = $waiting( array( '_elab_status' => 'cancelled' ) );
		T::same( 'closed', ELAB_Booking_Sync::claim( $cancelled ), 'Cancelled' );
		list( , $past ) = $waiting( array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ) ) );
		T::same( 'closed', ELAB_Booking_Sync::claim( $past ), 'Past' );
		T::same( 'Nothing to add', ELAB_Booking_Sync::advisor_page( $past )[0], 'Page says so' );
	},

	'Google Calendar opens filled in, with the visitor and guests invited' => function () use ( $waiting ) {
		list( , $booking ) = $waiting( array( '_elab_contact_email' => 'ana@example.test', '_elab_guests' => array( 'g@example.test' ), '_elab_start_utc' => '2030-01-07 10:00:00' ) );
		$url = ELAB_Booking_Sync::google_template_url( $booking );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		T::same( 'calendar.google.com', wp_parse_url( $url, PHP_URL_HOST ), 'Google Calendar' );
		T::same( 'TEMPLATE', $query['action'], 'New event page' );
		T::same( '20300107T100000Z/20300107T103000Z', $query['dates'], 'Start and end' );
		T::same( 'ana@example.test,g@example.test', $query['add'], 'Visitor and guest invited' );
		T::lacks( 'Private message', $url, 'No private details' );
	},

	'Cancelling a call the advisor added: they are asked to delete it, the visitor is emailed' => function () use ( $waiting ) {
		list( $advisor, $booking ) = $waiting();
		ELAB_Booking_Sync::claim( $booking );
		T::same( true, ELAB_Booking_Manage::cancel( $booking ), 'Cancelled' );
		T::check( (bool) get_post_meta( $booking, ELAB_Booking_Sync::META_MANUAL, true ), 'Flagged for manual removal' );
		$advisor_mail = T::mails_to( get_post_meta( $advisor, '_elab_email', true ) );
		T::contains( 'You added this call to your calendar yourself', end( $advisor_mail )['message'], 'Advisor asked to delete it' );
		T::same( 1, count( T::mail_with_subject( 'Your call was cancelled' ) ), 'Visitor emailed by the site' );
	},

	'The waiting emails carry the button' => function () use ( $waiting ) {
		list( $advisor, $booking ) = $waiting();
		ELAB_Notifications::booking_notice( $booking, 'confirmed' );
		$mail = T::mails_to( get_post_meta( $advisor, '_elab_email', true ) )[0];
		T::contains( esc_url( ELAB_Booking_Sync::advisor_url( $booking ) ), $mail['message'], 'Booking email links the manual step' );
		ELAB_Notifications::connection_lost( $advisor );
		T::contains( 'add it myself', end( T::$mail )['message'], 'Reconnect email lists each call with the manual step' );
	},

	'The admin booking record offers the same manual step' => function () use ( $waiting ) {
		list( , $booking ) = $waiting();
		$html = T::render( static function () use ( $booking ) { ( new ELAB_Booking_Admin() )->render( get_post( $booking ) ); } );
		T::contains( 'Add to the advisor’s calendar by hand', $html, 'Button shown' );
		ELAB_Booking_Sync::claim( $booking );
		$html = T::render( static function () use ( $booking ) { ( new ELAB_Booking_Admin() )->render( get_post( $booking ) ); } );
		T::contains( 'added this call to their own calendar by hand', $html, 'Record explains it' );
	},

	'The advisor page escapes visitor data' => function () use ( $waiting ) {
		list( , $booking ) = $waiting( array( '_elab_name' => '<script>alert(1)</script>' ) );
		$page = ELAB_Booking_Sync::advisor_page( $booking );
		$html = ELAB_Mailer::page( array( 'title' => $page[0], 'intro' => $page[1], 'rows' => $page[2], 'form' => $page[4] ) );
		T::lacks( '<script>alert', $html, 'No raw script' );
		T::contains( 'method="post"', $html, 'The action is a POST form' );
	},
);
