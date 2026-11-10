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
$saved_defaults = get_option( ELAB_Booking_Defaults::OPTION, false );
$saved_retention = get_option( ELAB_Privacy::OPTION_RETENTION, false );
$original_user = get_current_user_id();
$original_post = $_POST;
$original_ip = $_SERVER['REMOTE_ADDR'] ?? null;
$created = array();
$created_user = 0;
$advisor_id = 0;
$second_id = 0;
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

$cleanup = static function () use ( &$created, &$created_user, &$advisor_id, &$second_id, $saved_globals, $saved_defaults, $saved_retention, $original_post, $original_ip, $original_user, $email_filter, $http_filter ) {
	foreach ( array_filter( array( $advisor_id, $second_id ) ) as $owner ) {
		$linked = get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_elab_advisor', 'meta_value' => $owner ) );
		$created = array_merge( $created, $linked );
		delete_option( 'elab_advisor_lock_' . $owner );
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
	if ( false === $saved_defaults ) { delete_option( ELAB_Booking_Defaults::OPTION ); } else { update_option( ELAB_Booking_Defaults::OPTION, $saved_defaults ); }
	if ( false === $saved_retention ) { delete_option( ELAB_Privacy::OPTION_RETENTION ); } else { update_option( ELAB_Privacy::OPTION_RETENTION, $saved_retention ); }
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
	elab_smoke_assert( current_user_can( 'manage_options' ), 'Booking capabilities do not block WordPress administrator settings' );
	$advisor_id = wp_insert_post( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'publish', 'post_title' => 'Smoke advisor' ), true );
	elab_smoke_assert( ! is_wp_error( $advisor_id ), 'Temporary advisor created' );
	$created[] = $advisor_id;
	update_post_meta( $advisor_id, '_elab_email', 'advisor@example.test' );
	update_post_meta( $advisor_id, '_elab_timezone', 'Europe/Lisbon' );
	update_post_meta( $advisor_id, '_elab_duration', 30 );
	update_post_meta( $advisor_id, '_elab_availability', wp_json_encode( array_fill_keys( range( 1, 7 ), array( array( '09:00', '12:00' ) ) ) ) );
	update_post_meta( $advisor_id, ELAB_Availability::META_NOTICE, 0 );
	update_post_meta( $advisor_id, ELAB_Availability::META_HORIZON, 365 );
	$visitor_day = ( new DateTimeImmutable( 'next monday', new DateTimeZone( 'America/Bogota' ) ) )->format( 'Y-m-d' );
	$slots_request = new WP_REST_Request( 'GET', '/elab/v1/slots' );
	$slots_request->set_query_params( array( 'advisor' => $advisor_id, 'date' => $visitor_day, 'timezone' => 'America/Bogota' ) );
	$slots_response = rest_do_request( $slots_request );
	$slots = $slots_response->get_data();
	elab_smoke_assert( 200 === $slots_response->get_status() && count( $slots ) >= 3, 'Slots render in the visitor time zone' );
	elab_smoke_assert( $visitor_day === ( new DateTimeImmutable( $slots[0]['value'] ) )->setTimezone( new DateTimeZone( 'America/Bogota' ) )->format( 'Y-m-d' ), 'Slot date matches the visitor date' );
	$change = ( new DateTimeImmutable( gmdate( 'Y' ) . '-04-01', new DateTimeZone( 'Europe/Lisbon' ) ) )->modify( 'last sunday' );
	if ( $change->modify( '-3 days' )->getTimestamp() < time() ) {
		$change = ( new DateTimeImmutable( ( (int) gmdate( 'Y' ) + 1 ) . '-04-01', new DateTimeZone( 'Europe/Lisbon' ) ) )->modify( 'last sunday' );
	}
	$dst_values = array();
	foreach ( array( 'before' => $change->modify( '-2 days' ), 'after' => $change->modify( '+1 day' ) ) as $period => $day ) {
		$request = new WP_REST_Request( 'GET', '/elab/v1/slots' );
		$request->set_query_params( array( 'advisor' => $advisor_id, 'date' => $day->format( 'Y-m-d' ), 'timezone' => 'America/Bogota' ) );
		$available = rest_do_request( $request )->get_data();
		$dst_values[ $period ] = $available[0]['value'] ?? '';
	}
	elab_smoke_assert( '09' === substr( $dst_values['before'], 11, 2 ) && '08' === substr( $dst_values['after'], 11, 2 ), 'Lisbon daylight saving shifts the UTC slot correctly' );

	$month_request = new WP_REST_Request( 'GET', '/elab/v1/availability' );
	$month_request->set_query_params( array( 'advisor' => $advisor_id, 'month' => substr( $visitor_day, 0, 7 ), 'timezone' => 'America/Bogota' ) );
	$month = rest_do_request( $month_request )->get_data();
	elab_smoke_assert( isset( $month['days'][ $visitor_day ] ) && count( $slots ) === $month['days'][ $visitor_day ], 'Month view counts the same open times as the day view' );
	elab_smoke_assert( ! empty( $slots[0]['advisors'] ) && $advisor_id === $slots[0]['advisors'][0]['id'], 'Each slot lists the advisor who is free at that time' );

	update_post_meta( $advisor_id, '_elab_slot_interval', 15 );
	$fine = array_column( rest_do_request( $slots_request )->get_data(), 'value' );
	elab_smoke_assert( count( $fine ) > count( $slots ) && 15 * MINUTE_IN_SECONDS === strtotime( $fine[1] ) - strtotime( $fine[0] ), 'Start times can be offered every 15 minutes for 30-minute calls' );
	elab_smoke_assert( strtotime( end( $fine ) ) + 30 * MINUTE_IN_SECONDS <= strtotime( end( $slots )['value'] ) + 30 * MINUTE_IN_SECONDS, 'A finer interval never lets a call run past the advisor’s hours' );
	delete_post_meta( $advisor_id, '_elab_slot_interval' );
	elab_smoke_assert( 0 === ELAB_Availability::rules( $advisor_id )['interval'] && count( $slots ) === count( rest_do_request( $slots_request )->get_data() ), 'Interval defaults to one start per call length' );
	elab_smoke_assert( 5 === ELAB_Booking_Defaults::clamp( 'interval', 3 ), 'A non-zero interval is at least 5 minutes' );
	$window_start = ( new DateTimeImmutable( $slots[0]['value'] ) )->getTimestamp();
	update_post_meta( $advisor_id, ELAB_Availability::META_HORIZON, 1 );
	$far = ELAB_Availability::advisor_starts( $advisor_id, time() + 3 * DAY_IN_SECONDS, time() + 10 * DAY_IN_SECONDS );
	elab_smoke_assert( array() === $far, 'Booking window hides times beyond the horizon' );
	update_post_meta( $advisor_id, ELAB_Availability::META_HORIZON, 365 );
	update_post_meta( $advisor_id, ELAB_Availability::META_NOTICE, 720 );
	$soon = ELAB_Availability::advisor_starts( $advisor_id, time(), time() + 40 * DAY_IN_SECONDS );
	elab_smoke_assert( ! $soon || min( $soon ) >= time() + 720 * HOUR_IN_SECONDS, 'Minimum notice hides times that start too soon' );
	update_post_meta( $advisor_id, ELAB_Availability::META_NOTICE, 0 );

	$sanitize = new ReflectionMethod( 'ELAB_Advisor_Admin', 'sanitize_availability' );
	$sanitize->setAccessible( true );
	$merged = $sanitize->invoke( new ELAB_Advisor_Admin(), array( 1 => array( array( '11:00', '14:00' ), array( '09:00', '12:00' ), array( '15:00', '16:00' ) ) ) );
	elab_smoke_assert( array( 1 => array( array( '09:00', '14:00' ), array( '15:00', '16:00' ) ) ) === $merged, 'Overlapping weekly ranges are merged' );
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

	$booking_request = static function ( $start, $name, $advisor = null ) use ( &$advisor_id, $visitor_email ) {
		$request = new WP_REST_Request( 'POST', '/elab/v1/bookings' );
		$email = str_replace( '@', '+' . strtolower( wp_generate_password( 6, false, false ) ) . '@', $visitor_email );
		$request->set_body_params( array( 'advisor' => null === $advisor ? $advisor_id : $advisor, 'start' => $start, 'name' => $name, 'email' => $email, 'sector' => 'Private sector', 'timezone' => 'America/Bogota', 'message' => 'Private message', 'website' => '' ) );
		return rest_do_request( $request );
	};
	$slot_values = static function () use ( $slots_request ) {
		return array_column( rest_do_request( $slots_request )->get_data(), 'value' );
	};
	$manual = $booking_request( $slots[0]['value'], 'Manual visitor' );
	elab_smoke_assert( 201 === $manual->get_status() && empty( $manual->get_data()['calendar'] ), 'Without Google, booking stays a pending request' );
	$manual_ids = get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'private', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_elab_name', 'meta_value' => 'Manual visitor' ) );
	$manual_id = (int) $manual_ids[0];
	$created[] = $manual_id;
	elab_smoke_assert( 'pending' === get_post_meta( $manual_id, '_elab_status', true ), 'Manual request is private and pending' );
	elab_smoke_assert( 30 === (int) get_post_meta( $manual_id, '_elab_duration', true ), 'Booking stores its own call length' );
	elab_smoke_assert( ELAB_Booking_Service::DEFAULT_LANGUAGE === get_post_meta( $manual_id, '_elab_language', true ) && 'es' === ELAB_Booking_Service::DEFAULT_LANGUAGE, 'Meeting language defaults to Spanish' );
	$bad_language = new WP_REST_Request( 'POST', '/elab/v1/bookings' );
	$bad_language->set_body_params( array( 'advisor' => $advisor_id, 'start' => $slots[2]['value'], 'name' => 'Language visitor', 'email' => 'lang-' . $visitor_email, 'timezone' => 'America/Bogota', 'language' => 'xx' ) );
	elab_smoke_assert( 400 === rest_do_request( $bad_language )->get_status(), 'Unknown meeting languages are rejected' );
	elab_smoke_assert( ! in_array( $slots[0]['value'], $slot_values(), true ), 'A booked time is no longer offered' );
	elab_smoke_assert( 409 === $booking_request( $slots[0]['value'], 'Double booking' )->get_status(), 'The same time cannot be booked twice' );
	update_post_meta( $advisor_id, '_elab_duration', 20 );
	$shifted = $slot_values();
	$first_end = ( new DateTimeImmutable( $slots[0]['value'] ) )->modify( '+30 minutes' );
	$overlapping = array_filter( $shifted, static function ( $value ) use ( $first_end ) { return new DateTimeImmutable( $value ) < $first_end; } );
	elab_smoke_assert( ! $overlapping, 'A shorter call length never overlaps an existing longer booking' );
	update_post_meta( $advisor_id, '_elab_duration', 30 );
	update_post_meta( $advisor_id, ELAB_Availability::META_BUFFER, 30 );
	elab_smoke_assert( ! in_array( $slots[1]['value'], $slot_values(), true ), 'Break between calls hides the next adjacent time' );
	update_post_meta( $advisor_id, ELAB_Availability::META_BUFFER, 0 );
	elab_smoke_assert( in_array( $slots[1]['value'], $slot_values(), true ), 'Adjacent time returns without a break' );
	delete_option( ELAB_Booking_Defaults::OPTION );
	delete_post_meta( $advisor_id, ELAB_Availability::META_BUFFER );
	elab_smoke_assert( 0 === ELAB_Availability::rules( $advisor_id )['buffer'], 'Break between calls defaults to 0' );
	update_option( ELAB_Booking_Defaults::OPTION, array( 'buffer' => 30 ) );
	elab_smoke_assert( 30 === ELAB_Availability::rules( $advisor_id )['buffer'] && ! in_array( $slots[1]['value'], $slot_values(), true ), 'An advisor without its own break follows the site default' );
	update_post_meta( $advisor_id, ELAB_Availability::META_BUFFER, 0 );
	elab_smoke_assert( 0 === ELAB_Availability::rules( $advisor_id )['buffer'] && in_array( $slots[1]['value'], $slot_values(), true ), 'An advisor’s own value overrides the site default' );
	update_option( ELAB_Booking_Defaults::OPTION, array( 'buffer' => 999, 'duration' => 5 ) );
	$clamped_defaults = ELAB_Booking_Defaults::get();
	elab_smoke_assert( 120 === $clamped_defaults['buffer'] && 15 === $clamped_defaults['duration'], 'Site defaults are kept within their limits' );
	delete_option( ELAB_Booking_Defaults::OPTION );
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
	$advisor_type = get_post_type_object( ELAB_Post_Types::ADVISOR );
	elab_smoke_assert( ! user_can( $created_user, 'edit_post', $advisor_id ) && ! user_can( $created_user, $advisor_type->cap->create_posts ) && ! user_can( $created_user, 'delete_post', $advisor_id ), 'Editors cannot create, edit or delete advisor profiles' );
	elab_smoke_assert( current_user_can( 'edit_post', $advisor_id ) && current_user_can( $advisor_type->cap->create_posts ), 'Administrators manage advisor profiles' );
	elab_smoke_assert( ! current_user_can( $booking_type->cap->create_posts ) && current_user_can( 'edit_post', $manual_id ), 'Bookings cannot be added by hand but stay editable' );
	elab_smoke_assert( '' !== ELAB_Advisor_Admin::account_problem( $created_user, $advisor_id ), 'Editor accounts cannot be linked to an advisor' );
	elab_smoke_assert( '' !== ELAB_Advisor_Admin::account_problem( get_current_user_id(), $advisor_id ), 'Administrator accounts cannot be linked to an advisor' );
	( new WP_User( $created_user ) )->set_role( 'subscriber' );
	elab_smoke_assert( '' === ELAB_Advisor_Admin::account_problem( $created_user, $advisor_id ), 'A free Subscriber account can be linked' );
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
	$cached_calls = count( $http_calls );
	elab_smoke_assert( in_array( $target, $slot_values(), true ) && count( $http_calls ) === $cached_calls, 'Google busy periods are served from a short cache' );
	ELAB_Google_Calendar::flush_busy_cache( $advisor_id );
	$blocked_slots = rest_do_request( $slots_request )->get_data();
	elab_smoke_assert( ! in_array( $target, array_column( $blocked_slots, 'value' ), true ), 'Google busy time hides an overlapping slot' );
	$booking_attempt = $booking_request( $target, 'Busy visitor' );
	elab_smoke_assert( 409 === $booking_attempt->get_status(), 'Booking re-checks Google live and rejects a busy time' );
	$busy_periods = array();
	ELAB_Google_Calendar::flush_busy_cache( $advisor_id );
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
	$_POST = $original_post;

	$second_id = wp_insert_post( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'publish', 'post_title' => 'Smoke advisor two' ), true );
	$created[] = $second_id;
	foreach ( array( '_elab_email', '_elab_timezone', '_elab_duration', '_elab_availability', ELAB_Availability::META_NOTICE, ELAB_Availability::META_HORIZON, ELAB_Google_Calendar::META_REFRESH, ELAB_Google_Calendar::META_CLIENT_HASH, ELAB_Google_Calendar::META_ACCOUNT_EMAIL, ELAB_Google_Calendar::META_ACCOUNT_SUB ) as $key ) {
		update_post_meta( $second_id, $key, get_post_meta( $advisor_id, $key, true ) );
	}
	$bookable = ELAB_Availability::bookable_advisors();
	elab_smoke_assert( in_array( $advisor_id, $bookable, true ) && in_array( $second_id, $bookable, true ), 'Connected advisors are bookable' );
	$shared_request = new WP_REST_Request( 'GET', '/elab/v1/slots' );
	$shared_request->set_query_params( array( 'date' => $visitor_day, 'timezone' => 'America/Bogota' ) );
	$shared = array_values( array_filter( rest_do_request( $shared_request )->get_data(), static function ( $slot ) use ( $advisor_id, $second_id ) {
		$ids = array_column( $slot['advisors'], 'id' );
		return in_array( $advisor_id, $ids, true ) && in_array( $second_id, $ids, true );
	} ) );
	elab_smoke_assert( ! empty( $shared ), 'Combined view lists both advisors on a shared time' );
	$shared_time = $shared[0]['value'];
	$first_auto = $booking_request( $shared_time, 'Auto one', '' );
	$second_auto = $booking_request( $shared_time, 'Auto two', '' );
	$third_auto = $booking_request( $shared_time, 'Auto three', '' );
	$assigned = array( $first_auto->get_data()['booking']['advisor'] ?? '', $second_auto->get_data()['booking']['advisor'] ?? '' );
	sort( $assigned );
	elab_smoke_assert( 201 === $first_auto->get_status() && 201 === $second_auto->get_status() && array( 'Smoke advisor', 'Smoke advisor two' ) === $assigned, 'First available assigns each advisor once on a shared time' );
	elab_smoke_assert( 409 === $third_auto->get_status(), 'A shared time closes once every advisor is booked' );
	update_post_meta( $second_id, '_elab_wp_user', $created_user );
	elab_smoke_assert( false !== strpos( ELAB_Advisor_Admin::account_problem( $created_user, $advisor_id ), 'Smoke advisor two' ), 'An account linked to one advisor cannot be linked to another' );
	delete_post_meta( $second_id, '_elab_wp_user' );
	elab_smoke_assert( array() === ELAB_Advisor_Admin::booking_issues( $advisor_id ), 'A complete connected advisor reports no booking issues' );

	$saved_query = $GLOBALS['wp_the_query'] ?? null;
	$saved_get = $_GET;
	set_current_screen( 'edit-' . ELAB_Post_Types::BOOKING );
	$list_query = static function ( $filters ) {
		$_GET = $filters;
		$query = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$query->query( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
		return $query->posts;
	};
	$confirmed = $list_query( array( 'elab_status' => 'confirmed', 'elab_advisor' => (string) $advisor_id ) );
	$starts = array_map( static function ( $id ) { return get_post_meta( $id, '_elab_start_utc', true ); }, $confirmed );
	$sorted = $starts;
	sort( $sorted );
	elab_smoke_assert( count( $confirmed ) >= 2 && $starts === $sorted, 'Booking list shows upcoming calls soonest first' );
	elab_smoke_assert( ! array_filter( $confirmed, static function ( $id ) use ( $advisor_id ) { return 'confirmed' !== get_post_meta( $id, '_elab_status', true ) || $advisor_id !== (int) get_post_meta( $id, '_elab_advisor', true ); } ), 'Booking list filters by status and advisor' );
	elab_smoke_assert( ! array_intersect( $confirmed, $list_query( array( 'elab_when' => 'past', 'elab_advisor' => (string) $advisor_id ) ) ), 'Upcoming calls are not listed as past' );
	$_GET = $saved_get;
	$GLOBALS['wp_the_query'] = $saved_query;
	unset( $GLOBALS['current_screen'] );

	ob_start();
	( new ELAB_Advisor_Admin() )->render_preview( get_post( $advisor_id ) );
	$preview = ob_get_clean();
	$preview_zone = ELAB_Availability::rules( $advisor_id )['timezone'];
	$preview_week = ELAB_Availability::advisor_starts( $advisor_id, time(), ( new DateTimeImmutable( 'now', $preview_zone ) )->setTime( 0, 0 )->modify( '+7 days' )->getTimestamp() );
	elab_smoke_assert( substr_count( $preview, 'elab-preview-time' ) === count( $preview_week ) && count( $preview_week ) > 0, 'Advisor preview lists the same times visitors can book' );
	ob_start();
	( new ELAB_Booking_Admin() )->render( get_post( $confirmed[0] ) );
	$detail = ob_get_clean();
	elab_smoke_assert( false !== strpos( $detail, 'elab-status--confirmed' ) && false !== strpos( $detail, 'America/Bogota' ) && false !== strpos( $detail, 'Europe/Lisbon' ), 'Booking detail shows status and both local times' );

	$_POST = array(
		'elab_nonce' => wp_create_nonce( ELAB_Advisor_Admin::NONCE_ACTION ),
		'elab_email' => 'advisor@example.test',
		'elab_timezone' => 'Europe/Lisbon',
		'elab_duration' => '200',
		'elab_notice_hours' => '',
		'elab_horizon_days' => '365',
		'elab_buffer_minutes' => '0',
		'elab_user_id' => (string) get_current_user_id(),
		'elab_day_active' => array( 1 => '1' ),
		'elab_availability' => array( 1 => array( array( 'start' => '10:00', 'end' => '09:00' ), array( 'start' => '09:00', 'end' => '12:00' ), array( 'start' => '11:00', 'end' => '13:00' ) ) ),
	);
	( new ELAB_Advisor_Admin() )->save( $advisor_id );
	$save_notes = get_transient( 'elab_advisor_saved_' . get_current_user_id() . '_' . $advisor_id );
	delete_transient( 'elab_advisor_saved_' . get_current_user_id() . '_' . $advisor_id );
	elab_smoke_assert( 180 === (int) get_post_meta( $advisor_id, '_elab_duration', true ) && '' === get_post_meta( $advisor_id, ELAB_Availability::META_NOTICE, true ), 'Saving clamps out-of-range values and empties fall back to defaults' );
	elab_smoke_assert( wp_json_encode( array( 1 => array( array( '09:00', '13:00' ) ) ) ) === get_post_meta( $advisor_id, '_elab_availability', true ), 'Saving drops reversed ranges and merges overlaps' );
	elab_smoke_assert( is_array( $save_notes ) && 4 <= count( $save_notes ), 'Saving explains each correction to the administrator' );
	elab_smoke_assert( 0 === (int) get_post_meta( $advisor_id, '_elab_wp_user', true ), 'An administrator account is not linked on save' );
	$_POST = $original_post;
	$privacy_email = 'privacy+' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test';
	$make_booking = static function ( $start, $status, $event = '' ) use ( &$created, $advisor_id, $privacy_email ) {
		$id = wp_insert_post( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'private', 'post_title' => 'Privacy check' ) );
		$created[] = $id;
		foreach ( array( '_elab_advisor' => $advisor_id, '_elab_status' => $status, '_elab_name' => 'Privacy visitor', '_elab_contact_email' => $privacy_email, '_elab_start_utc' => $start, '_elab_message' => 'Private note' ) as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		if ( $event ) {
			update_post_meta( $id, ELAB_Google_Calendar::META_EVENT, $event );
		}
		return $id;
	};
	$old = $make_booking( gmdate( 'Y-m-d H:i:s', strtotime( '-26 months' ) ), 'confirmed' );
	$recent = $make_booking( gmdate( 'Y-m-d H:i:s', strtotime( '-2 months' ) ), 'confirmed' );
	$future = $make_booking( gmdate( 'Y-m-d H:i:s', strtotime( '+20 days' ) ), 'confirmed', 'elabsmokeevent' );
	update_option( ELAB_Privacy::OPTION_RETENTION, 0 );
	elab_smoke_assert( 0 === ELAB_Privacy::cleanup() && get_post( $old ), 'Retention 0 keeps every booking' );
	update_option( ELAB_Privacy::OPTION_RETENTION, 24 );
	ELAB_Privacy::cleanup();
	elab_smoke_assert( ! get_post( $old ) && get_post( $recent ) && get_post( $future ), 'Retention deletes only bookings older than the period' );
	$export = ELAB_Privacy::export( $privacy_email );
	$exported = wp_list_pluck( $export['data'], 'item_id' );
	elab_smoke_assert( $export['done'] && in_array( 'elab-booking-' . $recent, $exported, true ) && in_array( 'Private note', array_column( $export['data'][0]['data'], 'value' ), true ), 'Personal data export includes the visitor’s bookings' );
	$erased = ELAB_Privacy::erase( $privacy_email );
	elab_smoke_assert( $erased['items_removed'] && $erased['items_retained'] && ! get_post( $recent ) && get_post( $future ) && $erased['done'], 'Erasure deletes past bookings and keeps an upcoming Google event until cancelled' );
	update_option( ELAB_Privacy::OPTION_RETENTION, 0 );

	$status_checks = ELAB_System_Status::checks();
	$sections = array_unique( array_column( $status_checks, 0 ) );
	elab_smoke_assert( ! array_diff( array( 'site', 'email', 'google', 'advisors', 'privacy' ), $sections ) && ! array_diff( array_unique( array_column( $status_checks, 2 ) ), array( 'ok', 'warn', 'error', 'info' ) ), 'System status covers site, email, Google, advisors and privacy' );
	$advisor_rows = array_filter( $status_checks, static function ( $check ) { return 'advisors' === $check[0] && 'Smoke advisor' === $check[1]; } );
	elab_smoke_assert( 1 === count( $advisor_rows ), 'System status lists each advisor' );

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
