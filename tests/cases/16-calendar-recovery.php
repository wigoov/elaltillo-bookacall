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
		T::contains( 'being put back', T::mails_to( 'advisor@example.test' )[0]['message'], 'Advisor told it is under way' );
		T::$mail = array();
		ELAB_Google_Calendar::watch_connections();
		T::same( 'test-cal-1@group.calendar.google.com', get_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, true ), 'Put back by the next sync' );
		T::same( 1, count( T::$calendar_posts ), 'Still one new calendar' );
		T::same( 1, count( T::mails_to( 'advisor@example.test' ) ), 'One closing email' );
		T::contains( 'are back', T::mails_to( 'advisor@example.test' )[0]['subject'], 'Says every call is back' );
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
		T::check( (bool) wp_next_scheduled( ELAB_Google_Calendar::RESTORE_CRON ), 'Calls are put back in the background, not during the connection' );
		ELAB_Google_Calendar::run_restores();
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

	'A year of calls: only upcoming ones come back, in batches, nearest first, with two emails' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'advisor@example.test' ) ) );
		$old = 'test-cal@group.calendar.google.com';
		$future = array();
		for ( $day = 1; $day <= 30; $day++ ) {
			$future[ $day ] = T::booking( $advisor, array( '_elab_name' => 'Visitor ' . $day, '_elab_start_utc' => gmdate( 'Y-m-d 15:00:00', strtotime( '+' . ( $day * 12 ) . ' days' ) ) ) );
		}
		$past = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d 15:00:00', strtotime( '-40 days' ) ) ) );
		foreach ( array_merge( $future, array( $past ) ) as $booking ) {
			update_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, ELAB_Google_Calendar::event_id_for( $booking ) );
			update_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, $old );
		}
		T::$mail = array();
		T::$calendar_gone = true;
		ELAB_Google_Calendar::watch_connections();
		$new = ELAB_Google_Calendar::calendar_id( $advisor );
		$back = static function () use ( $future, $new ) {
			return count( array_filter( $future, static function ( $id ) use ( $new ) { return $new === get_post_meta( $id, ELAB_Google_Calendar::META_CALENDAR, true ); } ) );
		};
		T::same( ELAB_Google_Calendar::RESTORE_BATCH, $back(), 'First batch only' );
		T::same( $new, get_post_meta( $future[1], ELAB_Google_Calendar::META_CALENDAR, true ), 'Nearest call first' );
		T::same( $old, get_post_meta( $future[30], ELAB_Google_Calendar::META_CALENDAR, true ), 'Farthest call later' );
		T::check( (bool) wp_next_scheduled( ELAB_Google_Calendar::RESTORE_CRON ), 'Next batch scheduled' );
		$first = T::mails_to( 'advisor@example.test' );
		T::same( 1, count( $first ), 'One email when it starts' );
		T::contains( 'being put back', $first[0]['message'], 'Says it is under way' );
		T::contains( 'and 5 more calls', $first[0]['message'], 'Long lists are shortened' );

		T::$mail = array();
		wp_clear_scheduled_hook( ELAB_Google_Calendar::RESTORE_CRON );
		ELAB_Google_Calendar::run_restores();
		T::same( 30, $back(), 'Every upcoming call is back' );
		T::same( $old, get_post_meta( $past, ELAB_Google_Calendar::META_CALENDAR, true ), 'The past call stays only in WordPress' );
		T::check( ! wp_next_scheduled( ELAB_Google_Calendar::RESTORE_CRON ), 'Nothing more scheduled' );
		$last = T::mails_to( 'advisor@example.test' );
		T::same( 1, count( $last ), 'One email when it ends' );
		T::contains( 'are back', $last[0]['subject'], 'Closing subject' );
		T::contains( 'and 10 more calls', $last[0]['message'], 'Counts every call' );
	},

	'A visitor’s booking never waits for the other calls to be put back' => function () {
		$advisor = T::connect( T::advisor() );
		$others = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$others[] = $other = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d 15:00:00', strtotime( '+' . ( 10 + $i ) . ' days' ) ) ) );
			update_post_meta( $other, ELAB_Google_Calendar::META_EVENT, ELAB_Google_Calendar::event_id_for( $other ) );
			update_post_meta( $other, ELAB_Google_Calendar::META_CALENDAR, 'test-cal@group.calendar.google.com' );
		}
		$booking = T::booking( $advisor );
		T::$calendar_gone = true;
		T::check( ! is_wp_error( ELAB_Booking_Sync::create_for( $booking ) ), 'The visitor’s call is placed' );
		T::same( 1, count( T::requests( '#POST .*test-cal-1%40group[^/]*/events\?#' ) ), 'Only the visitor’s own event was created in the new calendar now' );
		T::same( 'test-cal@group.calendar.google.com', get_post_meta( $others[0], ELAB_Google_Calendar::META_CALENDAR, true ), 'Others wait for the background' );
		T::check( (bool) wp_next_scheduled( ELAB_Google_Calendar::RESTORE_CRON ), 'Background batch scheduled' );
		ELAB_Google_Calendar::run_restores();
		T::same( 'test-cal-1@group.calendar.google.com', get_post_meta( $others[2], ELAB_Google_Calendar::META_CALENDAR, true ), 'Then they are back' );
	},

	'When Google asks to slow down, the batch stops and continues later' => function () {
		$advisor = T::connect( T::advisor() );
		$bookings = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$bookings[] = $booking = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d 15:00:00', strtotime( '+' . ( 5 + $i ) . ' days' ) ) ) );
			update_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, ELAB_Google_Calendar::event_id_for( $booking ) );
			update_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, 'test-cal@group.calendar.google.com' );
		}
		T::$calendar_gone = true;
		T::$event_insert_codes = array( 429 );
		T::$event_get_code = 404;
		ELAB_Google_Calendar::watch_connections();
		T::$event_get_code = 200;
		T::same( 1, count( T::requests( '#POST .*/events\?#' ) ), 'Stopped after Google said to slow down' );
		ELAB_Google_Calendar::run_restores();
		T::same( 'test-cal-1@group.calendar.google.com', get_post_meta( $bookings[2], ELAB_Google_Calendar::META_CALENDAR, true ), 'All back on the next batch' );
	},

	'A call Google keeps refusing is left after a few tries and named in the email' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'advisor@example.test' ) ) );
		$booking = T::booking( $advisor, array( '_elab_name' => 'Refused visitor' ) );
		update_post_meta( $booking, ELAB_Google_Calendar::META_EVENT, ELAB_Google_Calendar::event_id_for( $booking ) );
		update_post_meta( $booking, ELAB_Google_Calendar::META_CALENDAR, 'test-cal@group.calendar.google.com' );
		T::$calendar_gone = true;
		T::$event_insert_codes = array_fill( 0, ELAB_Google_Calendar::RESTORE_TRIES, 400 );
		T::$event_get_code = 404;
		ELAB_Google_Calendar::watch_connections();
		for ( $i = 1; $i < ELAB_Google_Calendar::RESTORE_TRIES; $i++ ) {
			ELAB_Google_Calendar::run_restores();
		}
		T::$event_get_code = 200;
		T::same( '', (string) get_post_meta( $advisor, ELAB_Google_Calendar::META_GONE_CALENDARS, true ), 'Finished' );
		$last = T::mails_to( 'advisor@example.test' );
		T::contains( 'Refused visitor', end( $last )['message'], 'The refused call is named' );
		T::contains( 'kept refusing', end( $last )['message'], 'And explained' );
		T::same( 'confirmed', get_post_meta( $booking, '_elab_status', true ), 'Still booked in WordPress' );
	},

	'The time zone comes from the advisor’s Google Calendar and follows it' => function () use ( $connect_flow ) {
		T::$token_scope .= ' ' . ELAB_Google_Calendar::SETTINGS_SCOPE;
		$advisor = T::advisor( array( '_elab_email' => 'advisor@example.test', '_elab_timezone' => 'Europe/Lisbon' ) );
		$connect_flow( $advisor );
		T::same( 'America/Mexico_City', get_post_meta( $advisor, '_elab_timezone', true ), 'Taken from Google on connecting' );
		T::check( ELAB_Google_Calendar::timezone_from_google( $advisor ), 'Marked as following Google' );

		T::$google_timezone = 'America/Bogota';
		T::$http = T::$mail = array();
		delete_transient( 'elab_timezone_synced_' . $advisor );
		ELAB_Google_Calendar::watch_connections();
		T::same( 'America/Bogota', get_post_meta( $advisor, '_elab_timezone', true ), 'Follows a change in Google' );
		T::same( 1, count( T::requests( '#^PATCH .*/calendars/#' ) ), 'The site’s calendar follows too' );
		T::same( 1, count( T::mails_to( 'advisor@example.test' ) ), 'The advisor gets their hours in the new time zone' );

		T::$http = array();
		ELAB_Google_Calendar::watch_connections();
		T::same( array(), T::requests( '#settings/timezone#' ), 'Checked at most once an hour' );

		T::$google_timezone = 'Not/AZone';
		delete_transient( 'elab_timezone_synced_' . $advisor );
		ELAB_Google_Calendar::watch_connections();
		T::same( 'America/Bogota', get_post_meta( $advisor, '_elab_timezone', true ), 'An unknown value is ignored' );
	},

	'Without the settings permission the time zone stays as set in WordPress' => function () use ( $connect_flow ) {
		$advisor = T::advisor( array( '_elab_email' => 'advisor@example.test', '_elab_timezone' => 'Europe/Lisbon' ) );
		$connect_flow( $advisor );
		T::same( 'Europe/Lisbon', get_post_meta( $advisor, '_elab_timezone', true ), 'Unchanged' );
		T::check( ! ELAB_Google_Calendar::timezone_from_google( $advisor ), 'Editable in WordPress' );
		T::same( array(), T::requests( '#settings/timezone#' ), 'Google settings never asked' );
	},
);

