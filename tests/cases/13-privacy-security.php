<?php
/** Privacy (retention, export, erasure) and security (bots, limits, addresses, files). */

use ELAB_Test as T;

return array(
	'Retention 0 keeps everything; a period deletes only older bookings' => function () {
		$advisor = T::advisor();
		$old = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( '-26 months' ) ) ) );
		$recent = T::booking( $advisor, array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( '-2 months' ) ) ) );
		update_option( ELAB_Privacy::OPTION_RETENTION, 0 );
		T::same( 0, ELAB_Privacy::cleanup(), 'Nothing deleted with 0' );
		update_option( ELAB_Privacy::OPTION_RETENTION, 24 );
		ELAB_Privacy::cleanup();
		T::check( ! get_post( $old ) && get_post( $recent ), 'Only the old booking deleted' );
	},

	'Personal data export includes the message and the guests' => function () {
		$booking = T::booking( T::advisor(), array( '_elab_contact_email' => 'export@example.test', '_elab_guests' => array( 'g@example.test' ), '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 month' ) ) ) );
		$export = ELAB_Privacy::export( 'export@example.test' );
		$values = array_column( $export['data'][0]['data'] ?? array(), 'value' );
		T::check( in_array( 'Private message', $values, true ), 'Message exported' );
		T::check( in_array( 'g@example.test', $values, true ), 'Guests exported' );
	},

	'Erasure deletes past bookings and keeps an upcoming Google event until cancelled' => function () {
		$advisor = T::advisor();
		$past = T::booking( $advisor, array( '_elab_contact_email' => 'erase@example.test', '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 month' ) ) ) );
		$future = T::booking( $advisor, array( '_elab_contact_email' => 'erase@example.test' ) );
		update_post_meta( $future, ELAB_Google_Calendar::META_EVENT, 'elabevent' );
		$result = ELAB_Privacy::erase( 'erase@example.test' );
		T::check( ! get_post( $past ) && get_post( $future ) && $result['items_retained'], 'Past erased, upcoming kept' );
	},

	'Hidden spam field rejects bots' => function () {
		$request = new WP_REST_Request( 'POST', '/elab/v1/bookings' );
		$request->set_body_params( array( 'website' => 'spam' ) );
		T::error( 'invalid_booking', ELAB_Booking_Guard::submission( $request ), 'Honeypot' );
	},

	'Twelve bookings per address in 15 minutes, five per email in an hour' => function () {
		$_SERVER['REMOTE_ADDR'] = '127.7.' . random_int( 1, 254 ) . '.' . random_int( 1, 254 );
		$request = new WP_REST_Request( 'POST', '/elab/v1/bookings' );
		for ( $i = 0; $i < 12; $i++ ) {
			T::same( true, ELAB_Booking_Guard::submission( $request ), 'Attempt ' . ( $i + 1 ) );
		}
		T::error( 'rate_limited', ELAB_Booking_Guard::submission( $request ), '13th refused' );
		$email = 'limit+' . wp_generate_password( 6, false, false ) . '@example.test';
		$by_email = new WP_REST_Request( 'POST', '/elab/v1/bookings' );
		$by_email->set_param( 'email', $email );
		for ( $i = 0; $i < 5; $i++ ) {
			T::new_ip();
			ELAB_Booking_Guard::submission( $by_email );
		}
		T::new_ip();
		T::error( 'rate_limited', ELAB_Booking_Guard::submission( $by_email ), '6th booking for one email refused' );
	},

	'Turnstile: no token or a bad one is refused, a good one passes' => function () {
		update_option( ELAB_Security::OPTION, array( 'proxy' => 'direct', 'site_key' => 'site-key', 'secret' => ELAB_Google_Calendar::encrypt( 'turnstile-secret' ) ) );
		$request = new WP_REST_Request( 'POST', '/elab/v1/bookings' );
		T::check( is_wp_error( ELAB_Booking_Guard::submission( $request ) ), 'No token' );
		T::new_ip();
		$request->set_param( 'cf-turnstile-response', 'bad-token' );
		T::check( is_wp_error( ELAB_Booking_Guard::submission( $request ) ), 'Bad token' );
		T::new_ip();
		$request->set_param( 'cf-turnstile-response', 'good-token' );
		T::same( true, ELAB_Booking_Guard::submission( $request ), 'Good token' );
	},

	'Visitor address: direct, Cloudflare and custom proxy modes cannot be spoofed' => function () {
		update_option( ELAB_Security::OPTION, array( 'proxy' => 'direct' ) );
		T::same( '203.0.113.9', ELAB_Security::visitor_ip( array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1' ) ), 'Direct ignores headers' );
		update_option( ELAB_Security::OPTION, array( 'proxy' => 'cloudflare' ) );
		T::same( '198.51.100.4', ELAB_Security::visitor_ip( array( 'REMOTE_ADDR' => '173.245.48.10', 'HTTP_CF_CONNECTING_IP' => '198.51.100.4' ) ), 'Trusted from Cloudflare' );
		T::same( '203.0.113.9', ELAB_Security::visitor_ip( array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.4' ) ), 'Ignored from elsewhere' );
		update_option( ELAB_Security::OPTION, array( 'proxy' => 'custom', 'proxy_ips' => array( '10.0.0.0/8' ), 'header' => 'HTTP_X_FORWARDED_FOR' ) );
		T::same( '198.51.100.8', ELAB_Security::visitor_ip( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.8' ) ), 'Spoofed hop ignored' );
		T::check( ELAB_Security::ip_in_range( '2606:4700::1111', '2606:4700::/32' ) && ! ELAB_Security::ip_in_range( '2001:db8::1', '2606:4700::/32' ), 'IPv6 ranges' );
	},

	'Attachments are stored outside the web root' => function () {
		$dir = T::call( 'ELAB_Private_Attachments', 'safe_dir' );
		T::check( $dir && 0 !== strpos( $dir, trailingslashit( realpath( ABSPATH ) ) ), 'Outside ABSPATH' );
	},

	'Malformed upload fields are rejected without warnings' => function () {
		$warnings = array();
		set_error_handler( static function ( $no, $message ) use ( &$warnings ) { $warnings[] = $message; return true; } );
		$_FILES = array( 'attachments' => array( 'name' => 'single.pdf', 'type' => 'application/pdf', 'tmp_name' => '/nonexistent', 'error' => 0, 'size' => 10 ) );
		$single = ( new ELAB_Private_Attachments() )->upload();
		$_FILES = array( 'attachments' => array( 'name' => array( 'a.pdf', 'b.pdf' ), 'tmp_name' => array( '/x' ), 'error' => array( 0 ), 'size' => array( 1 ) ) );
		$mismatched = ( new ELAB_Private_Attachments() )->upload();
		restore_error_handler();
		T::check( is_wp_error( $single ) && is_wp_error( $mismatched ), 'Both rejected' );
		T::same( array(), $warnings, 'No PHP warnings' );
	},

	'A virus scanner can reject files through elab_scan_attachment' => function () {
		$reject = static function () { return false; };
		add_filter( 'elab_scan_attachment', $reject );
		$scan = ELAB_Private_Attachments::scan( __FILE__, 'file.pdf' );
		remove_filter( 'elab_scan_attachment', $reject );
		T::check( is_wp_error( $scan ), 'Rejected by the scanner' );
		T::same( true, ELAB_Private_Attachments::scan( __FILE__, 'file.pdf' ), 'Accepted without it' );
	},

	'No test ever reached a real outside service' => function () {
		$advisor = T::connect( T::advisor() );
		T::book( $advisor, T::slot_values( $advisor )[0] );
		foreach ( T::$http as $request ) {
			T::check( (bool) preg_match( '#^(GET|POST|DELETE|PATCH|PUT) https://(www|oauth2|openidconnect)\.googleapis\.com/#', $request ), 'Only mocked Google endpoints: ' . $request );
		}
	},

	'Uploads: only real PDF, Word, Excel and PowerPoint files, whatever the name says' => function () {
		if ( ! ELAB_Private_Attachments::storage_ready() ) {
			return;
		}
		$try = static function ( $name, $content ) {
			$tmp = wp_tempnam( 'elab-upload' );
			file_put_contents( $tmp, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$_FILES = array( 'attachments' => array( 'name' => array( $name ), 'tmp_name' => array( $tmp ), 'error' => array( 0 ), 'size' => array( strlen( $content ) ) ) );
			try {
				return ( new ELAB_Private_Attachments() )->upload();
			} finally {
				$_FILES = array();
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		};
		T::error( 'invalid_file_type', $try( 'invoice.pdf', '<?php echo "not a pdf"; ?>' ), 'Script renamed .pdf refused' );
		T::error( 'invalid_file_type', $try( 'page.html', '<html><script>alert(1)</script></html>' ), 'HTML refused' );
		T::error( 'invalid_file_type', $try( 'tool.exe', "MZ\x90\x00" ), 'Program refused' );
		T::error( 'invalid_file_type', $try( 'shell.php', '<?php system($_GET["c"]); ?>' ), 'PHP refused' );
	},
);

