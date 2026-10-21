<?php
/** Availability generation in advisor time, returned in visitor time. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Availability {
	public function get_slots( WP_REST_Request $request ) {
		$advisor_id = absint( $request['advisor'] );
		$advisor = get_post( $advisor_id );
		$date = $request['date'];
		$visitor_timezone = $request['timezone'];
		if ( ! $advisor || ELAB_Post_Types::ADVISOR !== $advisor->post_type || 'publish' !== $advisor->post_status || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'invalid_request', __( 'Invalid advisor or date.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		try {
			$advisor_timezone = new DateTimeZone( get_post_meta( $advisor_id, '_elab_timezone', true ) ?: 'UTC' );
			$visitor_zone = new DateTimeZone( $visitor_timezone );
			$local_date = new DateTimeImmutable( $date . ' 00:00:00', $visitor_zone );
		} catch ( Exception $exception ) {
			return new WP_Error( 'invalid_timezone', __( 'Invalid time zone.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		$duration = max( 15, (int) get_post_meta( $advisor_id, '_elab_duration', true ) );
		$availability = json_decode( get_post_meta( $advisor_id, '_elab_availability', true ), true );
		$busy = $this->booked_starts( $advisor_id );
		$google_busy = array();
		if ( ELAB_Google_Calendar::configured() ) {
			if ( ! ELAB_Google_Calendar::connected( $advisor_id ) ) {
				return new WP_Error( 'google_disconnected', __( 'This advisor has not connected Google Calendar yet.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
			}
			$google_busy = ELAB_Google_Calendar::busy( $advisor_id, $local_date->setTimezone( new DateTimeZone( 'UTC' ) ), $local_date->modify( '+1 day' )->setTimezone( new DateTimeZone( 'UTC' ) ) );
			if ( is_wp_error( $google_busy ) ) {
				return $google_busy;
			}
		}
		$slots = array();
		// Adjacent local dates cover offsets across the international date line.
		for ( $delta = -1; $delta <= 1; $delta++ ) {
			$visitor_day = $local_date->modify( ( $delta >= 0 ? '+' : '' ) . $delta . ' days' );
			$utc_start = $visitor_day->setTimezone( new DateTimeZone( 'UTC' ) );
			$utc_end = $visitor_day->modify( '+1 day' )->setTimezone( new DateTimeZone( 'UTC' ) );
			$cursor = $utc_start->setTimezone( $advisor_timezone )->setTime( 0, 0 );
			$advisor_end = $utc_end->setTimezone( $advisor_timezone );
			while ( $cursor < $advisor_end ) {
				if ( ELAB_Blackouts::is_blocked( $advisor_id, $cursor ) ) {
					$cursor = $cursor->modify( '+1 day' );
					continue;
				}
				$weekday = (int) $cursor->format( 'N' );
				foreach ( (array) ( $availability[ $weekday ] ?? array() ) as $range ) {
					if ( ! is_array( $range ) || 2 !== count( $range ) ) {
						continue;
					}
					$start = new DateTimeImmutable( $cursor->format( 'Y-m-d' ) . ' ' . $range[0], $advisor_timezone );
					$end = new DateTimeImmutable( $cursor->format( 'Y-m-d' ) . ' ' . $range[1], $advisor_timezone );
					for ( $slot = $start; $slot->modify( '+' . $duration . ' minutes' ) <= $end; $slot = $slot->modify( '+' . $duration . ' minutes' ) ) {
						$utc = $slot->setTimezone( new DateTimeZone( 'UTC' ) );
						$visitor_time = $utc->setTimezone( $visitor_zone );
						$utc_key = $utc->format( 'Y-m-d H:i:s' );
						$ends_at = $utc->modify( '+' . $duration . ' minutes' )->getTimestamp();
						$overlaps_google = false;
						foreach ( $google_busy as $period ) {
							if ( isset( $period['start'], $period['end'] ) && $utc->getTimestamp() < strtotime( $period['end'] ) && $ends_at > strtotime( $period['start'] ) ) {
								$overlaps_google = true;
								break;
							}
						}
						if ( $visitor_time->format( 'Y-m-d' ) !== $date || $utc <= new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) || isset( $busy[ $utc_key ] ) || $overlaps_google ) {
							continue;
						}
						$slots[ $utc->format( 'Y-m-d\TH:i:s\Z' ) ] = array(
							'value' => $utc->format( 'Y-m-d\TH:i:s\Z' ),
							'label' => wp_date( get_option( 'time_format' ), $utc->getTimestamp(), $visitor_zone ) . ' (' . $visitor_time->format( 'T' ) . ')',
						);
					}
				}
				$cursor = $cursor->modify( '+1 day' );
			}
		}
		ksort( $slots );
		return rest_ensure_response( array_values( $slots ) );
	}

	private function booked_starts( $advisor_id ) {
		$bookings = get_posts( array(
			'post_type'   => ELAB_Post_Types::BOOKING,
			'post_status' => array( 'private', 'publish' ),
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_query'  => array(
				array( 'key' => '_elab_advisor', 'value' => $advisor_id ),
				array( 'key' => '_elab_status', 'value' => array( 'pending', 'confirmed' ), 'compare' => 'IN' ),
			),
		) );
		$busy = array();
		foreach ( $bookings as $booking_id ) {
			$start = get_post_meta( $booking_id, '_elab_start_utc', true );
			if ( $start ) {
				$busy[ $start ] = true;
			}
		}
		return $busy;
	}
}
