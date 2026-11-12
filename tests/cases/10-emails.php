<?php
/** Every email: who gets it, what it says, that it is formatted and safe. */

use ELAB_Test as T;

$advisor_mail = static function ( $advisor ) {
	$mails = T::mails_to( get_post_meta( $advisor, '_elab_email', true ) );
	T::check( (bool) $mails, 'The advisor got an email' );
	return end( $mails );
};

return array(
	'Every email is HTML with a plain-text version' => function () {
		$text = T::call( 'ELAB_Mailer', 'text', array( 'title' => 'Hello', 'rows' => array( 'When' => 'Monday', 'Link' => array( 'Open', 'https://example.test/x' ) ), 'button' => array( 'Go', 'https://example.test/go' ) ) );
		T::contains( 'When: Monday', $text, 'Rows in text' );
		T::contains( 'Link: https://example.test/x', $text, 'Links in text' );
		T::contains( 'Go: https://example.test/go', $text, 'Button in text' );
		$booking = T::booking( T::advisor() );
		ELAB_Notifications::visitor_booked( $booking );
		T::contains( 'text/html', implode( "\n", (array) T::$mail[0]['headers'] ), 'HTML content type' );
		T::contains( '<table', T::$mail[0]['message'], 'Formatted layout' );
	},

	'Visitor data is escaped in every email' => function () use ( $advisor_mail ) {
		$advisor = T::advisor();
		$booking = T::booking( $advisor, array( '_elab_name' => '<img src=x onerror=alert(1)>' ) );
		ELAB_Notifications::booking_notice( $booking, 'confirmed' );
		T::lacks( '<img src=x', $advisor_mail( $advisor )['message'], 'No raw HTML from the visitor' );
	},

	'Advisor — new call: details, guests, no private message' => function () use ( $advisor_mail ) {
		$advisor = T::advisor();
		$booking = T::booking( $advisor, array( '_elab_guests' => array( 'g@example.test' ) ) );
		ELAB_Notifications::booking_notice( $booking, 'confirmed' );
		$mail = $advisor_mail( $advisor );
		T::same( 'New call booked', $mail['subject'], 'Subject' );
		foreach ( array( 'Test visitor', 'g@example.test', 'Europe/Lisbon', '30 minutes' ) as $piece ) {
			T::contains( $piece, $mail['message'], 'Contains ' . $piece );
		}
		T::lacks( 'Private message', $mail['message'], 'No message' );
		T::lacks( 'Private sector', $mail['message'], 'No sector' );
	},

	'Advisor — request to confirm (no Google)' => function () use ( $advisor_mail ) {
		$advisor = T::advisor();
		ELAB_Notifications::booking_notice( T::booking( $advisor ), 'pending' );
		T::same( 'New call request', $advisor_mail( $advisor )['subject'], 'Subject' );
	},

	'Advisor — cancelled by the visitor' => function () use ( $advisor_mail ) {
		$advisor = T::advisor();
		$booking = T::booking( $advisor, array( '_elab_cancelled_by' => 'visitor' ) );
		ELAB_Notifications::booking_notice( $booking, 'cancelled' );
		T::contains( 'cancelled the call from their private link', $advisor_mail( $advisor )['message'], 'Says the visitor cancelled' );
	},

	'Advisor — Google slow: event follows within the hour, no manual step, no reconnect' => function () use ( $advisor_mail ) {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		ELAB_Booking_Sync::queue_create( $booking, new WP_Error( 'google_calendar_failed' ) );
		ELAB_Notifications::booking_notice( $booking, 'confirmed' );
		$mail = $advisor_mail( $advisor );
		T::contains( 'within the hour', $mail['message'], 'Explains the retry' );
		T::lacks( 'Add it to my calendar myself', $mail['message'], 'No manual step' );
		T::lacks( 'Reconnect Google Calendar', $mail['message'], 'No reconnect when the connection is fine' );
	},

	'Advisor — connection broken: action needed, reconnect is the only button' => function () use ( $advisor_mail ) {
		$advisor = T::connect( T::advisor() );
		update_post_meta( $advisor, ELAB_Google_Calendar::META_REVOKED, time() );
		$booking = T::booking( $advisor );
		ELAB_Booking_Sync::queue_create( $booking, new WP_Error( 'google_revoked' ) );
		ELAB_Notifications::booking_notice( $booking, 'confirmed' );
		$mail = $advisor_mail( $advisor );
		T::same( 'Action needed: new call waiting for Google Calendar', $mail['subject'], 'Subject' );
		T::contains( 'Reconnect Google Calendar', $mail['message'], 'Reconnect button' );
		T::contains( 'post.php?post=' . $advisor, $mail['message'], 'Reconnect goes to the advisor profile' );
		T::lacks( 'elab_advisor_event', $mail['message'], 'No link to add it by hand' );
	},

	'Advisor — reconnect email goes to advisor and site admin, listing waiting calls' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor, array( '_elab_name' => 'Waiting Ana' ) );
		ELAB_Booking_Sync::queue_create( $booking, new WP_Error( 'google_revoked' ) );
		ELAB_Notifications::connection_lost( $advisor );
		T::same( 1, count( T::mails_to( get_post_meta( $advisor, '_elab_email', true ) ) ), 'Advisor' );
		T::same( 1, count( T::mails_to( get_option( 'admin_email' ) ) ), 'Site admin' );
		T::contains( 'Waiting Ana', T::$mail[0]['message'], 'Waiting call listed' );
	},

	'Advisor — schedule summary only for connected advisors' => function () use ( $advisor_mail ) {
		$advisor = T::advisor();
		T::same( false, ELAB_Notifications::schedule_summary( $advisor ), 'Not sent before connecting' );
		T::connect( $advisor );
		T::same( true, ELAB_Notifications::schedule_summary( $advisor ), 'Sent once connected' );
		T::contains( '09:00–12:00', $advisor_mail( $advisor )['message'], 'Lists the hours' );
	},

	'Visitor — booked with Google: details, manage button, invitation note' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		ELAB_Booking_Sync::create_for( $booking );
		ELAB_Notifications::visitor_booked( $booking );
		$mail = T::mail_with_subject( 'Your call is booked' )[0];
		T::contains( 'View or cancel my call', $mail['message'], 'Manage button' );
		T::contains( 'America/Bogota', $mail['message'], 'In the visitor’s time zone' );
		T::contains( 'Google Calendar also sends you an invitation', $mail['message'], 'Invitation note' );
	},

	'Visitor — booked while Google waits: invitation will follow' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		ELAB_Booking_Sync::queue_create( $booking, new WP_Error( 'x' ) );
		ELAB_Notifications::visitor_booked( $booking );
		T::contains( 'invitation will follow', T::mail_with_subject( 'Your call is booked' )[0]['message'], 'Explains the wait' );
	},

	'Visitor — request received (no Google)' => function () {
		$booking = T::booking( T::advisor(), array( '_elab_status' => 'pending' ) );
		ELAB_Notifications::visitor_booked( $booking );
		T::same( 1, count( T::mail_with_subject( 'We received your call request' ) ), 'Subject' );
	},

	'Visitor — cancelled: book-another button, no video link' => function () {
		$booking = T::booking( T::advisor(), array( '_elab_meet_url' => 'https://meet.google.com/aaa-bbbb-ccc' ) );
		ELAB_Notifications::visitor_notice( $booking, 'cancelled' );
		$mail = T::mail_with_subject( 'Your call was cancelled' )[0];
		T::contains( 'Book another time', $mail['message'], 'Book again' );
		T::lacks( 'meet.google.com', $mail['message'], 'No stale video link' );
	},

	'A failed email is recorded on the booking' => function () {
		T::$mail_fails = true;
		$booking = T::booking( T::advisor() );
		ELAB_Notifications::visitor_booked( $booking );
		ELAB_Notifications::booking_notice( $booking, 'confirmed' );
		T::same( 'failed', get_post_meta( $booking, ELAB_Notifications::META_VISITOR_MAIL, true ), 'Visitor email failure' );
		T::same( 'failed', get_post_meta( $booking, ELAB_Notifications::META_BOOKING_MAIL, true ), 'Advisor email failure' );
	},
);
