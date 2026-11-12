<?php
/**
 * The site's own calendar: connecting the same account again never piles up copies, and a
 * calendar deleted in Google comes back with its upcoming calls.
 */

use ELAB_Test as T;

/** Connect the advisor through the real Connect → Google → callback flow. */
$connect_flow = static function ( $advisor ) {
	T::configure_google();
	$location = '';
	$redirect = static function ( $url ) use ( &$location ) {
		$location = $url;
		throw new ELAB_Test_Failure( 'redirect' );
	};
	$die = static function () { return static function ( $message ) { throw new ELAB_Test_Failure( 'die:' . ( is_string( $message ) ? $message : '' ) ); }; };
	add_filter( 'wp_redirect', $redirect, 1 );
	add_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	try {
		$_GET['advisor'] = (string) $advisor;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'elab_google_connect_' . $advisor );
		try {
			( new ELAB_Google_Calendar() )->connect();
		} catch ( ELAB_Test_Failure $stopped ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$_GET = array( 'state' => $query['state'] ?? '', 'code' => 'test-code' );
		try {
			( new ELAB_Google_Calendar() )->callback();
		} catch ( ELAB_Test_Failure $stopped ) {
			T::check( 0 !== strpos( $stopped->getMessage(), 'die:' ), 'Callback finished: ' . $stopped->getMessage() );
		}
	} finally {
		remove_filter( 'wp_redirect', $redirect, 1 );
		remove_filter( 'wp_die_handler', $die, PHP_INT_MAX );
		$_GET = array();
		unset( $_REQUEST['_wpnonce'] );
	}
};

return array(
	'Disconnect and connect the same account again: the same calendar, no copy' => function () use ( $connect_flow ) {
		$advisor = T::advisor( array( '_elab_email' => 'advisor@example.test' ) );
		$connect_flow( $advisor );
		T::same( 1, count( T::$calendar_posts ), 'First connection creates the calendar' );
		$calendar = ELAB_Google_Calendar::calendar_id( $advisor );
		ELAB_Google_Calendar::forget( $advisor, true );
		T::check( ! ELAB_Google_Calendar::linked( $advisor ), 'Disconnected' );
		$connect_flow( $advisor );
		T::same( 1, count( T::$calendar_posts ), 'No second calendar' );
		T::same( $calendar, ELAB_Google_Calendar::calendar_id( $advisor ), 'Same calendar' );
		T::check( ELAB_Google_Calendar::connected( $advisor ), 'Connected again' );
	},

	'Advisors connected before the fix keep their calendar too' => function () use ( $connect_flow ) {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'advisor@example.test' ) ) );
		ELAB_Google_Calendar::watch_connections();
		ELAB_Google_Calendar::forget( $advisor, true );
		$connect_flow( $advisor );
		T::same( 0, count( T::$calendar_posts ), 'No new calendar' );
		T::same( 'test-cal@group.calendar.google.com', ELAB_Google_Calendar::calendar_id( $advisor ), 'Same calendar' );
	},

	'Another Google account or another Google app gets its own calendar' => function () use ( $connect_flow ) {
		$advisor = T::advisor( array( '_elab_email' => 'advisor@example.test' ) );
		$connect_flow( $advisor );
		ELAB_Google_Calendar::forget( $advisor, true );
		ELAB_Google_Calendar::save_credentials( 'another-client', 'another-secret' );
		$connect_flow( $advisor );
		T::same( 2, count( T::$calendar_posts ), 'A new app cannot see the old calendar, so it makes its own' );
		T::same( '', (string) get_post_meta( $advisor, ELAB_Google_Calendar::META_GONE_CALENDARS, true ), 'The old app’s calendar is not treated as deleted' );
	},

	'A calendar deleted in Google is created again with its upcoming calls and the same Meet' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'advisor@example.test' ) ) );
		$first = T::booking( $advisor, array( '_elab_name' => 'Laura' ) );
		$second = T::booking( $advisor, array( '_elab_name' => 'Pedro', '_elab_start_utc' => gmdate( 'Y-m-d H:00:00', strtotime( '+8 days' ) ) ) );
		$past = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:00:00', strtotime( '-2 days' ) ) ) );
		$cancelled = T::booking( $advisor, array( '_elab_status' => 'cancelled' ) );
		foreach ( array( $first, $second, $past, $cancelled ) as $booking ) {
			update_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, ELAB_Google_Calendar::event_id_for( $booking ) );
			update_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, 'test-cal@group.calendar.google.com' );
			update_post_meta( $booking, '_elab_meet_url', 'https://meet.google.com/abc-defg-hij' );
		}
		T::$mail = array();
		T::$calendar_gone = true;
		ELAB_Google_Calendar::watch_connections();

		$new = 'test-cal-1@group.calendar.google.com';
		T::same( 1, count( T::$calendar_posts ), 'One new calendar' );
		T::same( $new, ELAB_Google_Calendar::calendar_id( $advisor ), 'Advisor uses it' );
		T::same( $new, get_post_meta( $first, ELAB_Google_Calendar::META_CALENDAR, true ), 'First call put back' );
		T::same( $new, get_post_meta( $second, ELAB_Google_Calendar::META_CALENDAR, true ), 'Second call put back' );
		T::same( 'test-cal@group.calendar.google.com', get_post_meta( $past, ELAB_Google_Calendar::META_CALENDAR, true ), 'Past calls left alone' );
		T::same( 'test-cal@group.calendar.google.com', get_post_meta( $cancelled, ELAB_Google_Calendar::META_CALENDAR, true ), 'Cancelled calls left alone' );
		T::same( 'abc-defg-hij', T::$event_body['conferenceData']['conferenceId'] ?? '', 'The existing Meet is attached' );
		T::check( empty( T::$event_body['conferenceData']['createRequest'] ), 'No new Meet requested' );
		T::same( 'https://meet.google.com/abc-defg-hij', get_post_meta( $first, '_elab_meet_url', true ), 'Visitor’s link unchanged' );
		T::same( 'confirmed', get_post_meta( $first, '_elab_status', true ), 'Still confirmed' );
		T::same( '', (string) get_post_meta( $advisor, ELAB_Google_Calendar::META_GONE_CALENDARS, true ), 'Nothing left to restore' );

		$to_advisor = T::mails_to( 'advisor@example.test' );
		T::same( 1, count( $to_advisor ), 'Advisor told once' );
		T::contains( 'was created again', $to_advisor[0]['subject'], 'Advisor subject' );
		T::contains( 'Laura', $to_advisor[0]['message'], 'Lists the calls put back' );
		T::contains( 'Pedro', $to_advisor[0]['message'], 'Lists every call put back' );
		T::contains( 'hide it', $to_advisor[0]['message'], 'Explains how to avoid it next time' );
		T::same( 1, count( T::mails_to( get_option( 'admin_email' ) ) ), 'Administrator told too' );

		T::$mail = array();
		ELAB_Google_Calendar::watch_connections();
		T::same( 1, count( T::$calendar_posts ), 'The next sync does nothing more' );
		T::same( array(), T::$mail, 'No more emails' );
	},

	'A call Google refuses to put back now is tried again by the next sync' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'advisor@example.test' ) ) );
		$booking = T::booking( $advisor );
		update_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, ELAB_Google_Calendar::event_id_for( $booking ) );
		update_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, 'test-cal@group.calendar.google.com' );
		T::$calendar_gone = true;
		T::$event_insert_codes = array( 503 );
		T::$event_get_code = 404; // Really not created: the follow-up lookup finds nothing.
		ELAB_Google_Calendar::watch_connections();
		T::$event_get_code = 200;
		T::same( 'test-cal@group.calendar.google.com', get_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, true ), 'Not put back yet' );
		T::contains( 'could not be put back yet', T::mails_to( 'advisor@example.test' )[0]['message'], 'Advisor told it is pending' );
		T::$mail = array();
		ELAB_Google_Calendar::watch_connections();
		T::same( 'test-cal-1@group.calendar.google.com', get_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, true ), 'Put back by the next sync' );
		T::same( 1, count( T::$calendar_posts ), 'Still one new calendar' );
		T::same( array(), T::$mail, 'No second email' );
	},

	'If Google will not attach the old Meet, the call gets a new one' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		update_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, ELAB_Google_Calendar::event_id_for( $booking ) );
		update_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, 'test-cal@group.calendar.google.com' );
		update_post_meta( $booking, '_elab_meet_url', 'https://meet.google.com/abc-defg-hij' );
		T::$calendar_gone = true;
		T::$event_insert_codes = array( 400 );
		ELAB_Google_Calendar::watch_connections();
		T::same( 'test-cal-1@group.calendar.google.com', get_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, true ), 'Put back' );
		T::same( 'https://meet.google.com/tst-test-call', get_post_meta( $booking, '_elab_meet_url', true ), 'New Meet saved' );
	},

	'If Google still holds the old event ID, a fresh one is used' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		update_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, ELAB_Google_Calendar::event_id_for( $booking ) );
		update_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, 'test-cal@group.calendar.google.com' );
		T::$calendar_gone = true;
		T::$event_insert_codes = array( 409 );
		ELAB_Google_Calendar::watch_connections();
		$id = (string) get_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, true );
		T::check( (bool) preg_match( '/^elab[0-9a-f]{32}$/', $id ) && ELAB_Google_Calendar::event_id_for( $booking ) !== $id, 'Fresh ID in the plugin’s format' );
		T::same( array(), ELAB_Google_Calendar::delete_event( $advisor, $id, $booking ), 'And the site can still remove it' );
	},

	'A calendar that is only unreachable for a moment is never replaced' => function () {
		$advisor = T::connect( T::advisor() );
		T::$google_down = true;
		ELAB_Google_Calendar::watch_connections();
		T::same( 0, count( T::$calendar_posts ), 'No new calendar' );
		T::same( 'test-cal@group.calendar.google.com', ELAB_Google_Calendar::calendar_id( $advisor ), 'Same calendar' );
	},

	'Deleted, then the advisor reconnects before the sync notices: the calls come back too' => function () use ( $connect_flow ) {
		$advisor = T::advisor( array( '_elab_email' => 'advisor@example.test' ) );
		$connect_flow( $advisor );
		$old = ELAB_Google_Calendar::calendar_id( $advisor );
		$booking = T::booking( $advisor );
		update_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, ELAB_Google_Calendar::event_id_for( $booking ) );
		update_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, $old );
		// Mark the first calendar gone: the mock answers 404 for IDs starting with "gone".
		update_post_meta( $advisor, ELAB_Google_Calendar::META_CALENDAR, 'gone-' . $old );
		update_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, 'gone-' . $old );
		ELAB_Google_Calendar::forget( $advisor, true );
		$connect_flow( $advisor );
		$new = ELAB_Google_Calendar::calendar_id( $advisor );
		T::check( 'gone-' . $old !== $new, 'A new calendar' );
		T::same( $new, get_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, true ), 'The call is in it' );
	},

	'The advisor reads it in Spanish when that is their email language' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'advisor@example.test', ELAB_Notifications::META_ADVISOR_LOCALE => 'es_CO' ) ) );
		$booking = T::booking( $advisor );
		update_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, ELAB_Google_Calendar::event_id_for( $booking ) );
		update_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, 'test-cal@group.calendar.google.com' );
		T::$calendar_gone = true;
		ELAB_Google_Calendar::watch_connections();
		$mail = T::mails_to( 'advisor@example.test' )[0];
		T::same( 'Tu calendario «El Altillo · Citas» se creó de nuevo', $mail['subject'], 'Spanish subject' );
		T::contains( 'Se volvió a poner en él 1 llamada', $mail['message'], 'Spanish body' );
	},

	'“Test Google now” repairs a calendar Google no longer has, and says why a test fails' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'advisor@example.test' ) ) );
		T::$calendar_gone = true;
		$result = ELAB_System_Status::run_google_test();
		T::same( 1, $result['passed'], 'Answers after the calendar is created again' );
		T::same( 'test-cal-1@group.calendar.google.com', ELAB_Google_Calendar::calendar_id( $advisor ), 'New calendar in use' );
		T::$google_down = true;
		$result = ELAB_System_Status::run_google_test();
		T::contains( 'HTTP 503', $result['advisors'][ $advisor ], 'Google’s answer is shown' );
		T::same( 1, count( T::$calendar_posts ), 'An outage never creates a calendar' );
	},
);

