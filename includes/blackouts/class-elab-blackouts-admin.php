<?php
/** Global blocked dates management under the Advisors menu. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Blackouts_Admin {
	private $hook_suffix;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_elab_save_global_blackouts', array( $this, 'save' ) );
	}

	public function register_page() {
		$this->hook_suffix = add_submenu_page(
			'edit.php?post_type=' . ELAB_Post_Types::ADVISOR,
			__( 'Unavailable dates', 'elaltillo-bookacall' ),
			__( 'Unavailable dates', 'elaltillo-bookacall' ),
			'manage_options',
			'elab-blackouts',
			array( $this, 'render' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( $this->hook_suffix !== $hook ) {
			return;
		}
		wp_enqueue_style( 'elab-advisor-admin', plugins_url( 'assets/css/advisor-admin.css', ELAB_FILE ), array(), ELAB_VERSION );
		wp_enqueue_script( 'elab-advisor-admin', plugins_url( 'assets/js/advisor-admin.min.js', ELAB_FILE ), array(), ELAB_VERSION, true );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap elab-blackouts-page"><h1><?php esc_html_e( 'Unavailable dates for all advisors', 'elaltillo-bookacall' ); ?></h1>
		<p><?php esc_html_e( 'Block holidays, closures and other dates for every advisor. Each advisor can choose to work on a global date from their profile; personal unavailable dates still take priority.', 'elaltillo-bookacall' ); ?></p>
		<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Unavailable dates saved.', 'elaltillo-bookacall' ); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="elab_save_global_blackouts">
			<?php wp_nonce_field( 'elab_save_global_blackouts' ); ?>
			<?php ELAB_Blackouts::render_editor( get_option( ELAB_Blackouts::OPTION, array() ), 'elab_global_blackouts' ); ?>
			<?php submit_button( __( 'Save unavailable dates', 'elaltillo-bookacall' ) ); ?>
		</form></div>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change global unavailable dates.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'elab_save_global_blackouts' );
		$rows = isset( $_POST['elab_global_blackouts'] ) && is_array( $_POST['elab_global_blackouts'] ) ? wp_unslash( $_POST['elab_global_blackouts'] ) : array();
		update_option( ELAB_Blackouts::OPTION, ELAB_Blackouts::sanitize( $rows ), false );
		wp_safe_redirect( add_query_arg( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'page' => 'elab-blackouts', 'updated' => '1' ), admin_url( 'edit.php' ) ) );
		exit;
	}
}
