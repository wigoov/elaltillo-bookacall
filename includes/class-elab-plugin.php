<?php
/** Plugin composition root: load modules and connect their WordPress hooks. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/content/class-elab-post-types.php';
require_once __DIR__ . '/google/class-elab-google-calendar.php';
require_once __DIR__ . '/google/class-elab-google-settings-admin.php';
require_once __DIR__ . '/notifications/class-elab-notifications.php';
require_once __DIR__ . '/blackouts/class-elab-blackouts.php';
require_once __DIR__ . '/blackouts/class-elab-blackouts-admin.php';
require_once __DIR__ . '/blackouts/class-elab-global-overrides-admin.php';
require_once __DIR__ . '/advisors/class-elab-advisor-admin.php';
require_once __DIR__ . '/bookings/class-elab-booking-admin.php';
require_once __DIR__ . '/bookings/class-elab-availability.php';
require_once __DIR__ . '/bookings/class-elab-booking-guard.php';
require_once __DIR__ . '/bookings/class-elab-booking-service.php';
require_once __DIR__ . '/bookings/class-elab-rest-controller.php';
require_once __DIR__ . '/files/class-elab-private-attachments.php';
require_once __DIR__ . '/frontend/class-elab-booking-form.php';

final class ELAB_Plugin {
	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( 'ELAB_Post_Types', 'register' ) );
		new ELAB_Google_Calendar();
		new ELAB_Google_Settings_Admin();
		new ELAB_Blackouts_Admin();
		new ELAB_Global_Overrides_Admin();
		new ELAB_Advisor_Admin();
		new ELAB_Booking_Admin();
		new ELAB_REST_Controller();
		new ELAB_Booking_Form();
	}
}
