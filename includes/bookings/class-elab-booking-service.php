<?php
/** Booking request validation, persistence and notification. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_Service {
	private $availability;
	private $attachments;

	public function __construct( ELAB_Availability $availability, ELAB_Private_Attachments $attachments ) {
		$this->availability = $availability;
		$this->attachments = $attachments;
	}

	public function create( WP_REST_Request $request ) {
		$advisor_id = absint( $request->get_param( 'advisor' ) );
		$advisor = get_post( $advisor_id );
		$start = sanitize_text_field( $request->get_param( 'start' ) );
		$name = sanitize_text_field( $request->get_param( 'name' ) );
		$email = sanitize_email( $request->get_param( 'email' ) );
		$sector = sanitize_text_field( $request->get_param( 'sector' ) );
		$timezone = sanitize_text_field( $request->get_param( 'timezone' ) );
		$message = sanitize_textarea_field( $request->get_param( 'message' ) );
		if ( ! $advisor || ELAB_Post_Types::ADVISOR !== $advisor->post_type || 'publish' !== $advisor->post_status || ! $name || ! is_email( $email ) || ! $start ) {
			return new WP_Error( 'invalid_booking', __( 'Please complete all required fields.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		try {
			$start_utc = new DateTimeImmutable( $start );
			new DateTimeZone( $timezone );
		} catch ( Exception $exception ) {
			return new WP_Error( 'invalid_time', __( 'Invalid meeting time or time zone.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		$start_key = $start_utc->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$slot_request = new WP_REST_Request( 'GET', '/elab/v1/slots' );
		$slot_request->set_query_params( array(
			'advisor' => $advisor_id,
			'date' => $start_utc->setTimezone( new DateTimeZone( $timezone ) )->format( 'Y-m-d' ),
			'timezone' => $timezone,
		) );
		$available = $this->availability->get_slots( $slot_request );
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		$valid_slot = false;
		foreach ( $available->get_data() as $slot ) {
			if ( $start_utc->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ) === $slot['value'] ) {
				$valid_slot = true;
				break;
			}
		}
		if ( ! $valid_slot ) {
			return new WP_Error( 'slot_unavailable', __( 'That time is no longer available. Please choose another.', 'elaltillo-bookacall' ), array( 'status' => 409 ) );
		}
		$lock = 'elab_slot_lock_' . md5( $advisor_id . '|' . $start_key );
		$existing_lock = (int) get_option( $lock );
		if ( $existing_lock && $existing_lock < time() - 2 * MINUTE_IN_SECONDS ) {
			delete_option( $lock );
		}
		if ( ! add_option( $lock, time(), '', 'no' ) ) {
			return new WP_Error( 'slot_unavailable', __( 'That time is being booked. Please choose another.', 'elaltillo-bookacall' ), array( 'status' => 409 ) );
		}
		try {
		$files = $this->attachments->upload();
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$booking_id = wp_insert_post( array(
			'post_type' => ELAB_Post_Types::BOOKING,
			'post_status' => 'private',
			'post_title' => sprintf( '%s — %s', $name, $start_key ),
		), true );
		if ( is_wp_error( $booking_id ) ) {
			$this->attachments->discard( $files );
			return $booking_id;
		}
		$values = array(
			'_elab_status' => 'pending',
			'_elab_advisor' => $advisor_id,
			'_elab_name' => $name,
			'_elab_contact_email' => $email,
			'_elab_sector' => $sector,
			'_elab_start_utc' => $start_key,
			'_elab_visitor_timezone' => $timezone,
			'_elab_message' => $message,
			'_elab_files' => $files,
		);
		foreach ( $values as $key => $value ) {
			update_post_meta( $booking_id, $key, $value );
		}
		if ( ELAB_Google_Calendar::configured() ) {
			$duration = max( 15, (int) get_post_meta( $advisor_id, '_elab_duration', true ) );
			$event = ELAB_Google_Calendar::create_event( $advisor_id, $booking_id, $start_utc->setTimezone( new DateTimeZone( 'UTC' ) ), $duration, $name, $email, $sector, $message );
			if ( is_wp_error( $event ) ) {
				$this->attachments->discard( $files );
				wp_delete_post( $booking_id, true );
				return $event;
			}
			update_post_meta( $booking_id, '_elab_status', 'confirmed' );
			ELAB_Notifications::booking_notice( $booking_id, 'confirmed' );
			$ends_at = $start_utc->modify( '+' . $duration . ' minutes' );
			return new WP_REST_Response( array(
				'message' => __( 'Your call is confirmed. A Google Calendar invitation was sent to your email.', 'elaltillo-bookacall' ),
				'calendar' => array(
					'title' => sprintf( __( 'Call with %s', 'elaltillo-bookacall' ), get_the_title( $advisor_id ) ),
					'start' => $start_utc->format( 'Y-m-d\TH:i:s\Z' ),
					'end' => $ends_at->format( 'Y-m-d\TH:i:s\Z' ),
					'uid' => sanitize_text_field( $event['iCalUID'] ?? 'elab-' . $booking_id . '@desdeelaltillo.com' ),
					'url' => esc_url_raw( $event['htmlLink'] ?? '' ),
				),
			), 201 );
		}
		ELAB_Notifications::booking_notice( $booking_id, 'pending' );
		return new WP_REST_Response( array( 'message' => __( 'Your request was received. We will confirm by email.', 'elaltillo-bookacall' ) ), 201 );
		} finally {
			delete_option( $lock );
		}
	}
}
