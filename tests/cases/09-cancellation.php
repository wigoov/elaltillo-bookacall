<?php
/** Cancelling: by the visitor (private link) or by an administrator. Never fails because of Google. */

use ELAB_Test as T;

return array(
	'A visitor cancels an upcoming call: the event is removed and who/when is recorded' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		ELAB_Booking_Sync::create_for( $booking );
		T::check( ELAB_Booking_Manage::can_cancel( $booking ), 'Can cancel' );
		T::same( true, ELAB_Booking_Manage::cancel( $booking, 'visitor' ), 'Cancelled' );
		T::same( 'cancelled', get_post_meta( $booking, '_elab_status', true ), 'Status' );
		T::same( 'visitor', get_post_meta( $booking, '_elab_cancelled_by', true ), 'Who' );
		T::check( (bool) get_post_meta( $booking, '_elab_cancelled_at', true ), 'When' );
		T::same( 1, count( T::requests( '#^DELETE #' ) ), 'Event deleted' );
		T::same( 0, count( T::mail_with_subject( 'Your call was cancelled' ) ), 'Google tells the visitor, not a second email' );
	},

	'A call cannot be cancelled twice, nor after it took place' => function () {
		$advisor = T::advisor();
		$booking = T::booking( $advisor );
		ELAB_Booking_Manage::cancel( $booking );
		T::error( 'cannot_cancel', ELAB_Booking_Manage::cancel( $booking ), 'Second time' );
		$past = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ) ) );
		T::error( 'cannot_cancel', ELAB_Booking_Manage::cancel( $past ), 'Past call' );
	},

	'Without a Google event the site emails the visitor and the advisor' => function () {
		$advisor = T::advisor();
		$booking = T::booking( $advisor, array( '_elab_status' => 'pending' ) );
		ELAB_Booking_Manage::cancel( $booking );
		T::same( array(), T::$http, 'No Google request' );
		T::same( 1, count( T::mail_with_subject( 'Your call was cancelled' ) ), 'Visitor emailed' );
		T::same( 1, count( T::mail_with_subject( 'Call cancelled' ) ), 'Advisor emailed' );
	},

	'The time is free again right after cancelling' => function () {
		$advisor = T::advisor();
		$time = T::slot_values( $advisor )[0];
		$booking = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( $time ) ) ) );
		ELAB_Booking_Manage::cancel( $booking );
		T::check( in_array( $time, T::slot_values( $advisor ), true ), 'Time offered again' );
	},

	'An administrator cancels from the booking record' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		ELAB_Booking_Sync::create_for( $booking );
		$_POST = array( 'elab_booking_nonce' => wp_create_nonce( 'elab_booking_meta' ), 'elab_status' => 'cancelled' );
		( new ELAB_Booking_Admin() )->save( $booking );
		T::same( 'cancelled', get_post_meta( $booking, '_elab_status', true ), 'Cancelled' );
		T::same( 'admin', get_post_meta( $booking, '_elab_cancelled_by', true ), 'By admin' );
		T::same( 1, count( T::requests( '#^DELETE #' ) ), 'Event deleted' );
	},

	'A cancelled call cannot be reopened, and saving without a change does nothing' => function () {
		$advisor = T::advisor();
		$booking = T::booking( $advisor, array( '_elab_status' => 'cancelled' ) );
		$_POST = array( 'elab_booking_nonce' => wp_create_nonce( 'elab_booking_meta' ), 'elab_status' => 'confirmed' );
		( new ELAB_Booking_Admin() )->save( $booking );
		T::same( 'cancelled', get_post_meta( $booking, '_elab_status', true ), 'Still cancelled' );
		$confirmed = T::booking( $advisor );
		$_POST['elab_status'] = 'confirmed';
		( new ELAB_Booking_Admin() )->save( $confirmed );
		T::same( array(), T::$mail, 'No email when nothing changed' );
	},

	'The admin form needs its nonce' => function () {
		$booking = T::booking( T::advisor() );
		$_POST = array( 'elab_booking_nonce' => 'bad', 'elab_status' => 'cancelled' );
		( new ELAB_Booking_Admin() )->save( $booking );
		T::same( 'confirmed', get_post_meta( $booking, '_elab_status', true ), 'Unchanged' );
	},

	'Confirming a manual request emails the visitor and guests with a calendar link' => function () {
		$advisor = T::advisor();
		$booking = T::booking( $advisor, array( '_elab_status' => 'pending', '_elab_guests' => array( 'guest@example.test' ) ) );
		$_POST = array( 'elab_booking_nonce' => wp_create_nonce( 'elab_booking_meta' ), 'elab_status' => 'confirmed' );
		( new ELAB_Booking_Admin() )->save( $booking );
		$visitor = T::mail_with_subject( 'Your call is confirmed' );
		T::same( 1, count( $visitor ), 'Visitor confirmation' );
		T::contains( 'calendar.google.com/calendar/render', $visitor[0]['message'], 'Add-to-calendar link' );
		T::contains( 'elab_token', $visitor[0]['message'], 'Private manage link for the visitor' );
		$guest = T::mails_to( 'guest@example.test' );
		T::same( 1, count( $guest ), 'Guest emailed' );
		T::lacks( 'elab_token', $guest[0]['message'], 'Guests never get the private link' );
	},

	'Guests hear about a cancellation only if they heard about the call' => function () {
		$advisor = T::advisor();
		$never_told = T::booking( $advisor, array( '_elab_status' => 'pending', '_elab_guests' => array( 'quiet@example.test' ) ) );
		ELAB_Booking_Manage::cancel( $never_told );
		T::same( array(), T::mails_to( 'quiet@example.test' ), 'Untold guest not emailed' );
		$told = T::booking( $advisor, array( '_elab_status' => 'pending', '_elab_guests' => array( 'told@example.test' ) ) );
		ELAB_Notifications::visitor_notice( $told, 'confirmed' );
		ELAB_Booking_Manage::cancel( $told );
		T::same( 2, count( T::mails_to( 'told@example.test' ) ), 'Told guest gets confirmation and cancellation' );
	},

	'The private page reads the token from an HttpOnly cookie, never the URL' => function () {
		$booking = T::booking( T::advisor() );
		$token = ELAB_Booking_Manage::issue_token( $booking );
		$_GET['elab_booking'] = (string) $booking;
		$_COOKIE[ ELAB_Booking_Manage::COOKIE . $booking ] = $token;
		$found = ELAB_Booking_Manage::requested_booking();
		T::check( $found && ELAB_Booking_Manage::verify( $found[0], $found[1] ), 'Cookie authenticates' );
	},

	'Too many cancel attempts from one address are limited' => function () {
		$_SERVER['REMOTE_ADDR'] = '127.8.' . random_int( 1, 254 ) . '.' . random_int( 1, 254 );
		for ( $i = 0; $i < 20; $i++ ) {
			ELAB_Booking_Guard::cancellation();
		}
		T::error( 'rate_limited', ELAB_Booking_Guard::cancellation(), '21st attempt in an hour' );
	},
);
