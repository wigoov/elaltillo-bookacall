<?php
/**
 * Bot protection (Cloudflare Turnstile) and the visitor IP used by rate limits.
 *
 * Every booking makes Google send an invitation from the advisor's account, so the public
 * form must prove a human is behind it. Behind a proxy or CDN, REMOTE_ADDR is the proxy;
 * a forwarded header is trusted only when the request really comes from a trusted proxy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Security {
	const OPTION = 'elab_security';
	const ACTION = 'elab_save_security';
	const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

	/** Cloudflare's published edge ranges (https://www.cloudflare.com/ips/). */
	const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	);

	public function __construct() {
		add_action( 'elab_settings_before', array( $this, 'render_settings' ), 15 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save_settings' ) );
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'site_key'    => isset( $saved['site_key'] ) ? (string) $saved['site_key'] : '',
			'secret'      => isset( $saved['secret'] ) ? (string) $saved['secret'] : '',
			'proxy'       => isset( $saved['proxy'] ) && in_array( $saved['proxy'], array( 'direct', 'cloudflare', 'custom' ), true ) ? $saved['proxy'] : 'direct',
			'proxy_ips'   => isset( $saved['proxy_ips'] ) && is_array( $saved['proxy_ips'] ) ? $saved['proxy_ips'] : array(),
			'header'      => isset( $saved['header'] ) && in_array( $saved['header'], array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ), true ) ? $saved['header'] : 'HTTP_X_FORWARDED_FOR',
		);
	}

	/* ---------- Turnstile ---------- */

	public static function turnstile_site_key() {
		return self::turnstile_enabled() ? self::settings()['site_key'] : '';
	}

	public static function turnstile_enabled() {
		$settings = self::settings();
		return '' !== $settings['site_key'] && '' !== $settings['secret'];
	}

	/** True, or a WP_Error explaining why the challenge failed. Fails closed. */
	public static function verify_turnstile( $token ) {
		if ( ! self::turnstile_enabled() ) {
			return true;
		}
		$token = is_string( $token ) ? trim( $token ) : '';
		$fail = new WP_Error( 'bot_check_failed', __( 'Please complete the security check and try again.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		if ( '' === $token || strlen( $token ) > 2048 ) {
			return $fail;
		}
		$secret = ELAB_Google_Calendar::decrypt( self::settings()['secret'] );
		if ( ! $secret ) {
			return new WP_Error( 'bot_check_unavailable', __( 'The security check is not available right now. Please try again later.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		$response = wp_remote_post( self::VERIFY_URL, array(
			'timeout' => 10,
			'body'    => array( 'secret' => $secret, 'response' => $token, 'remoteip' => self::visitor_ip() ),
		) );
		$data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bot_check_unavailable', __( 'The security check is not available right now. Please try again later.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		if ( true !== ( $data['success'] ?? false ) ) {
			return $fail;
		}
		// Cloudflare's published test keys always pass and report example.com; real keys
		// report the page's hostname, which must be this site (with or without www).
		$testing = ! empty( $data['metadata']['result_with_testing_key'] );
		if ( ! $testing && ! empty( $data['hostname'] ) && ! in_array( strtolower( $data['hostname'] ), self::allowed_hostnames(), true ) ) {
			return $fail;
		}
		return true;
	}

	public static function allowed_hostnames() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$bare = preg_replace( '/^www\./', '', $host );
		return array_values( array_unique( array_map( 'strtolower', (array) apply_filters( 'elab_turnstile_hostnames', array( $host, $bare, 'www.' . $bare ) ) ) ) );
	}

	/* ---------- Visitor IP ---------- */

	public static function ip_in_range( $ip, $range ) {
		if ( false === strpos( $range, '/' ) ) {
			$range .= false === strpos( $range, ':' ) ? '/32' : '/128';
		}
		list( $subnet, $bits ) = explode( '/', $range, 2 );
		$ip_bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input returns false.
		$subnet_bin = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$bits = (int) $bits;
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) || $bits < 0 || $bits > strlen( $ip_bin ) * 8 ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( ! $rest ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask );
	}

	private static function trusted( $ip, $ranges ) {
		foreach ( $ranges as $range ) {
			if ( self::ip_in_range( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The visitor's address. A forwarded header is used only when REMOTE_ADDR is a trusted
	 * proxy; in a forwarded chain the right-most address that is not a trusted proxy wins,
	 * so a visitor cannot spoof their address by sending the header themselves.
	 */
	public static function visitor_ip( $server = null ) {
		$server = null === $server ? $_SERVER : $server;
		$remote = isset( $server['REMOTE_ADDR'] ) ? trim( (string) $server['REMOTE_ADDR'] ) : '';
		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
		$settings = self::settings();
		if ( ! $remote || 'direct' === $settings['proxy'] ) {
			return $remote ?: 'unknown';
		}
		$ranges = 'cloudflare' === $settings['proxy'] ? self::CLOUDFLARE_RANGES : $settings['proxy_ips'];
		if ( ! self::trusted( $remote, $ranges ) ) {
			return $remote;
		}
		if ( 'cloudflare' === $settings['proxy'] ) {
			$ip = isset( $server['HTTP_CF_CONNECTING_IP'] ) ? trim( (string) $server['HTTP_CF_CONNECTING_IP'] ) : '';
			return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : $remote;
		}
		$chain = isset( $server[ $settings['header'] ] ) ? array_map( 'trim', explode( ',', (string) $server[ $settings['header'] ] ) ) : array();
		for ( $index = count( $chain ) - 1; $index >= 0; $index-- ) {
			$candidate = $chain[ $index ];
			if ( ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				break;
			}
			if ( ! self::trusted( $candidate, $ranges ) ) {
				return $candidate;
			}
		}
		return $remote;
	}

	/* ---------- Settings card ---------- */

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::settings();
		$status = isset( $_GET['elab_status'] ) ? sanitize_key( wp_unslash( $_GET['elab_status'] ) ) : '';
		?>
		<?php if ( 'security_saved' === $status ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Security settings saved.', 'elaltillo-bookacall' ); ?></p></div>
		<?php elseif ( 'security_failed' === $status ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'The Turnstile secret could not be encrypted on this server. No changes were saved.', 'elaltillo-bookacall' ); ?></p></div>
		<?php endif; ?>
		<div class="card elab-google-settings-card">
			<h2><?php esc_html_e( 'Security', 'elaltillo-bookacall' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<h3><?php esc_html_e( 'Bot protection (Cloudflare Turnstile)', 'elaltillo-bookacall' ); ?></h3>
				<p class="description"><?php echo wp_kses( sprintf( __( 'Every booking sends a calendar invitation from the advisor’s account, so bots must be stopped. Create a free widget in <a href="%s" target="_blank" rel="noopener noreferrer">Cloudflare → Turnstile</a> for this domain (mode: Managed) and paste its keys. Leave both empty to turn the check off.', 'elaltillo-bookacall' ), 'https://dash.cloudflare.com/?to=/:account/turnstile' ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ); ?></p>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><label for="elab-turnstile-site"><?php esc_html_e( 'Site key', 'elaltillo-bookacall' ); ?></label></th><td><input type="text" class="regular-text code" id="elab-turnstile-site" name="elab_turnstile_site" maxlength="200" value="<?php echo esc_attr( $settings['site_key'] ); ?>" autocomplete="off"></td></tr>
					<tr><th scope="row"><label for="elab-turnstile-secret"><?php esc_html_e( 'Secret key', 'elaltillo-bookacall' ); ?></label></th><td><input type="password" class="regular-text" id="elab-turnstile-secret" name="elab_turnstile_secret" maxlength="200" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings['secret'] ? __( 'Saved; leave blank to keep it', 'elaltillo-bookacall' ) : __( 'Paste the secret key', 'elaltillo-bookacall' ) ); ?>">
						<?php if ( $settings['secret'] ) : ?><label class="elab-inline-check"><input type="checkbox" name="elab_turnstile_clear" value="1"> <?php esc_html_e( 'Remove saved keys', 'elaltillo-bookacall' ); ?></label><?php endif; ?>
						<p class="description"><?php echo esc_html( self::turnstile_enabled() ? __( 'Turnstile is on: bookings need a passed check.', 'elaltillo-bookacall' ) : __( 'Turnstile is off.', 'elaltillo-bookacall' ) ); ?></p></td></tr>
				</tbody></table>
				<h3><?php esc_html_e( 'Visitor IP behind a proxy', 'elaltillo-bookacall' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Booking limits count requests per visitor address. Behind a CDN or load balancer every request seems to come from the proxy, so tell the plugin which proxy to trust.', 'elaltillo-bookacall' ); ?></p>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e( 'Requests arrive', 'elaltillo-bookacall' ); ?></th><td><fieldset>
						<label><input type="radio" name="elab_proxy" value="direct" <?php checked( 'direct', $settings['proxy'] ); ?>> <?php esc_html_e( 'Directly (no proxy)', 'elaltillo-bookacall' ); ?></label><br>
						<label><input type="radio" name="elab_proxy" value="cloudflare" <?php checked( 'cloudflare', $settings['proxy'] ); ?>> <?php esc_html_e( 'Through Cloudflare (uses CF-Connecting-IP from Cloudflare addresses only)', 'elaltillo-bookacall' ); ?></label><br>
						<label><input type="radio" name="elab_proxy" value="custom" <?php checked( 'custom', $settings['proxy'] ); ?>> <?php esc_html_e( 'Through my own proxy or load balancer', 'elaltillo-bookacall' ); ?></label>
					</fieldset></td></tr>
					<tr><th scope="row"><label for="elab-proxy-ips"><?php esc_html_e( 'Trusted proxy addresses', 'elaltillo-bookacall' ); ?></label></th><td><textarea id="elab-proxy-ips" name="elab_proxy_ips" rows="3" class="large-text code" placeholder="10.0.0.0/8&#10;203.0.113.7"><?php echo esc_textarea( implode( "\n", $settings['proxy_ips'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'Only for “my own proxy”. One IP or CIDR range per line.', 'elaltillo-bookacall' ); ?></p>
						<label for="elab-proxy-header"><?php esc_html_e( 'Header', 'elaltillo-bookacall' ); ?></label> <select id="elab-proxy-header" name="elab_proxy_header"><option value="HTTP_X_FORWARDED_FOR" <?php selected( 'HTTP_X_FORWARDED_FOR', $settings['header'] ); ?>>X-Forwarded-For</option><option value="HTTP_X_REAL_IP" <?php selected( 'HTTP_X_REAL_IP', $settings['header'] ); ?>>X-Real-IP</option></select></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Your address as seen now', 'elaltillo-bookacall' ); ?></th><td><code><?php echo esc_html( self::visitor_ip() ); ?></code> <span class="description"><?php echo esc_html( sprintf( __( '(connection from %s)', 'elaltillo-bookacall' ), isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '—' ) ); ?></span></td></tr>
				</tbody></table>
				<?php submit_button( __( 'Save security settings', 'elaltillo-bookacall' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change security settings.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$settings = self::settings();
		$field = static function ( $name ) {
			return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		};
		$settings['site_key'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', $field( 'elab_turnstile_site' ) );
		$secret = trim( $field( 'elab_turnstile_secret' ) );
		if ( '' !== $secret ) {
			$encrypted = ELAB_Google_Calendar::encrypt( $secret );
			if ( ! $encrypted ) {
				wp_safe_redirect( add_query_arg( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'page' => 'elab-google-settings', 'elab_status' => 'security_failed' ), admin_url( 'edit.php' ) ) );
				exit;
			}
			$settings['secret'] = $encrypted;
		}
		if ( '1' === $field( 'elab_turnstile_clear' ) || '' === $settings['site_key'] ) {
			$settings['site_key'] = '';
			$settings['secret'] = '';
		}
		$proxy = $field( 'elab_proxy' );
		$settings['proxy'] = in_array( $proxy, array( 'direct', 'cloudflare', 'custom' ), true ) ? $proxy : 'direct';
		$header = $field( 'elab_proxy_header' );
		$settings['header'] = in_array( $header, array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ), true ) ? $header : 'HTTP_X_FORWARDED_FOR';
		$raw = isset( $_POST['elab_proxy_ips'] ) && is_string( $_POST['elab_proxy_ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['elab_proxy_ips'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		$ranges = array();
		foreach ( preg_split( '/[\s,]+/', $raw ) as $range ) {
			$address = explode( '/', $range )[0];
			if ( $range && filter_var( $address, FILTER_VALIDATE_IP ) && self::ip_in_range( $address, $range ) ) {
				$ranges[] = $range;
			}
		}
		$settings['proxy_ips'] = array_values( array_unique( $ranges ) );
		update_option( self::OPTION, $settings, false );
		wp_safe_redirect( add_query_arg( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'page' => 'elab-google-settings', 'elab_status' => 'security_saved' ), admin_url( 'edit.php' ) ) );
		exit;
	}
}
