<?php
/** Google connection: least privilege, the site's own calendar, connection states. */

use ELAB_Test as T;

return array(
	'Only the app-created-calendars permission is requested' => function () {
		T::same( 'https://www.googleapis.com/auth/calendar.app.created', ELAB_Google_Calendar::SCOPE, 'Calendar scope' );
	},

	'Connect sends the advisor to Google with that scope, PKCE and their email' => function () {
		$advisor = T::advisor( array( '_elab_email' => 'advisor@example.test' ) );
		T::configure_google();
		$location = '';
		$catch = static function ( $url ) use ( &$location ) {
			$location = $url;
			throw new ELAB_Test_Failure( 'redirected' );
		};
		add_filter( 'wp_redirect', $catch, 1 );
		$_GET['advisor'] = (string) $advisor;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'elab_google_connect_' . $advisor );
		try {
			( new ELAB_Google_Calendar() )->connect();
		} catch ( ELAB_Test_Failure $redirect ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		} finally {
			remove_filter( 'wp_redirect', $catch, 1 );
			unset( $_REQUEST['_wpnonce'] );
		}
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		T::contains( 'accounts.google.com', $location, 'Goes to Google' );
		T::same( 'openid email ' . ELAB_Google_Calendar::SCOPE . ' ' . ELAB_Google_Calendar::SETTINGS_SCOPE, $query['scope'] ?? '', 'Exactly these scopes: own calendar and read-only settings' );
		T::same( 'S256', $query['code_challenge_method'] ?? '', 'PKCE S256' );
		T::same( 'advisor@example.test', $query['login_hint'] ?? '', 'Opens on the advisor’s account' );
		T::check( ! empty( $query['state'] ), 'Single-use state' );
	},

	'PKCE pairs are valid S256' => function () {
		$pkce = ELAB_Google_Calendar::pkce_pair();
		T::check( (bool) preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $pkce['verifier'] ), 'Verifier format' );
		T::same( rtrim( strtr( base64_encode( hash( 'sha256', $pkce['verifier'], true ) ), '+/', '-_' ), '=' ), $pkce['challenge'], 'Challenge matches' );
	},

	'Connecting creates the “El Altillo · Citas” calendar in the advisor’s time zone' => function () {
		$advisor = T::advisor( array( '_elab_timezone' => 'America/Bogota' ) );
		$calendar = ELAB_Google_Calendar::ensure_calendar( $advisor, 'test-access' );
		T::same( 'test-cal-1@group.calendar.google.com', $calendar, 'Calendar ID returned' );
		T::same( ELAB_Google_Calendar::CALENDAR_NAME, T::$calendar_posts[0]['summary'], 'Calendar name' );
		T::same( 'America/Bogota', T::$calendar_posts[0]['timeZone'], 'Calendar time zone' );
	},

	'Reconnecting reuses the calendar; a deleted one is created again' => function () {
		$advisor = T::advisor();
		T::same( 'kept@group.calendar.google.com', ELAB_Google_Calendar::ensure_calendar( $advisor, 'test-access', 'kept@group.calendar.google.com' ), 'Existing calendar reused' );
		T::same( 0, count( T::$calendar_posts ), 'No new calendar' );
		T::same( 'test-cal-1@group.calendar.google.com', ELAB_Google_Calendar::ensure_calendar( $advisor, 'test-access', 'gone@group.calendar.google.com' ), 'Missing calendar replaced' );
	},

	'Connected means: same OAuth client, verified account and the site’s calendar' => function () {
		$advisor = T::connect( T::advisor() );
		T::check( ELAB_Google_Calendar::connected( $advisor ), 'Connected' );
		update_post_meta( $advisor, ELAB_Google_Calendar::META_CLIENT_HASH, hash( 'sha256', 'another-client' ) );
		T::check( ! ELAB_Google_Calendar::connected( $advisor ), 'A different OAuth client disconnects' );
		T::connect( $advisor );
		delete_post_meta( $advisor, ELAB_Google_Calendar::META_CALENDAR );
		T::check( ! ELAB_Google_Calendar::connected( $advisor ) && ELAB_Google_Calendar::linked( $advisor ), 'Old permission without own calendar: linked, not connected' );
	},

	'The Google account must be verified' => function () {
		T::same( 'advisor@example.test', T::call( 'ELAB_Google_Calendar', 'account_identity', 'test-access' )['email'], 'Verified account accepted' );
		T::$identity['email_verified'] = false;
		T::error( 'google_identity_failed', T::call( 'ELAB_Google_Calendar', 'account_identity', 'test-access' ), 'Unverified email refused' );
	},

	'The live check only asks for the site’s own calendar' => function () {
		$advisor = T::connect( T::advisor() );
		T::same( true, ELAB_Google_Calendar::check( $advisor ), 'Check passes' );
		T::same( 1, count( T::requests( '#^GET https://www\.googleapis\.com/calendar/v3/calendars/test-cal%40group\.calendar\.google\.com$#' ) ), 'One GET of that calendar' );
		delete_post_meta( $advisor, ELAB_Google_Calendar::META_CALENDAR );
		T::error( 'google_not_ready', ELAB_Google_Calendar::check( $advisor ), 'No calendar, no check' );
	},

	'A revoked grant marks the advisor and emails advisor and admin once' => function () {
		$advisor = T::connect( T::advisor() );
		T::$revoke = true;
		$booking = T::booking( $advisor );
		T::error( null, ELAB_Booking_Sync::create_for( $booking ), 'Event cannot be created' );
		T::check( ELAB_Google_Calendar::revoked( $advisor ) && ! ELAB_Google_Calendar::connected( $advisor ), 'Advisor marked to reconnect' );
		ELAB_Notifications::send_queued_connection_lost(); // What happens when the request ends.
		$lost = array_filter( T::$mail, static function ( $mail ) { return in_array( $mail['subject'], array( 'Reconnect your Google Calendar', 'Test advisor needs to reconnect Google Calendar' ), true ); } );
		T::check( count( $lost ) >= 1, 'Reconnect email sent' );
		$count = count( T::$mail );
		ELAB_Booking_Sync::create_for( $booking );
		ELAB_Notifications::send_queued_connection_lost();
		T::same( $count, count( T::$mail ), 'Not emailed again for the same loss' );
	},

	'Disconnecting revokes the token at Google and forgets the connection' => function () {
		$advisor = T::connect( T::advisor() );
		ELAB_Google_Calendar::forget( $advisor, true );
		T::same( 1, count( T::requests( '#oauth2\.googleapis\.com/revoke#' ) ), 'Token revoked' );
		T::check( ! ELAB_Google_Calendar::linked( $advisor ), 'Connection forgotten' );
	},

	'Tokens are encrypted and tampering is detected' => function () {
		$encrypted = ELAB_Google_Calendar::encrypt( 'secret-value' );
		T::check( $encrypted && false === strpos( $encrypted, 'secret-value' ), 'Ciphertext hides the value' );
		T::same( 'secret-value', ELAB_Google_Calendar::decrypt( $encrypted ), 'Round trip' );
		$bytes = base64_decode( $encrypted );
		$bytes[ strlen( $bytes ) - 1 ] = chr( ord( $bytes[ strlen( $bytes ) - 1 ] ) ^ 1 );
		T::same( false, ELAB_Google_Calendar::decrypt( base64_encode( $bytes ) ), 'Tampered ciphertext is refused' );
	},

	'The Client Secret is stored encrypted, never in plain text' => function () {
		T::configure_google();
		T::check( ELAB_Google_Calendar::has_saved_secret(), 'Secret saved' );
		T::lacks( 'test-secret', (string) get_option( ELAB_Google_Calendar::OPTION_CLIENT_SECRET ), 'Option holds ciphertext only' );
	},

	'Google itself confirms the access is limited to the site’s own calendar' => function () {
		$advisor = T::connect( T::advisor() );
		T::check( ! ELAB_Google_Calendar::scopes_verified( $advisor ), 'Not verified before asking Google' );
		T::same( true, ELAB_Google_Calendar::check( $advisor ), 'Live check passes' );
		T::check( ELAB_Google_Calendar::scopes_verified( $advisor ), 'Verified after Google answered' );
		T::same( array(), ELAB_Google_Calendar::extra_scopes( $advisor ), 'Nothing beyond the allowed permissions' );
		T::same( 1, count( T::requests( '#^POST https://oauth2\.googleapis\.com/tokeninfo$#' ) ), 'The token is sent in the request body, never in the address' );
	},

	'Broader access than needed is reported as a failure' => function () {
		$advisor = T::connect( T::advisor() );
		T::$token_scope .= ' https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy';
		$result = ELAB_Google_Calendar::check( $advisor );
		T::error( 'google_scope_too_broad', $result, 'Live check fails' );
		T::same( array( 'calendar.events', 'calendar.freebusy' ), ELAB_Google_Calendar::extra_scopes( $advisor ), 'Names the extra permissions' );
		$html = T::render( static function () use ( $advisor ) { ( new ELAB_Advisor_Admin() )->render( get_post( $advisor ) ); } );
		T::contains( 'Google reports more access than this site needs', $html, 'The advisor profile says so' );
		T::contains( 'Reconnect Google Calendar', $html, 'And offers to reconnect' );
	},

	'Reconnecting a connection that is not in use withdraws the old grant at Google first' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'advisor@example.test' ) ), false );
		$catch = static function () { throw new ELAB_Test_Failure( 'redirected' ); };
		add_filter( 'wp_redirect', $catch, 1 );
		$_GET['advisor'] = (string) $advisor;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'elab_google_connect_' . $advisor );
		try {
			( new ELAB_Google_Calendar() )->connect();
		} catch ( ELAB_Test_Failure $redirect ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		} finally {
			remove_filter( 'wp_redirect', $catch, 1 );
			unset( $_REQUEST['_wpnonce'] );
		}
		T::same( 1, count( T::requests( '#oauth2\.googleapis\.com/revoke#' ) ), 'Old grant revoked before the new consent' );
	},

	'Reconnecting a healthy connection does not revoke it' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'advisor@example.test' ) ) );
		$catch = static function () { throw new ELAB_Test_Failure( 'redirected' ); };
		add_filter( 'wp_redirect', $catch, 1 );
		$_GET['advisor'] = (string) $advisor;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'elab_google_connect_' . $advisor );
		try {
			( new ELAB_Google_Calendar() )->connect();
		} catch ( ELAB_Test_Failure $redirect ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		} finally {
			remove_filter( 'wp_redirect', $catch, 1 );
			unset( $_REQUEST['_wpnonce'] );
		}
		T::same( array(), T::requests( '#oauth2\.googleapis\.com/revoke#' ), 'No revoke' );
	},

	'Access removed in Google is noticed on the next request, not when the cache expires' => function () {
		$advisor = T::connect( T::advisor() );
		set_transient( 'elab_google_access_' . $advisor, 'stale-token', HOUR_IN_SECONDS );
		T::$unauthorized_once = true;
		T::$revoke = true;
		T::check( is_wp_error( ELAB_Google_Calendar::check( $advisor ) ), 'Check fails' );
		T::check( ELAB_Google_Calendar::revoked( $advisor ), 'Marked as needing to reconnect' );
		ELAB_Notifications::send_queued_connection_lost();
		T::same( 1, count( T::mail_with_subject( 'Reconnect your Google Calendar' ) ), 'Advisor told at once' );
		T::same( 1, count( T::mail_with_subject( 'Test advisor needs to reconnect Google Calendar' ) ), 'Site admin told at once, in their own words' );
	},

	'A 401 with a still valid grant just renews the access and goes on' => function () {
		$advisor = T::connect( T::advisor() );
		set_transient( 'elab_google_access_' . $advisor, 'stale-token', HOUR_IN_SECONDS );
		T::$unauthorized_once = true;
		T::same( true, ELAB_Google_Calendar::check( $advisor ), 'Check passes after renewing' );
		T::check( ! ELAB_Google_Calendar::revoked( $advisor ), 'Not marked revoked' );
	},

	'Disconnecting from the site emails the advisor' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'advisor@example.test' ) ) );
		T::booking( $advisor );
		$catch = static function () { throw new ELAB_Test_Failure( 'redirected' ); };
		add_filter( 'wp_redirect', $catch, 1 );
		$_GET['advisor'] = (string) $advisor;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'elab_google_disconnect_' . $advisor );
		try {
			( new ELAB_Google_Calendar() )->disconnect();
		} catch ( ELAB_Test_Failure $redirect ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		} finally {
			remove_filter( 'wp_redirect', $catch, 1 );
			unset( $_REQUEST['_wpnonce'] );
		}
		$mail = T::mail_with_subject( 'Google Calendar disconnected' );
		T::same( 1, count( $mail ), 'One email' );
		T::same( 'advisor@example.test', $mail[0]['to'], 'To the advisor' );
		T::check( ! ELAB_Google_Calendar::linked( $advisor ), 'Disconnected' );
		T::same( 1, count( T::requests( '#oauth2\.googleapis\.com/revoke#' ) ), 'Grant revoked at Google' );
	},
);
