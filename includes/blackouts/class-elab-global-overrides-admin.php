<?php
/** Advisor exceptions to shared unavailable dates. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Global_Overrides_Admin {
	const NONCE_ACTION = 'elab_save_global_overrides';

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . ELAB_Post_Types::ADVISOR, array( $this, 'save_advisor' ) );
		add_action( 'show_user_profile', array( $this, 'render_own_profile' ) );
		add_action( 'personal_options_update', array( $this, 'save_own_profile' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_profile_styles' ) );
	}

	public function enqueue_profile_styles( $hook ) {
		if ( 'profile.php' === $hook && $this->assigned_advisors( get_current_user_id() ) ) {
			wp_enqueue_style( 'elab-advisor-admin', plugins_url( 'assets/css/advisor-admin.css', ELAB_FILE ), array(), ELAB_VERSION );
		}
	}

	public function register_meta_box() {
		add_meta_box(
			'elab-global-overrides',
			__( 'Work on global unavailable dates', 'elaltillo-bookacall' ),
			array( $this, 'render_advisor' ),
			ELAB_Post_Types::ADVISOR,
			'normal',
			'default'
		);
	}

	public function render_advisor( $post ) {
		wp_nonce_field( self::NONCE_ACTION, 'elab_global_overrides_nonce' );
		ELAB_Blackouts::render_global_overrides( $post->ID, 'elab_global_overrides[]' );
	}

	public function save_advisor( $post_id ) {
		if ( ! $this->valid_nonce() || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$submitted = isset( $_POST['elab_global_overrides'] ) && is_array( $_POST['elab_global_overrides'] ) ? wp_unslash( $_POST['elab_global_overrides'] ) : array();
		$clean = ELAB_Blackouts::sanitize_override_keys( $submitted );
		$previous = get_post_meta( $post_id, ELAB_Blackouts::META_GLOBAL_OVERRIDES, true );
		update_post_meta( $post_id, ELAB_Blackouts::META_GLOBAL_OVERRIDES, $clean );
		if ( $clean !== ( is_array( $previous ) ? $previous : array() ) ) {
			ELAB_Notifications::queue_schedule_summary( $post_id );
		}
	}

	public function render_own_profile( $user ) {
		if ( get_current_user_id() !== (int) $user->ID ) {
			return;
		}
		$advisors = $this->assigned_advisors( $user->ID );
		if ( ! $advisors ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Availability on global unavailable dates', 'elaltillo-bookacall' ) . '</h2>';
		wp_nonce_field( self::NONCE_ACTION, 'elab_global_overrides_nonce' );
		foreach ( $advisors as $advisor ) {
			echo '<h3>' . esc_html( get_the_title( $advisor ) ) . '</h3>';
			ELAB_Blackouts::render_global_overrides( $advisor->ID, 'elab_global_overrides[' . $advisor->ID . '][]' );
		}
	}

	public function save_own_profile( $user_id ) {
		if ( get_current_user_id() !== (int) $user_id || ! $this->valid_nonce() ) {
			return;
		}
		$submitted = isset( $_POST['elab_global_overrides'] ) && is_array( $_POST['elab_global_overrides'] ) ? wp_unslash( $_POST['elab_global_overrides'] ) : array();
		foreach ( $this->assigned_advisors( $user_id ) as $advisor ) {
			$values = isset( $submitted[ $advisor->ID ] ) && is_array( $submitted[ $advisor->ID ] ) ? $submitted[ $advisor->ID ] : array();
			$clean = ELAB_Blackouts::sanitize_override_keys( $values );
			$previous = get_post_meta( $advisor->ID, ELAB_Blackouts::META_GLOBAL_OVERRIDES, true );
			update_post_meta( $advisor->ID, ELAB_Blackouts::META_GLOBAL_OVERRIDES, $clean );
			if ( $clean !== ( is_array( $previous ) ? $previous : array() ) ) {
				ELAB_Notifications::queue_schedule_summary( $advisor->ID );
			}
		}
	}

	private function assigned_advisors( $user_id ) {
		return get_posts( array(
			'post_type' => ELAB_Post_Types::ADVISOR,
			'post_status' => 'publish',
			'numberposts' => -1,
			'meta_key' => '_elab_wp_user',
			'meta_value' => $user_id,
		) );
	}

	private function valid_nonce() {
		return isset( $_POST['elab_global_overrides_nonce'] ) && is_string( $_POST['elab_global_overrides_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['elab_global_overrides_nonce'] ) ), self::NONCE_ACTION );
	}
}
