<?php
/**
 * Advisors → System status: one place to confirm the site is ready for real bookings.
 * Checks are read-only; the email test and the live Google check run only on request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_System_Status {
	const PAGE = 'elab-system-status';
	const MAIL_TEST = 'elab_status_mail_test';
	const GOOGLE_TEST = 'elab_status_google_test';
	const OPTION_MAIL = 'elab_mail_test_result';
	const TRANSIENT_GOOGLE = 'elab_google_test_result';
	private $hook_suffix;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::MAIL_TEST, array( $this, 'send_test_mail' ) );
		add_action( 'admin_post_' . self::GOOGLE_TEST, array( $this, 'test_google' ) );
	}

	public function register_page() {
		$this->hook_suffix = add_submenu_page( 'edit.php?post_type=' . ELAB_Post_Types::ADVISOR, __( 'System status', 'elaltillo-bookacall' ), __( 'System status', 'elaltillo-bookacall' ), 'manage_options', self::PAGE, array( $this, 'render' ) );
	}

	public function enqueue( $hook ) {
		if ( $this->hook_suffix === $hook ) {
			wp_enqueue_style( 'elab-advisor-admin', plugins_url( 'assets/css/advisor-admin.css', ELAB_FILE ), array(), ELAB_Plugin::asset_version( 'assets/css/advisor-admin.css' ) );
		}
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'page' => self::PAGE ), $args ), admin_url( 'edit.php' ) );
	}

	private static function action_button( $action, $label ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="elab-inline-form"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">' . wp_nonce_field( $action, '_wpnonce', true, false ) . '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Every check as [section, label, state (ok|warn|error|info), detail, action HTML].
	 */
	public static function checks() {
		$checks = array();
		$settings = ELAB_Google_Settings_Admin::url( 'security' );
		$settings_google = ELAB_Google_Settings_Admin::url( 'google' );
		$settings_privacy = ELAB_Google_Settings_Admin::url( 'privacy' );

		// Site.
		$https = 0 === strpos( home_url(), 'https://' ) && 0 === strpos( admin_url(), 'https://' );
		$checks[] = array( 'site', __( 'HTTPS', 'elaltillo-bookacall' ), $https ? 'ok' : 'error', $https ? __( 'Site and admin use HTTPS.', 'elaltillo-bookacall' ) : __( 'Google sign-in and visitor data need HTTPS on the whole site.', 'elaltillo-bookacall' ), '' );
		global $wpdb;
		$page = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE %s LIMIT 1", '%' . $wpdb->esc_like( '[elaltillo_bookacall' ) . '%' ) );
		$checks[] = array( 'site', __( 'Booking page', 'elaltillo-bookacall' ), $page ? 'ok' : 'warn', $page ? sprintf( __( 'The calendar is published on “%s”.', 'elaltillo-bookacall' ), get_the_title( (int) $page ) ) : __( 'No published page contains the [elaltillo_bookacall] shortcode yet.', 'elaltillo-bookacall' ), $page ? '<a class="button" href="' . esc_url( get_permalink( (int) $page ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View page', 'elaltillo-bookacall' ) . '</a>' : '' );
		$security = ELAB_Security::settings();
		// Warn only when a proxy reports a visitor address different from the connection's.
		// Hosts such as Hostinger's CDN already restore the real address into REMOTE_ADDR.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		$reported = array();
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$reported[] = trim( explode( ',', (string) $_SERVER[ $header ] )[0] );
			}
		}
		$forwarded = (bool) array_filter( $reported, static function ( $ip ) use ( $remote ) { return filter_var( $ip, FILTER_VALIDATE_IP ) && $ip !== $remote; } );
		if ( 'direct' === $security['proxy'] ) {
			$checks[] = array( 'site', __( 'Visitor IP for rate limits', 'elaltillo-bookacall' ), $forwarded ? 'warn' : 'ok', $forwarded ? __( 'This request came through a proxy or CDN, but the plugin is set to “directly”. All visitors may share one booking limit. Choose your proxy under Settings → Security.', 'elaltillo-bookacall' ) : __( 'Requests reach WordPress directly; limits use each visitor’s address.', 'elaltillo-bookacall' ), '<a class="button" href="' . esc_url( $settings ) . '">' . esc_html__( 'Settings', 'elaltillo-bookacall' ) . '</a>' );
		} else {
			$checks[] = array( 'site', __( 'Visitor IP for rate limits', 'elaltillo-bookacall' ), 'ok', sprintf( __( 'Trusting %1$s. Your address is detected as %2$s.', 'elaltillo-bookacall' ), 'cloudflare' === $security['proxy'] ? 'Cloudflare' : __( 'your proxy list', 'elaltillo-bookacall' ), ELAB_Security::visitor_ip() ), '<a class="button" href="' . esc_url( $settings ) . '">' . esc_html__( 'Settings', 'elaltillo-bookacall' ) . '</a>' );
		}
		$checks[] = array( 'site', __( 'Bot protection', 'elaltillo-bookacall' ), ELAB_Security::turnstile_enabled() ? 'ok' : 'error', ELAB_Security::turnstile_enabled() ? __( 'Cloudflare Turnstile checks every booking.', 'elaltillo-bookacall' ) : __( 'Turnstile is off: bots can create bookings and make Google send invitations from advisor accounts.', 'elaltillo-bookacall' ), '<a class="button" href="' . esc_url( $settings ) . '">' . esc_html__( 'Settings', 'elaltillo-bookacall' ) . '</a>' );
		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$next = wp_next_scheduled( ELAB_Privacy::CRON );
		$checks[] = array( 'site', __( 'Scheduled tasks', 'elaltillo-bookacall' ), $cron_off ? 'warn' : 'ok', $cron_off ? __( 'WP-Cron is disabled. Make sure a server cron job calls wp-cron.php, or retention clean-up will not run.', 'elaltillo-bookacall' ) : ( $next ? sprintf( __( 'Next retention clean-up: %s.', 'elaltillo-bookacall' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) ) : __( 'Retention clean-up will be scheduled on the next page load.', 'elaltillo-bookacall' ) ), '' );

		// Email.
		$mail = get_option( self::OPTION_MAIL );
		$smtp = has_action( 'phpmailer_init' ) || defined( 'WPMS_ON' ) || class_exists( 'FluentMail\\App\\Services\\Mailer\\Manager' ) || class_exists( 'PostmanOptions' );
		$checks[] = array( 'email', __( 'Mail delivery service', 'elaltillo-bookacall' ), $smtp ? 'ok' : 'warn', $smtp ? __( 'A plugin customises how WordPress sends email.', 'elaltillo-bookacall' ) : __( 'WordPress uses the server’s default mail function, which often lands in spam. Use a transactional mail service before launch.', 'elaltillo-bookacall' ), '' );
		if ( is_array( $mail ) && ! empty( $mail['time'] ) ) {
			$detail = sprintf( $mail['ok'] ? __( 'Test sent to %1$s on %2$s. Check that it arrived in the inbox, not spam.', 'elaltillo-bookacall' ) : __( 'The test to %1$s on %2$s could not be handed to the mailer.', 'elaltillo-bookacall' ), $mail['to'], wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $mail['time'] ) );
			$state = $mail['ok'] ? 'ok' : 'error';
		} else {
			$detail = __( 'No test email has been sent from this page yet.', 'elaltillo-bookacall' );
			$state = 'warn';
		}
		$checks[] = array( 'email', __( 'Test email', 'elaltillo-bookacall' ), $state, $detail, self::action_button( self::MAIL_TEST, __( 'Send test email to me', 'elaltillo-bookacall' ) ) );
		$failed = get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'any', 'numberposts' => 5, 'fields' => 'ids', 'meta_query' => array( 'relation' => 'OR', array( 'key' => ELAB_Notifications::META_BOOKING_MAIL, 'value' => 'failed' ), array( 'key' => ELAB_Notifications::META_VISITOR_MAIL, 'value' => 'failed' ) ) ) );
		$checks[] = array( 'email', __( 'Booking emails', 'elaltillo-bookacall' ), $failed ? 'error' : 'ok', $failed ? sprintf( _n( '%d booking has an email that could not be sent.', '%d or more bookings have emails that could not be sent.', count( $failed ), 'elaltillo-bookacall' ), count( $failed ) ) : __( 'No failed booking emails recorded.', 'elaltillo-bookacall' ), $failed ? '<a class="button" href="' . esc_url( get_edit_post_link( $failed[0] ) ) . '">' . esc_html__( 'Open booking', 'elaltillo-bookacall' ) . '</a>' : '' );

		// Google Calendar.
		$configured = ELAB_Google_Calendar::configured();
		$checks[] = array( 'google', __( 'OAuth credentials', 'elaltillo-bookacall' ), $configured ? 'ok' : 'warn', $configured ? __( 'Client ID and encrypted Client Secret are saved.', 'elaltillo-bookacall' ) : __( 'Not configured: bookings stay as requests that you confirm by hand.', 'elaltillo-bookacall' ), '<a class="button" href="' . esc_url( $settings_google ) . '">' . esc_html__( 'Settings', 'elaltillo-bookacall' ) . '</a>' );
		$host = (string) wp_parse_url( ELAB_Google_Calendar::redirect_uri(), PHP_URL_HOST );
		$local = (bool) preg_match( '/(\.local|\.test|localhost)$/i', $host );
		$checks[] = array( 'google', __( 'Redirect URI', 'elaltillo-bookacall' ), $local ? 'warn' : 'ok', $local ? sprintf( __( '%s is a local address; Google only accepts it for testing. Connect advisors on the production site.', 'elaltillo-bookacall' ), $host ) : ELAB_Google_Calendar::redirect_uri(), '' );
		$live = get_transient( self::TRANSIENT_GOOGLE );
		$checks[] = array( 'google', __( 'Live connection test', 'elaltillo-bookacall' ), is_array( $live ) ? ( $live['failed'] ? 'error' : 'ok' ) : 'info', is_array( $live ) ? sprintf( __( '%1$d of %2$d connected calendars answered on %3$s.', 'elaltillo-bookacall' ), $live['passed'], $live['passed'] + $live['failed'], wp_date( get_option( 'time_format' ), (int) $live['time'] ) ) : __( 'Checks that each advisor’s “El Altillo · Citas” calendar answers, and retries calls waiting for Google.', 'elaltillo-bookacall' ), $configured ? self::action_button( self::GOOGLE_TEST, __( 'Test Google now', 'elaltillo-bookacall' ) ) : '' );
		$waiting = ELAB_Booking_Sync::pending();
		$checks[] = array( 'google', __( 'Calls waiting for Google', 'elaltillo-bookacall' ), $waiting ? 'warn' : 'ok', $waiting ? sprintf( _n( '%d call is saved but its calendar event is not in Google yet. It is retried every hour and when the advisor reconnects.', '%d calls are saved but their calendar events are not in Google yet. They are retried every hour and when the advisor reconnects.', count( $waiting ), 'elaltillo-bookacall' ), count( $waiting ) ) : __( 'Every booked call is in Google Calendar.', 'elaltillo-bookacall' ), $waiting ? '<a class="button" href="' . esc_url( admin_url( 'post.php?post=' . (int) $waiting[0] . '&action=edit' ) ) . '">' . esc_html__( 'Open booking', 'elaltillo-bookacall' ) . '</a>' : '' );

		// Advisors.
		$advisors = get_posts( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$bookable = 0;
		foreach ( $advisors as $advisor ) {
			$issues = ELAB_Advisor_Admin::booking_issues( $advisor->ID );
			$result = is_array( $live ) && isset( $live['advisors'][ $advisor->ID ] ) ? $live['advisors'][ $advisor->ID ] : '';
			if ( $result && true !== $result ) {
				$issues[] = sprintf( __( 'Google test failed: %s', 'elaltillo-bookacall' ), $result );
			}
			$bookable += $issues ? 0 : 1;
			$warning = $issues ? '' : ELAB_Advisor_Admin::google_warning( $advisor->ID );
			$checks[] = array( 'advisors', get_the_title( $advisor ), $issues || $warning ? 'warn' : 'ok', $issues ? implode( ' ', $issues ) : trim( __( 'Bookable.', 'elaltillo-bookacall' ) . ' ' . $warning ), '<a class="button" href="' . esc_url( get_edit_post_link( $advisor->ID ) ) . '">' . esc_html__( 'Edit', 'elaltillo-bookacall' ) . '</a>' );
		}
		if ( ! $bookable ) {
			$checks[] = array( 'advisors', __( 'Booking calendar', 'elaltillo-bookacall' ), 'error', __( 'No advisor is bookable, so visitors see no times.', 'elaltillo-bookacall' ), '' );
		}

		// Privacy and files.
		$storage = ELAB_Private_Attachments::storage_ready();
		$checks[] = array( 'privacy', __( 'Private document storage', 'elaltillo-bookacall' ), $storage ? 'ok' : 'error', $storage ? __( 'Uploads are stored outside the public web folder.', 'elaltillo-bookacall' ) : __( 'No writable folder outside the web root. Visitors cannot attach files until ELAB_PRIVATE_UPLOAD_DIR is set.', 'elaltillo-bookacall' ), '' );
		$months = ELAB_Privacy::retention_months();
		$checks[] = array( 'privacy', __( 'Retention', 'elaltillo-bookacall' ), $months ? 'ok' : 'warn', $months ? sprintf( _n( 'Bookings are deleted %d month after the call.', 'Bookings are deleted %d months after the call.', $months, 'elaltillo-bookacall' ), $months ) : __( 'Bookings are kept forever. Set a retention period to comply with data minimisation.', 'elaltillo-bookacall' ), '<a class="button" href="' . esc_url( $settings_privacy ) . '">' . esc_html__( 'Settings', 'elaltillo-bookacall' ) . '</a>' );
		$policy = (int) get_option( 'wp_page_for_privacy_policy' );
		$published = $policy && 'publish' === get_post_status( $policy );
		$checks[] = array( 'privacy', __( 'Privacy policy page', 'elaltillo-bookacall' ), $published ? 'ok' : 'warn', $published ? sprintf( __( '“%s” is published. Add the booking section from Settings → Privacy → Policy guide.', 'elaltillo-bookacall' ), get_the_title( $policy ) ) : __( 'No published privacy policy page is set.', 'elaltillo-bookacall' ), '<a class="button" href="' . esc_url( admin_url( 'options-privacy.php' ) ) . '">' . esc_html__( 'Privacy settings', 'elaltillo-bookacall' ) . '</a>' );
		return $checks;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sections = array(
			'site'     => __( 'Site', 'elaltillo-bookacall' ),
			'email'    => __( 'Email', 'elaltillo-bookacall' ),
			'google'   => __( 'Google Calendar', 'elaltillo-bookacall' ),
			'advisors' => __( 'Advisors', 'elaltillo-bookacall' ),
			'privacy'  => __( 'Privacy and documents', 'elaltillo-bookacall' ),
		);
		$states = array( 'ok' => __( 'Ready', 'elaltillo-bookacall' ), 'warn' => __( 'Review', 'elaltillo-bookacall' ), 'error' => __( 'Fix', 'elaltillo-bookacall' ), 'info' => __( 'Optional', 'elaltillo-bookacall' ) );
		$checks = self::checks();
		$counts = array_count_values( array_column( $checks, 2 ) );
		$notice = isset( $_GET['elab_status'] ) ? sanitize_key( wp_unslash( $_GET['elab_status'] ) ) : '';
		?>
		<div class="wrap elab-status-page">
			<h1><?php esc_html_e( 'System status', 'elaltillo-bookacall' ); ?></h1>
			<?php if ( 'mail_sent' === $notice || 'mail_failed' === $notice || 'google_tested' === $notice ) : ?>
				<div class="notice notice-<?php echo 'mail_failed' === $notice ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( 'mail_sent' === $notice ? __( 'Test email handed to the mailer. Check your inbox.', 'elaltillo-bookacall' ) : ( 'mail_failed' === $notice ? __( 'WordPress could not send the test email.', 'elaltillo-bookacall' ) : __( 'Google connection test finished.', 'elaltillo-bookacall' ) ) ); ?></p></div>
			<?php endif; ?>
			<p class="elab-status-summary">
				<?php echo esc_html( empty( $counts['error'] ) && empty( $counts['warn'] ) ? __( 'Everything is ready for real bookings.', 'elaltillo-bookacall' ) : sprintf( __( '%1$d to fix, %2$d to review before launch.', 'elaltillo-bookacall' ), $counts['error'] ?? 0, $counts['warn'] ?? 0 ) ); ?>
			</p>
			<?php foreach ( $sections as $key => $title ) :
				$rows = array_filter( $checks, static function ( $check ) use ( $key ) { return $key === $check[0]; } );
				if ( ! $rows ) {
					continue;
				}
				?>
				<h2><?php echo esc_html( $title ); ?></h2>
				<table class="widefat elab-status-table"><tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr><th scope="row"><?php echo esc_html( $row[1] ); ?></th><td class="elab-status-state"><span class="elab-check elab-check--<?php echo esc_attr( $row[2] ); ?>"><?php echo esc_html( $states[ $row[2] ] ); ?></span></td><td><?php echo esc_html( $row[3] ); ?></td><td class="elab-status-action"><?php echo $row[4]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in checks(). ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public function send_test_mail() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot run this test.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::MAIL_TEST );
		$to = wp_get_current_user()->user_email;
		$sent = wp_mail( $to, __( 'Book a Call: test email', 'elaltillo-bookacall' ), __( 'This is a test from El Altillo Book a Call. If it reached your inbox, advisors and visitors will receive booking emails too.', 'elaltillo-bookacall' ) );
		update_option( self::OPTION_MAIL, array( 'time' => time(), 'ok' => (bool) $sent, 'to' => $to ), false );
		wp_safe_redirect( self::url( array( 'elab_status' => $sent ? 'mail_sent' : 'mail_failed' ) ) );
		exit;
	}

	public function test_google() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot run this test.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::GOOGLE_TEST );
		$result = array( 'time' => time(), 'passed' => 0, 'failed' => 0, 'advisors' => array() );
		foreach ( get_posts( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $advisor_id ) {
			if ( ! ELAB_Google_Calendar::linked( $advisor_id ) ) {
				continue;
			}
			$check = ELAB_Google_Calendar::connected( $advisor_id ) ? ELAB_Google_Calendar::check( $advisor_id ) : new WP_Error( 'google_not_ready', __( 'Needs to reconnect Google Calendar.', 'elaltillo-bookacall' ) );
			$ok = ! is_wp_error( $check );
			$result['advisors'][ $advisor_id ] = $ok ? true : $check->get_error_message();
			$result[ $ok ? 'passed' : 'failed' ]++;
		}
		ELAB_Booking_Sync::run();
		set_transient( self::TRANSIENT_GOOGLE, $result, HOUR_IN_SECONDS );
		$to_settings = isset( $_POST['elab_return'] ) && 'settings' === $_POST['elab_return']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		wp_safe_redirect( $to_settings ? ELAB_Google_Settings_Admin::url( 'google', array( 'elab_status' => 'google_tested' ) ) : self::url( array( 'elab_status' => 'google_tested' ) ) );
		exit;
	}
}
