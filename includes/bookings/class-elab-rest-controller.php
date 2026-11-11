<?php
/** Public REST interface for availability and booking submission. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_REST_Controller {
	private $availability;
	private $booking_service;

	public function __construct() {
		$this->availability = new ELAB_Availability();
		$this->booking_service = new ELAB_Booking_Service( new ELAB_Private_Attachments() );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'no_store' ), 10, 3 );
	}

	/**
	 * Availability changes minute to minute and bookings carry personal data: never cache.
	 * LiteSpeed Cache ignores Cache-Control when "Cache REST API" is on, so it is told
	 * directly through its API and its response header; otherwise visitors see booked
	 * times as free until the cached copy expires.
	 */
	public function no_store( $response, $server, $request ) {
		if ( $response instanceof WP_HTTP_Response && 0 === strpos( $request->get_route(), '/elab/v1/' ) ) {
			$response->header( 'Cache-Control', 'no-store, max-age=0' );
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
			do_action( 'litespeed_control_set_nocache', 'elab booking API' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache API.
		}
		return $response;
	}

	public function register_routes() {
		$scope = array(
			'advisor'  => array( 'required' => false, 'sanitize_callback' => 'absint' ),
			'timezone' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
		);
		register_rest_route( 'elab/v1', '/availability', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_month' ),
			'permission_callback' => '__return_true',
			'args'                => $scope + array( 'month' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );
		register_rest_route( 'elab/v1', '/slots', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_slots' ),
			'permission_callback' => '__return_true',
			'args'                => $scope + array( 'date' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );
		register_rest_route( 'elab/v1', '/bookings', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'create_booking' ),
			'permission_callback' => '__return_true',
		) );
	}

	public function get_month( WP_REST_Request $request ) {
		$allowed = ELAB_Booking_Guard::slots();
		return is_wp_error( $allowed ) ? $allowed : $this->availability->get_month( $request );
	}

	public function get_slots( WP_REST_Request $request ) {
		$allowed = ELAB_Booking_Guard::slots();
		return is_wp_error( $allowed ) ? $allowed : $this->availability->get_slots( $request );
	}

	public function create_booking( WP_REST_Request $request ) {
		$allowed = ELAB_Booking_Guard::submission( $request );
		return is_wp_error( $allowed ) ? $allowed : $this->booking_service->create( $request );
	}
}
