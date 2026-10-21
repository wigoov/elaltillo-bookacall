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
			__( 'Google Calendar Settings', 'elaltillo-bookacall' ),
			__( 'Settings', 'elaltillo-bookacall' ),
			'manage_options',
			'elab-google-settings',
			array( $this, 'render' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( $this->hook_suffix === $hook ) {
			wp_enqueue_style( 'elab-advisor-admin', plugins_url( 'assets/css/advisor-admin.css', ELAB_FILE ), array(), ELAB_VERSION );
		}
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$client_id_saved = '' !== ELAB_Google_Calendar::client_id();
		$secret_saved = ELAB_Google_Calendar::has_saved_secret();
		$status = isset( $_GET['elab_status'] ) ? sanitize_key( wp_unslash( $_GET['elab_status'] ) ) : '';
		$redirect_uri = ELAB_Google_Calendar::redirect_uri();
		$redirect_host = wp_parse_url( $redirect_uri, PHP_URL_HOST );
		$local_redirect = is_string( $redirect_host ) && preg_match( '/\.(local|test)$/i', $redirect_host );
		?>
		<div class="wrap elab-google-settings-page">
			<h1><?php esc_html_e( 'Google Calendar Settings', 'elaltillo-bookacall' ); ?></h1>
			<p><?php esc_html_e( 'Set up one Google Cloud OAuth client for this site. Each advisor then connects their own Google account from their WordPress profile.', 'elaltillo-bookacall' ); ?></p>
			<?php if ( 'saved' === $status ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google credentials saved.', 'elaltillo-bookacall' ); ?></p></div>
			<?php elseif ( 'missing_id' === $status ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Enter the Google Client ID before saving.', 'elaltillo-bookacall' ); ?></p></div>
			<?php elseif ( 'missing_secret' === $status ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Enter the Client Secret for the new OAuth client.', 'elaltillo-bookacall' ); ?></p></div>
			<?php elseif ( 'encryption_failed' === $status ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The Client Secret could not be encrypted on this server. No changes were saved.', 'elaltillo-bookacall' ); ?></p></div>
			<?php endif; ?>
			<div class="card elab-google-settings-card">
				<h2><?php esc_html_e( 'Connect the Google Calendar API', 'elaltillo-bookacall' ); ?></h2>
				<ol class="elab-google-setup-steps">
					<li><strong><?php esc_html_e( 'Create or select a Google Cloud project.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'Open Google Cloud Console with the account that will own this integration.', 'elaltillo-bookacall' ); ?> <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Google Cloud Console', 'elaltillo-bookacall' ); ?></a></li>
					<li><strong><?php esc_html_e( 'Enable the Google Calendar API.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'In APIs & Services, find Google Calendar API and select Enable.', 'elaltillo-bookacall' ); ?></li>
					<li><strong><?php esc_html_e( 'Configure the OAuth consent screen.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'In Google Auth Platform, complete Branding, Audience, and Data Access. Choose Internal only when every advisor belongs to the same Google Workspace organization; otherwise choose External. For an External app in Testing, add each advisor as a test user.', 'elaltillo-bookacall' ); ?></li>
					<li><strong><?php esc_html_e( 'Add the required scopes.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'In Data Access, add the Calendar scopes used by this plugin:', 'elaltillo-bookacall' ); ?> <code>https://www.googleapis.com/auth/calendar.events</code> <?php esc_html_e( 'and', 'elaltillo-bookacall' ); ?> <code>https://www.googleapis.com/auth/calendar.freebusy</code>. <?php esc_html_e( 'The connection also requests openid and email to verify which Google account was connected; it does not request Gmail mailbox access.', 'elaltillo-bookacall' ); ?></li>
					<li><strong><?php esc_html_e( 'Create an OAuth client.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'In Google Auth Platform → Clients, create a client with application type Web application. Add the exact URI below to Authorized redirect URIs.', 'elaltillo-bookacall' ); ?></li>
				</ol>
				<label for="elab-google-redirect-uri"><strong><?php esc_html_e( 'Authorized redirect URI', 'elaltillo-bookacall' ); ?></strong></label>
				<input id="elab-google-redirect-uri" type="text" class="large-text code" readonly value="<?php echo esc_attr( $redirect_uri ); ?>">
				<?php if ( $local_redirect ) : ?>
					<p class="description elab-google-local-note"><?php esc_html_e( 'This is a local development address. Google generally requires a public domain with HTTPS for this redirect URI. Complete advisor authorization on the production site, where this field will show the production URI.', 'elaltillo-bookacall' ); ?></p>
				<?php endif; ?>
				<ol class="elab-google-setup-steps" start="6">
					<li><strong><?php esc_html_e( 'Save the client credentials below.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'Copy the Client ID and Client Secret from Google Cloud into this page.', 'elaltillo-bookacall' ); ?></li>
					<li><strong><?php esc_html_e( 'Connect each advisor.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'Set Advisor Google email and assign a WordPress account in the advisor profile. The advisor opens Users → Profile, selects Connect Google Calendar, chooses that exact Google account, and grants access. The plugin checks the email and primary calendar before completing the connection.', 'elaltillo-bookacall' ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'External apps in Testing can issue authorizations that expire after seven days. Before relying on recurring bookings, move the OAuth app to production and complete any verification Google requires.', 'elaltillo-bookacall' ); ?> <a href="https://support.google.com/cloud/answer/15549945" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'About app audiences and testing', 'elaltillo-bookacall' ); ?></a></p>
				<p class="description"><?php esc_html_e( 'Google documentation:', 'elaltillo-bookacall' ); ?> <a href="https://developers.google.com/identity/protocols/oauth2/web-server" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'OAuth web server flow', 'elaltillo-bookacall' ); ?></a> · <a href="https://developers.google.com/workspace/calendar/api/auth" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Calendar API scopes', 'elaltillo-bookacall' ); ?></a></p>
				<p class="description"><?php esc_html_e( 'Weekly hours stay in this plugin as availability rules. They are checked against Google busy times; only confirmed bookings create Google Calendar events. Advisors receive their hours by email after connecting or changing availability.', 'elaltillo-bookacall' ); ?></p>
			</div>
			<div class="card elab-google-settings-card">
				<h2><?php esc_html_e( 'OAuth credentials', 'elaltillo-bookacall' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="elab_save_google_settings">
					<?php wp_nonce_field( 'elab_save_google_settings' ); ?>
					<table class="form-table" role="presentation"><tbody>
						<tr><th scope="row"><label for="elab-google-client-id"><?php esc_html_e( 'Client ID', 'elaltillo-bookacall' ); ?></label></th><td><input id="elab-google-client-id" name="elab_google_client_id" type="password" class="regular-text" maxlength="512" autocomplete="new-password" placeholder="<?php echo esc_attr( $client_id_saved ? __( 'Saved; leave blank to keep it', 'elaltillo-bookacall' ) : __( 'Paste the Client ID', 'elaltillo-bookacall' ) ); ?>"><p class="description"><?php echo esc_html( $client_id_saved ? __( 'Client ID saved.', 'elaltillo-bookacall' ) : __( 'No Client ID saved yet.', 'elaltillo-bookacall' ) ); ?></p></td></tr>
						<tr><th scope="row"><label for="elab-google-client-secret"><?php esc_html_e( 'Client Secret', 'elaltillo-bookacall' ); ?></label></th><td><input id="elab-google-client-secret" name="elab_google_client_secret" type="password" class="regular-text" maxlength="512" autocomplete="new-password" placeholder="<?php echo esc_attr( $secret_saved ? __( 'Saved; leave blank to keep it', 'elaltillo-bookacall' ) : __( 'Paste the Client Secret', 'elaltillo-bookacall' ) ); ?>"><p class="description"><?php echo esc_html( $secret_saved ? __( 'Client Secret encrypted and saved. It is never displayed again.', 'elaltillo-bookacall' ) : __( 'No Client Secret saved yet.', 'elaltillo-bookacall' ) ); ?></p></td></tr>
					</tbody></table>
					<?php submit_button( __( 'Save settings', 'elaltillo-bookacall' ) ); ?>
				</form>
			</div>
			<?php if ( ELAB_Google_Calendar::configured() ) : ?>
				<p><strong><?php esc_html_e( 'Ready to connect advisors.', 'elaltillo-bookacall' ); ?></strong> <?php esc_html_e( 'If you change the OAuth client, each advisor must authorize their calendar again.', 'elaltillo-bookacall' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Save both credentials to enable calendar connections.', 'elaltillo-bookacall' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change Google Calendar settings.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'elab_save_google_settings' );
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
		wp_safe_redirect( add_query_arg( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'page' => 'elab-google-settings', 'elab_status' => $status ), admin_url( 'edit.php' ) ) );
		exit;
	}
}
