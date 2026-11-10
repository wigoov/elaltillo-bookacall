<?php
/** Booking request validation, advisor assignment, persistence and notification. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_Service {
	const LOCK_TTL = 2 * MINUTE_IN_SECONDS;
	const DEFAULT_LANGUAGE = 'es';
	private $attachments;

	/**
	 * Languages a visitor can ask for, as code => name in that language.
	 * Filter `elab_meeting_languages` to add or remove options.
	 */
	public static function languages() {
		$languages = apply_filters( 'elab_meeting_languages', array( 'es' => 'Español', 'en' => 'English' ) );
		$clean = array();
		foreach ( (array) $languages as $code => $label ) {
			if ( is_string( $code ) && preg_match( '/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $code ) && is_string( $label ) && '' !== $label ) {
				$clean[ $code ] = $label;
			}
		}
		return $clean ?: array( self::DEFAULT_LANGUAGE => 'Español' );
	}

	public static function language_label( $code ) {
		$languages = self::languages();
		return $languages[ $code ] ?? (string) $code;
	}

	public function __construct( ELAB_Private_Attachments $attachments ) {
		$this->attachments = $attachments;
	}

	public function create( WP_REST_Request $request ) {
		foreach ( array( 'advisor', 'start', 'name', 'email', 'sector', 'timezone', 'message', 'language' ) as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value && ! is_string( $value ) && ! is_int( $value ) ) {
				return new WP_Error( 'invalid_booking', __( 'Please check the booking details and try again.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
			}
		}
		$advisor_id = absint( $request->get_param( 'advisor' ) );
		$start = sanitize_text_field( (string) $request->get_param( 'start' ) );
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$sector = sanitize_text_field( (string) $request->get_param( 'sector' ) );
		$timezone = sanitize_text_field( (string) $request->get_param( 'timezone' ) );
		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		if ( '1' !== (string) $request->get_param( 'consent' ) ) {
			return new WP_Error( 'consent_required', __( 'Please accept the privacy policy to book a call.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		$language = sanitize_key( (string) ( $request->get_param( 'language' ) ?? self::DEFAULT_LANGUAGE ) );
		if ( ! array_key_exists( $language, self::languages() ) ) {
			return new WP_Error( 'invalid_booking', __( 'Please choose a meeting language.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		if ( ! $name || ! is_email( $email ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $start ) || strlen( $name ) > 120 || strlen( $email ) > 190 || strlen( $sector ) > 120 || strlen( $message ) > 5000 || strlen( $timezone ) > 100 ) {
			return new WP_Error( 'invalid_booking', __( 'Please complete all required fields.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		try {
			$start_utc = new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) );
			new DateTimeZone( $timezone );
		} catch ( Exception $exception ) {
			return new WP_Error( 'invalid_time', __( 'Invalid meeting time or time zone.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		$timestamp = $start_utc->getTimestamp();

		// Candidates come from the cached view; each one is re-checked live while locked.
		$bookable = ELAB_Availability::bookable_advisors();
		if ( $advisor_id && ! in_array( $advisor_id, $bookable, true ) ) {
			return new WP_Error( 'invalid_booking', __( 'This advisor is not available for online booking.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		$map = ELAB_Availability::collect( $advisor_id ? array( $advisor_id ) : $bookable, $timestamp, $timestamp + 1 );
		if ( is_wp_error( $map ) ) {
			return $map;
		}
		$candidates = $map[ $timestamp ] ?? array();
		if ( ! $candidates ) {
			return $this->unavailable();
		}
		if ( count( $candidates ) > 1 ) {
			$load = array();
			foreach ( $candidates as $candidate ) {
				$load[ $candidate ] = ELAB_Availability::upcoming_load( $candidate );
			}
			usort( $candidates, static function ( $a, $b ) use ( $load ) { return $load[ $a ] <=> $load[ $b ] ?: $a <=> $b; } );
		}

		$files = $this->attachments->upload();
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$last_error = null;
		foreach ( $candidates as $candidate ) {
			$lock = $this->acquire_lock( $candidate );
			if ( ! $lock ) {
				continue;
			}
			try {
				$available = ELAB_Availability::is_available( $candidate, $timestamp );
				if ( is_wp_error( $available ) ) {
					$last_error = $available;
					continue;
				}
				if ( ! $available ) {
					continue;
				}
				$result = $this->save( $candidate, $start_utc, array_merge( compact( 'name', 'email', 'sector', 'timezone', 'message', 'language', 'files' ), array( 'page' => absint( $request->get_param( 'page' ) ) ) ) );
				if ( is_wp_error( $result ) ) {
					$last_error = $result;
					continue;
				}
				return $result;
			} finally {
				delete_option( $lock );
			}
		}
		$this->attachments->discard( $files );
		return $last_error ?: $this->unavailable();
	}

	private function unavailable() {
		return new WP_Error( 'slot_unavailable', __( 'That time was just taken. Please choose another.', 'elaltillo-bookacall' ), array( 'status' => 409 ) );
	}

	/** One booking at a time per advisor; waits briefly for a concurrent request to finish. */
	private function acquire_lock( $advisor_id ) {
		$lock = 'elab_advisor_lock_' . (int) $advisor_id;
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$held = (int) get_option( $lock );
			if ( $held && $held < time() - self::LOCK_TTL ) {
				delete_option( $lock );
			}
			if ( add_option( $lock, time(), '', 'no' ) ) {
				return $lock;
			}
			usleep( 250000 );
		}
		return false;
	}

	private function save( $advisor_id, DateTimeImmutable $start_utc, array $visitor ) {
		$duration = ELAB_Availability::rules( $advisor_id )['duration'];
		$start_key = $start_utc->format( 'Y-m-d H:i:s' );
		$booking_id = wp_insert_post( array(
			'post_type'   => ELAB_Post_Types::BOOKING,
			'post_status' => 'private',
			'post_title'  => sprintf( '%s — %s', $visitor['name'], $start_key ),
		), true );
		if ( is_wp_error( $booking_id ) ) {
			return $booking_id;
		}
		$values = array(
			'_elab_status'           => 'pending',
			'_elab_advisor'          => $advisor_id,
			'_elab_name'             => $visitor['name'],
			'_elab_contact_email'    => $visitor['email'],
			'_elab_sector'           => $visitor['sector'],
			'_elab_start_utc'        => $start_key,
			'_elab_duration'         => $duration,
			'_elab_visitor_timezone' => $visitor['timezone'],
			'_elab_message'          => $visitor['message'],
			'_elab_language'         => $visitor['language'],
			'_elab_consent_at'       => gmdate( 'Y-m-d H:i:s' ),
			'_elab_consent_policy'   => esc_url_raw( get_privacy_policy_url() ),
			'_elab_files'            => $visitor['files'],
		);
		foreach ( $values as $key => $value ) {
			update_post_meta( $booking_id, $key, $value );
		}
		$page = absint( $visitor['page'] ?? 0 );
		if ( $page && 'publish' === get_post_status( $page ) && has_shortcode( (string) get_post_field( 'post_content', $page ), 'elaltillo_bookacall' ) ) {
			update_post_meta( $booking_id, ELAB_Booking_Manage::META_PAGE, $page );
		}
		ELAB_Booking_Manage::issue_token( $booking_id );
		$summary = array(
			'advisor'  => get_the_title( $advisor_id ),
			'start'    => $start_utc->format( 'Y-m-d\TH:i:s\Z' ),
			'end'      => $start_utc->modify( '+' . $duration . ' minutes' )->format( 'Y-m-d\TH:i:s\Z' ),
			'duration' => $duration,
			'language' => self::language_label( $visitor['language'] ),
		);
		if ( ELAB_Google_Calendar::configured() ) {
			$event = ELAB_Google_Calendar::create_event( $advisor_id, $booking_id, $start_utc, $duration, $visitor['email'] );
			if ( is_wp_error( $event ) ) {
				// Keep the uploaded files; they belong to the request, which may still go to another advisor.
				delete_post_meta( $booking_id, '_elab_files' );
				wp_delete_post( $booking_id, true );
				return $event;
			}
			update_post_meta( $booking_id, '_elab_status', 'confirmed' );
			ELAB_Notifications::booking_notice( $booking_id, 'confirmed' );
			ELAB_Notifications::visitor_booked( $booking_id );
			return new WP_REST_Response( array(
				'status'   => 'confirmed',
				'message'  => __( 'A Google Calendar invitation is on its way to your email.', 'elaltillo-bookacall' ),
				'booking'  => $summary,
				'manage'   => ELAB_Booking_Manage::url( $booking_id ),
				'meet'     => (string) get_post_meta( $booking_id, '_elab_meet_url', true ),
				'calendar' => array(
					'title' => sprintf( __( 'Call with %s', 'elaltillo-bookacall' ), $summary['advisor'] ),
					'start' => $summary['start'],
					'end'   => $summary['end'],
					'uid'   => sanitize_text_field( $event['iCalUID'] ?? 'elab-' . $booking_id . '@desdeelaltillo.com' ),
					'url'   => esc_url_raw( $event['htmlLink'] ?? '' ),
				),
			), 201 );
		}
		ELAB_Notifications::booking_notice( $booking_id, 'pending' );
		ELAB_Notifications::visitor_booked( $booking_id );
		return new WP_REST_Response( array(
			'status'  => 'pending',
			'message' => __( 'We will confirm this time by email shortly.', 'elaltillo-bookacall' ),
			'booking' => $summary,
			'manage'  => ELAB_Booking_Manage::url( $booking_id ),
		), 201 );
	}
}
