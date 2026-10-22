<?php
/** Private booking request details and status controls in wp-admin. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_Admin {
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . ELAB_Post_Types::BOOKING, array( $this, 'save' ) );
	}

	public function register_meta_box() {
		add_meta_box( 'elab-booking-details', __( 'Request details', 'elaltillo-bookacall' ), array( $this, 'render' ), ELAB_Post_Types::BOOKING, 'normal', 'high' );
	}

	public function render( $post ) {
		wp_nonce_field( 'elab_booking_meta', 'elab_booking_nonce' );
		$fields = array(
			__( 'Status', 'elaltillo-bookacall' ) => get_post_meta( $post->ID, '_elab_status', true ),
			__( 'Advisor', 'elaltillo-bookacall' ) => get_the_title( (int) get_post_meta( $post->ID, '_elab_advisor', true ) ),
			__( 'Contact', 'elaltillo-bookacall' ) => get_post_meta( $post->ID, '_elab_name', true ) . ' — ' . get_post_meta( $post->ID, '_elab_contact_email', true ),
			__( 'Sector', 'elaltillo-bookacall' ) => get_post_meta( $post->ID, '_elab_sector', true ),
			__( 'Start (UTC)', 'elaltillo-bookacall' ) => get_post_meta( $post->ID, '_elab_start_utc', true ),
			__( 'Visitor time zone', 'elaltillo-bookacall' ) => get_post_meta( $post->ID, '_elab_visitor_timezone', true ),
			__( 'Message', 'elaltillo-bookacall' ) => get_post_meta( $post->ID, '_elab_message', true ),
		);
		echo '<dl>';
		foreach ( $fields as $label => $value ) {
			echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . nl2br( esc_html( (string) $value ) ) . '</dd>';
		}
		$this->render_downloads( $post->ID );
		$this->render_status_control( $post->ID );
		if ( get_post_meta( $post->ID, ELAB_Google_Calendar::META_EVENT, true ) ) {
			echo '<p>' . esc_html__( 'This booking is linked to the advisor’s Google Calendar. Cancelling it will remove the event and notify the visitor.', 'elaltillo-bookacall' ) . '</p>';
		}
		if ( 'failed' === get_post_meta( $post->ID, ELAB_Notifications::META_BOOKING_MAIL, true ) ) {
			echo '<p class="notice notice-warning inline">' . esc_html__( 'The advisor email could not be sent. Check the site email configuration and contact the advisor directly.', 'elaltillo-bookacall' ) . '</p>';
		}
		if ( 'failed' === get_post_meta( $post->ID, ELAB_Notifications::META_VISITOR_MAIL, true ) ) {
			echo '<p class="notice notice-warning inline">' . esc_html__( 'The visitor email could not be sent. Contact the visitor directly.', 'elaltillo-bookacall' ) . '</p>';
		}
	}

	private function render_downloads( $booking_id ) {
		$files = get_post_meta( $booking_id, '_elab_files', true );
		if ( ! is_array( $files ) || ! $files ) {
			return;
		}
		echo '<dt><strong>' . esc_html__( 'Attachments', 'elaltillo-bookacall' ) . '</strong></dt><dd>';
		foreach ( $files as $file ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'elab_download', 'booking' => $booking_id, 'file' => $file['stored'] ), admin_url( 'admin-post.php' ) ), 'elab_download_' . $booking_id . '_' . $file['stored'] );
			printf( '<a href="%s">%s</a><br>', esc_url( $url ), esc_html( $file['name'] ) );
		}
		echo '</dd>';
	}

	private function render_status_control( $booking_id ) {
		$current = get_post_meta( $booking_id, '_elab_status', true ) ?: 'pending';
		echo '</dl><p><label for="elab-status"><strong>' . esc_html__( 'Update status', 'elaltillo-bookacall' ) . '</strong></label><br><select id="elab-status" name="elab_status">';
		$statuses = array( 'pending' => __( 'Pending', 'elaltillo-bookacall' ), 'confirmed' => __( 'Confirmed', 'elaltillo-bookacall' ), 'cancelled' => __( 'Cancelled', 'elaltillo-bookacall' ) );
		foreach ( $statuses as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select></p>';
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST['elab_booking_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['elab_booking_nonce'] ) ), 'elab_booking_meta' ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$status = isset( $_POST['elab_status'] ) ? sanitize_key( wp_unslash( $_POST['elab_status'] ) ) : 'pending';
		if ( in_array( $status, array( 'pending', 'confirmed', 'cancelled' ), true ) ) {
			$current = get_post_meta( $post_id, '_elab_status', true );
			$event_id = get_post_meta( $post_id, ELAB_Google_Calendar::META_EVENT, true );
			if ( 'cancelled' === $current && 'cancelled' !== $status ) {
				return;
			}
			if ( 'confirmed' === $status && ! $event_id && ELAB_Google_Calendar::configured() ) {
				$advisor_id = (int) get_post_meta( $post_id, '_elab_advisor', true );
				$start = get_post_meta( $post_id, '_elab_start_utc', true );
				$event = ELAB_Google_Calendar::create_event( $advisor_id, $post_id, new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) ), max( 15, (int) get_post_meta( $advisor_id, '_elab_duration', true ) ), get_post_meta( $post_id, '_elab_contact_email', true ) );
				if ( is_wp_error( $event ) ) {
					wp_die( esc_html__( 'Google Calendar could not create the event. The booking was not confirmed.', 'elaltillo-bookacall' ), '', array( 'response' => 503 ) );
				}
				$event_id = get_post_meta( $post_id, ELAB_Google_Calendar::META_EVENT, true );
			}
			if ( 'cancelled' === $status && 'cancelled' !== $current && $event_id ) {
				$advisor_id = (int) get_post_meta( $post_id, '_elab_advisor', true );
				$deleted = ELAB_Google_Calendar::delete_event( $advisor_id, $event_id );
				if ( is_wp_error( $deleted ) ) {
					wp_die( esc_html__( 'Google Calendar could not cancel the event. The booking was not changed.', 'elaltillo-bookacall' ), '', array( 'response' => 503 ) );
				}
				delete_post_meta( $post_id, ELAB_Google_Calendar::META_EVENT );
			}
			if ( $event_id && 'pending' === $status ) {
				return;
			}
			update_post_meta( $post_id, '_elab_status', $status );
			if ( $status !== $current ) {
				ELAB_Notifications::booking_notice( $post_id, $status );
				if ( ! $event_id && in_array( $status, array( 'confirmed', 'cancelled' ), true ) ) {
					ELAB_Notifications::visitor_notice( $post_id, $status );
				}
			}
		}
	}
}
