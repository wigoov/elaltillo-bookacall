<?php
/** Google OAuth credentials under the Advisors menu. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Google_Settings_Admin {
	private $hook_suffix;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_elab_save_google_settings', array( $this, 'save' ) );
	}

	public function register_page() {
		$this->hook_suffix = add_submenu_page(
			'edit.php?post_type=' . ELAB_Post_Types::ADVISOR,
			__( 'Booking settings', 'elaltillo-bookacall' ),
			__( 'Settings', 'elaltillo-bookacall' ),
			'manage_options',
			'elab-google-settings',
			array( $this, 'render' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( $this->hook_suffix === $hook ) {
			wp_enqueue_style( 'elab-advisor-admin', plugins_url( 'assets/css/advisor-admin.css', ELAB_FILE ), array(), ELAB_Plugin::asset_version( 'assets/css/advisor-admin.css' ) );
		}
	}

	const TABS = array( 'general', 'google', 'security', 'privacy' );

	/** Current Settings tab; each card renders only on its own tab. */
	public static function tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		return in_array( $tab, self::TABS, true ) ? $tab : 'general';
	}

	public static function url( $tab, $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'page' => 'elab-google-settings', 'tab' => $tab ), $args ), admin_url( 'edit.php' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = array(
			'general'  => __( 'General', 'elaltillo-bookacall' ),
			'google'   => __( 'Google Calendar', 'elaltillo-bookacall' ),
			'security' => __( 'Security', 'elaltillo-bookacall' ),
			'privacy'  => __( 'Privacy', 'elaltillo-bookacall' ),
		);
		$current = self::tab();
		?>
		<div class="wrap elab-settings">
			<h1><?php esc_html_e( 'Booking settings', 'elaltillo-bookacall' ); ?></h1>
			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Settings sections', 'elaltillo-bookacall' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $key === $current ? 'nav-tab-active' : ''; ?>" <?php echo $key === $current ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="elab-settings-body">
				<?php
				if ( 'google' === $current ) {
					$this->render_google();
				} else {
					do_action( 'elab_settings_before' );
				}
				?>
			</div>
		</div>
		<?php
	}

	private function render_google() {
		$client_id = ELAB_Google_Calendar::client_id();
		$secret_saved = ELAB_Google_Calendar::has_saved_secret();
		$configured = ELAB_Google_Calendar::configured();
		$status = isset( $_GET['elab_status'] ) ? sanitize_key( wp_unslash( $_GET['elab_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$redirect_uri = ELAB_Google_Calendar::redirect_uri();
		$redirect_host = wp_parse_url( $redirect_uri, PHP_URL_HOST );
		$local_redirect = is_string( $redirect_host ) && preg_match( '/(\.local|\.test|localhost)$/i', $redirect_host );
		$advisors = get_posts( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
		$connected = count( array_filter( $advisors, array( 'ELAB_Google_Calendar', 'connected' ) ) );
		$revoked = count( array_filter( $advisors, static function ( $id ) {
			return ELAB_Google_Calendar::linked( $id ) && ! ELAB_Google_Calendar::connected( $id );
		} ) );
		$waiting = count( ELAB_Booking_Sync::pending() );
		$live = get_transient( ELAB_System_Status::TRANSIENT_GOOGLE );
		$live_problems = '';
		foreach ( is_array( $live ) ? (array) ( $live['advisors'] ?? array() ) : array() as $advisor_id => $outcome ) {
			if ( true !== $outcome ) {
				$live_problems .= ' ' . get_the_title( (int) $advisor_id ) . ': ' . $outcome;
			}
		}
		$notices = array(
			'saved'             => array( 'success', __( 'Google settings saved.', 'elaltillo-bookacall' ) ),
			'missing_id'        => array( 'error', __( 'Enter the Google Client ID before saving.', 'elaltillo-bookacall' ) ),
			'missing_secret'    => array( 'error', __( 'Enter the Client Secret for the new OAuth client.', 'elaltillo-bookacall' ) ),
			'encryption_failed' => array( 'error', __( 'The Client Secret could not be encrypted on this server. No changes were saved.', 'elaltillo-bookacall' ) ),
			'google_tested'     => array( 'success', __( 'Google connection test finished.', 'elaltillo-bookacall' ) ),
		);
		if ( isset( $notices[ $status ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notices[ $status ][0] ), esc_html( $notices[ $status ][1] ) );
		}
		$rows = array(
			array( __( 'OAuth client', 'elaltillo-bookacall' ), $configured ? 'ok' : 'warn', $configured ? __( 'Client ID and encrypted Client Secret saved.', 'elaltillo-bookacall' ) : __( 'Not configured yet. Follow the setup guide below.', 'elaltillo-bookacall' ) ),
			array( __( 'Advisors connected', 'elaltillo-bookacall' ), $advisors && $connected === count( $advisors ) ? 'ok' : 'warn', sprintf( __( '%1$d of %2$d published advisors.', 'elaltillo-bookacall' ), $connected, count( $advisors ) ) . ( $revoked ? ' ' . sprintf( _n( '%d needs to reconnect.', '%d need to reconnect.', $revoked, 'elaltillo-bookacall' ), $revoked ) : '' ) ),
			array( __( 'Calls waiting for Google', 'elaltillo-bookacall' ), $waiting ? 'warn' : 'ok', $waiting ? sprintf( _n( '%d call is up to date in WordPress but not in Google yet (an event to add or remove). It is retried every hour.', '%d calls are up to date in WordPress but not in Google yet (events to add or remove). They are retried every hour.', $waiting, 'elaltillo-bookacall' ), $waiting ) : __( 'None. Google Calendar matches every booking.', 'elaltillo-bookacall' ) ),
			array( __( 'Last live test', 'elaltillo-bookacall' ), is_array( $live ) ? ( $live['failed'] ? 'error' : 'ok' ) : 'info', is_array( $live ) ? sprintf( __( '%1$d of %2$d calendars answered at %3$s.', 'elaltillo-bookacall' ), $live['passed'], $live['passed'] + $live['failed'], wp_date( get_option( 'time_format' ), (int) $live['time'] ) ) . $live_problems : __( 'Not run yet.', 'elaltillo-bookacall' ) ),
			array( __( 'Encryption key', 'elaltillo-bookacall' ), ELAB_Google_Calendar::has_own_key() ? 'ok' : 'info', ELAB_Google_Calendar::has_own_key() ? __( 'ELAB_ENCRYPTION_KEY from wp-config.php protects stored tokens.', 'elaltillo-bookacall' ) : __( 'Tokens are encrypted with the WordPress auth salt. Define ELAB_ENCRYPTION_KEY (32+ random characters) in wp-config.php so a salt change does not force every advisor to reconnect.', 'elaltillo-bookacall' ) ),
		);
		$states = array( 'ok' => __( 'Ready', 'elaltillo-bookacall' ), 'warn' => __( 'Review', 'elaltillo-bookacall' ), 'error' => __( 'Fix', 'elaltillo-bookacall' ), 'info' => __( 'Optional', 'elaltillo-bookacall' ) );
		?>
		<div class="card elab-settings-card">
			<h2><?php esc_html_e( 'Connection', 'elaltillo-bookacall' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Availability is managed here in WordPress. Google is only used to add each booked call to the advisor’s calendar and invite the visitor. When an advisor connects, the site creates a separate calendar, “El Altillo · Citas”, and can only see and change that one: never their other calendars or events. If Google is unreachable, bookings are still saved and added later.', 'elaltillo-bookacall' ); ?></p>
			<table class="widefat elab-status-table elab-settings-status"><tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr><th scope="row"><?php echo esc_html( $row[0] ); ?></th><td class="elab-status-state"><span class="elab-check elab-check--<?php echo esc_attr( $row[1] ); ?>"><?php echo esc_html( $states[ $row[1] ] ); ?></span></td><td><?php echo esc_html( $row[2] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php if ( $configured ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="elab-settings-actions">
					<input type="hidden" name="action" value="<?php echo esc_attr( ELAB_System_Status::GOOGLE_TEST ); ?>">
					<input type="hidden" name="elab_return" value="settings">
					<?php wp_nonce_field( ELAB_System_Status::GOOGLE_TEST ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Test connected calendars', 'elaltillo-bookacall' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<div class="card elab-settings-card">
			<h2><?php esc_html_e( 'OAuth client', 'elaltillo-bookacall' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="elab_save_google_settings">
				<?php wp_nonce_field( 'elab_save_google_settings' ); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><label for="elab-google-redirect-uri"><?php esc_html_e( 'Authorized redirect URI', 'elaltillo-bookacall' ); ?></label></th><td><input id="elab-google-redirect-uri" type="text" class="large-text code" readonly value="<?php echo esc_attr( $redirect_uri ); ?>" onclick="this.select()"><p class="description"><?php esc_html_e( 'Add this exact address to the OAuth client in Google Cloud.', 'elaltillo-bookacall' ); ?></p>
						<?php if ( $local_redirect ) : ?><p class="description elab-google-local-note"><?php esc_html_e( 'This is a local address. Google only accepts it for testing; connect advisors on the production site.', 'elaltillo-bookacall' ); ?></p><?php endif; ?></td></tr>
					<tr><th scope="row"><label for="elab-google-client-id"><?php esc_html_e( 'Client ID', 'elaltillo-bookacall' ); ?></label></th><td><input id="elab-google-client-id" name="elab_google_client_id" type="text" class="large-text code" maxlength="512" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $client_id ); ?>" placeholder="123456789-abc.apps.googleusercontent.com"><p class="description"><?php esc_html_e( 'Public identifier of the OAuth client; it ends in .apps.googleusercontent.com.', 'elaltillo-bookacall' ); ?></p></td></tr>
					<tr><th scope="row"><label for="elab-google-client-secret"><?php esc_html_e( 'Client Secret', 'elaltillo-bookacall' ); ?></label></th><td><input id="elab-google-client-secret" name="elab_google_client_secret" type="password" class="large-text" maxlength="512" autocomplete="new-password" placeholder="<?php echo esc_attr( $secret_saved ? __( 'Saved; leave blank to keep it', 'elaltillo-bookacall' ) : __( 'Paste the Client Secret', 'elaltillo-bookacall' ) ); ?>"><p class="description"><?php echo esc_html( $secret_saved ? __( 'Encrypted and saved. It is never displayed again.', 'elaltillo-bookacall' ) : __( 'No Client Secret saved yet.', 'elaltillo-bookacall' ) ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Video call', 'elaltillo-bookacall' ); ?></th><td><input type="hidden" name="elab_google_meet" value="0"><label><input type="checkbox" name="elab_google_meet" value="1" <?php checked( ELAB_Google_Calendar::meet_enabled() ); ?>> <?php esc_html_e( 'Add a Google Meet link to every confirmed call', 'elaltillo-bookacall' ); ?></label><p class="description"><?php esc_html_e( 'Shown in the Google invitation, on the confirmation screen and on the visitor’s private booking page.', 'elaltillo-bookacall' ); ?></p></td></tr>
				</tbody></table>
				<p class="description"><?php esc_html_e( 'Changing the Client ID disconnects every advisor; each must connect again.', 'elaltillo-bookacall' ); ?></p>
				<?php submit_button( __( 'Save Google settings', 'elaltillo-bookacall' ) ); ?>
			</form>
		</div>

		<details class="card elab-settings-card elab-settings-guide" <?php echo $configured ? '' : 'open'; ?>>
			<summary><h2><?php esc_html_e( 'Setup guide', 'elaltillo-bookacall' ); ?></h2></summary>
			<ol class="elab-google-setup-steps">
				<li><strong><?php esc_html_e( 'Create or select a Google Cloud project.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'Use the account that will own this integration.', 'elaltillo-bookacall' ); ?> <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Google Cloud Console', 'elaltillo-bookacall' ); ?></a></li>
				<li><strong><?php esc_html_e( 'Enable the Google Calendar API.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'In APIs & Services, find Google Calendar API and select Enable.', 'elaltillo-bookacall' ); ?></li>
				<li><strong><?php esc_html_e( 'Choose the app audience.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'In Google Auth Platform → Audience: choose Internal when every advisor uses an account of your own Google Workspace; no Google review is needed. Choose External if any advisor uses a personal Gmail account: while the app is in Testing, add each advisor as a test user, and note that access then expires every 7 days. Publish the app and complete Google’s verification (the calendar scope is sensitive) before going live.', 'elaltillo-bookacall' ); ?> <a href="https://support.google.com/cloud/answer/15549945" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'About audiences', 'elaltillo-bookacall' ); ?></a></li>
				<li><strong><?php esc_html_e( 'Complete Branding and Data Access.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'Add your logo, home page and privacy policy, then these scopes:', 'elaltillo-bookacall' ); ?> <code>calendar.app.created</code>, <code>calendar.settings.readonly</code>, <code>openid</code>, <code>email</code>. <?php esc_html_e( 'The first only reaches calendars created by this site; the second only reads settings such as the time zone, never events. No Gmail access is requested.', 'elaltillo-bookacall' ); ?></li>
				<li><strong><?php esc_html_e( 'Create an OAuth client of type Web application.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'Add the redirect URI shown above, then paste the Client ID and Client Secret here.', 'elaltillo-bookacall' ); ?></li>
				<li><strong><?php esc_html_e( 'Connect each advisor.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'Set the advisor’s Google email and WordPress account in their profile. The advisor opens Users → Profile and selects Connect Google Calendar; Google opens on that account, the plugin checks the email and creates the “El Altillo · Citas” calendar in it.', 'elaltillo-bookacall' ); ?></li>
				<li><strong><?php esc_html_e( 'Test.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'Use “Test connected calendars”, then make one real booking: the visitor receives Google’s invitation and the event appears in the advisor’s “El Altillo · Citas” calendar.', 'elaltillo-bookacall' ); ?></li>
			</ol>
		</details>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change Google Calendar settings.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'elab_save_google_settings' );
		update_option( 'elab_google_meet', isset( $_POST['elab_google_meet'] ) && '1' === $_POST['elab_google_meet'] ? '1' : '0', false );
		$submitted_id = isset( $_POST['elab_google_client_id'] ) && is_string( $_POST['elab_google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['elab_google_client_id'] ) ) : '';
		$submitted_secret = isset( $_POST['elab_google_client_secret'] ) && is_string( $_POST['elab_google_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['elab_google_client_secret'] ) ) : '';
		$current_id = ELAB_Google_Calendar::client_id();
		$client_id = trim( $submitted_id ) ?: $current_id;
		$client_secret = trim( $submitted_secret );
		$status = 'saved';
		if ( '' === $client_id ) {
			$status = 'missing_id';
		} elseif ( '' === $client_secret && ( ! ELAB_Google_Calendar::has_saved_secret() || $client_id !== $current_id ) ) {
			$status = 'missing_secret';
		} elseif ( is_wp_error( ELAB_Google_Calendar::save_credentials( $client_id, $client_secret ) ) ) {
			$status = 'encryption_failed';
		}
		wp_safe_redirect( self::url( 'google', array( 'elab_status' => $status ) ) );
		exit;
	}
}
