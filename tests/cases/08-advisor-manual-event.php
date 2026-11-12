<?php
/**
 * Reconnecting is the only way to bring a waiting call into Google. Links from older emails
 * change nothing, and calls an advisor added by hand under an earlier version are respected.
 */

use ELAB_Test as T;

$waiting = static function ( array $meta = array() ) {
	$advisor = T::connect( T::advisor() );
	update_post_meta( $advisor, ELAB_Google_Calendar::META_REVOKED, time() );
	$booking = T::booking( $advisor, $meta );
	ELAB_Booking_Sync::queue_create( $booking, new WP_Error( 'google_revoked' ) );
	return array( $advisor, $booking );
};

return array(
	'No email or admin screen offers to add the call by hand' => function () use ( $waiting ) {
		list( $advisor, $booking ) = $waiting();
		ELAB_Notifications::booking_notice( $booking, 'confirmed' );
		ELAB_Notifications::connection_lost( $advisor );
		foreach ( T::$mail as $mail ) {
			T::lacks( 'elab_advisor_event', $mail['message'], 'No manual link in “' . $mail['subject'] . '”' );
			T::lacks( 'calendar.google.com/calendar/render', $mail['message'], 'No prefilled Google event' );
		}
		T::contains( 'Reconnect Google Calendar', end( T::$mail )['message'], 'Reconnect offered' );
		$html = T::render( static function () use ( $booking ) { ( new ELAB_Booking_Admin() )->render( get_post( $booking ) ); } );
		T::lacks( 'by hand', $html, 'No manual button on the record' );
		T::contains( 'Open advisor connection', $html, 'Reconnect offered on the record' );
	},

	'The reconnect email still lists every waiting call' => function () use ( $waiting ) {
		list( $advisor ) = $waiting( array( '_elab_name' => 'Waiting Ana' ) );
		ELAB_Notifications::connection_lost( $advisor );
		T::contains( 'Waiting Ana', end( T::$mail )['message'], 'Call listed' );
	},

	'An old manual link only explains that reconnecting adds the call' => function () use ( $waiting ) {
		list( , $booking ) = $waiting();
		T::check( false !== has_action( 'admin_post_nopriv_' . ELAB_Booking_Sync::OLD_ACTION, array( 'ELAB_Booking_Sync', 'handle_old_link' ) ), 'Old links still answer' );
		T::contains( 'reconnect', ELAB_Booking_Sync::old_link_message()[1], 'Points to reconnecting' );
		T::check( ! method_exists( 'ELAB_Booking_Sync', 'claim' ), 'Nothing can hand the call to the advisor any more' );
		T::same( ELAB_Booking_Sync::CREATE, ELAB_Booking_Sync::state( $booking ), 'Still waiting for reconnection' );
	},

	'Reconnecting creates the waiting event and tells the advisor' => function () use ( $waiting ) {
		list( $advisor, $booking ) = $waiting();
		delete_post_meta( $advisor, ELAB_Google_Calendar::META_REVOKED );
		T::same( 1, ELAB_Booking_Sync::run( $advisor )['created'], 'Created on reconnection' );
		T::same( '', ELAB_Booking_Sync::state( $booking ), 'Off the queue' );
		T::same( 1, count( T::mail_with_subject( 'Call added to your calendar' ) ), 'Advisor told' );
	},

	'A call added by hand under an earlier version is never duplicated, and its cancellation is explained' => function () use ( $waiting ) {
		list( $advisor, $booking ) = $waiting();
		update_post_meta( $booking, ELAB_Booking_Sync::META_BY_ADVISOR, time() );
		delete_post_meta( $advisor, ELAB_Google_Calendar::META_REVOKED );
		ELAB_Booking_Sync::run();
		T::same( array(), T::requests( '#^POST .*/events#' ), 'No second event' );
		T::same( true, ELAB_Booking_Manage::cancel( $booking ), 'Cancelled' );
		T::contains( 'You added this call to your calendar yourself', end( T::mails_to( get_post_meta( $advisor, '_elab_email', true ) ) )['message'], 'Advisor asked to delete it' );
	},
);
