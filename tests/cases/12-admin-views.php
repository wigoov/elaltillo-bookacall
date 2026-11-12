<?php
/** Admin screens: advisor profile, advisor list, bookings list and record, settings, system status, permissions. */

use ELAB_Test as T;

$advisor_screen = static function ( $advisor ) {
	return T::render( static function () use ( $advisor ) { ( new ELAB_Advisor_Admin() )->render( get_post( $advisor ) ); } );
};
$column = static function ( $object, $column, $id ) {
	return T::render( static function () use ( $object, $column, $id ) { $object->render_column( $column, $id ); } );
};

return array(
	'Advisor profile, Google not set up: points to the settings' => function () use ( $advisor_screen ) {
		$html = $advisor_screen( T::advisor() );
		T::contains( 'Not set up', $html, 'Badge' );
		T::contains( 'Set up Google Calendar', $html, 'Button' );
	},

	'Advisor profile, never connected: Connect button' => function () use ( $advisor_screen ) {
		T::configure_google();
		$html = $advisor_screen( T::advisor() );
		T::contains( 'Connect Google Calendar', $html, 'Connect button' );
		T::contains( 'Not shown in the booking calendar yet', $html, 'Explains why hidden' );
	},

	'Advisor profile, connected: badge, account and Disconnect' => function () use ( $advisor_screen ) {
		$advisor = T::connect( T::advisor() );
		$html = $advisor_screen( $advisor );
		T::contains( 'Connected as ' . get_post_meta( $advisor, '_elab_email', true ), $html, 'Account shown' );
		T::contains( '>Disconnect<', $html, 'Disconnect button' );
		T::contains( 'Bookable: this advisor’s times appear', $html, 'Bookable notice' );
	},

	'Advisor profile, old permission or revoked: Reconnect, still bookable, waiting count' => function () use ( $advisor_screen ) {
		$advisor = T::connect( T::advisor(), false );
		$html = $advisor_screen( $advisor );
		T::contains( 'Connected with the old, broader permission', $html, 'Old permission explained' );
		T::contains( 'Reconnect Google Calendar', $html, 'Reconnect button' );
		T::connect( $advisor );
		update_post_meta( $advisor, ELAB_Google_Calendar::META_REVOKED, time() );
		ELAB_Booking_Sync::queue_create( T::booking( $advisor ), new WP_Error( 'x' ) );
		$html = $advisor_screen( $advisor );
		T::contains( 'Google access was removed or expired', $html, 'Revoked explained' );
		T::contains( '1 call is saved and waiting', $html, 'Waiting count' );
		T::contains( 'Bookable.', $html, 'Still bookable' );
	},

	'Advisor profile has the email-language choice, Spanish by default' => function () use ( $advisor_screen ) {
		$advisor = T::advisor();
		delete_post_meta( $advisor, ELAB_Notifications::META_ADVISOR_LOCALE );
		$html = $advisor_screen( $advisor );
		T::contains( 'name="elab_email_locale"', $html, 'Field' );
		if ( in_array( 'es_CO', get_available_languages(), true ) ) {
			T::check( (bool) preg_match( '/value="es_CO"[^>]*selected/', $html ), 'Spanish preselected' );
		}
	},

	'Advisor list: bookable, hidden with reasons, or bookable with a reconnect warning' => function () use ( $column ) {
		$list = new ELAB_Advisor_Admin();
		T::contains( 'Bookable', $column( $list, 'elab_bookable', T::advisor() ), 'Bookable' );
		T::configure_google();
		T::contains( 'Hidden', $column( $list, 'elab_bookable', T::advisor() ), 'Hidden when not connected' );
		$broken = T::connect( T::advisor(), false );
		T::contains( 'Google needs to be reconnected', $column( $list, 'elab_bookable', $broken ), 'Warning' );
		T::contains( 'Reconnect required', $column( $list, 'elab_google', $broken ), 'Google column' );
	},

	'Advisor preview lists the same times visitors can book' => function () {
		$advisor = T::advisor();
		$html = T::render( static function () use ( $advisor ) { ( new ELAB_Advisor_Admin() )->render_preview( get_post( $advisor ) ); } );
		$zone = ELAB_Availability::rules( $advisor )['timezone'];
		$week = ELAB_Availability::advisor_starts( $advisor, time(), ( new DateTimeImmutable( 'now', $zone ) )->setTime( 0, 0 )->modify( '+7 days' )->getTimestamp() );
		T::same( count( $week ), substr_count( $html, 'elab-preview-time' ), 'Same count' );
	},

	'Saving an advisor clamps values, merges ranges and explains each correction' => function () {
		$advisor = T::advisor();
		$_POST = array(
			'elab_nonce'          => wp_create_nonce( ELAB_Advisor_Admin::NONCE_ACTION ),
			'elab_email'          => 'advisor@example.test',
			'elab_timezone'       => 'Europe/Lisbon',
			'elab_duration'       => '200',
			'elab_notice_hours'   => '',
			'elab_horizon_days'   => '365',
			'elab_buffer_minutes' => '0',
			'elab_user_id'        => (string) get_current_user_id(),
			'elab_day_active'     => array( 1 => '1' ),
			'elab_availability'   => array( 1 => array( array( 'start' => '10:00', 'end' => '09:00' ), array( 'start' => '09:00', 'end' => '12:00' ), array( 'start' => '11:00', 'end' => '13:00' ) ) ),
		);
		( new ELAB_Advisor_Admin() )->save( $advisor );
		$notes = get_transient( 'elab_advisor_saved_' . get_current_user_id() . '_' . $advisor );
		T::same( 180, (int) get_post_meta( $advisor, '_elab_duration', true ), 'Length clamped to 180' );
		T::same( wp_json_encode( array( 1 => array( array( '09:00', '13:00' ) ) ) ), get_post_meta( $advisor, '_elab_availability', true ), 'Reversed dropped, overlaps merged' );
		T::check( is_array( $notes ) && count( $notes ) >= 3, 'Corrections explained' );
		T::same( 0, (int) get_post_meta( $advisor, '_elab_wp_user', true ), 'Administrator account not linked' );
	},

	'Only free Subscriber accounts can be linked to an advisor' => function () {
		$advisor = T::advisor();
		$editor = T::user( 'editor' );
		$subscriber = T::user( 'subscriber' );
		T::check( '' !== ELAB_Advisor_Admin::account_problem( $editor, $advisor ), 'Editor refused' );
		T::check( '' !== ELAB_Advisor_Admin::account_problem( get_current_user_id(), $advisor ), 'Administrator refused' );
		T::same( '', ELAB_Advisor_Admin::account_problem( $subscriber, $advisor ), 'Subscriber accepted' );
		$other = T::advisor( array( '_elab_wp_user' => $subscriber ), 'Other advisor' );
		T::contains( 'Other advisor', ELAB_Advisor_Admin::account_problem( $subscriber, $advisor ), 'Already linked elsewhere' );
	},

	'Bookings list: status badge with the Google state below it' => function () use ( $column ) {
		$booking = T::booking( T::advisor() );
		ELAB_Booking_Sync::queue_create( $booking, new WP_Error( 'x' ) );
		$html = $column( new ELAB_Booking_List_Admin(), 'elab_status', $booking );
		T::contains( 'elab-status--confirmed', $html, 'Badge' );
		T::contains( 'Waiting for Google', $html, 'Google state' );
	},

	'Bookings list filters by status and advisor and shows upcoming calls soonest first' => function () {
		$advisor = T::advisor();
		$later = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:00:00', strtotime( '+9 days' ) ) ) );
		$sooner = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:00:00', strtotime( '+2 days' ) ) ) );
		T::booking( $advisor, array( '_elab_status' => 'cancelled' ) );
		set_current_screen( 'edit-' . ELAB_Post_Types::BOOKING );
		$_GET = array( 'elab_status' => 'confirmed', 'elab_advisor' => (string) $advisor );
		$query = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$query->query( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
		unset( $GLOBALS['current_screen'] );
		T::same( array( $sooner, $later ), array_map( 'intval', $query->posts ), 'Confirmed only, soonest first' );
	},

	'Booking record: status, both local times, guests and the Google state' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor, array( '_elab_guests' => array( 'guest@example.test' ) ) );
		ELAB_Booking_Sync::create_for( $booking );
		$html = T::render( static function () use ( $booking ) { ( new ELAB_Booking_Admin() )->render( get_post( $booking ) ); } );
		foreach ( array( 'elab-status--confirmed', 'America/Bogota', 'Europe/Lisbon', 'guest@example.test', 'In the advisor’s Google Calendar', 'name="elab_status"' ) as $piece ) {
			T::contains( $piece, $html, 'Shows ' . $piece );
		}
	},

	'Booking record without guests leaves the row out' => function () {
		$booking = T::booking( T::advisor() );
		$html = T::render( static function () use ( $booking ) { ( new ELAB_Booking_Admin() )->render( get_post( $booking ) ); } );
		T::lacks( '<dt>Guests</dt>', $html, 'No empty guests row' );
	},

	'Settings, Google tab: least-privilege texts, waiting calls and the test button' => function () {
		T::configure_google();
		$_GET['tab'] = 'google';
		$html = T::render( static function () { T::call( new ELAB_Google_Settings_Admin(), 'render_google' ); } );
		foreach ( array( 'calendar.app.created', 'El Altillo · Citas', 'Calls waiting for Google', 'Test connected calendars' ) as $piece ) {
			T::contains( $piece, $html, 'Shows ' . $piece );
		}
		T::lacks( 'calendar.freebusy', $html, 'No broader scope mentioned' );
	},

	'System status covers every area and lists each advisor' => function () {
		$advisor = T::advisor( array(), 'Status advisor' );
		$checks = ELAB_System_Status::checks();
		T::same( array(), array_diff( array( 'site', 'email', 'google', 'advisors', 'privacy' ), array_unique( array_column( $checks, 0 ) ) ), 'All sections' );
		T::same( array(), array_diff( array_unique( array_column( $checks, 2 ) ), array( 'ok', 'warn', 'error', 'info' ) ), 'Known states only' );
		T::same( 1, count( array_filter( $checks, static function ( $check ) { return 'advisors' === $check[0] && 'Status advisor' === $check[1]; } ) ), 'Advisor listed' );
	},

	'Permissions: editors cannot see bookings or change advisors' => function () {
		$booking = T::booking( T::advisor() );
		$editor = T::user( 'editor' );
		T::check( ! user_can( $editor, 'read_post', $booking ) && ! user_can( $editor, 'edit_post', $booking ), 'Bookings private to administrators' );
		$advisor_type = get_post_type_object( ELAB_Post_Types::ADVISOR );
		T::check( ! user_can( $editor, $advisor_type->cap->create_posts ), 'Editors cannot create advisors' );
		T::check( ! current_user_can( get_post_type_object( ELAB_Post_Types::BOOKING )->cap->create_posts ), 'Bookings are never added by hand' );
	},

	'Advisor languages: shown, saved, and never left empty' => function () use ( $advisor_screen ) {
		$advisor = T::advisor();
		T::contains( 'name="elab_languages[]"', $advisor_screen( $advisor ), 'Field shown' );
		$base = array( 'elab_nonce' => wp_create_nonce( ELAB_Advisor_Admin::NONCE_ACTION ), 'elab_email' => 'x@example.test', 'elab_timezone' => 'Europe/Lisbon', 'elab_day_active' => array( 1 => '1' ), 'elab_availability' => array( 1 => array( array( 'start' => '09:00', 'end' => '12:00' ) ) ) );
		$_POST = $base + array( 'elab_languages' => array( 'en', 'xx' ) );
		( new ELAB_Advisor_Admin() )->save( $advisor );
		T::same( array( 'en' ), ELAB_Availability::languages( $advisor ), 'Only English saved, unknown dropped' );
		$_POST = $base;
		( new ELAB_Advisor_Admin() )->save( $advisor );
		T::same( array_keys( ELAB_Booking_Service::languages() ), ELAB_Availability::languages( $advisor ), 'None checked keeps every language' );
		$notes = get_transient( 'elab_advisor_saved_' . get_current_user_id() . '_' . $advisor );
		T::check( is_array( $notes ) && (bool) preg_grep( '/meeting language/', $notes ), 'The admin is told why' );
	},

	'The Trash shows every date by default, so no trashed booking is hidden' => function () {
		$advisor = T::advisor();
		$past = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( '-3 days' ) ) ) );
		wp_trash_post( $past );
		$_GET['post_status'] = 'trash';
		$html = T::render( static function () { ( new ELAB_Booking_List_Admin() )->filters( ELAB_Post_Types::BOOKING ); } );
		T::check( (bool) preg_match( '/value="all"\s+selected/', $html ), 'All dates selected in the Trash' );
		unset( $_GET['post_status'] );
		$html = T::render( static function () { ( new ELAB_Booking_List_Admin() )->filters( ELAB_Post_Types::BOOKING ); } );
		T::check( (bool) preg_match( '/value="upcoming"\s+selected/', $html ), 'Upcoming stays the default elsewhere' );
	},
);
