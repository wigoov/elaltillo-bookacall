<?php
/** Advisor profile fields and validation. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Advisor_Admin {
	const NONCE_ACTION = 'elab_advisor_meta';

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . ELAB_Post_Types::ADVISOR, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'manage_' . ELAB_Post_Types::ADVISOR . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . ELAB_Post_Types::ADVISOR . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	public function columns( $columns ) {
		return array(
			'cb' => $columns['cb'] ?? '',
			'title' => __( 'Advisor', 'elaltillo-bookacall' ),
			'elab_timezone' => __( 'Time zone', 'elaltillo-bookacall' ),
			'elab_google' => __( 'Google Calendar', 'elaltillo-bookacall' ),
			'date' => $columns['date'] ?? __( 'Date', 'elaltillo-bookacall' ),
		);
	}

	public function render_column( $column, $post_id ) {
		if ( 'elab_timezone' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_elab_timezone', true ) ?: '—' );
		} elseif ( 'elab_google' === $column ) {
			if ( ELAB_Google_Calendar::connected( $post_id ) ) {
				echo esc_html( sprintf( __( 'Connected as %s', 'elaltillo-bookacall' ), ELAB_Google_Calendar::account_email( $post_id ) ) );
			} elseif ( get_post_meta( $post_id, ELAB_Google_Calendar::META_REFRESH, true ) ) {
				echo esc_html__( 'Reconnect required', 'elaltillo-bookacall' );
			} else {
				echo esc_html__( 'Not connected', 'elaltillo-bookacall' );
			}
		}
	}

	public function register_meta_box() {
		add_meta_box( 'elab-advisor-settings', __( 'Advisor availability', 'elaltillo-bookacall' ), array( $this, 'render' ), ELAB_Post_Types::ADVISOR, 'normal', 'high' );
	}

	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();
		if ( $screen && ELAB_Post_Types::ADVISOR === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_style( 'elab-advisor-admin', plugins_url( 'assets/css/advisor-admin.css', ELAB_FILE ), array(), ELAB_VERSION );
			wp_enqueue_script( 'elab-advisor-admin', plugins_url( 'assets/js/advisor-admin.min.js', ELAB_FILE ), array(), ELAB_VERSION, true );
		}
	}

	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, 'elab_nonce' );
		$email = get_post_meta( $post->ID, '_elab_email', true );
		$timezone = get_post_meta( $post->ID, '_elab_timezone', true ) ?: 'Europe/Lisbon';
		$duration = (int) ( get_post_meta( $post->ID, '_elab_duration', true ) ?: 30 );
		$stored = get_post_meta( $post->ID, '_elab_availability', true );
		$availability = $stored ? json_decode( $stored, true ) : array_fill_keys( range( 1, 5 ), array( array( '09:00', '17:00' ) ) );
		$availability = is_array( $availability ) ? $availability : array();
		$days = array(
			1 => __( 'Monday', 'elaltillo-bookacall' ), 2 => __( 'Tuesday', 'elaltillo-bookacall' ),
			3 => __( 'Wednesday', 'elaltillo-bookacall' ), 4 => __( 'Thursday', 'elaltillo-bookacall' ),
			5 => __( 'Friday', 'elaltillo-bookacall' ), 6 => __( 'Saturday', 'elaltillo-bookacall' ),
			7 => __( 'Sunday', 'elaltillo-bookacall' ),
		);
		?>
		<p class="description"><?php esc_html_e( 'Set the hours when this advisor can receive calls. Visitors will see these times in their own time zone.', 'elaltillo-bookacall' ); ?></p>
		<table class="form-table" role="presentation"><tbody>
		<tr><th scope="row"><label for="elab-email"><?php esc_html_e( 'Advisor Google email', 'elaltillo-bookacall' ); ?></label></th><td><input class="regular-text" type="email" id="elab-email" name="elab_email" value="<?php echo esc_attr( $email ); ?>" autocomplete="email" required><p class="description"><?php esc_html_e( 'Enter the Google account that this advisor will authorize. The account must match during connection; booking and schedule emails are sent here.', 'elaltillo-bookacall' ); ?></p></td></tr>
		<?php if ( current_user_can( 'manage_options' ) ) : ?><tr><th scope="row"><label for="elab-user-id"><?php esc_html_e( 'Advisor WordPress account', 'elaltillo-bookacall' ); ?></label></th><td><?php wp_dropdown_users( array( 'name' => 'elab_user_id', 'id' => 'elab-user-id', 'selected' => (int) get_post_meta( $post->ID, '_elab_wp_user', true ), 'show_option_none' => __( 'Select an account', 'elaltillo-bookacall' ), 'option_none_value' => '0' ) ); ?><p class="description"><?php esc_html_e( 'This account may connect its own Google Calendar from its WordPress profile. It cannot edit the advisor profile.', 'elaltillo-bookacall' ); ?></p></td></tr><?php endif; ?>
		<tr><th scope="row"><label for="elab-timezone"><?php esc_html_e( 'Advisor time zone', 'elaltillo-bookacall' ); ?></label></th><td><label class="screen-reader-text" for="elab-timezone-search"><?php esc_html_e( 'Search time zones', 'elaltillo-bookacall' ); ?></label><input type="search" id="elab-timezone-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search by city or region', 'elaltillo-bookacall' ); ?>" autocomplete="off"><br><select id="elab-timezone" name="elab_timezone" class="regular-text" required><?php echo wp_timezone_choice( $timezone ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core returns escaped options. ?></select><p class="description"><?php esc_html_e( 'Choose the city where the advisor works. Seasonal time changes are automatic.', 'elaltillo-bookacall' ); ?></p></td></tr>
		<tr><th scope="row"><label for="elab-duration"><?php esc_html_e( 'Call duration', 'elaltillo-bookacall' ); ?></label></th><td><input type="number" min="15" max="180" step="5" id="elab-duration" name="elab_duration" value="<?php echo esc_attr( $duration ); ?>" class="small-text"> <?php esc_html_e( 'minutes', 'elaltillo-bookacall' ); ?></td></tr>
		</tbody></table>
		<h3><?php esc_html_e( 'Weekly hours', 'elaltillo-bookacall' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Turn on each working day. Add separate time ranges for breaks.', 'elaltillo-bookacall' ); ?></p>
		<table class="widefat striped" data-elab-weekly-hours><thead><tr><th scope="col"><?php esc_html_e( 'Day', 'elaltillo-bookacall' ); ?></th><th scope="col"><?php esc_html_e( 'Available hours', 'elaltillo-bookacall' ); ?></th></tr></thead><tbody>
		<?php foreach ( $days as $day => $label ) :
			$ranges = isset( $availability[ $day ] ) && is_array( $availability[ $day ] ) ? $availability[ $day ] : array();
			$active = ! empty( $ranges );
			if ( ! $ranges ) { $ranges = array( array( '09:00', '17:00' ) ); }
			?>
			<tr data-elab-day="<?php echo esc_attr( $day ); ?>"><th scope="row"><label><input type="checkbox" name="elab_day_active[<?php echo esc_attr( $day ); ?>]" value="1" <?php checked( $active ); ?>> <strong><?php echo esc_html( $label ); ?></strong></label></th><td><div data-elab-ranges>
			<?php foreach ( $ranges as $index => $range ) : ?>
				<div data-elab-range><label><span class="screen-reader-text"><?php esc_html_e( 'From', 'elaltillo-bookacall' ); ?></span><input type="time" aria-label="<?php echo esc_attr( sprintf( __( '%s start time', 'elaltillo-bookacall' ), $label ) ); ?>" name="elab_availability[<?php echo esc_attr( $day ); ?>][<?php echo esc_attr( $index ); ?>][start]" value="<?php echo esc_attr( $range[0] ?? '09:00' ); ?>"></label><span aria-hidden="true" class="elab-range-separator">—</span><label><span class="screen-reader-text"><?php esc_html_e( 'To', 'elaltillo-bookacall' ); ?></span><input type="time" aria-label="<?php echo esc_attr( sprintf( __( '%s end time', 'elaltillo-bookacall' ), $label ) ); ?>" name="elab_availability[<?php echo esc_attr( $day ); ?>][<?php echo esc_attr( $index ); ?>][end]" value="<?php echo esc_attr( $range[1] ?? '17:00' ); ?>"></label><button type="button" class="button-link-delete" data-elab-remove-range aria-label="<?php esc_attr_e( 'Remove time range', 'elaltillo-bookacall' ); ?>">×</button></div>
			<?php endforeach; ?>
			</div><button type="button" class="button-link" data-elab-add-range>+ <?php esc_html_e( 'Add another interval', 'elaltillo-bookacall' ); ?></button></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<h3><?php esc_html_e( 'Unavailable dates for this advisor', 'elaltillo-bookacall' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Use this for vacations, personal holidays or specific days off. Global unavailable dates also apply.', 'elaltillo-bookacall' ); ?></p>
		<?php ELAB_Blackouts::render_editor( get_post_meta( $post->ID, ELAB_Blackouts::META, true ), 'elab_blackouts' ); ?>
		<h3><?php esc_html_e( 'Google Calendar', 'elaltillo-bookacall' ); ?></h3>
		<?php if ( ! ELAB_Google_Calendar::can_connect( $post->ID ) ) : ?>
			<p class="description"><?php esc_html_e( 'The assigned advisor connects Google Calendar from their WordPress profile.', 'elaltillo-bookacall' ); ?></p>
		<?php elseif ( ! ELAB_Google_Calendar::configured() ) : ?>
			<p class="description"><?php esc_html_e( 'To connect this advisor, save the Google credentials in Advisors → Settings.', 'elaltillo-bookacall' ); ?></p>
		<?php elseif ( ELAB_Google_Calendar::connected( $post->ID ) ) :
			$disconnect = wp_nonce_url( add_query_arg( array( 'action' => 'elab_google_disconnect', 'advisor' => $post->ID ), admin_url( 'admin-post.php' ) ), 'elab_google_disconnect_' . $post->ID );
			?>
			<p><strong><?php echo esc_html( sprintf( __( 'Connected as %s', 'elaltillo-bookacall' ), ELAB_Google_Calendar::account_email( $post->ID ) ) ); ?></strong> · <a href="<?php echo esc_url( $disconnect ); ?>"><?php esc_html_e( 'Disconnect', 'elaltillo-bookacall' ); ?></a></p>
		<?php else :
			$connect = wp_nonce_url( add_query_arg( array( 'action' => 'elab_google_connect', 'advisor' => $post->ID ), admin_url( 'admin-post.php' ) ), 'elab_google_connect_' . $post->ID );
			?>
			<?php if ( get_post_meta( $post->ID, ELAB_Google_Calendar::META_REFRESH, true ) ) : ?><p class="notice notice-warning inline"><?php esc_html_e( 'The previous Google connection no longer matches this advisor email or OAuth client. Reconnect the correct account.', 'elaltillo-bookacall' ); ?></p><?php endif; ?>
			<p><a class="button button-primary" href="<?php echo esc_url( $connect ); ?>"><?php esc_html_e( 'Connect Google Calendar', 'elaltillo-bookacall' ); ?></a></p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Each advisor authorizes their own Google account. Existing meetings block available times; confirmed bookings send a calendar invitation to the visitor.', 'elaltillo-bookacall' ); ?></p>
		<p class="description"><?php esc_html_e( 'Weekly hours are booking availability, not calendar events. The advisor receives a schedule email when they connect or when these hours change. Confirmed calls are added to Google Calendar and emailed to the advisor.', 'elaltillo-bookacall' ); ?></p>
		<?php if ( 'failed' === get_post_meta( $post->ID, ELAB_Notifications::META_SCHEDULE_MAIL, true ) ) : ?><p class="notice notice-warning inline"><?php esc_html_e( 'The last schedule email could not be sent. Check the site email configuration.', 'elaltillo-bookacall' ); ?></p><?php endif; ?>
		<p class="description"><?php esc_html_e( 'Save the advisor to publish these hours.', 'elaltillo-bookacall' ); ?></p>
		<?php
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST['elab_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['elab_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$previous = array(
			'email' => get_post_meta( $post_id, '_elab_email', true ),
			'timezone' => get_post_meta( $post_id, '_elab_timezone', true ),
			'duration' => (int) get_post_meta( $post_id, '_elab_duration', true ),
			'availability' => get_post_meta( $post_id, '_elab_availability', true ),
			'blackouts' => get_post_meta( $post_id, ELAB_Blackouts::META, true ),
		);
		$email = isset( $_POST['elab_email'] ) ? sanitize_email( wp_unslash( $_POST['elab_email'] ) ) : '';
		$timezone = isset( $_POST['elab_timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['elab_timezone'] ) ) : 'UTC';
		$duration = isset( $_POST['elab_duration'] ) ? absint( $_POST['elab_duration'] ) : 30;
		$submitted = isset( $_POST['elab_availability'] ) && is_array( $_POST['elab_availability'] ) ? wp_unslash( $_POST['elab_availability'] ) : array();
		$active = isset( $_POST['elab_day_active'] ) && is_array( $_POST['elab_day_active'] ) ? wp_unslash( $_POST['elab_day_active'] ) : array();
		try {
			new DateTimeZone( $timezone );
		} catch ( Exception $exception ) {
			$timezone = 'UTC';
		}
		$availability = array();
		foreach ( $active as $day => $enabled ) {
			$day = absint( $day );
			if ( $day < 1 || $day > 7 || ! isset( $submitted[ $day ] ) || ! is_array( $submitted[ $day ] ) ) {
				continue;
			}
			foreach ( $submitted[ $day ] as $range ) {
				if ( ! is_array( $range ) ) {
					continue;
				}
				$start = isset( $range['start'] ) && is_string( $range['start'] ) ? $range['start'] : '';
				$end = isset( $range['end'] ) && is_string( $range['end'] ) ? $range['end'] : '';
				if ( preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $start ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $end ) && $start < $end ) {
					$availability[ $day ][] = array( $start, $end );
				}
			}
		}
		update_post_meta( $post_id, '_elab_email', $email );
		if ( current_user_can( 'manage_options' ) && isset( $_POST['elab_user_id'] ) ) {
			$user_id = absint( $_POST['elab_user_id'] );
			update_post_meta( $post_id, '_elab_wp_user', $user_id && get_user_by( 'id', $user_id ) ? $user_id : 0 );
		}
		update_post_meta( $post_id, '_elab_timezone', $timezone );
		$duration = min( 180, max( 15, $duration ) );
		$encoded_availability = wp_json_encode( $this->sanitize_availability( $availability ) );
		update_post_meta( $post_id, '_elab_duration', $duration );
		update_post_meta( $post_id, '_elab_availability', $encoded_availability );
		$blackouts = isset( $_POST['elab_blackouts'] ) && is_array( $_POST['elab_blackouts'] ) ? wp_unslash( $_POST['elab_blackouts'] ) : array();
		$clean_blackouts = ELAB_Blackouts::sanitize( $blackouts );
		update_post_meta( $post_id, ELAB_Blackouts::META, $clean_blackouts );
		if ( 'publish' === get_post_status( $post_id ) && ELAB_Google_Calendar::connected( $post_id ) && ( $previous['email'] !== $email || $previous['timezone'] !== $timezone || $previous['duration'] !== $duration || $previous['availability'] !== $encoded_availability || $previous['blackouts'] !== $clean_blackouts ) ) {
			ELAB_Notifications::queue_schedule_summary( $post_id );
		}
	}

	private function sanitize_availability( $availability ) {
		$clean = array();
		foreach ( $availability as $day => $ranges ) {
			$day = absint( $day );
			if ( $day < 1 || $day > 7 || ! is_array( $ranges ) ) {
				continue;
			}
			foreach ( $ranges as $range ) {
				if ( ! is_array( $range ) || count( $range ) !== 2 || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $range[0] ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $range[1] ) || $range[0] >= $range[1] ) {
					continue;
				}
				$clean[ $day ][] = array( $range[0], $range[1] );
			}
		}
		return $clean;
	}
}
