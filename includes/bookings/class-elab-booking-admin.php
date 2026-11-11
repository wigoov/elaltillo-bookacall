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
		$message = __( 'Cancel this call? The visitor is notified, the time is freed and the event is removed from the advisor’s Google Calendar. This cannot be undone.', 'elaltillo-bookacall' );
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
			__( 'Guests', 'elaltillo-bookacall' ) => implode( ', ', ELAB_Booking_Sync::guests( $post->ID ) ) ?: '—',
			__( 'Received', 'elaltillo-bookacall' ) => get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post ),
			__( 'Video call', 'elaltillo-bookacall' ) => get_post_meta( $post->ID, '_elab_meet_url', true ) ?: '—',
			__( 'Cancelled', 'elaltillo-bookacall' ) => get_post_meta( $post->ID, '_elab_cancelled_at', true ) ? sprintf( array( 'visitor' => __( 'By the visitor, %s UTC', 'elaltillo-bookacall' ), 'google' => __( 'Deleted in the advisor’s Google Calendar, noticed %s UTC', 'elaltillo-bookacall' ) )[ get_post_meta( $post->ID, '_elab_cancelled_by', true ) ] ?? __( 'By an administrator, %s UTC', 'elaltillo-bookacall' ), get_post_meta( $post->ID, '_elab_cancelled_at', true ) ) : '—',
			__( 'Privacy consent', 'elaltillo-bookacall' ) => get_post_meta( $post->ID, '_elab_consent_at', true ) ? sprintf( __( 'Accepted %s UTC', 'elaltillo-bookacall' ), get_post_meta( $post->ID, '_elab_consent_at', true ) ) : '—',
		);
		// Rows that only apply sometimes are left out instead of showing a dash.
		foreach ( array( __( 'Guests', 'elaltillo-bookacall' ), __( 'Video call', 'elaltillo-bookacall' ), __( 'Cancelled', 'elaltillo-bookacall' ), __( 'Privacy consent', 'elaltillo-bookacall' ) ) as $optional ) {
			if ( isset( $fields[ $optional ] ) && '—' === $fields[ $optional ] ) {
				unset( $fields[ $optional ] );
			}
		}
		echo '<dl class="elab-booking-details">';
		foreach ( $fields as $label => $value ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . nl2br( esc_html( (string) $value ) ) . '</dd>';
		}
		$this->render_downloads( $post->ID );
		$this->render_status_control( $post->ID );
		$sync = ELAB_Booking_Sync::state( $post->ID );
		if ( ELAB_Booking_Sync::CREATE === $sync ) {
			$broken = ! ELAB_Google_Calendar::connected( $advisor_id );
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Saved, waiting for Google.', 'elaltillo-bookacall' ) . '</strong> ' . esc_html( $broken ? __( 'The advisor’s Google connection is not working, so the calendar event and the visitor’s invitation are not created yet. They are added automatically when the advisor reconnects. The advisor was emailed.', 'elaltillo-bookacall' ) : __( 'Google did not answer. The event is retried every hour.', 'elaltillo-bookacall' ) ) . '</p>';
			echo '<p class="elab-inline-actions">';
			if ( $broken && current_user_can( 'edit_post', $advisor_id ) ) {
				echo '<a class="button button-primary" href="' . esc_url( admin_url( 'post.php?post=' . $advisor_id . '&action=edit#elab-google-connection' ) ) . '">' . esc_html__( 'Open advisor connection', 'elaltillo-bookacall' ) . '</a> ';
			}
			echo '<a class="button" href="' . esc_url( ELAB_Booking_Sync::advisor_url( $post->ID ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Add to the advisor’s calendar by hand', 'elaltillo-bookacall' ) . '</a></p>';
			echo '</div>';
		} elseif ( ELAB_Booking_Sync::DELETE === $sync ) {
			echo '<p class="notice notice-warning inline">' . esc_html__( 'Cancelled here. Google did not answer yet, so the event is removed from the advisor’s calendar as soon as it does.', 'elaltillo-bookacall' ) . '</p>';
		} elseif ( get_post_meta( $post->ID, ELAB_Booking_Sync::META_MANUAL, true ) ) {
			echo '<p class="notice notice-warning inline">' . esc_html( ELAB_Booking_Sync::by_advisor( $post->ID ) ? __( 'The advisor added this call to their calendar by hand, so the site cannot remove it: the advisor should delete that event. The visitor was emailed.', 'elaltillo-bookacall' ) : __( 'This call was created in the advisor’s main Google Calendar before the dedicated calendar existed. The site can no longer change it: the advisor should delete that event by hand. The visitor was emailed.', 'elaltillo-bookacall' ) ) . '</p>';
		} elseif ( ELAB_Booking_Sync::by_advisor( $post->ID ) ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'The advisor added this call to their own calendar by hand on %s, so the site will not create it.', 'elaltillo-bookacall' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), ELAB_Booking_Sync::by_advisor( $post->ID ) ) ) ) . '</p>';
		} elseif ( get_post_meta( $post->ID, ELAB_Google_Calendar::META_EVENT, true ) ) {
			echo '<p class="description">' . esc_html__( 'In the advisor’s Google Calendar. Cancelling removes the event and Google notifies the visitor.', 'elaltillo-bookacall' ) . '</p>';
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
			if ( $status === $current || ( ( $event_id || ELAB_Booking_Sync::state( $post_id ) ) && 'pending' === $status ) ) {
				return;
			}
			if ( 'cancelled' === $status ) {
				ELAB_Booking_Manage::mark_cancelled( $post_id, 'admin' );
				return;
			}
			update_post_meta( $post_id, '_elab_status', $status );
			$invited = false;
			if ( 'confirmed' === $status && ! $event_id && ELAB_Google_Calendar::configured() ) {
				$event = ELAB_Booking_Sync::create_for( $post_id );
				if ( is_wp_error( $event ) ) {
					ELAB_Booking_Sync::queue_create( $post_id, $event );
				} else {
					$invited = true;
				}
			}
			ELAB_Notifications::booking_notice( $post_id, $status );
			if ( 'confirmed' === $status && ! $invited ) {
				ELAB_Notifications::visitor_notice( $post_id, $status );
			}
		}
	}
}
