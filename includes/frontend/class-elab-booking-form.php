<?php
/** Shortcode renderer for the public booking form. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_Form {
	const SCRIPT_HANDLE = 'elab-booking';

	public function __construct() {
		add_shortcode( 'elaltillo_bookacall', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_page_style' ) );
	}

	public function enqueue_page_style() {
		$post = get_queried_object();
		if ( is_page( 'appointment' ) || ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'elaltillo_bookacall' ) ) ) {
			wp_enqueue_style( 'elab-booking', plugins_url( 'assets/css/bookacall.min.css', ELAB_FILE ), array(), ELAB_VERSION );
		}
	}

	public function render() {
		$advisors = get_posts( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		if ( ELAB_Google_Calendar::configured() ) {
			$advisors = array_values( array_filter( $advisors, static function ( $advisor ) { return ELAB_Google_Calendar::connected( $advisor->ID ); } ) );
		}
		wp_enqueue_style( 'elab-booking', plugins_url( 'assets/css/bookacall.min.css', ELAB_FILE ), array(), ELAB_VERSION );
		wp_enqueue_script( self::SCRIPT_HANDLE, plugins_url( 'assets/js/booking.min.js', ELAB_FILE ), array(), ELAB_VERSION, true );
		wp_localize_script( self::SCRIPT_HANDLE, 'elabBooking', array(
			'api'   => esc_url_raw( rest_url( 'elab/v1' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) );
		ob_start();
		require ELAB_DIR . 'includes/frontend/templates/booking-form.php';
		return ob_get_clean();
	}
}
