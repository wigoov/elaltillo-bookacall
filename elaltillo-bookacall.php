<?php
/**
 * Plugin Name: El Altillo Book a Call
 * Description: Book calls with advisors across time zones and connect their Google Calendars.
 * Version: 0.2.4
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: elaltillo-bookacall
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ELAB_VERSION', '0.2.4' );
define( 'ELAB_FILE', __FILE__ );
define( 'ELAB_DIR', plugin_dir_path( __FILE__ ) );

require_once ELAB_DIR . 'includes/class-elab-plugin.php';

add_action( 'plugins_loaded', array( 'ELAB_Plugin', 'instance' ) );
