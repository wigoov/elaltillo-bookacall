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
		add_filter( 'wp_insert_post_data', array( $this, 'name_before_invite' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'manage_' . ELAB_Post_Types::ADVISOR . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . ELAB_Post_Types::ADVISOR . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_save_notices' ) );
	}

	/** Roles that may be linked to an advisor profile. Administrators never qualify. */
	public static function account_roles() {
		return (array) apply_filters( 'elab_advisor_account_roles', array( 'subscriber' ) );
	}

	/** Users already linked to another advisor profile. */
	private static function assigned_users( $except_advisor ) {
		$ids = get_posts( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'exclude' => array( (int) $except_advisor ), 'meta_key' => '_elab_wp_user', 'meta_compare' => 'EXISTS' ) );
		$users = array();
		foreach ( $ids as $id ) {
			$user = (int) get_post_meta( $id, '_elab_wp_user', true );
			if ( $user ) {
				$users[ $user ] = $id;
			}
		}
		return $users;
	}

	/** Why an account cannot be linked to this advisor, or '' when it can. */
	public static function account_problem( $user_id, $advisor_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return __( 'The selected account no longer exists.', 'elaltillo-bookacall' );
		}
		if ( user_can( $user, 'manage_options' ) || ! array_intersect( self::account_roles(), (array) $user->roles ) ) {
			return __( 'Only Subscriber accounts can be linked to an advisor. Administrators connect Google directly from this screen.', 'elaltillo-bookacall' );
		}
		$assigned = self::assigned_users( $advisor_id );
		if ( isset( $assigned[ $user_id ] ) ) {
			return sprintf( __( 'That account is already linked to %s.', 'elaltillo-bookacall' ), get_the_title( $assigned[ $user_id ] ) );
		}
		return '';
	}

	/** What keeps this advisor out of the public calendar right now. */
	public static function booking_issues( $advisor_id ) {
		$issues = array();
		if ( 'publish' !== get_post_status( $advisor_id ) ) {
			$issues[] = __( 'The profile is not published.', 'elaltillo-bookacall' );
		}
		if ( ! ELAB_Notifications::advisor_email( $advisor_id ) ) {
			$issues[] = __( 'No valid advisor email is set.', 'elaltillo-bookacall' );
		}
		if ( ! array_filter( ELAB_Availability::rules( $advisor_id )['weekly'] ) ) {
			$issues[] = __( 'No weekly hours are turned on.', 'elaltillo-bookacall' );
		}
		if ( ELAB_Google_Calendar::configured() && ! ELAB_Google_Calendar::linked( $advisor_id ) ) {
			$issues[] = __( 'Google Calendar is not connected.', 'elaltillo-bookacall' );
		}
		return $issues;
	}

	/**
	 * The advisor stays bookable when a working connection breaks (calls are saved and wait
	 * for Google), but someone has to reconnect. '' when nothing is wrong.
	 */
	public static function google_warning( $advisor_id ) {
		if ( ! ELAB_Google_Calendar::configured() || ! ELAB_Google_Calendar::linked( $advisor_id ) || ELAB_Google_Calendar::connected( $advisor_id ) ) {
			return '';
		}
		return __( 'Google needs to be reconnected: new calls are saved and wait to be added to the calendar.', 'elaltillo-bookacall' );
	}

	private static function notice_key( $post_id ) {
		return 'elab_advisor_saved_' . get_current_user_id() . '_' . (int) $post_id;
	}

	/** Corrections made while saving, shown once on the next screen load. */
	public function render_save_notices() {
		$screen = get_current_screen();
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $screen || ELAB_Post_Types::ADVISOR !== $screen->post_type || 'post' !== $screen->base || ! $post_id ) {
			return;
		}
		$messages = get_transient( self::notice_key( $post_id ) );
		if ( ! is_array( $messages ) || ! $messages ) {
			return;
		}
		delete_transient( self::notice_key( $post_id ) );
		echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'Some advisor settings were adjusted when saving:', 'elaltillo-bookacall' ) . '</strong></p><ul class="ul-disc">';
		foreach ( $messages as $message ) {
			echo '<li>' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	public function columns( $columns ) {
		return array(
			'cb' => $columns['cb'] ?? '',
			'title' => __( 'Advisor', 'elaltillo-bookacall' ),
			'elab_timezone' => __( 'Time zone', 'elaltillo-bookacall' ),
			'elab_google' => __( 'Google Calendar', 'elaltillo-bookacall' ),
			'elab_bookable' => __( 'Booking calendar', 'elaltillo-bookacall' ),
			'date' => $columns['date'] ?? __( 'Date', 'elaltillo-bookacall' ),
		);
	}

	public function render_column( $column, $post_id ) {
		if ( 'elab_bookable' === $column ) {
			$issues = self::booking_issues( $post_id );
			$warning = self::google_warning( $post_id );
			if ( ! $issues ) {
				echo '<span class="elab-status elab-status--confirmed">' . esc_html__( 'Bookable', 'elaltillo-bookacall' ) . '</span>';
				if ( $warning ) {
					echo '<br><span class="description elab-google-warning">' . esc_html( $warning ) . '</span>';
				}
			} else {
				echo '<span class="elab-status elab-status--pending">' . esc_html__( 'Hidden', 'elaltillo-bookacall' ) . '</span><br><span class="description">' . esc_html( implode( ' ', $issues ) ) . '</span>';
			}
			return;
		}
		if ( 'elab_timezone' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_elab_timezone', true ) ?: '—' );
			$names = array_intersect_key( ELAB_Booking_Service::languages(), array_flip( ELAB_Availability::languages( $post_id ) ) );
			echo '<br><span class="description">' . esc_html( implode( ', ', $names ) ) . '</span>';
		} elseif ( 'elab_google' === $column ) {
			if ( ! ELAB_Google_Calendar::configured() ) {
				echo '<span class="description">' . esc_html__( 'Not set up', 'elaltillo-bookacall' ) . '</span>';
			} elseif ( ELAB_Google_Calendar::connected( $post_id ) ) {
				echo esc_html( sprintf( __( 'Connected as %s', 'elaltillo-bookacall' ), ELAB_Google_Calendar::account_email( $post_id ) ) );
			} elseif ( ELAB_Google_Calendar::linked( $post_id ) ) {
				echo '<strong>' . esc_html__( 'Reconnect required', 'elaltillo-bookacall' ) . '</strong>';
			} else {
				echo esc_html__( 'Not connected', 'elaltillo-bookacall' );
			}
		}
	}

	public function register_meta_box() {
		add_meta_box( 'elab-advisor-settings', __( 'Advisor availability', 'elaltillo-bookacall' ), array( $this, 'render' ), ELAB_Post_Types::ADVISOR, 'normal', 'high' );
		add_meta_box( 'elab-advisor-preview', __( 'What visitors see: next 7 days', 'elaltillo-bookacall' ), array( $this, 'render_preview' ), ELAB_Post_Types::ADVISOR, 'normal', 'high' );
	}

	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();
		if ( $screen && ELAB_Post_Types::ADVISOR === $screen->post_type && 'edit.php' === $hook ) {
			wp_enqueue_style( 'elab-advisor-admin', plugins_url( 'assets/css/advisor-admin.css', ELAB_FILE ), array(), ELAB_Plugin::asset_version( 'assets/css/advisor-admin.css' ) );
		}
		if ( $screen && ELAB_Post_Types::ADVISOR === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_style( 'elab-advisor-admin', plugins_url( 'assets/css/advisor-admin.css', ELAB_FILE ), array(), ELAB_Plugin::asset_version( 'assets/css/advisor-admin.css' ) );
			wp_enqueue_script( 'elab-advisor-admin', plugins_url( 'assets/js/advisor-admin.min.js', ELAB_FILE ), array(), ELAB_Plugin::asset_version( 'assets/js/advisor-admin.min.js' ), true );
		}
	}

	/**
	 * Saved availability for the next seven days, computed exactly as the public calendar does
	 * (notice, window, closures, existing bookings and the break between them), in the advisor's zone.
	 */
	public function render_preview( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Save the advisor to preview their times.', 'elaltillo-bookacall' ) . '</p>';
			return;
		}
		$rules = ELAB_Availability::rules( $post->ID );
		$zone = $rules['timezone'];
		$first_day = ( new DateTimeImmutable( 'now', $zone ) )->setTime( 0, 0 );
		$starts = ELAB_Availability::advisor_starts( $post->ID, time(), $first_day->modify( '+7 days' )->getTimestamp() );
		echo '<p class="description">' . esc_html( sprintf( __( 'Shown in the advisor’s time zone (%1$s), %2$d-minute calls. Visitors see the same times converted to their own zone. Reflects the last saved settings.', 'elaltillo-bookacall' ), str_replace( '_', ' ', $zone->getName() ), $rules['duration'] ) ) . '</p>';
		if ( self::booking_issues( $post->ID ) ) {
			echo '<p class="notice notice-warning inline">' . esc_html__( 'Visitors do not see these times until the issues listed above are fixed.', 'elaltillo-bookacall' ) . '</p>';
		}
		if ( is_wp_error( $starts ) ) {
			echo '<p class="notice notice-error inline">' . esc_html( $starts->get_error_message() ) . '</p>';
			return;
		}
		$days = array();
		foreach ( $starts as $start ) {
			$days[ wp_date( 'Y-m-d', $start, $zone ) ][] = $start;
		}
		echo '<table class="widefat striped elab-preview"><tbody>';
		for ( $offset = 0; $offset < 7; $offset++ ) {
			$day = $first_day->modify( '+' . $offset . ' days' );
			$day_ts = $day->getTimestamp();
			$key = $day->format( 'Y-m-d' );
			echo '<tr><th scope="row">' . esc_html( wp_date( 'D j M', $day_ts, $zone ) ) . '</th><td>';
			if ( empty( $days[ $key ] ) ) {
				echo '<span class="description">' . esc_html__( 'No times', 'elaltillo-bookacall' ) . '</span>';
			} else {
				foreach ( $days[ $key ] as $start ) {
					echo '<span class="elab-preview-time">' . esc_html( wp_date( get_option( 'time_format' ), $start, $zone ) ) . '</span>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, 'elab_nonce' );
		$email = get_post_meta( $post->ID, '_elab_email', true );
		$timezone = get_post_meta( $post->ID, '_elab_timezone', true ) ?: 'Europe/Lisbon';
		$defaults = ELAB_Booking_Defaults::get();
		$own = array();
		foreach ( ELAB_Booking_Defaults::fields() as $rule => $field ) {
			$own[ $rule ] = ELAB_Booking_Defaults::inherits( $post->ID, $rule ) ? '' : ELAB_Booking_Defaults::clamp( $rule, get_post_meta( $post->ID, $field[4], true ) );
		}
		$issues = 'auto-draft' === $post->post_status ? array() : self::booking_issues( $post->ID );
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
		<?php if ( 'auto-draft' !== $post->post_status ) : ?>
			<?php if ( $issues ) : ?>
				<div class="notice notice-warning inline elab-advisor-status"><p><strong><?php esc_html_e( 'Not shown in the booking calendar yet:', 'elaltillo-bookacall' ); ?></strong></p><ul class="ul-disc"><?php foreach ( $issues as $issue ) : ?><li><?php echo esc_html( $issue ); ?></li><?php endforeach; ?></ul></div>
			<?php elseif ( self::google_warning( $post->ID ) ) : ?>
				<div class="notice notice-warning inline elab-advisor-status"><p><strong><?php esc_html_e( 'Bookable.', 'elaltillo-bookacall' ); ?></strong> <?php echo esc_html( self::google_warning( $post->ID ) ); ?> <a href="#elab-google-connection"><?php esc_html_e( 'Reconnect', 'elaltillo-bookacall' ); ?></a></p></div>
			<?php else : ?>
				<div class="notice notice-success inline elab-advisor-status"><p><?php esc_html_e( 'Bookable: this advisor’s times appear in the booking calendar.', 'elaltillo-bookacall' ); ?></p></div>
			<?php endif; ?>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Set the hours when this advisor can receive calls. Visitors will see these times in their own time zone.', 'elaltillo-bookacall' ); ?></p>
		<table class="form-table" role="presentation"><tbody>
		<tr><th scope="row"><label for="elab-email"><?php esc_html_e( 'Advisor Google email', 'elaltillo-bookacall' ); ?></label></th><td><input class="regular-text" type="email" id="elab-email" name="elab_email" value="<?php echo esc_attr( $email ); ?>" autocomplete="email" required><p class="description"><?php esc_html_e( 'Enter the Google account that this advisor will authorize. The account must match during connection; booking and schedule emails are sent here.', 'elaltillo-bookacall' ); ?></p></td></tr>
		<tr><th scope="row"><label for="elab-email-locale"><?php esc_html_e( 'Email language', 'elaltillo-bookacall' ); ?></label></th><td><select id="elab-email-locale" name="elab_email_locale"><?php foreach ( ELAB_Notifications::locales() as $elab_locale => $elab_locale_name ) : ?><option value="<?php echo esc_attr( $elab_locale ); ?>" <?php selected( ELAB_Notifications::advisor_locale( $post->ID ), $elab_locale ); ?>><?php echo esc_html( $elab_locale_name ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Language of the booking, reconnection and schedule emails this advisor receives.', 'elaltillo-bookacall' ); ?></p></td></tr>
		<?php if ( current_user_can( 'manage_options' ) ) :
			$linked = (int) get_post_meta( $post->ID, '_elab_wp_user', true );
			$taken = self::assigned_users( $post->ID );
			$accounts = get_users( array( 'role__in' => self::account_roles(), 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
			$listed = wp_list_pluck( $accounts, 'ID' );
			?>
			<tr><th scope="row"><label for="elab-user-id"><?php esc_html_e( 'Advisor WordPress account', 'elaltillo-bookacall' ); ?></label></th><td><select id="elab-user-id" name="elab_user_id"><option value="0"><?php esc_html_e( 'No account', 'elaltillo-bookacall' ); ?></option>
			<?php if ( $linked && ! in_array( (string) $linked, array_map( 'strval', $listed ), true ) && ( $current = get_user_by( 'id', $linked ) ) ) : ?>
				<option value="<?php echo esc_attr( $linked ); ?>" selected><?php echo esc_html( sprintf( __( '%s (not a Subscriber, reassign)', 'elaltillo-bookacall' ), $current->display_name ) ); ?></option>
			<?php endif; ?>
			<?php foreach ( $accounts as $account ) : ?>
				<option value="<?php echo esc_attr( $account->ID ); ?>" <?php selected( $linked, (int) $account->ID ); ?> <?php disabled( isset( $taken[ (int) $account->ID ] ) ); ?>><?php echo esc_html( $account->display_name . ' — ' . $account->user_email . ( isset( $taken[ (int) $account->ID ] ) ? ' · ' . sprintf( __( 'linked to %s', 'elaltillo-bookacall' ), get_the_title( $taken[ (int) $account->ID ] ) ) : '' ) ); ?></option>
			<?php endforeach; ?>
			</select><p class="description"><?php esc_html_e( 'Only Subscriber accounts that are not linked to another advisor can be chosen. This account connects its own Google Calendar from its profile and cannot edit this advisor profile.', 'elaltillo-bookacall' ); ?></p></td></tr>
		<?php endif; ?>
		<?php if ( ELAB_Google_Calendar::timezone_from_google( $post->ID ) ) : ?>
		<tr><th scope="row"><?php esc_html_e( 'Advisor time zone', 'elaltillo-bookacall' ); ?></th><td><strong><?php echo esc_html( $timezone ); ?></strong><p class="description"><?php esc_html_e( 'Taken from the advisor’s Google Calendar settings and kept up to date: when they change it in Google, their hours here follow within an hour.', 'elaltillo-bookacall' ); ?></p></td></tr>
		<?php else : ?>
		<tr><th scope="row"><label for="elab-timezone"><?php esc_html_e( 'Advisor time zone', 'elaltillo-bookacall' ); ?></label></th><td><label class="screen-reader-text" for="elab-timezone-search"><?php esc_html_e( 'Search time zones', 'elaltillo-bookacall' ); ?></label><input type="search" id="elab-timezone-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search by city or region', 'elaltillo-bookacall' ); ?>" autocomplete="off"><br><select id="elab-timezone" name="elab_timezone" class="regular-text" required><?php echo wp_timezone_choice( $timezone ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core returns escaped options. ?></select><p class="description"><?php esc_html_e( 'Choose the city where the advisor works. Seasonal time changes are automatic.', 'elaltillo-bookacall' ); ?> <?php if ( ELAB_Google_Calendar::connected( $post->ID ) && ! ELAB_Google_Calendar::reads_settings( $post->ID ) ) { esc_html_e( 'Reconnect Google Calendar once to take it from Google automatically.', 'elaltillo-bookacall' ); } ?></p></td></tr>
		<?php endif; ?>
		<tr><th scope="row"><?php esc_html_e( 'Meeting languages', 'elaltillo-bookacall' ); ?></th><td><fieldset class="elab-language-choice"><legend class="screen-reader-text"><?php esc_html_e( 'Meeting languages', 'elaltillo-bookacall' ); ?></legend><?php $elab_spoken = ELAB_Availability::languages( $post->ID ); foreach ( ELAB_Booking_Service::languages() as $elab_code => $elab_name ) : ?><label><input type="checkbox" name="elab_languages[]" value="<?php echo esc_attr( $elab_code ); ?>" <?php checked( in_array( $elab_code, $elab_spoken, true ) ); ?>> <?php echo esc_html( $elab_name ); ?></label><?php endforeach; ?></fieldset><p class="description"><?php esc_html_e( 'Visitors choose the meeting language first and only see the times of advisors who speak it. Choose at least one.', 'elaltillo-bookacall' ); ?></p></td></tr>
		<tr><th scope="row"><label for="elab-duration"><?php esc_html_e( 'Call duration', 'elaltillo-bookacall' ); ?></label></th><td><input type="number" min="15" max="180" step="5" id="elab-duration" name="elab_duration" value="<?php echo esc_attr( $own['duration'] ); ?>" placeholder="<?php echo esc_attr( $defaults['duration'] ); ?>" class="small-text"> <?php esc_html_e( 'minutes', 'elaltillo-bookacall' ); ?><p class="description"><?php echo esc_html( sprintf( __( 'Leave empty to use the site default (%d). Times start every call length inside each range; existing bookings keep their length.', 'elaltillo-bookacall' ), $defaults['duration'] ) ); ?></p></td></tr>
		</tbody></table>
		<h3><?php esc_html_e( 'Booking rules', 'elaltillo-bookacall' ); ?></h3>
		<p class="description"><?php echo wp_kses( sprintf( __( 'Empty fields follow the <a href="%s">site booking defaults</a>.', 'elaltillo-bookacall' ), esc_url( ELAB_Google_Settings_Admin::url( 'general' ) ) ), array( 'a' => array( 'href' => true ) ) ); ?></p>
		<table class="form-table" role="presentation"><tbody>
		<tr><th scope="row"><label for="elab-notice"><?php esc_html_e( 'Minimum notice', 'elaltillo-bookacall' ); ?></label></th><td><input type="number" min="0" max="720" step="1" id="elab-notice" name="elab_notice_hours" value="<?php echo esc_attr( $own['notice'] ); ?>" placeholder="<?php echo esc_attr( $defaults['notice'] ); ?>" class="small-text"> <?php esc_html_e( 'hours', 'elaltillo-bookacall' ); ?><p class="description"><?php echo esc_html( sprintf( __( 'Visitors cannot book a call that starts sooner than this. Leave empty to use the site default (%d).', 'elaltillo-bookacall' ), $defaults['notice'] ) ); ?></p></td></tr>
		<tr><th scope="row"><label for="elab-horizon"><?php esc_html_e( 'Booking window', 'elaltillo-bookacall' ); ?></label></th><td><input type="number" min="1" max="365" step="1" id="elab-horizon" name="elab_horizon_days" value="<?php echo esc_attr( $own['horizon'] ); ?>" placeholder="<?php echo esc_attr( $defaults['horizon'] ); ?>" class="small-text"> <?php esc_html_e( 'days ahead', 'elaltillo-bookacall' ); ?><p class="description"><?php echo esc_html( sprintf( __( 'How far into the future visitors can book. Leave empty to use the site default (%d).', 'elaltillo-bookacall' ), $defaults['horizon'] ) ); ?></p></td></tr>
		<tr><th scope="row"><label for="elab-interval"><?php esc_html_e( 'Start times every', 'elaltillo-bookacall' ); ?></label></th><td><input type="number" min="0" max="120" step="5" id="elab-interval" name="elab_slot_interval" value="<?php echo esc_attr( $own['interval'] ); ?>" placeholder="<?php echo esc_attr( $defaults['interval'] ); ?>" class="small-text"> <?php esc_html_e( 'minutes', 'elaltillo-bookacall' ); ?><p class="description"><?php echo esc_html( sprintf( __( '0 offers a start every call length; 15 offers 9:00, 9:15, 9:30… with the same call length. Leave empty to use the site default (%d).', 'elaltillo-bookacall' ), $defaults['interval'] ) ); ?></p></td></tr>
		<tr><th scope="row"><label for="elab-buffer"><?php esc_html_e( 'Break between calls', 'elaltillo-bookacall' ); ?></label></th><td><input type="number" min="0" max="120" step="5" id="elab-buffer" name="elab_buffer_minutes" value="<?php echo esc_attr( $own['buffer'] ); ?>" placeholder="<?php echo esc_attr( $defaults['buffer'] ); ?>" class="small-text"> <?php esc_html_e( 'minutes', 'elaltillo-bookacall' ); ?><p class="description"><?php echo esc_html( sprintf( __( 'Free time kept before and after each booking. Leave empty to use the site default (%d).', 'elaltillo-bookacall' ), $defaults['buffer'] ) ); ?></p></td></tr>
		</tbody></table>
		<h3><?php esc_html_e( 'Weekly hours', 'elaltillo-bookacall' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Turn on each working day. Add separate time ranges for breaks. Overlapping ranges are merged when you save.', 'elaltillo-bookacall' ); ?></p>
		<table class="widefat striped" data-elab-weekly-hours><thead><tr><th scope="col"><?php esc_html_e( 'Day', 'elaltillo-bookacall' ); ?></th><th scope="col"><?php esc_html_e( 'Available hours', 'elaltillo-bookacall' ); ?></th></tr></thead><tbody>
		<?php foreach ( $days as $day => $label ) :
			$ranges = isset( $availability[ $day ] ) && is_array( $availability[ $day ] ) ? $availability[ $day ] : array();
			$active = ! empty( $ranges );
			if ( ! $ranges ) { $ranges = array( array( '09:00', '17:00' ) ); }
			?>
			<tr data-elab-day="<?php echo esc_attr( $day ); ?>"><th scope="row"><label><input type="checkbox" name="elab_day_active[<?php echo esc_attr( $day ); ?>]" value="1" <?php checked( $active ); ?>> <strong><?php echo esc_html( $label ); ?></strong></label></th><td><div data-elab-ranges>
			<?php foreach ( $ranges as $index => $range ) : ?>
				<div data-elab-range><label><span class="screen-reader-text"><?php esc_html_e( 'From', 'elaltillo-bookacall' ); ?></span><input type="time" aria-label="<?php echo esc_attr( sprintf( __( '%s start time', 'elaltillo-bookacall' ), $label ) ); ?>" name="elab_availability[<?php echo esc_attr( $day ); ?>][<?php echo esc_attr( $index ); ?>][start]" value="<?php echo esc_attr( $range[0] ?? '09:00' ); ?>"></label><span aria-hidden="true" class="elab-range-separator">—</span><label><span class="screen-reader-text"><?php esc_html_e( 'To', 'elaltillo-bookacall' ); ?></span><input type="time" aria-label="<?php echo esc_attr( sprintf( __( '%s end time', 'elaltillo-bookacall' ), $label ) ); ?>" name="elab_availability[<?php echo esc_attr( $day ); ?>][<?php echo esc_attr( $index ); ?>][end]" value="<?php echo esc_attr( $range[1] ?? '17:00' ); ?>"></label><button type="button" class="button-link button-link-delete elab-remove-range" data-elab-remove-range aria-label="<?php esc_attr_e( 'Remove time range', 'elaltillo-bookacall' ); ?>">×</button></div>
			<?php endforeach; ?>
			</div><button type="button" class="button-link" data-elab-add-range>+ <?php esc_html_e( 'Add another interval', 'elaltillo-bookacall' ); ?></button></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<h3><?php esc_html_e( 'Unavailable dates for this advisor', 'elaltillo-bookacall' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Use this for vacations, personal holidays or specific days off. Global unavailable dates also apply.', 'elaltillo-bookacall' ); ?></p>
		<?php ELAB_Blackouts::render_editor( get_post_meta( $post->ID, ELAB_Blackouts::META, true ), 'elab_blackouts' ); ?>
		<h3 id="elab-google-connection"><?php esc_html_e( 'Google Calendar', 'elaltillo-bookacall' ); ?></h3>
		<p class="description"><?php echo esc_html( sprintf( __( 'Each booked call is added to a separate calendar, “%s”, created by this site in the advisor’s Google account, with the visitor invited as a guest. The site cannot see or change any other calendar or event.', 'elaltillo-bookacall' ), ELAB_Google_Calendar::CALENDAR_NAME ) ); ?></p>
		<?php
		$google_state = ! ELAB_Google_Calendar::configured() ? 'setup' : ( ELAB_Google_Calendar::connected( $post->ID ) ? 'connected' : ( ELAB_Google_Calendar::revoked( $post->ID ) ? 'revoked' : ( ELAB_Google_Calendar::linked( $post->ID ) && ! ELAB_Google_Calendar::calendar_id( $post->ID ) ? 'upgrade' : ( ELAB_Google_Calendar::linked( $post->ID ) ? 'mismatch' : 'disconnected' ) ) ) );
		$waiting = count( ELAB_Booking_Sync::pending( $post->ID ) );
		$connect = wp_nonce_url( add_query_arg( array( 'action' => 'elab_google_connect', 'advisor' => $post->ID ), admin_url( 'admin-post.php' ) ), 'elab_google_connect_' . $post->ID );
		$disconnect = wp_nonce_url( add_query_arg( array( 'action' => 'elab_google_disconnect', 'advisor' => $post->ID ), admin_url( 'admin-post.php' ) ), 'elab_google_disconnect_' . $post->ID );
		$google_text = array(
			'setup'        => __( 'Google Calendar is not set up for this site yet.', 'elaltillo-bookacall' ),
			'connected'    => sprintf( __( 'Connected as %s.', 'elaltillo-bookacall' ), ELAB_Google_Calendar::account_email( $post->ID ) ) . ' ' . ( ELAB_Google_Calendar::extra_scopes( $post->ID ) ? sprintf( __( 'Google reports more access than this site needs (%s): disconnect and connect again.', 'elaltillo-bookacall' ), implode( ', ', ELAB_Google_Calendar::extra_scopes( $post->ID ) ) ) : ( ELAB_Google_Calendar::scopes_verified( $post->ID ) ? __( 'Google confirms this site can only reach its own “El Altillo · Citas” calendar and read the calendar settings (time zone).', 'elaltillo-bookacall' ) : '' ) ),
			'revoked'      => __( 'Google access was removed or expired, so this advisor is hidden from the booking calendar until they reconnect. Calls already booked are kept. The advisor was emailed a link to reconnect.', 'elaltillo-bookacall' ),
			'upgrade'      => sprintf( __( 'Connected with the old, broader permission. Reconnect once so the site creates its own “%s” calendar and only uses that one.', 'elaltillo-bookacall' ), ELAB_Google_Calendar::CALENDAR_NAME ),
			'mismatch'     => __( 'The saved connection no longer matches this advisor’s email or the site’s OAuth client, so the advisor is hidden from the booking calendar. Connect the correct account.', 'elaltillo-bookacall' ),
			'disconnected' => __( 'Not connected, so hidden from the booking calendar. Email the advisor a connection link (no website login needed), or connect here.', 'elaltillo-bookacall' ),
		)[ $google_state ];
		if ( 'connected' === $google_state && ELAB_Google_Calendar::extra_scopes( $post->ID ) ) {
			$google_state = 'toobroad';
		}
		$google_badge = array( 'toobroad' => array( 'error', __( 'Reconnect', 'elaltillo-bookacall' ) ), 'setup' => array( 'info', __( 'Not set up', 'elaltillo-bookacall' ) ), 'connected' => array( 'ok', __( 'Connected', 'elaltillo-bookacall' ) ), 'revoked' => array( 'error', __( 'Reconnect', 'elaltillo-bookacall' ) ), 'upgrade' => array( 'error', __( 'Reconnect', 'elaltillo-bookacall' ) ), 'mismatch' => array( 'error', __( 'Reconnect', 'elaltillo-bookacall' ) ), 'disconnected' => array( 'warn', __( 'Not connected', 'elaltillo-bookacall' ) ) )[ $google_state ];
		?>
		<table class="form-table" role="presentation"><tbody>
			<tr><th scope="row"><?php esc_html_e( 'Connection', 'elaltillo-bookacall' ); ?></th><td>
				<p class="elab-google-line"><span class="elab-check elab-check--<?php echo esc_attr( $google_badge[0] ); ?>"><?php echo esc_html( $google_badge[1] ); ?></span> <?php echo esc_html( $google_text ); ?></p>
				<p class="elab-google-actions">
				<?php if ( 'setup' === $google_state ) : ?>
					<a class="button" href="<?php echo esc_url( ELAB_Google_Settings_Admin::url( 'google' ) ); ?>"><?php esc_html_e( 'Set up Google Calendar', 'elaltillo-bookacall' ); ?></a>
				<?php elseif ( 'connected' === $google_state && ELAB_Google_Calendar::can_connect( $post->ID ) ) : ?>
					<a class="button" href="<?php echo esc_url( $disconnect ); ?>"><?php esc_html_e( 'Disconnect', 'elaltillo-bookacall' ); ?></a>
				<?php elseif ( 'disconnected' === $google_state && ELAB_Google_Calendar::can_connect( $post->ID ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $connect ); ?>"><?php esc_html_e( 'Connect Google Calendar', 'elaltillo-bookacall' ); ?></a>
				<?php elseif ( ELAB_Google_Calendar::can_connect( $post->ID ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $connect ); ?>"><?php esc_html_e( 'Reconnect Google Calendar', 'elaltillo-bookacall' ); ?></a>
					<a class="button" href="<?php echo esc_url( $disconnect ); ?>"><?php esc_html_e( 'Disconnect', 'elaltillo-bookacall' ); ?></a>
				<?php endif; ?>
				<?php if ( ! in_array( $google_state, array( 'setup', 'connected' ), true ) && current_user_can( 'manage_options' ) ) : ?>
					<?php // Shown as soon as the Google email field has an address; saves the advisor, then emails the link. ?>
					<button type="button" class="button" data-elab-send-invite hidden><?php esc_html_e( 'Save and email a connection link', 'elaltillo-bookacall' ); ?></button>
					<input type="hidden" name="elab_send_invite" value="" data-elab-send-invite-flag>
				<?php endif; ?>
				</p>
				<?php $elab_invite_result = isset( $_GET['elab_google'] ) ? sanitize_key( wp_unslash( $_GET['elab_google'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only. ?>
				<?php if ( 'invite_sent' === $elab_invite_result ) : ?><p class="notice notice-success inline"><?php echo esc_html( sprintf( __( 'Connection link sent to %1$s. It works until %2$s, without a website login.', 'elaltillo-bookacall' ), ELAB_Notifications::advisor_email( $post->ID ), wp_date( get_option( 'date_format' ), ELAB_Google_Calendar::invite_expires( $post->ID ) ) ) ); ?></p><?php endif; ?>
				<?php if ( 'invite_failed' === $elab_invite_result ) : ?><p class="notice notice-error inline"><?php esc_html_e( 'The connection link could not be emailed. Check the site email configuration.', 'elaltillo-bookacall' ); ?></p><?php endif; ?>
				<?php if ( $waiting ) : ?><p class="notice notice-warning inline"><?php echo esc_html( sprintf( _n( '%d call is saved and waiting to be added to Google Calendar.', '%d calls are saved and waiting to be added to Google Calendar.', $waiting, 'elaltillo-bookacall' ), $waiting ) ); ?></p><?php endif; ?>
				<p class="description"><?php esc_html_e( 'Weekly hours are availability rules, not calendar events. The advisor receives their schedule by email after connecting or when these settings change.', 'elaltillo-bookacall' ); ?></p>
				<?php if ( 'failed' === get_post_meta( $post->ID, ELAB_Notifications::META_SCHEDULE_MAIL, true ) ) : ?><p class="notice notice-warning inline"><?php esc_html_e( 'The last schedule email could not be sent. Check the site email configuration.', 'elaltillo-bookacall' ); ?></p><?php endif; ?>
			</td></tr>
		</tbody></table>
		<?php
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST['elab_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['elab_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$notes = array();
		$previous = array(
			'email' => get_post_meta( $post_id, '_elab_email', true ),
			'timezone' => get_post_meta( $post_id, '_elab_timezone', true ),
			'availability' => get_post_meta( $post_id, '_elab_availability', true ),
			'blackouts' => get_post_meta( $post_id, ELAB_Blackouts::META, true ),
			'rules' => array_diff_key( ELAB_Availability::rules( $post_id ), array_flip( array( 'timezone', 'weekly' ) ) ),
		);

		$raw_email = isset( $_POST['elab_email'] ) ? trim( (string) wp_unslash( $_POST['elab_email'] ) ) : '';
		$email = sanitize_email( $raw_email );
		if ( '' !== $raw_email && ! is_email( $email ) ) {
			$email = '';
			$notes[] = __( 'The advisor email was not a valid address and was cleared.', 'elaltillo-bookacall' );
		}
		update_post_meta( $post_id, '_elab_email', $email );
		$this->reconnect_after_email_change( $post_id, (string) $previous['email'], $email, $notes );

		if ( current_user_can( 'manage_options' ) && isset( $_POST['elab_user_id'] ) ) {
			$user_id = absint( $_POST['elab_user_id'] );
			$current = (int) get_post_meta( $post_id, '_elab_wp_user', true );
			if ( ! $user_id ) {
				update_post_meta( $post_id, '_elab_wp_user', 0 );
			} elseif ( $user_id !== $current ) {
				$problem = self::account_problem( $user_id, $post_id );
				if ( $problem ) {
					$notes[] = $problem . ' ' . __( 'The previous account link was kept.', 'elaltillo-bookacall' );
				} else {
					update_post_meta( $post_id, '_elab_wp_user', $user_id );
				}
			}
		}

		// A time zone that follows Google is not taken from the form.
		if ( ! ELAB_Google_Calendar::timezone_from_google( $post_id ) ) {
			$timezone = isset( $_POST['elab_timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['elab_timezone'] ) ) : 'UTC';
			try {
				new DateTimeZone( $timezone );
			} catch ( Exception $exception ) {
				$notes[] = sprintf( __( 'The time zone “%s” is not recognised; UTC was used.', 'elaltillo-bookacall' ), $timezone );
				$timezone = 'UTC';
			}
			update_post_meta( $post_id, '_elab_timezone', $timezone );
		}

		$all_languages = array_keys( ELAB_Booking_Service::languages() );
		$languages = isset( $_POST['elab_languages'] ) && is_array( $_POST['elab_languages'] ) ? array_values( array_intersect( $all_languages, array_map( 'sanitize_key', wp_unslash( $_POST['elab_languages'] ) ) ) ) : array();
		if ( ! $languages ) {
			$languages = $all_languages;
			$notes[] = __( 'At least one meeting language is needed, so every language was kept.', 'elaltillo-bookacall' );
		}
		update_post_meta( $post_id, ELAB_Availability::META_LANGUAGES, $languages );

		foreach ( ELAB_Booking_Defaults::fields() as $rule => $field ) {
			$input = array( 'duration' => 'elab_duration', 'notice' => 'elab_notice_hours', 'horizon' => 'elab_horizon_days', 'buffer' => 'elab_buffer_minutes', 'interval' => 'elab_slot_interval' )[ $rule ];
			if ( ! isset( $_POST[ $input ] ) ) {
				continue;
			}
			$value = trim( (string) wp_unslash( $_POST[ $input ] ) );
			if ( '' === $value ) {
				delete_post_meta( $post_id, $field[4] );
				continue;
			}
			if ( ! is_numeric( $value ) ) {
				$notes[] = sprintf( __( '%s was not a number; the site default is used.', 'elaltillo-bookacall' ), ELAB_Booking_Defaults_Admin::labels()[ $rule ][0] );
				delete_post_meta( $post_id, $field[4] );
				continue;
			}
			$clamped = ELAB_Booking_Defaults::clamp( $rule, $value );
			if ( (string) $clamped !== (string) (int) $value || (float) $value !== (float) (int) $value ) {
				$notes[] = sprintf( __( '%1$s must be between %2$d and %3$d; it was set to %4$d.', 'elaltillo-bookacall' ), ELAB_Booking_Defaults_Admin::labels()[ $rule ][0], $field[1], $field[2], $clamped );
			}
			update_post_meta( $post_id, $field[4], $clamped );
		}

		$submitted = isset( $_POST['elab_availability'] ) && is_array( $_POST['elab_availability'] ) ? wp_unslash( $_POST['elab_availability'] ) : array();
		$active = isset( $_POST['elab_day_active'] ) && is_array( $_POST['elab_day_active'] ) ? wp_unslash( $_POST['elab_day_active'] ) : array();
		$availability = array();
		$dropped = 0;
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
				} elseif ( '' !== $start || '' !== $end ) {
					$dropped++;
				}
			}
		}
		if ( $dropped ) {
			$notes[] = sprintf( _n( '%d time range was removed because it ended before it started or was incomplete.', '%d time ranges were removed because they ended before they started or were incomplete.', $dropped, 'elaltillo-bookacall' ), $dropped );
		}
		$clean = $this->sanitize_availability( $availability );
		$merged_days = array();
		foreach ( $availability as $day => $ranges ) {
			if ( isset( $clean[ $day ] ) && count( $clean[ $day ] ) < count( $ranges ) ) {
				$merged_days[] = date_i18n( 'l', strtotime( 'Sunday +' . $day . ' days' ) );
			}
		}
		if ( $merged_days ) {
			$notes[] = sprintf( __( 'Overlapping ranges were merged on: %s.', 'elaltillo-bookacall' ), implode( ', ', $merged_days ) );
		}
		$locale = isset( $_POST['elab_email_locale'] ) && is_string( $_POST['elab_email_locale'] ) ? sanitize_text_field( wp_unslash( $_POST['elab_email_locale'] ) ) : '';
		if ( isset( ELAB_Notifications::locales()[ $locale ] ) ) {
			update_post_meta( $post_id, ELAB_Notifications::META_ADVISOR_LOCALE, $locale );
		}
		$encoded_availability = wp_json_encode( $clean );
		update_post_meta( $post_id, '_elab_availability', $encoded_availability );

		$blackouts = isset( $_POST['elab_blackouts'] ) && is_array( $_POST['elab_blackouts'] ) ? wp_unslash( $_POST['elab_blackouts'] ) : array();
		$clean_blackouts = ELAB_Blackouts::sanitize( $blackouts );
		$filled = count( array_filter( $blackouts, static function ( $row ) { return is_array( $row ) && ! empty( $row['start'] ); } ) );
		if ( $filled > count( $clean_blackouts ) ) {
			$notes[] = sprintf( _n( '%d unavailable date was ignored because its dates were invalid.', '%d unavailable dates were ignored because their dates were invalid.', $filled - count( $clean_blackouts ), 'elaltillo-bookacall' ), $filled - count( $clean_blackouts ) );
		}
		update_post_meta( $post_id, ELAB_Blackouts::META, $clean_blackouts );

		if ( $notes ) {
			set_transient( self::notice_key( $post_id ), $notes, 5 * MINUTE_IN_SECONDS );
		}
		$rules = array_diff_key( ELAB_Availability::rules( $post_id ), array_flip( array( 'timezone', 'weekly' ) ) );
		if ( 'publish' === get_post_status( $post_id ) && ELAB_Google_Calendar::connected( $post_id ) && ( $previous['email'] !== $email || $previous['timezone'] !== $timezone || $previous['availability'] !== $encoded_availability || $previous['blackouts'] !== $clean_blackouts || $previous['rules'] !== $rules ) ) {
			ELAB_Notifications::queue_schedule_summary( $post_id );
		}
		$this->send_invite_after_save( $post_id );
	}

	/** True when this request is the advisor form asking to save and email the connection link. */
	private static function invite_requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only read here; save() and name_before_invite() check the nonce.
		return ! empty( $_POST['elab_send_invite'] ) && current_user_can( 'manage_options' ) && ELAB_Google_Calendar::configured();
	}

	/** An unnamed advisor saved to send the link gets a generic name with the email, so it can be found. */
	public function name_before_invite( $data, $postarr ) {
		if ( ELAB_Post_Types::ADVISOR !== ( $data['post_type'] ?? '' ) || '' !== trim( (string) ( $data['post_title'] ?? '' ) ) || ! self::invite_requested() || ! isset( $_POST['elab_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['elab_nonce'] ) ), self::NONCE_ACTION ) ) {
			return $data;
		}
		$email = isset( $_POST['elab_email'] ) ? sanitize_email( wp_unslash( $_POST['elab_email'] ) ) : '';
		$data['post_title'] = $email ? sprintf( __( 'New advisor (%s)', 'elaltillo-bookacall' ), $email ) : __( 'New advisor', 'elaltillo-bookacall' );
		return $data;
	}

	/** After the advisor is saved with its email, send the link and say so on the reloaded screen. */
	private function send_invite_after_save( $post_id ) {
		if ( ! self::invite_requested() || isset( self::$invited[ $post_id ] ) || wp_is_post_revision( $post_id ) || ELAB_Google_Calendar::connected( $post_id ) ) {
			return;
		}
		self::$invited[ $post_id ] = true;
		$result = ELAB_Notifications::advisor_email( $post_id ) && ELAB_Notifications::connect_invite( $post_id ) ? 'invite_sent' : 'invite_failed';
		add_filter( 'redirect_post_location', static function ( $location ) use ( $result ) {
			return add_query_arg( 'elab_google', $result, $location ) . '#elab-google-connection';
		} );
	}

	/** Advisors already emailed a connection link in this request (never twice). */
	private static $invited = array();

	/**
	 * The Google email changed on an advisor with a connection: that connection belongs to the
	 * previous account, so it is withdrawn at Google and the advisor is sent a connection link
	 * at the new address. Until they connect, they are hidden from the booking calendar.
	 */
	private function reconnect_after_email_change( $post_id, $before, $after, array &$notes ) {
		if ( '' === $before || '' === $after || 0 === strcasecmp( $before, $after ) || ! ELAB_Google_Calendar::linked( $post_id ) ) {
			return;
		}
		ELAB_Google_Calendar::forget( $post_id, true );
		$sent = false;
		if ( ELAB_Google_Calendar::configured() && ! isset( self::$invited[ $post_id ] ) ) {
			self::$invited[ $post_id ] = true;
			$sent = ELAB_Notifications::connect_invite( $post_id );
		}
		$notes[] = $sent
			? sprintf( __( 'The Google email changed, so the connection with %1$s was removed. A connection link was sent to %2$s; until they connect, the advisor is hidden from the booking calendar. Calls already booked stay in the previous account’s calendar.', 'elaltillo-bookacall' ), $before, $after )
			: sprintf( __( 'The Google email changed, so the connection with %1$s was removed. Send %2$s a connection link: until they connect, the advisor is hidden from the booking calendar.', 'elaltillo-bookacall' ), $before, $after );
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
		foreach ( $clean as $day => $ranges ) {
			usort( $ranges, static function ( $a, $b ) { return strcmp( $a[0], $b[0] ); } );
			$merged = array();
			foreach ( $ranges as $range ) {
				$last = count( $merged ) - 1;
				if ( $last >= 0 && $range[0] <= $merged[ $last ][1] ) {
					$merged[ $last ][1] = max( $merged[ $last ][1], $range[1] );
				} else {
					$merged[] = $range;
				}
			}
			$clean[ $day ] = $merged;
		}
		ksort( $clean );
		return $clean;
	}
}
