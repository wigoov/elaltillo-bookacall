<?php
/**
 * Personal data handling: retention clean-up, WordPress export/erase tools and the
 * suggested privacy policy text.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Privacy {
	const OPTION_RETENTION = 'elab_retention_months';
	const CRON = 'elab_retention_cleanup';
	const ACTION = 'elab_save_privacy';
	const BATCH = 50;
	const PAGE = 20;

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'cleanup' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
		add_action( 'elab_settings_before', array( $this, 'render_settings' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save_settings' ) );
	}

	/** Months to keep a booking after its call; 0 keeps records until deleted by hand. */
	public static function retention_months() {
		return min( 120, max( 0, (int) get_option( self::OPTION_RETENTION, 0 ) ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Permanently delete bookings whose call ended more than the retention period ago.
	 * Attachments go with them (ELAB_Private_Attachments hooks before_delete_post).
	 *
	 * @return int Records deleted in this run.
	 */
	public static function cleanup() {
		$months = self::retention_months();
		if ( ! $months ) {
			return 0;
		}
		$cutoff = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '-' . $months . ' months' )->format( 'Y-m-d H:i:s' );
		$ids = get_posts( array(
			'post_type'   => ELAB_Post_Types::BOOKING,
			'post_status' => 'any',
			'numberposts' => self::BATCH,
			'fields'      => 'ids',
			'meta_query'  => array( array( 'key' => '_elab_start_utc', 'value' => $cutoff, 'compare' => '<', 'type' => 'DATETIME' ) ),
		) );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		update_option( 'elab_retention_last_run', array( 'time' => time(), 'deleted' => count( $ids ) ), false );
		if ( count( $ids ) === self::BATCH ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON );
		}
		return count( $ids );
	}

	private static function bookings_for( $email, $page ) {
		return get_posts( array(
			'post_type'   => ELAB_Post_Types::BOOKING,
			'post_status' => 'any',
			'numberposts' => self::PAGE,
			'paged'       => max( 1, (int) $page ),
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'fields'      => 'ids',
			'meta_query'  => array( array( 'key' => '_elab_contact_email', 'value' => sanitize_email( $email ) ) ),
		) );
	}

	public function register_exporter( $exporters ) {
		$exporters['elaltillo-bookacall'] = array(
			'exporter_friendly_name' => __( 'Call bookings', 'elaltillo-bookacall' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public function register_eraser( $erasers ) {
		$erasers['elaltillo-bookacall'] = array(
			'eraser_friendly_name' => __( 'Call bookings', 'elaltillo-bookacall' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function export( $email, $page = 1 ) {
		$ids = self::bookings_for( $email, $page );
		$items = array();
		foreach ( $ids as $id ) {
			$files = get_post_meta( $id, '_elab_files', true );
			$fields = array(
				__( 'Call time (UTC)', 'elaltillo-bookacall' ) => get_post_meta( $id, '_elab_start_utc', true ),
				__( 'Advisor', 'elaltillo-bookacall' ) => get_the_title( (int) get_post_meta( $id, '_elab_advisor', true ) ),
				__( 'Status', 'elaltillo-bookacall' ) => get_post_meta( $id, '_elab_status', true ),
				__( 'Name', 'elaltillo-bookacall' ) => get_post_meta( $id, '_elab_name', true ),
				__( 'Email', 'elaltillo-bookacall' ) => get_post_meta( $id, '_elab_contact_email', true ),
				__( 'Sector', 'elaltillo-bookacall' ) => get_post_meta( $id, '_elab_sector', true ),
				__( 'Message', 'elaltillo-bookacall' ) => get_post_meta( $id, '_elab_message', true ),
				__( 'Meeting language', 'elaltillo-bookacall' ) => ELAB_Booking_Service::language_label( get_post_meta( $id, '_elab_language', true ) ?: ELAB_Booking_Service::DEFAULT_LANGUAGE ),
				__( 'Time zone', 'elaltillo-bookacall' ) => get_post_meta( $id, '_elab_visitor_timezone', true ),
				__( 'Attachments', 'elaltillo-bookacall' ) => is_array( $files ) ? implode( ', ', wp_list_pluck( $files, 'name' ) ) : '',
				__( 'Received', 'elaltillo-bookacall' ) => get_post_field( 'post_date_gmt', $id ),
				__( 'Privacy consent (UTC)', 'elaltillo-bookacall' ) => get_post_meta( $id, '_elab_consent_at', true ),
			);
			$data = array();
			foreach ( $fields as $name => $value ) {
				if ( '' !== (string) $value ) {
					$data[] = array( 'name' => $name, 'value' => (string) $value );
				}
			}
			$items[] = array( 'group_id' => 'elab-bookings', 'group_label' => __( 'Call bookings', 'elaltillo-bookacall' ), 'item_id' => 'elab-booking-' . $id, 'data' => $data );
		}
		return array( 'data' => $items, 'done' => count( $ids ) < self::PAGE );
	}

	/**
	 * Delete the person's bookings and files. An upcoming call that still exists in an
	 * advisor's Google Calendar is kept until it is cancelled, so the visitor is notified.
	 */
	public static function erase( $email, $page = 1 ) {
		$removed = false;
		$retained = false;
		$messages = array();
		// Deleting shifts later pages forward, so always read the first page of what remains.
		$ids = self::bookings_for( $email, 1 );
		$now = gmdate( 'Y-m-d H:i:s' );
		$kept = 0;
		foreach ( $ids as $id ) {
			$upcoming = get_post_meta( $id, '_elab_start_utc', true ) >= $now && 'cancelled' !== get_post_meta( $id, '_elab_status', true );
			if ( $upcoming && get_post_meta( $id, ELAB_Google_Calendar::META_EVENT, true ) ) {
				$retained = true;
				$kept++;
				$messages[] = sprintf( __( 'An upcoming call on %s was kept. Cancel it under Advisors → Booking requests, then erase again.', 'elaltillo-bookacall' ), get_post_meta( $id, '_elab_start_utc', true ) . ' UTC' );
				continue;
			}
			$removed = wp_delete_post( $id, true ) || $removed;
		}
		return array( 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => count( $ids ) - $kept === 0 || count( $ids ) < self::PAGE );
	}

	public function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'When you book a call we store your name, email address, time zone, chosen meeting language, the call time and advisor, and anything you add: sector, message and documents. Documents are kept in private storage that only site administrators can download.', 'elaltillo-bookacall' ) . '</p>'
			. '<p>' . esc_html__( 'The advisor receives your name, email and call time. When the advisor uses Google Calendar, a private event with your email as the attendee is created in their calendar and Google sends you the invitation.', 'elaltillo-bookacall' ) . '</p>'
			. '<p>' . esc_html__( 'Booking records and their documents are deleted automatically after the retention period set by the site, and you can ask us to export or erase them at any time.', 'elaltillo-bookacall' ) . '</p>';
		wp_add_privacy_policy_content( __( 'El Altillo Book a Call', 'elaltillo-bookacall' ), wp_kses_post( $text ) );
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) || 'privacy' !== ELAB_Google_Settings_Admin::tab() ) {
			return;
		}
		$status = isset( $_GET['elab_status'] ) ? sanitize_key( wp_unslash( $_GET['elab_status'] ) ) : '';
		$months = self::retention_months();
		$last = get_option( 'elab_retention_last_run' );
		?>
		<?php if ( 'privacy_saved' === $status ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Privacy settings saved.', 'elaltillo-bookacall' ); ?></p></div>
		<?php endif; ?>
		<div class="card elab-settings-card">
			<h2><?php esc_html_e( 'Privacy and retention', 'elaltillo-bookacall' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><label for="elab-retention"><?php esc_html_e( 'Delete bookings after', 'elaltillo-bookacall' ); ?></label></th><td><input type="number" class="small-text" id="elab-retention" name="elab_retention_months" min="0" max="120" step="1" value="<?php echo esc_attr( $months ); ?>"> <?php esc_html_e( 'months after the call', 'elaltillo-bookacall' ); ?><p class="description"><?php esc_html_e( 'Booking records and their documents are permanently deleted once a day after this period. 0 keeps them until you delete them by hand.', 'elaltillo-bookacall' ); ?></p>
					<?php if ( is_array( $last ) && ! empty( $last['time'] ) ) : ?><p class="description"><?php echo esc_html( sprintf( _n( 'Last clean-up: %1$s, %2$d record deleted.', 'Last clean-up: %1$s, %2$d records deleted.', (int) $last['deleted'], 'elaltillo-bookacall' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] ), (int) $last['deleted'] ) ); ?></p><?php endif; ?></td></tr>
				</tbody></table>
				<p class="description"><?php echo wp_kses( sprintf( __( 'Visitors’ requests to export or erase their data are handled with the WordPress tools under <a href="%1$s">Tools → Export Personal Data</a> and <a href="%2$s">Erase Personal Data</a>. Suggested wording for your privacy policy is available in <a href="%3$s">Settings → Privacy</a>.', 'elaltillo-bookacall' ), esc_url( admin_url( 'export-personal-data.php' ) ), esc_url( admin_url( 'erase-personal-data.php' ) ), esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ) ), array( 'a' => array( 'href' => true ) ) ); ?></p>
				<?php submit_button( __( 'Save privacy settings', 'elaltillo-bookacall' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change privacy settings.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$months = isset( $_POST['elab_retention_months'] ) && is_numeric( $_POST['elab_retention_months'] ) ? (int) $_POST['elab_retention_months'] : 0;
		update_option( self::OPTION_RETENTION, min( 120, max( 0, $months ) ), false );
		wp_safe_redirect( ELAB_Google_Settings_Admin::url( 'privacy', array( 'elab_status' => 'privacy_saved' ) ) );
		exit;
	}
}
