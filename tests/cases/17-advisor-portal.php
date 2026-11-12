<?php
/** Advisor portal: Google sign-in, only one's own bookings, safe files, the email link. */

use ELAB_Test as T;

$session_for = static function ( $advisor ) {
	$token = ELAB_Advisor_Portal::start_session( $advisor, ELAB_Notifications::advisor_email( $advisor ) );
	$_COOKIE[ ELAB_Advisor_Portal::COOKIE ] = $token;
	return ELAB_Advisor_Portal::session();
};

/** Run a handler that ends in a redirect or wp_die(); return [kind, url-or-message]. */
$stop = static function ( callable $handler ) {
	$redirect = static function ( $location ) { throw new ELAB_Test_Failure( 'redirect:' . $location ); };
	$die = static function () { return static function ( $message ) { throw new ELAB_Test_Failure( 'die:' . ( is_string( $message ) ? $message : '' ) ); }; };
	$page = static function ( $code ) { throw new ELAB_Test_Failure( 'page:' . $code ); };
	add_filter( 'wp_redirect', $redirect, 1 );
	add_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	add_action( 'elab_portal_output', $page );
	try {
		$handler();
		return array( 'none', '' );
	} catch ( ELAB_Test_Failure $stopped ) {
		list( $kind, $detail ) = explode( ':', $stopped->getMessage(), 2 );
		return array( $kind, $detail );
	} finally {
		remove_filter( 'wp_redirect', $redirect, 1 );
		remove_filter( 'wp_die_handler', $die, PHP_INT_MAX );
		remove_action( 'elab_portal_output', $page );
	}
};

return array(
	'Signing in asks Google only who the person is' => function () use ( $stop ) {
		T::configure_google();
		$_GET['booking'] = '12';
		try {
			$result = $stop( static function () { ( new ELAB_Advisor_Portal() )->login(); } );
		} finally {
			unset( $_GET['booking'] );
		}
		T::same( 'redirect', $result[0], 'Sent to Google' );
		parse_str( (string) wp_parse_url( $result[1], PHP_URL_QUERY ), $query );
		T::same( 'openid email', $query['scope'] ?? '', 'No calendar access asked' );
		T::same( 'S256', $query['code_challenge_method'] ?? '', 'PKCE' );
		T::same( ELAB_Google_Calendar::redirect_uri(), $query['redirect_uri'] ?? '', 'Same redirect URI as connecting (nothing to add in Google Cloud)' );
		$state = get_transient( 'elab_google_state_' . hash( 'sha256', $query['state'] ) );
		T::check( ! empty( $state['portal'] ) && 12 === $state['booking'], 'Remembers the booking to return to' );
	},

	'Google sign-in opens a session only for an advisor’s own Google email' => function () use ( $stop ) {
		T::configure_google();
		$advisor = T::advisor( array( '_elab_email' => 'Advisor@Example.test' ) );
		$sign_in = static function () use ( $stop ) {
			$result = $stop( static function () { ( new ELAB_Advisor_Portal() )->login(); } );
			parse_str( (string) wp_parse_url( $result[1], PHP_URL_QUERY ), $query );
			$_GET = array( 'state' => $query['state'], 'code' => 'test-code' );
			try {
				return $stop( static function () { ( new ELAB_Google_Calendar() )->callback(); } );
			} finally {
				$_GET = array();
			}
		};
		wp_set_current_user( 0 );
		ELAB_Advisor_Portal::$last_token = '';
		$result = $sign_in();
		T::same( 'redirect', $result[0], 'Signed in' );
		T::contains( 'action=elab_portal', $result[1], 'Back to the portal' );
		T::check( '' !== ELAB_Advisor_Portal::$last_token, 'Session created' );
		$stored = get_transient( 'elab_portal_' . hash( 'sha256', ELAB_Advisor_Portal::$last_token ) );
		T::same( $advisor, $stored['advisor'], 'For that advisor (email compared without case)' );
		T::check( (bool) get_post_meta( $advisor, ELAB_Advisor_Portal::META_LOG, true ), 'Sign-in recorded' );
		T::check( (bool) T::requests( '#oauth2.googleapis.com/revoke#' ), 'The sign-in token is withdrawn at Google' );

		ELAB_Advisor_Portal::$last_token = '';
		T::$identity = array( 'sub' => 'other', 'email' => 'stranger@example.test', 'email_verified' => true );
		$result = $sign_in();
		T::same( array( 'page', '403' ), $result, 'Refused page' );
		T::same( '', ELAB_Advisor_Portal::$last_token, 'No session for an account that is not an advisor' );
		T::$identity['email_verified'] = false;
		T::$identity['email'] = 'advisor@example.test';
		$result = $sign_in();
		T::same( '', ELAB_Advisor_Portal::$last_token, 'No session for an unverified email' );
	},

	'An advisor sees only their own calls, with everything the visitor sent' => function () use ( $session_for ) {
		$mine = T::advisor( array( '_elab_email' => 'mine@example.test' ), 'Mariana' );
		$other = T::advisor( array( '_elab_email' => 'other@example.test' ), 'Federico' );
		$my_booking = T::booking( $mine, array( '_elab_name' => 'Laura Visitor', '_elab_sector' => 'Arquitectura', '_elab_message' => "Line one\n<script>alert(1)</script>", '_elab_meet_url' => 'https://meet.google.com/abc-defg-hij' ) );
		$other_booking = T::booking( $other, array( '_elab_name' => 'Pedro Secret' ) );
		$session = $session_for( $mine );
		$list = ELAB_Advisor_Portal::render_list( $session );
		T::contains( 'Laura Visitor', $list, 'Own call listed' );
		T::check( false === strpos( $list, 'Pedro Secret' ), 'Another advisor’s call never listed' );
		$detail = ELAB_Advisor_Portal::render_detail( $session, $my_booking );
		T::contains( 'Arquitectura', $detail, 'Sector shown' );
		T::contains( 'Line one', $detail, 'Message shown' );
		T::check( false === strpos( $detail, '<script>' ), 'Visitor text is escaped' );
		T::contains( 'https://meet.google.com/abc-defg-hij', $detail, 'Meet link' );
		T::same( '', ELAB_Advisor_Portal::render_detail( $session, $other_booking ), 'Another advisor’s call cannot be opened' );
		T::error( 'portal_not_yours', ELAB_Advisor_Portal::viewer_page( $session, $other_booking, 'x.pdf' ), 'Nor its files' );
		T::error( 'portal_signed_out', ELAB_Advisor_Portal::viewer_page( null, $my_booking, 'x.pdf' ), 'Files need a session' );
		T::error( 'attachment_unknown', ELAB_Advisor_Portal::viewer_page( $session, $my_booking, 'not-attached.pdf' ), 'Only files attached to that booking' );
	},

	'A session ends when the advisor’s email changes or the advisor is unpublished' => function () use ( $session_for ) {
		$advisor = T::advisor( array( '_elab_email' => 'mine@example.test' ) );
		T::check( null !== $session_for( $advisor ), 'Signed in' );
		update_post_meta( $advisor, '_elab_email', 'new@example.test' );
		T::same( null, ELAB_Advisor_Portal::session(), 'Email changed: signed out' );
		update_post_meta( $advisor, '_elab_email', 'mine@example.test' );
		T::check( null !== ELAB_Advisor_Portal::session(), 'Same email again' );
		wp_update_post( array( 'ID' => $advisor, 'post_status' => 'draft' ) );
		T::same( null, ELAB_Advisor_Portal::session(), 'Unpublished: signed out' );
		$_COOKIE[ ELAB_Advisor_Portal::COOKIE ] = 'forged';
		T::same( null, ELAB_Advisor_Portal::session(), 'A forged cookie is nothing' );
		unset( $_COOKIE[ ELAB_Advisor_Portal::COOKIE ] );
	},

	'Signing out needs the session’s own token' => function () use ( $session_for, $stop ) {
		$advisor = T::advisor( array( '_elab_email' => 'mine@example.test' ) );
		$session = $session_for( $advisor );
		$_POST['elab_csrf'] = 'wrong';
		$stop( static function () { ( new ELAB_Advisor_Portal() )->logout(); } );
		T::check( null !== ELAB_Advisor_Portal::session(), 'A forged sign-out does nothing' );
		$_POST['elab_csrf'] = $session['csrf'];
		$stop( static function () { ( new ELAB_Advisor_Portal() )->logout(); } );
		unset( $_POST['elab_csrf'] );
		T::same( null, ELAB_Advisor_Portal::session(), 'Signed out' );
		unset( $_COOKIE[ ELAB_Advisor_Portal::COOKIE ] );
	},

	'The advisor’s booking email opens that booking in the portal' => function () {
		$advisor = T::connect( T::advisor( array( '_elab_email' => 'mine@example.test' ) ) );
		$booking = T::booking( $advisor );
		T::$mail = array();
		ELAB_Notifications::booking_notice( $booking, 'confirmed' );
		$mail = T::mails_to( 'mine@example.test' )[0];
		T::contains( esc_url( ELAB_Advisor_Portal::url( $booking ) ), $mail['message'], 'Button to the booking' );
		T::contains( 'mine@example.test', $mail['message'], 'Says which Google account to use' );
		T::check( false === strpos( $mail['message'], 'Private message' ), 'The message itself stays out of the email' );
	},

	'Portal pages and the file viewer speak the advisor’s language' => function () use ( $session_for ) {
		if ( ! in_array( 'es_CO', get_available_languages(), true ) ) {
			return;
		}
		$advisor = T::advisor( array( '_elab_email' => 'mine@example.test', ELAB_Notifications::META_ADVISOR_LOCALE => 'es_CO' ) );
		$session = $session_for( $advisor );
		$error = ELAB_Advisor_Portal::viewer_page( $session, 999999, 'x.pdf' );
		T::same( 'Esta reserva no existe o pertenece a otro asesor.', $error->get_error_message(), 'Viewer error in Spanish' );
		T::contains( 'Próximas llamadas', ELAB_Advisor_Portal::render_list( $session ), 'List in Spanish' );
		T::same( 'en_US', determine_locale(), 'Language restored afterwards' );
		unset( $_COOKIE[ ELAB_Advisor_Portal::COOKIE ] );
	},
);

