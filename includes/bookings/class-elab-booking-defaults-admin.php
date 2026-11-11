<?php
/** "Booking defaults" card on the Advisors → Settings page. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_Defaults_Admin {
	const ACTION = 'elab_save_booking_defaults';

	public function __construct() {
		add_action( 'elab_settings_before', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	public static function labels() {
		return array(
			'duration' => array( __( 'Call duration', 'elaltillo-bookacall' ), __( 'minutes', 'elaltillo-bookacall' ), __( 'Length of each call. Times start every call length inside the advisor’s hours.', 'elaltillo-bookacall' ) ),
			'notice'   => array( __( 'Minimum notice', 'elaltillo-bookacall' ), __( 'hours', 'elaltillo-bookacall' ), __( 'Visitors cannot book a call that starts sooner than this.', 'elaltillo-bookacall' ) ),
			'horizon'  => array( __( 'Booking window', 'elaltillo-bookacall' ), __( 'days ahead', 'elaltillo-bookacall' ), __( 'How far into the future visitors can book.', 'elaltillo-bookacall' ) ),
			'interval' => array( __( 'Start times every', 'elaltillo-bookacall' ), __( 'minutes', 'elaltillo-bookacall' ), __( '0 offers a new start time every call length (9:00, 9:30… for 30-minute calls). 15 offers 9:00, 9:15, 9:30… while each call keeps its length.', 'elaltillo-bookacall' ) ),
			'buffer'   => array( __( 'Break between calls', 'elaltillo-bookacall' ), __( 'minutes', 'elaltillo-bookacall' ), __( 'Optional free time kept before and after each booking. 0 means calls can be back to back.', 'elaltillo-bookacall' ) ),
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) || 'general' !== ELAB_Google_Settings_Admin::tab() ) {
			return;
		}
		$values = ELAB_Booking_Defaults::get();
		$status = isset( $_GET['elab_status'] ) ? sanitize_key( wp_unslash( $_GET['elab_status'] ) ) : '';
		?>
		<?php if ( 'defaults_saved' === $status ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Booking defaults saved. Advisors without their own values now use them.', 'elaltillo-bookacall' ); ?></p></div>
		<?php endif; ?>
		<div class="card elab-settings-card">
			<h2><?php esc_html_e( 'Booking defaults', 'elaltillo-bookacall' ); ?></h2>
			<p class="description"><?php esc_html_e( 'These values apply to every advisor whose profile leaves the field empty. An advisor’s own value always wins.', 'elaltillo-bookacall' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation"><tbody>
				<?php foreach ( ELAB_Booking_Defaults::fields() as $rule => $field ) :
					$label = self::labels()[ $rule ];
					?>
					<tr><th scope="row"><label for="elab-default-<?php echo esc_attr( $rule ); ?>"><?php echo esc_html( $label[0] ); ?></label></th><td><input type="number" class="small-text" id="elab-default-<?php echo esc_attr( $rule ); ?>" name="elab_defaults[<?php echo esc_attr( $rule ); ?>]" min="<?php echo esc_attr( $field[1] ); ?>" max="<?php echo esc_attr( $field[2] ); ?>" step="<?php echo esc_attr( $field[3] ); ?>" value="<?php echo esc_attr( $values[ $rule ] ); ?>" required> <?php echo esc_html( $label[1] ); ?><p class="description"><?php echo esc_html( $label[2] ); ?></p></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php submit_button( __( 'Save booking defaults', 'elaltillo-bookacall' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change booking defaults.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$submitted = isset( $_POST['elab_defaults'] ) && is_array( $_POST['elab_defaults'] ) ? wp_unslash( $_POST['elab_defaults'] ) : array();
		$previous = ELAB_Booking_Defaults::get();
		$values = $previous;
		foreach ( array_keys( ELAB_Booking_Defaults::fields() ) as $rule ) {
			if ( isset( $submitted[ $rule ] ) && is_numeric( $submitted[ $rule ] ) ) {
				$values[ $rule ] = ELAB_Booking_Defaults::clamp( $rule, $submitted[ $rule ] );
			}
		}
		update_option( ELAB_Booking_Defaults::OPTION, $values );
		$changed = array_keys( array_diff_assoc( $values, $previous ) );
		if ( $changed ) {
			// Connected advisors that follow a changed default get their updated schedule by email.
			$advisors = get_posts( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
			foreach ( $advisors as $advisor_id ) {
				foreach ( $changed as $rule ) {
					if ( ELAB_Booking_Defaults::inherits( $advisor_id, $rule ) && ELAB_Google_Calendar::connected( $advisor_id ) ) {
						ELAB_Notifications::queue_schedule_summary( $advisor_id );
						break;
					}
				}
			}
		}
		wp_safe_redirect( ELAB_Google_Settings_Admin::url( 'general', array( 'elab_status' => 'defaults_saved' ) ) );
		exit;
	}
}
