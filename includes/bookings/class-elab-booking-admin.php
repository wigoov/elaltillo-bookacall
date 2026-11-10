<?php
/** Private booking request details and status controls in wp-admin. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_Admin {
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . ELAB_Post_Types::BOOKING, array( $this, 'save' ) );
		add_action( 'admin_footer-post.php', array( $this, 'confirm_cancel_script' ) );
	}

	/** Ask before a cancellation, which deletes the Google event and notifies the visitor. */
	public function confirm_cancel_script() {
		$screen = get_current_screen();
		if ( ! $screen || ELAB_Post_Types::BOOKING !== $screen->post_type ) {
			return;
		}
		$message = __( 'Cancel this call? The visitor is notified and, if connected, the event is removed from the advisor’s Google Calendar. This cannot be undone.', 'elaltillo-bookacall' );
		wp_print_inline_script_tag( '(function(){var f=document.getElementById("post"),s=document.getElementById("elab-status");if(!f||!s)return;var initial=s.value;f.addEventListener("submit",function(e){if(s.value==="cancelled"&&initial!=="cancelled"&&!window.confirm(' . wp_json_encode( $message ) . ')){e.preventDefault();e.stopImmediatePropagation();var b=document.querySelector("#publishing-action .spinner");if(b)b.classList.remove("is-active");document.querySelectorAll("#publish,#save-post").forEach(function(x){x.classList.remove("disabled");x.disabled=false;});}});})();' );
	}

	/** Booking length in minutes; older records fall back to the advisor's current length. */
	public static function duration( $booking_id ) {
		$minutes = (int) get_post_meta( $booking_id, '_elab_duration', true );
		return $minutes ?: ELAB_Availability::rules( (int) get_post_meta( $booking_id, '_elab_advisor', true ) )['duration'];
	}

	public function register_meta_box() {
		add_meta_box( 'elab-booking-details', __( 'Request details', 'elaltillo-bookacall' ), array( $this, 'render' ), ELAB_Post_Types::BOOKING, 'normal', 'high' );
	}

	public function render( $post ) {
		wp_nonce_field( 'elab_booking_meta', 'elab_booking_nonce' );
		$advisor_id = (int) get_post_meta( $post->ID, '_elab_advisor', true );
		$advisor_zone = (string) get_post_meta( $advisor_id, '_elab_timezone', true ) ?: 'UTC';
		$visitor_zone = (string) get_post_meta( $post->ID, '_elab_visitor_timezone', true ) ?: 'UTC';
		$status = (string) get_post_meta( $post->ID, '_elab_status', true ) ?: 'pending';
		echo '<div class="elab-booking-head"><p class="elab-booking-when">' . esc_html( ELAB_Booking_List_Admin::format_time( $post->ID, $advisor_zone ) ) . '</p>' . ELAB_Booking_List_Admin::status_badge( $status ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge escaped in status_badge().
		$fields = array(
			__( 'Advisor', 'elaltillo-bookacall' ) => $advisor_id ? get_the_title( $advisor_id ) . ' (' . str_replace( '_', ' ', $advisor_zone ) . ')' : '—',
			__( 'Visitor', 'elaltillo-bookacall' ) => get_post_meta( $post->ID, '_elab_name', true ) . ' — ' . get_post_meta( $post->ID, '_elab_contact_email', true ),
			__( 'Visitor’s local time', 'elaltillo-bookacall' ) => ELAB_Booking_List_Admin::format_time( $post->ID, $visitor_zone ) . ' (' . str_replace( '_', ' ', $visitor_zone ) . ')',
			__( 'UTC', 'elaltillo-bookacall' ) => ELAB_Booking_List_Admin::format_time( $post->ID, 'UTC' ),
			__( 'Length', 'elaltillo-bookacall' ) => sprintf( __( '%d minutes', 'elaltillo-bookacall' ), self::duration( $post->ID ) ),
			__( 'Meeting language', 'elaltillo-bookacall' ) => ELAB_Booking_Service::language_label( get_post_meta( $post->ID, '_elab_language', true ) ?: ELAB_Booking_Service::DEFAULT_LANGUAGE ),
			__( 'Sector', 'elaltillo-bookacall' ) => get_post_meta( $post->ID, '_elab_sector', true ) ?: '—',
			__( 'Message', 'elaltillo-bookacall' ) => get_post_meta( $post->ID, '_elab_message', true ) ?: '—',
			__( 'Received', 'elaltillo-bookacall' ) => get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post ),
		);
		echo '<dl class="elab-booking-details">';
		foreach ( $fields as $label => $value ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . nl2br( esc_html( (string) $value ) ) . '</dd>';
		}
		$this->render_downloads( $post->ID );
		$this->render_status_control( $post->ID );
		if ( get_post_meta( $post->ID, ELAB_Google_Calendar::META_EVENT, true ) ) {
			echo '<p class="description">' . esc_html__( 'This booking is linked to the advisor’s Google Calendar. Cancelling it will remove the event and notify the visitor.', 'elaltillo-bookacall' ) . '</p>';
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
		echo '<dt>' . esc_html__( 'Attachments', 'elaltillo-bookacall' ) . '</dt><dd>';
		foreach ( $files as $file ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'elab_download', 'booking' => $booking_id, 'file' => $file['stored'] ), admin_url( 'admin-post.php' ) ), 'elab_download_' . $booking_id . '_' . $file['stored'] );
			printf( '<a href="%s">%s</a><br>', esc_url( $url ), esc_html( $file['name'] ) );
		}
		echo '</dd>';
	}

	private function render_status_control( $booking_id ) {
		$current = get_post_meta( $booking_id, '_elab_status', true ) ?: 'pending';
		echo '</dl><p class="elab-status-control"><label for="elab-status"><strong>' . esc_html__( 'Update status', 'elaltillo-bookacall' ) . '</strong></label><br><select id="elab-status" name="elab_status"' . disabled( 'cancelled', $current, false ) . '>';
		foreach ( ELAB_Booking_List_Admin::statuses() as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select> <span class="description">' . esc_html( 'cancelled' === $current ? __( 'Cancelled calls cannot be reopened.', 'elaltillo-bookacall' ) : __( 'Choose a status and select Update.', 'elaltillo-bookacall' ) ) . '</span></p>';
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
				$event = ELAB_Google_Calendar::create_event( $advisor_id, $post_id, new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) ), ELAB_Booking_Admin::duration( $post_id ), get_post_meta( $post_id, '_elab_contact_email', true ) );
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
