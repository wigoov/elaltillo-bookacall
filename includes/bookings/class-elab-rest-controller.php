<?php
/** Public REST interface for availability and booking submission. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_REST_Controller {
	private $availability;
	private $booking_service;

	public function __construct() {
		$attachments = new ELAB_Private_Attachments();
		$this->availability = new ELAB_Availability();
		$this->booking_service = new ELAB_Booking_Service( $this->availability, $attachments );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( 'elab/v1', '/slots', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_slots' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'advisor'  => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'date'     => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'timezone' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( 'elab/v1', '/bookings', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'create_booking' ),
			'permission_callback' => '__return_true',
		) );
	}

	public function get_slots( WP_REST_Request $request ) {
		return $this->availability->get_slots( $request );
	}

	public function create_booking( WP_REST_Request $request ) {
		return $this->booking_service->create( $request );
	}
}
