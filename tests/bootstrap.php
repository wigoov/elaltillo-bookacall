<?php
/**
 * Test harness for El Altillo Book a Call. Runs inside WP-CLI on a disposable/local site.
 *
 * - Google, Cloudflare and every other outbound request are answered by a mock; any
 *   unexpected request fails the test, so nothing ever leaves the machine.
 * - Emails are captured, never sent.
 * - Every test gets fresh fixtures and everything it created is deleted afterwards.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

final class ELAB_Test_Failure extends RuntimeException {}

final class ELAB_Test {
	/* Captured traffic */
	public static $mail = array();
	public static $http = array();
	public static $calendar_posts = array();
	public static $event_body = null;
	public static $event_path = '';
	/* Mock switches, reset before each test */
	public static $google_down = false;
	public static $revoke = false;
	public static $insert_times_out = false;
	public static $mail_fails = false;
	public static $token_scope = 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/calendar.app.created';
	public static $calendar_gone = false;
	public static $event_delete_code = 204;
	public static $event_get_code = 200;
	public static $event_status = 'confirmed';
	public static $unauthorized_once = false;
	public static $identity = array( 'sub' => 'test-sub', 'email' => 'advisor@example.test', 'email_verified' => true );
	public static $checks = 0;

	private static $posts = array();
	private static $users = array();
	private static $saved_options = array();
	private static $hidden_advisors = array();
	private static $original = array();
	const OPTIONS = array( 'elab_google_client_id', 'elab_google_client_secret', 'elab_global_blackouts', 'elab_booking_defaults', 'elab_retention_months', 'elab_security', 'elab_google_meet', 'elab_mail_test_result' );

	/* ---------- Assertions ---------- */

	public static function check( $condition, $message ) {
		self::$checks++;
		if ( ! $condition ) {
			throw new ELAB_Test_Failure( $message );
		}
	}

	public static function same( $expected, $actual, $message ) {
		self::check( $expected === $actual, $message . ' — expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) );
	}

	public static function contains( $needle, $haystack, $message ) {
		self::check( false !== strpos( (string) $haystack, (string) $needle ), $message . ' — missing "' . $needle . '"' );
	}

	public static function lacks( $needle, $haystack, $message ) {
		self::check( false === strpos( (string) $haystack, (string) $needle ), $message . ' — should not contain "' . $needle . '"' );
	}

	public static function error( $code, $value, $message ) {
		self::check( is_wp_error( $value ) && ( null === $code || $code === $value->get_error_code() ), $message . ' — expected WP_Error ' . $code . ', got ' . ( is_wp_error( $value ) ? $value->get_error_code() : wp_json_encode( $value ) ) );
	}

	/* ---------- Environment ---------- */

	public static function boot() {
		if ( get_option( 'elab_google_client_id' ) || get_option( 'elab_google_client_secret' ) ) {
			WP_CLI::error( 'Run the tests only on a disposable/local site without Google credentials.' );
		}
		foreach ( self::OPTIONS as $option ) {
			self::$saved_options[ $option ] = get_option( $option, null );
		}
		self::$original = array( 'post' => $_POST, 'get' => $_GET, 'cookie' => $_COOKIE, 'files' => $_FILES, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'user' => get_current_user_id() );
		// Other published advisors (demo data) would join the combined calendar; hide them while testing.
		foreach ( get_posts( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
			self::$hidden_advisors[] = $id;
			wp_update_post( array( 'ID' => $id, 'post_status' => 'private' ) );
		}
		add_filter( 'pre_wp_mail', array( __CLASS__, 'capture_mail' ), 10, 2 );
		add_filter( 'pre_http_request', array( __CLASS__, 'mock_http' ), 10, 3 );
		register_shutdown_function( array( __CLASS__, 'shutdown' ) );
		self::as_admin();
	}

	public static function shutdown() {
		self::reset();
		foreach ( self::$hidden_advisors as $id ) {
			wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		}
		self::$hidden_advisors = array();
		remove_filter( 'pre_wp_mail', array( __CLASS__, 'capture_mail' ), 10 );
		remove_filter( 'pre_http_request', array( __CLASS__, 'mock_http' ), 10 );
		wp_set_current_user( self::$original['user'] ?? 0 );
	}

	/** Undo everything a test did. */
	public static function reset() {
		foreach ( array_unique( self::$posts ) as $id ) {
			foreach ( get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_elab_advisor', 'meta_value' => $id ) ) as $booking ) {
				wp_delete_post( $booking, true );
			}
			delete_option( 'elab_advisor_lock_' . $id );
			delete_option( 'elab_sync_lock_' . $id );
			wp_delete_post( $id, true );
		}
		self::$posts = array();
		if ( self::$users ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( self::$users as $user ) {
				wp_delete_user( $user );
			}
			self::$users = array();
		}
		foreach ( self::$saved_options as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}
		delete_transient( ELAB_System_Status::TRANSIENT_GOOGLE );
		// Rate-limit counters (per address, per email, per guest) start empty for every test.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_elab\\_limit\\_%' OR option_name LIKE '\\_transient\\_timeout\\_elab\\_limit\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();
		$_POST = self::$original['post'] ?? array();
		$_GET = self::$original['get'] ?? array();
		$_COOKIE = self::$original['cookie'] ?? array();
		$_FILES = self::$original['files'] ?? array();
		self::new_ip();
		while ( restore_previous_locale() ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}
		self::$mail = self::$http = self::$calendar_posts = array();
		self::$event_body = null;
		self::$event_path = '';
		self::$google_down = self::$revoke = self::$insert_times_out = self::$mail_fails = false;
		self::$identity = array( 'sub' => 'test-sub', 'email' => 'advisor@example.test', 'email_verified' => true );
		self::$token_scope = 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/calendar.app.created';
		self::$calendar_gone = false;
		self::$event_delete_code = 204;
		self::$event_get_code = 200;
		self::$event_status = 'confirmed';
		self::$unauthorized_once = false;
		self::as_admin();
	}

	public static function as_admin() {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
		wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
	}

	/** Each request from a new address, so rate limits only trip where a test wants them. */
	public static function new_ip() {
		$_SERVER['REMOTE_ADDR'] = '127.' . random_int( 1, 254 ) . '.' . random_int( 1, 254 ) . '.' . random_int( 1, 254 );
	}

	/* ---------- Mocks ---------- */

	public static function capture_mail( $preempt, $mail ) {
		self::$mail[] = $mail;
		return ! self::$mail_fails;
	}

	public static function mock_http( $preempt, $args, $url ) {
		$method = $args['method'] ?? 'GET';
		self::$http[] = $method . ' ' . $url;
		$code = 200;
		$data = array();
		$calendar_api = 0 === strpos( $url, 'https://www.googleapis.com/calendar/v3/' );
		if ( $calendar_api && self::$unauthorized_once ) {
			self::$unauthorized_once = false;
			$code = 401;
		} elseif ( $calendar_api && self::$google_down ) {
			$code = 503;
		} elseif ( false !== strpos( $url, 'oauth2.googleapis.com/tokeninfo' ) ) {
			$data = array( 'scope' => self::$token_scope, 'expires_in' => 3000 );
		} elseif ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
			if ( self::$revoke ) {
				$code = 400;
				$data = array( 'error' => 'invalid_grant' );
			} else {
				$data = array( 'access_token' => 'test-access', 'expires_in' => 3600 );
			}
		} elseif ( false !== strpos( $url, 'oauth2.googleapis.com/revoke' ) ) {
			$data = array();
		} elseif ( false !== strpos( $url, 'openidconnect.googleapis.com/v1/userinfo' ) ) {
			$data = self::$identity;
		} elseif ( $calendar_api && self::$calendar_gone && false !== strpos( $url, '/calendars/test-cal%40group' ) ) {
			$code = 404;
		} elseif ( $calendar_api && preg_match( '#/calendar/v3/calendars$#', $url ) && 'POST' === $method ) {
			self::$calendar_posts[] = json_decode( $args['body'], true );
			$data = array( 'id' => 'test-cal-' . count( self::$calendar_posts ) . '@group.calendar.google.com' );
		} elseif ( $calendar_api && preg_match( '#/calendar/v3/calendars/([^/?]+)$#', $url, $match ) ) {
			$id = rawurldecode( $match[1] );
			if ( 0 === strpos( $id, 'gone' ) ) {
				$code = 404;
			} else {
				$data = array( 'id' => $id );
			}
		} elseif ( $calendar_api && false !== strpos( $url, '/events?sendUpdates=all' ) && 'POST' === $method ) {
			self::$event_body = json_decode( $args['body'], true );
			self::$event_path = $url;
			if ( self::$insert_times_out ) {
				return new WP_Error( 'http_request_failed', 'Operation timed out' );
			}
			$data = array( 'id' => self::$event_body['id'], 'iCalUID' => 'test@example.test', 'htmlLink' => 'https://calendar.google.com/calendar/event?eid=test' );
			if ( ! empty( self::$event_body['conferenceData']['createRequest'] ) ) {
				$data['hangoutLink'] = 'https://meet.google.com/tst-test-call';
			}
		} elseif ( $calendar_api && false !== strpos( $url, '/events/' ) && 'DELETE' === $method ) {
			$code = self::$event_delete_code;
		} elseif ( $calendar_api && false !== strpos( $url, '/events/' ) ) {
			$code = self::$event_get_code;
			$data = array( 'id' => rawurldecode( basename( wp_parse_url( $url, PHP_URL_PATH ) ) ), 'status' => self::$event_status );
		} elseif ( false !== strpos( $url, 'challenges.cloudflare.com/turnstile' ) ) {
			$good = isset( $args['body']['response'] ) && 'good-token' === $args['body']['response'] && 'turnstile-secret' === $args['body']['secret'];
			$data = array( 'success' => $good, 'hostname' => wp_parse_url( home_url(), PHP_URL_HOST ) );
		} else {
			throw new ELAB_Test_Failure( 'Unexpected outbound HTTP request: ' . $method . ' ' . $url );
		}
		return array( 'response' => array( 'code' => $code, 'message' => 'OK' ), 'body' => wp_json_encode( $data ), 'headers' => array(), 'cookies' => array() );
	}

	/** Requests matching a regular expression. */
	public static function requests( $pattern ) {
		return array_values( preg_grep( $pattern, self::$http ) );
	}

	public static function mails_to( $email ) {
		return array_values( array_filter( self::$mail, static function ( $mail ) use ( $email ) {
			return in_array( $email, (array) $mail['to'], true );
		} ) );
	}

	public static function mail_with_subject( $subject ) {
		return array_values( array_filter( self::$mail, static function ( $mail ) use ( $subject ) {
			return $subject === $mail['subject'];
		} ) );
	}

	/* ---------- Fixtures ---------- */

	public static function track( $id ) {
		self::$posts[] = (int) $id;
		return (int) $id;
	}

	/** Published advisor in Lisbon, 09:00–12:00 every day, 30-minute calls, no notice. */
	public static function advisor( array $meta = array(), $title = 'Test advisor' ) {
		$id = wp_insert_post( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'publish', 'post_title' => $title ), true );
		self::check( ! is_wp_error( $id ), 'Advisor fixture created' );
		self::track( $id );
		$meta += array(
			'_elab_email'                              => 'advisor+' . $id . '@example.test',
			'_elab_timezone'                           => 'Europe/Lisbon',
			'_elab_duration'                           => 30,
			'_elab_availability'                       => wp_json_encode( array_fill_keys( range( 1, 7 ), array( array( '09:00', '12:00' ) ) ) ),
			ELAB_Availability::META_NOTICE             => 0,
			ELAB_Availability::META_HORIZON            => 365,
			ELAB_Availability::META_BUFFER             => 0,
			ELAB_Notifications::META_ADVISOR_LOCALE    => 'en_US',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	/** Turn the Cloudflare bot check on (needed for guests). The mock accepts "good-token". */
	public static function turnstile_on() {
		update_option( ELAB_Security::OPTION, array( 'proxy' => 'direct', 'site_key' => 'site-key', 'secret' => ELAB_Google_Calendar::encrypt( 'turnstile-secret' ) ) );
	}

	public static function configure_google() {
		if ( ! ELAB_Google_Calendar::configured() ) {
			ELAB_Google_Calendar::save_credentials( 'test-client', 'test-secret' );
		}
	}

	/** A complete, working connection with the site's own calendar. $calendar false = old permission. */
	public static function connect( $advisor_id, $calendar = 'test-cal@group.calendar.google.com' ) {
		self::configure_google();
		update_post_meta( $advisor_id, ELAB_Google_Calendar::META_REFRESH, ELAB_Google_Calendar::encrypt( 'test-refresh' ) );
		update_post_meta( $advisor_id, ELAB_Google_Calendar::META_CLIENT_HASH, hash( 'sha256', 'test-client' ) );
		update_post_meta( $advisor_id, ELAB_Google_Calendar::META_ACCOUNT_EMAIL, get_post_meta( $advisor_id, '_elab_email', true ) );
		update_post_meta( $advisor_id, ELAB_Google_Calendar::META_ACCOUNT_SUB, 'test-sub' );
		if ( $calendar ) {
			update_post_meta( $advisor_id, ELAB_Google_Calendar::META_CALENDAR, $calendar );
		}
		delete_post_meta( $advisor_id, ELAB_Google_Calendar::META_REVOKED );
		delete_transient( 'elab_google_access_' . $advisor_id );
		return $advisor_id;
	}

	/** A booking stored directly (no REST, no emails). */
	public static function booking( $advisor_id, array $meta = array() ) {
		$id = wp_insert_post( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'private', 'post_title' => 'Test booking' ) );
		$meta += array(
			'_elab_advisor'          => $advisor_id,
			'_elab_status'           => 'confirmed',
			'_elab_name'             => 'Test visitor',
			'_elab_contact_email'    => 'visitor+' . $id . '@example.test',
			'_elab_start_utc'        => gmdate( 'Y-m-d H:00:00', strtotime( '+5 days' ) ),
			'_elab_duration'         => 30,
			'_elab_visitor_timezone' => 'America/Bogota',
			'_elab_language'         => 'es',
			'_elab_locale'           => 'en_US',
			'_elab_message'          => 'Private message',
			'_elab_sector'           => 'Private sector',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	/** Next Monday in Bogotá: a day every test advisor works. */
	public static function day( $tz = 'America/Bogota', $modify = 'next monday' ) {
		return ( new DateTimeImmutable( $modify, new DateTimeZone( $tz ) ) )->format( 'Y-m-d' );
	}

	public static function slots( $advisor = 0, $day = null, $tz = 'America/Bogota' ) {
		$request = new WP_REST_Request( 'GET', '/elab/v1/slots' );
		$request->set_query_params( array_filter( array( 'advisor' => $advisor, 'date' => $day ?: self::day(), 'timezone' => $tz ) ) );
		self::new_ip();
		return rest_do_request( $request );
	}

	public static function slot_values( $advisor = 0, $day = null, $tz = 'America/Bogota' ) {
		$data = self::slots( $advisor, $day, $tz )->get_data();
		return is_array( $data ) && ! isset( $data['code'] ) ? array_column( $data, 'value' ) : array();
	}

	public static function month( $advisor, $month, $tz = 'America/Bogota' ) {
		$request = new WP_REST_Request( 'GET', '/elab/v1/availability' );
		$request->set_query_params( array_filter( array( 'advisor' => $advisor, 'month' => $month, 'timezone' => $tz ) ) );
		self::new_ip();
		return rest_do_request( $request );
	}

	/** POST /bookings as a visitor would. */
	public static function book( $advisor, $start, array $fields = array() ) {
		$request = new WP_REST_Request( 'POST', '/elab/v1/bookings' );
		$request->set_body_params( $fields + array(
			'advisor'  => $advisor,
			'start'    => $start,
			'name'     => 'Ana Visitor',
			'email'    => 'visitor+' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test',
			'sector'   => 'Private sector',
			'timezone' => 'America/Bogota',
			'message'  => 'Private message',
			'website'  => '',
			'consent'  => '1',
			'cf-turnstile-response' => 'good-token',
		) );
		self::new_ip();
		return rest_do_request( $request );
	}

	/** The booking a REST response created. */
	public static function booking_from( $response ) {
		$data = $response->get_data();
		$ids = get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'private', 'numberposts' => 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC', 'meta_key' => '_elab_start_utc', 'meta_value' => gmdate( 'Y-m-d H:i:s', strtotime( $data['booking']['start'] ?? 'now' ) ) ) );
		return (int) ( $ids[0] ?? 0 );
	}

	public static function user( $role ) {
		$id = wp_create_user( 'elab_test_' . wp_generate_password( 8, false, false ), wp_generate_password( 32 ), 'elab-test-' . wp_generate_password( 8, false, false ) . '@example.test' );
		self::check( ! is_wp_error( $id ), 'User fixture created' );
		( new WP_User( $id ) )->set_role( $role );
		self::$users[] = $id;
		return $id;
	}

	/** Output of a rendering callback. */
	public static function render( callable $callback ) {
		ob_start();
		try {
			$callback();
		} finally {
			$html = ob_get_clean();
		}
		return $html;
	}

	/** Call a private/protected method. */
	public static function call( $class, $method, ...$args ) {
		$reflection = new ReflectionMethod( $class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( is_string( $class ) ? null : $class, ...$args );
	}
}
