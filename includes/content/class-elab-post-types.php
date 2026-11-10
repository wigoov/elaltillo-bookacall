<?php
/** WordPress content types owned by the booking plugin. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Post_Types {
	const ADVISOR = 'elab_advisor';
	const BOOKING = 'elab_booking';

	/** Capabilities that map every action on the type to administrators. */
	private static function admin_only( $singular, $create ) {
		return array(
			'edit_post'              => 'edit_' . $singular,
			'read_post'              => 'read_' . $singular,
			'delete_post'            => 'delete_' . $singular,
			'edit_posts'             => 'manage_options',
			'edit_others_posts'      => 'manage_options',
			'publish_posts'          => 'manage_options',
			'read_private_posts'     => 'manage_options',
			'delete_posts'           => 'manage_options',
			'delete_private_posts'   => 'manage_options',
			'delete_published_posts' => 'manage_options',
			'delete_others_posts'    => 'manage_options',
			'edit_private_posts'     => 'manage_options',
			'edit_published_posts'   => 'manage_options',
			'create_posts'           => $create,
		);
	}

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
			// Advisor profiles hold the email that receives bookings and decides which Google
			// account may connect, so only administrators may create or change them.
			'capabilities'    => self::admin_only( 'elab_advisor', 'manage_options' ),
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
			// Bookings come only from the public form; "Add New" would create empty records.
			'capabilities'    => self::admin_only( 'elab_booking', 'do_not_allow' ),
			'map_meta_cap'    => true,
		) );
	}
}
