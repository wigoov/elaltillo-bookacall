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
		T::contains( 'elab_google_invite', $mail['message'], 'Reconnect link needs no WordPress login' );
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

	'Emails are signed El Altillo, and a reply reaches the other side of the call' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'asesor@example.test' ) ) );
		$booking = T::booking( $advisor, array( '_elab_contact_email' => 'visita@example.test', '_elab_guests' => array( 'socio@example.test' ) ) );
		$from = '';
		// The sender name WordPress would use, read while the email is being sent.
		$name = static function ( $preempt ) use ( &$from ) { $from = apply_filters( 'wp_mail_from_name', 'WordPress' ); return $preempt; };
		add_filter( 'pre_wp_mail', $name, 5 );
		ELAB_Notifications::booking_notice( $booking, 'confirmed' );
		remove_filter( 'pre_wp_mail', $name, 5 );
		ELAB_Notifications::visitor_booked( $booking );
		update_post_meta( $booking, ELAB_Notifications::META_GUESTS_TOLD, 1 );
		ELAB_Notifications::visitor_notice( $booking, 'cancelled' );
		$reply = static function ( $mail ) { return implode( ' ', preg_grep( '/^Reply-To:/i', (array) $mail['headers'] ) ); };
		T::contains( 'visita@example.test', $reply( T::mails_to( 'asesor@example.test' )[0] ), 'Advisor replies reach the visitor' );
		T::contains( 'asesor@example.test', $reply( T::mails_to( 'visita@example.test' )[0] ), 'Visitor replies reach the advisor' );
		T::contains( 'visita@example.test', $reply( T::mails_to( 'socio@example.test' )[0] ), 'Guest replies reach the visitor who invited them' );
		T::contains( '>El Altillo</p>', T::mails_to( 'asesor@example.test' )[0]['message'], 'Brand at the top' );
		T::same( 'El Altillo', $from, 'Sender name' );
	},

	'The administrator cancelling: the visitor hears it from the site with a way to book again' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor, array( '_elab_guests' => array( 'socio@example.test' ) ) );
		update_post_meta( $booking, ELAB_Notifications::META_GUESTS_TOLD, 1 );
		ELAB_Booking_Sync::create_for( $booking );
		ELAB_Booking_Manage::mark_cancelled( $booking, 'admin' );
		$visitor = T::mail_with_subject( 'Your call was cancelled' );
		T::same( 1, count( $visitor ), 'Visitor emailed' );
		T::contains( 'If you still want to talk, choose another time', $visitor[0]['message'], 'Admin wording' );
		T::same( array(), T::mails_to( 'socio@example.test' ), 'Google already told the guests: no second email' );
	},

	'A connection that breaks while booking: one email to the advisor, the admin still told' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'asesor@example.test' ) ) );
		T::$revoke = true;
		T::book( $advisor, T::slot_values( $advisor )[0], array( 'language' => 'en', 'locale' => 'en_US' ) );
		ELAB_Notifications::send_queued_connection_lost();
		$to_advisor = T::mails_to( 'asesor@example.test' );
		T::same( 1, count( $to_advisor ), 'One email to the advisor' );
		T::same( 'Action needed: new call waiting for Google Calendar', $to_advisor[0]['subject'], 'The booking email, which asks to reconnect' );
		T::same( 1, count( T::mail_with_subject( 'Test advisor needs to reconnect Google Calendar' ) ), 'The admin is told' );
		$visitor = T::mail_with_subject( 'Your call is booked' );
		T::contains( 'as soon as the advisor confirms their calendar', $visitor[0]['message'], 'The visitor is told honestly why the invitation waits' );
	},

	'Reminder: once, 24 hours ahead, not for calls booked at the last minute' => function () {
		$advisor = T::connect( T::advisor() );
		$soon = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', time() + 20 * HOUR_IN_SECONDS ) ) );
		wp_update_post( array( 'ID' => $soon, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) );
		$last_minute = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', time() + 5 * HOUR_IN_SECONDS ) ) );
		$later = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ) ) );
		$cancelled = T::booking( $advisor, array( '_elab_status' => 'cancelled', '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', time() + 10 * HOUR_IN_SECONDS ) ) );
		T::same( 1, ELAB_Notifications::send_reminders(), 'One reminder' );
		$mail = T::mail_with_subject( 'Reminder: your call with Test advisor' );
		T::same( get_post_meta( $soon, '_elab_contact_email', true ), $mail[0]['to'], 'To the visitor of the call within 24 hours' );
		T::contains( 'View or cancel my call', $mail[0]['message'], 'With the cancel link' );
		T::same( 0, ELAB_Notifications::send_reminders(), 'Never twice' );
		T::check( (bool) get_post_meta( $last_minute, ELAB_Notifications::META_REMINDED, true ), 'A last-minute booking is skipped for good' );
		T::check( ! get_post_meta( $later, ELAB_Notifications::META_REMINDED, true ) && ! get_post_meta( $cancelled, ELAB_Notifications::META_REMINDED, true ), 'Later and cancelled calls untouched' );
		T::check( false !== has_action( ELAB_Booking_Sync::CRON, array( 'ELAB_Notifications', 'send_reminders' ) ), 'Runs with the 15-minute check' );
	},
);
