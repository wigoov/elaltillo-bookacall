<?php
/** Run with: wp eval-file tests/smoke.php (on a disposable/local WordPress site). */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

function elab_smoke_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	WP_CLI::log( 'PASS ' . $message );
}

$saved_id = get_option( ELAB_Google_Calendar::OPTION_CLIENT_ID, false );
$saved_secret = get_option( ELAB_Google_Calendar::OPTION_CLIENT_SECRET, false );
if ( false !== $saved_id || false !== $saved_secret ) {
	WP_CLI::error( 'Run this smoke check only on a disposable/local site without Google credentials.' );
}
$saved_globals = get_option( ELAB_Blackouts::OPTION, false );
$original_user = get_current_user_id();
$original_post = $_POST;
$original_ip = $_SERVER['REMOTE_ADDR'] ?? null;
$created = array();
$created_user = 0;
$advisor_id = 0;
$mail_log = array();
$http_calls = array();
$event_body = null;
$busy_periods = array();
$visitor_email = 'visitor+' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test';
$email_filter = static function ( $preempt, $mail ) use ( &$mail_log ) {
	$mail_log[] = $mail;
	return true;
};
$http_filter = static function ( $preempt, $args, $url ) use ( &$http_calls, &$event_body, &$busy_periods ) {
	$http_calls[] = $url;
	$data = array();
	$code = 200;
	if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
		$data = array( 'access_token' => 'smoke-access', 'expires_in' => 3600 );
	} elseif ( false !== strpos( $url, 'openidconnect.googleapis.com/v1/userinfo' ) ) {
		$data = array( 'sub' => 'smoke-sub', 'email' => 'advisor@example.test', 'email_verified' => true );
	} elseif ( false !== strpos( $url, '/freeBusy' ) ) {
		$data = array( 'calendars' => array( 'primary' => array( 'busy' => $busy_periods ) ) );
	} elseif ( false !== strpos( $url, '/events?sendUpdates=all' ) ) {
		$event_body = json_decode( $args['body'], true );
		$data = array( 'id' => $event_body['id'], 'iCalUID' => 'smoke-event@example.test', 'htmlLink' => 'https://calendar.google.com/calendar/event?eid=smoke' );
	} elseif ( false !== strpos( $url, '/events/' ) && 'DELETE' === $args['method'] ) {
		$code = 204;
	} elseif ( false !== strpos( $url, '/events/' ) ) {
		$data = array( 'id' => basename( wp_parse_url( $url, PHP_URL_PATH ) ) );
	} elseif ( false !== strpos( $url, 'desdeelaltillo.local' ) ) {
		$data = array();
	} else {
		throw new RuntimeException( 'Unexpected outbound HTTP request: ' . $url );
	}
	return array( 'response' => array( 'code' => $code, 'message' => 'OK' ), 'body' => wp_json_encode( $data ), 'headers' => array(), 'cookies' => array() );
};

$cleanup = static function () use ( &$created, &$created_user, &$advisor_id, $saved_globals, $original_post, $original_ip, $original_user, $email_filter, $http_filter ) {
	if ( $advisor_id ) {
		$linked = get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_elab_advisor', 'meta_value' => $advisor_id ) );
		$created = array_merge( $created, $linked );
	}
	foreach ( array_unique( $created ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $created_user && ! is_wp_error( $created_user ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $created_user );
	}
	if ( 'smoke-client' === get_option( ELAB_Google_Calendar::OPTION_CLIENT_ID ) ) {
		delete_option( ELAB_Google_Calendar::OPTION_CLIENT_ID );
		delete_option( ELAB_Google_Calendar::OPTION_CLIENT_SECRET );
	}
	if ( false === $saved_globals ) { delete_option( ELAB_Blackouts::OPTION ); } else { update_option( ELAB_Blackouts::OPTION, $saved_globals ); }
	$_POST = $original_post;
	if ( null === $original_ip ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $original_ip; }
	wp_set_current_user( $original_user );
	remove_filter( 'pre_wp_mail', $email_filter, 10 );
	remove_filter( 'pre_http_request', $http_filter, 10 );
};
register_shutdown_function( $cleanup );

try {
	add_filter( 'pre_wp_mail', $email_filter, 10, 2 );
	add_filter( 'pre_http_request', $http_filter, 10, 3 );
	$_SERVER['REMOTE_ADDR'] = '127.' . random_int( 1, 254 ) . '.' . random_int( 1, 254 ) . '.' . random_int( 1, 254 );
	delete_option( ELAB_Google_Calendar::OPTION_CLIENT_ID );
	delete_option( ELAB_Google_Calendar::OPTION_CLIENT_SECRET );
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	elab_smoke_assert( ! empty( $admins ), 'An administrator is available for admin flow checks' );
	wp_set_current_user( (int) $admins[0] );
	$advisor_id = wp_insert_post( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'publish', 'post_title' => 'Smoke advisor' ), true );
	elab_smoke_assert( ! is_wp_error( $advisor_id ), 'Temporary advisor created' );
	$created[] = $advisor_id;
	update_post_meta( $advisor_id, '_elab_email', 'advisor@example.test' );
	update_post_meta( $advisor_id, '_elab_timezone', 'Europe/Lisbon' );
	update_post_meta( $advisor_id, '_elab_duration', 30 );
	update_post_meta( $advisor_id, '_elab_availability', wp_json_encode( array_fill_keys( range( 1, 7 ), array( array( '09:00', '12:00' ) ) ) ) );
	$visitor_day = ( new DateTimeImmutable( 'next monday', new DateTimeZone( 'America/Bogota' ) ) )->format( 'Y-m-d' );
	$slots_request = new WP_REST_Request( 'GET', '/elab/v1/slots' );
	$slots_request->set_query_params( array( 'advisor' => $advisor_id, 'date' => $visitor_day, 'timezone' => 'America/Bogota' ) );
	$slots_response = rest_do_request( $slots_request );
	$slots = $slots_response->get_data();
	elab_smoke_assert( 200 === $slots_response->get_status() && count( $slots ) >= 3, 'Slots render in the visitor time zone' );
	elab_smoke_assert( $visitor_day === ( new DateTimeImmutable( $slots[0]['value'] ) )->setTimezone( new DateTimeZone( 'America/Bogota' ) )->format( 'Y-m-d' ), 'Slot date matches the visitor date' );
	$next_march = (int) gmdate( 'Y' ) + 1;
	$change = ( new DateTimeImmutable( $next_march . '-04-01', new DateTimeZone( 'Europe/Lisbon' ) ) )->modify( 'last sunday' );
	$dst_values = array();
	foreach ( array( 'before' => $change->modify( '-2 days' ), 'after' => $change->modify( '+1 day' ) ) as $period => $day ) {
		$request = new WP_REST_Request( 'GET', '/elab/v1/slots' );
		$request->set_query_params( array( 'advisor' => $advisor_id, 'date' => $day->format( 'Y-m-d' ), 'timezone' => 'America/Bogota' ) );
		$available = rest_do_request( $request )->get_data();
		$dst_values[ $period ] = $available[0]['value'] ?? '';
	}
	elab_smoke_assert( '09' === substr( $dst_values['before'], 11, 2 ) && '08' === substr( $dst_values['after'], 11, 2 ), 'Lisbon daylight saving shifts the UTC slot correctly' );
	$first = new DateTimeImmutable( $slots[0]['value'] );
	$advisor_day = $first->setTimezone( new DateTimeZone( 'Europe/Lisbon' ) );
	$global_rule = array( 'start' => $advisor_day->format( 'Y-m-d' ), 'end' => $advisor_day->format( 'Y-m-d' ), 'label' => 'Smoke closure', 'annual' => false );
	update_option( ELAB_Blackouts::OPTION, array( $global_rule ), false );
	elab_smoke_assert( ELAB_Blackouts::is_blocked( $advisor_id, $advisor_day ), 'Global closure blocks the advisor' );
	update_post_meta( $advisor_id, ELAB_Blackouts::META_GLOBAL_OVERRIDES, array( ELAB_Blackouts::rule_key( $global_rule ) ) );
	elab_smoke_assert( ! ELAB_Blackouts::is_blocked( $advisor_id, $advisor_day ), 'Advisor exception reopens the global closure' );
	update_post_meta( $advisor_id, ELAB_Blackouts::META, array( $global_rule ) );
	elab_smoke_assert( ELAB_Blackouts::is_blocked( $advisor_id, $advisor_day ), 'Personal time off overrides the exception' );
	delete_post_meta( $advisor_id, ELAB_Blackouts::META );
	delete_post_meta( $advisor_id, ELAB_Blackouts::META_GLOBAL_OVERRIDES );
	if ( false === $saved_globals ) { delete_option( ELAB_Blackouts::OPTION ); } else { update_option( ELAB_Blackouts::OPTION, $saved_globals ); }

	$booking_request = static function ( $start, $name ) use ( $advisor_id, $visitor_email ) {
		$request = new WP_REST_Request( 'POST', '/elab/v1/bookings' );
		$request->set_body_params( array( 'advisor' => $advisor_id, 'start' => $start, 'name' => $name, 'email' => $visitor_email, 'sector' => 'Private sector', 'timezone' => 'America/Bogota', 'message' => 'Private message', 'website' => '' ) );
		return rest_do_request( $request );
	};
	$manual = $booking_request( $slots[0]['value'], 'Manual visitor' );
	elab_smoke_assert( 201 === $manual->get_status() && empty( $manual->get_data()['calendar'] ), 'Without Google, booking stays a pending request' );
	$manual_ids = get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'private', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_elab_name', 'meta_value' => 'Manual visitor' ) );
	$manual_id = (int) $manual_ids[0];
	$created[] = $manual_id;
	elab_smoke_assert( 'pending' === get_post_meta( $manual_id, '_elab_status', true ), 'Manual request is private and pending' );
	$_POST = array( 'elab_booking_nonce' => wp_create_nonce( 'elab_booking_meta' ), 'elab_status' => 'confirmed' );
	( new ELAB_Booking_Admin() )->save( $manual_id );
	elab_smoke_assert( 'confirmed' === get_post_meta( $manual_id, '_elab_status', true ) && 'sent' === get_post_meta( $manual_id, ELAB_Notifications::META_VISITOR_MAIL, true ), 'Manual confirmation emails the visitor' );
	elab_smoke_assert( false !== strpos( end( $mail_log )['message'], 'calendar.google.com/calendar/render' ), 'Manual confirmation includes a calendar link' );
	$_POST = $original_post;
	$booking_type = get_post_type_object( ELAB_Post_Types::BOOKING );
	elab_smoke_assert( 'manage_options' === $booking_type->cap->read_private_posts, 'Private booking records are restricted to administrators' );
	$created_user = wp_create_user( 'elab_smoke_' . wp_generate_password( 8, false, false ), wp_generate_password( 32 ), 'elab-smoke-' . wp_generate_password( 8, false, false ) . '@example.test' );
	elab_smoke_assert( ! is_wp_error( $created_user ), 'Temporary non-admin user created' );
	( new WP_User( $created_user ) )->set_role( 'editor' );
	elab_smoke_assert( ! user_can( $created_user, 'read_post', $manual_id ) && ! user_can( $created_user, 'edit_post', $manual_id ), 'Editors cannot read or edit private bookings' );
	$storage = new ReflectionMethod( 'ELAB_Private_Attachments', 'safe_dir' );
	$storage->setAccessible( true );
	$private_dir = $storage->invoke( null );
	elab_smoke_assert( $private_dir && 0 !== strpos( $private_dir, trailingslashit( realpath( ABSPATH ) ) ), 'Attachments store outside the WordPress web root' );

	$credentials = ELAB_Google_Calendar::save_credentials( 'smoke-client', 'smoke-secret' );
	elab_smoke_assert( ! is_wp_error( $credentials ), 'Google credentials can be encrypted' );
	$encrypt = new ReflectionMethod( 'ELAB_Google_Calendar', 'encrypt' );
	$encrypt->setAccessible( true );
	update_post_meta( $advisor_id, ELAB_Google_Calendar::META_REFRESH, $encrypt->invoke( null, 'smoke-refresh' ) );
	update_post_meta( $advisor_id, ELAB_Google_Calendar::META_CLIENT_HASH, hash( 'sha256', 'smoke-client' ) );
	update_post_meta( $advisor_id, ELAB_Google_Calendar::META_ACCOUNT_EMAIL, 'advisor@example.test' );
	update_post_meta( $advisor_id, ELAB_Google_Calendar::META_ACCOUNT_SUB, 'smoke-sub' );
	elab_smoke_assert( ELAB_Google_Calendar::connected( $advisor_id ), 'Advisor connection validates identity and client' );
	$identity = new ReflectionMethod( 'ELAB_Google_Calendar', 'account_identity' );
	$identity->setAccessible( true );
	elab_smoke_assert( 'advisor@example.test' === $identity->invoke( null, 'smoke-access' )['email'], 'Google account identity is checked' );
	$google_slots = rest_do_request( $slots_request )->get_data();
	elab_smoke_assert( count( $google_slots ) >= 2, 'Google-connected slots remain available' );
	$target = $google_slots[0]['value'];
	$blocked_at = new DateTimeImmutable( $target );
	$busy_periods = array( array( 'start' => $blocked_at->format( DATE_RFC3339 ), 'end' => $blocked_at->modify( '+30 minutes' )->format( DATE_RFC3339 ) ) );
	$blocked_slots = rest_do_request( $slots_request )->get_data();
	elab_smoke_assert( ! in_array( $target, array_column( $blocked_slots, 'value' ), true ), 'Google busy time hides an overlapping slot' );
	$busy_periods = array();
	$google_booking = $booking_request( $target, 'Google visitor' );
	elab_smoke_assert( 201 === $google_booking->get_status() && ! empty( $google_booking->get_data()['calendar'] ), 'Google event confirms the booking' );
	$google_ids = get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'private', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_elab_name', 'meta_value' => 'Google visitor' ) );
	$google_id = (int) $google_ids[0];
	$created[] = $google_id;
	elab_smoke_assert( 'confirmed' === get_post_meta( $google_id, '_elab_status', true ) && get_post_meta( $google_id, ELAB_Google_Calendar::META_EVENT, true ), 'Private booking stores the confirmed Google event ID' );
	elab_smoke_assert( 'private' === $event_body['visibility'] && 'Private call' === $event_body['summary'] && ! isset( $event_body['description'] ) && ! isset( $event_body['attendees'][0]['displayName'] ), 'Calendar event excludes private booking details' );
	$_POST = array( 'elab_booking_nonce' => wp_create_nonce( 'elab_booking_meta' ), 'elab_status' => 'cancelled' );
	( new ELAB_Booking_Admin() )->save( $google_id );
	elab_smoke_assert( 'cancelled' === get_post_meta( $google_id, '_elab_status', true ) && ! get_post_meta( $google_id, ELAB_Google_Calendar::META_EVENT, true ), 'Cancellation removes the Google event and releases the booking' );
	$bot = new WP_REST_Request( 'POST', '/elab/v1/bookings' );
	$bot->set_body_params( array( 'website' => 'spam' ) );
	elab_smoke_assert( is_wp_error( ELAB_Booking_Guard::submission( $bot ) ), 'Hidden spam field rejects automated submissions' );
	$_SERVER['REMOTE_ADDR'] = '127.' . random_int( 1, 254 ) . '.' . random_int( 1, 254 ) . '.' . random_int( 1, 254 );
	$limit_request = new WP_REST_Request( 'POST', '/elab/v1/bookings' );
	for ( $attempt = 0; $attempt < 12; $attempt++ ) {
		elab_smoke_assert( true === ELAB_Booking_Guard::submission( $limit_request ), 'Booking attempt within the limit ' . ( $attempt + 1 ) );
	}
	elab_smoke_assert( is_wp_error( ELAB_Booking_Guard::submission( $limit_request ) ), 'Submission limit rejects the next request' );
} finally {
	$cleanup();
}
