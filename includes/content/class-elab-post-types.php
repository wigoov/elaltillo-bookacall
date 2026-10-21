<?php
/** WordPress content types owned by the booking plugin. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Post_Types {
	const ADVISOR = 'elab_advisor';
	const BOOKING = 'elab_booking';

	public static function register() {
		register_post_type( self::ADVISOR, array(
			'label'           => __( 'Advisors', 'elaltillo-bookacall' ),
			'labels'          => array(
				'name' => __( 'Advisors', 'elaltillo-bookacall' ),
				'singular_name' => __( 'Advisor', 'elaltillo-bookacall' ),
				'add_new_item' => __( 'Add advisor', 'elaltillo-bookacall' ),
				'edit_item' => __( 'Edit advisor', 'elaltillo-bookacall' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_icon'       => 'dashicons-calendar-alt',
			'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		) );

		register_post_type( self::BOOKING, array(
			'label'           => __( 'Booking requests', 'elaltillo-bookacall' ),
			'labels'          => array( 'singular_name' => __( 'Booking request', 'elaltillo-bookacall' ) ),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'edit.php?post_type=' . self::ADVISOR,
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		) );
	}
}
