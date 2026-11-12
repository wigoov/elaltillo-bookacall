<?php
/**
 * An advisor whose Google connection breaks is hidden and told at once; advisors connect from
 * an emailed link without a WordPress login; and what changes in Google (deleted, moved,
 * declined, accepted) reaches WordPress.
 */

use ELAB_Test as T;

/** Run a handler that ends in a redirect or wp_die(); return [kind, url-or-message]. */
$stop = static function ( callable $handler ) {
	$redirect = static function ( $location ) { throw new ELAB_Test_Failure( 'redirect:' . $location ); };
	$die = static function () { return static function ( $message ) { throw new ELAB_Test_Failure( 'die:' . ( is_string( $message ) ? $message : '' ) ); }; };
	// The die handler goes last, so WP-CLI's own one (which exits) never runs.
	add_filter( 'wp_redirect', $redirect, 1 );
	add_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	try {
		$handler();
		return array( 'none', '' );
	} catch ( ELAB_Test_Failure $stopped ) {
		list( $kind, $detail ) = explode( ':', $stopped->getMessage(), 2 );
		return array( $kind, $detail );
	} finally {
		remove_filter( 'wp_redirect', $redirect, 1 );
		remove_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	}
};
$token_of = static function ( $url ) {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	return $query['token'] ?? '';
};
$synced = static function () {
	$advisor = T::connect( T::advisor( array( '_elab_email' => 'advisor@example.test' ) ) );
	$booking = T::booking( $advisor, array( '_elab_contact_email' => 'visitor@example.test' ) );
	ELAB_Booking_Sync::create_for( $booking );
	return array( $advisor, $booking );
};

return array(
	'An advisor whose connection broke disappears from the calendar; their calls are kept' => function () {
		$advisor = T::connect( T::advisor() );
		$booking = T::booking( $advisor );
		T::check( ELAB_Availability::is_bookable( $advisor ), 'Bookable while connected' );
		update_post_meta( $advisor, ELAB_Google_Calendar::META_REVOKED, time() );
		T::check( ! ELAB_Availability::is_bookable( $advisor ), 'Hidden once the connection broke' );
		T::same( array(), T::slot_values( $advisor ), 'No times offered' );
		T::same( 'confirmed', get_post_meta( $booking, '_elab_status', true ), 'Existing call kept' );
	},

	'The 15-minute check notices access removed in Google and emails a no-login reconnect link' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'advisor@example.test' ) ) );
		set_transient( 'elab_google_access_' . $advisor, 'stale-token', HOUR_IN_SECONDS );
		T::$unauthorized_once = true;
		T::$revoke = true;
		ELAB_Google_Calendar::watch_connections();
		T::check( ELAB_Google_Calendar::revoked( $advisor ), 'Marked as broken' );
		T::check( ! ELAB_Availability::is_bookable( $advisor ), 'Hidden at once' );
		$mail = T::mails_to( 'advisor@example.test' );
		T::same( 1, count( $mail ), 'Advisor emailed once' );
		T::contains( 'elab_google_invite', $mail[0]['message'], 'Reconnect link needs no login' );
		T::contains( 'do not appear in the booking calendar', $mail[0]['message'], 'Explains they are hidden' );
		ELAB_Google_Calendar::watch_connections();
		T::same( 1, count( T::mails_to( 'advisor@example.test' ) ), 'Not emailed again' );
	},

	'The cron runs every 15 minutes and also watches connections' => function () {
		ELAB_Booking_Sync::schedule();
		T::same( 'elab_quarter_hour', wp_get_schedule( ELAB_Booking_Sync::CRON ), 'Every 15 minutes' );
		T::check( false !== has_action( ELAB_Booking_Sync::CRON, array( 'ELAB_Google_Calendar', 'watch_connections' ) ), 'Connections checked' );
	},

	'The connection link is personal, reused while valid, and refused when wrong or expired' => function () use ( $token_of ) {
		T::configure_google();
		$advisor = T::advisor( array( '_elab_email' => 'advisor@example.test' ) );
		$url = ELAB_Google_Calendar::invite_url( $advisor );
		$token = $token_of( $url );
		T::check( strlen( $token ) >= 40, 'Long random token' );
		T::same( $url, ELAB_Google_Calendar::invite_url( $advisor ), 'Same link in every email' );
		T::check( ELAB_Google_Calendar::valid_invite( $advisor, $token ), 'Valid' );
		T::check( ! ELAB_Google_Calendar::valid_invite( $advisor, 'forged' ), 'Forged token refused' );
		T::check( ! ELAB_Google_Calendar::valid_invite( T::advisor(), $token ), 'Not valid for another advisor' );
		T::lacks( $token, wp_json_encode( get_post_meta( $advisor, ELAB_Google_Calendar::META_INVITE, true ) ), 'Stored encrypted' );
		update_post_meta( $advisor, ELAB_Google_Calendar::META_INVITE, array_merge( get_post_meta( $advisor, ELAB_Google_Calendar::META_INVITE, true ), array( 'expires' => time() - 1 ) ) );
		T::check( ! ELAB_Google_Calendar::valid_invite( $advisor, $token ), 'Expired' );
		T::check( $token !== $token_of( ELAB_Google_Calendar::invite_url( $advisor ) ), 'A new link replaces an expired one' );
	},

	'An administrator emails the connection link from the advisor screen' => function () use ( $stop ) {
		T::configure_google();
		$advisor = T::advisor( array( '_elab_email' => 'advisor@example.test' ) );
		$html = T::render( static function () use ( $advisor ) { ( new ELAB_Advisor_Admin() )->render( get_post( $advisor ) ); } );
		T::contains( 'Email a connection link', $html, 'Button shown while not connected' );
		$_GET['advisor'] = (string) $advisor;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'elab_google_send_invite_' . $advisor );
		$result = $stop( static function () { ( new ELAB_Google_Calendar() )->send_invite(); } );
		T::same( 'redirect', $result[0], 'Back to the advisor' );
		T::contains( 'invite_sent', $result[1], 'Reports success' );
		$mail = T::mails_to( 'advisor@example.test' );
		T::same( 1, count( $mail ), 'One email to the advisor' );
		T::contains( 'elab_google_invite', $mail[0]['message'], 'Carries the link' );
		T::contains( 'cannot see or change any other calendar', $mail[0]['message'], 'Explains the limited access' );
	},

	'A subscriber cannot send connection links' => function () use ( $stop ) {
		T::configure_google();
		$advisor = T::advisor( array( '_elab_email' => 'advisor@example.test' ) );
		wp_set_current_user( T::user( 'subscriber' ) );
		$_GET['advisor'] = (string) $advisor;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'elab_google_send_invite_' . $advisor );
		T::same( 'die', $stop( static function () { ( new ELAB_Google_Calendar() )->send_invite(); } )[0], 'Refused' );
		T::same( array(), T::$mail, 'Nothing sent' );
	},

	'From the link, a visitor without a login reaches Google; then only the advisor’s own account connects' => function () use ( $stop, $token_of ) {
		T::configure_google();
		$advisor = T::advisor( array( '_elab_email' => 'advisor@example.test' ) );
		$token = $token_of( ELAB_Google_Calendar::invite_url( $advisor ) );
		wp_set_current_user( 0 );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['advisor'] = (string) $advisor;
		$_REQUEST['token'] = $token;
		try {
			$result = $stop( static function () { ( new ELAB_Google_Calendar() )->invite(); } );
		} finally {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			unset( $_REQUEST['advisor'], $_REQUEST['token'] );
		}
		T::same( 'redirect', $result[0], 'Sent to Google' );
		T::contains( 'accounts.google.com', $result[1], 'Google consent' );
		T::contains( 'calendar.app.created', $result[1], 'Only the limited permission' );
		parse_str( (string) wp_parse_url( $result[1], PHP_URL_QUERY ), $query );
		$state = get_transient( 'elab_google_state_' . hash( 'sha256', $query['state'] ) );
		T::check( ! empty( $state['invite'] ) && 0 === $state['user'], 'Marked as an emailed-link flow' );

		$_GET = array( 'state' => $query['state'], 'code' => 'test-code' );
		$result = $stop( static function () { ( new ELAB_Google_Calendar() )->callback(); } );
		T::same( 'redirect', $result[0], 'Finished' );
		T::contains( 'done=1', $result[1], 'Public confirmation page, not wp-admin' );
		T::check( ELAB_Google_Calendar::connected( $advisor ), 'Connected without a login' );
		T::same( '', get_post_meta( $advisor, ELAB_Google_Calendar::META_INVITE, true ), 'The link works only once' );
		T::check( ! ELAB_Google_Calendar::valid_invite( $advisor, $token ), 'Used link refused' );
	},

	'From the link, another Google account is refused' => function () use ( $stop, $token_of ) {
		T::configure_google();
		$advisor = T::advisor( array( '_elab_email' => 'advisor@example.test' ) );
		$token = $token_of( ELAB_Google_Calendar::invite_url( $advisor ) );
		wp_set_current_user( 0 );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['advisor'] = (string) $advisor;
		$_REQUEST['token'] = $token;
		try {
			$result = $stop( static function () { ( new ELAB_Google_Calendar() )->invite(); } );
		} finally {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			unset( $_REQUEST['advisor'], $_REQUEST['token'] );
		}
		parse_str( (string) wp_parse_url( $result[1], PHP_URL_QUERY ), $query );
		T::$identity = array( 'sub' => 'other', 'email' => 'someone.else@example.test', 'email_verified' => true );
		$_GET = array( 'state' => $query['state'], 'code' => 'test-code' );
		$result = $stop( static function () { ( new ELAB_Google_Calendar() )->callback(); } );
		T::same( 'die', $result[0], 'Refused' );
		T::check( ! ELAB_Google_Calendar::linked( $advisor ), 'Nothing saved' );
	},

	'A forged callback without a valid state is refused' => function () use ( $stop ) {
		T::configure_google();
		$advisor = T::advisor( array( '_elab_email' => 'advisor@example.test' ) );
		wp_set_current_user( 0 );
		$_GET = array( 'state' => 'made-up', 'code' => 'test-code' );
		T::same( 'die', $stop( static function () { ( new ELAB_Google_Calendar() )->callback(); } )[0], 'Refused' );
		T::check( ! ELAB_Google_Calendar::linked( $advisor ), 'Nothing saved' );
	},

	'Moved in Google: the call takes the new time and the visitor is emailed' => function () use ( $synced ) {
		list( , $booking ) = $synced();
		$new = strtotime( get_post_meta( $booking, '_elab_start_utc', true ) . ' UTC' ) + 2 * HOUR_IN_SECONDS;
		T::$event_get_extra = array( 'start' => array( 'dateTime' => gmdate( 'c', $new ) ), 'end' => array( 'dateTime' => gmdate( 'c', $new + 45 * MINUTE_IN_SECONDS ) ) );
		T::same( 1, ELAB_Booking_Sync::run()['moved'], 'Noticed' );
		T::same( gmdate( 'Y-m-d H:i:s', $new ), get_post_meta( $booking, '_elab_start_utc', true ), 'New time stored' );
		T::same( 45, ELAB_Booking_Admin::duration( $booking ), 'New length stored' );
		T::same( 'confirmed', get_post_meta( $booking, '_elab_status', true ), 'Still confirmed' );
		T::same( 1, count( T::mail_with_subject( 'Your call has a new time' ) ), 'Visitor emailed' );
		T::same( 0, ELAB_Booking_Sync::reconcile()['moved'], 'Only once' );
	},

	'Declined by the visitor: the call is cancelled, the event removed and the advisor told' => function () use ( $synced ) {
		list( $advisor, $booking ) = $synced();
		T::$event_get_extra = array( 'attendees' => array( array( 'email' => 'Visitor@Example.test', 'responseStatus' => 'declined' ) ) );
		T::same( 1, ELAB_Booking_Sync::run()['declined'], 'Noticed' );
		T::same( 'cancelled', get_post_meta( $booking, '_elab_status', true ), 'Cancelled' );
		T::same( 'declined', get_post_meta( $booking, '_elab_cancelled_by', true ), 'Recorded why' );
		T::same( 1, count( T::requests( '#^DELETE #' ) ), 'Event removed from the advisor’s calendar' );
		T::contains( 'declined the calendar invitation', wp_json_encode( T::mails_to( 'advisor@example.test' ) ), 'Advisor told' );
		T::same( 0, count( T::mail_with_subject( 'Your call was cancelled' ) ), 'The visitor who declined is not emailed again' );
	},

	'Accepted by the visitor: shown on the booking, nothing else changes' => function () use ( $synced ) {
		list( , $booking ) = $synced();
		T::$event_get_extra = array( 'attendees' => array( array( 'email' => 'visitor@example.test', 'responseStatus' => 'accepted' ) ) );
		$done = ELAB_Booking_Sync::run();
		T::same( array( 0, 0, 0 ), array( $done['cancelled'], $done['declined'], $done['moved'] ), 'No change' );
		T::same( 'accepted', get_post_meta( $booking, ELAB_Booking_Manage::META_RESPONSE, true ), 'Answer stored' );
		$html = T::render( static function () use ( $booking ) { ( new ELAB_Booking_Admin() )->render( get_post( $booking ) ); } );
		T::contains( 'Accepted by the visitor', $html, 'Shown on the record' );
		T::same( array(), T::$mail, 'No email' );
	},
);
