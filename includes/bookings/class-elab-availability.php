<?php
/**
 * Availability engine.
 *
 * Slots are generated in each advisor's time zone from their weekly hours, then
 * removed when they fall inside a closure, the booking notice window, beyond the
 * booking horizon, or overlap (with buffer) an existing booking or a Google busy
 * period. Public responses are grouped by the visitor's local calendar date.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Availability {
	const META_NOTICE = '_elab_notice_hours';
	const META_HORIZON = '_elab_horizon_days';
	const META_BUFFER = '_elab_buffer_minutes';
	/** Normalized booking rules for one advisor: its own values, else the site defaults. */
	public static function rules( $advisor_id ) {
		try {
			$zone = new DateTimeZone( (string) get_post_meta( $advisor_id, '_elab_timezone', true ) ?: 'UTC' );
		} catch ( Exception $exception ) {
			$zone = new DateTimeZone( 'UTC' );
		}
		$weekly = json_decode( (string) get_post_meta( $advisor_id, '_elab_availability', true ), true );
		$rules = array( 'timezone' => $zone );
		$defaults = ELAB_Booking_Defaults::get();
		foreach ( ELAB_Booking_Defaults::fields() as $rule => $field ) {
			$rules[ $rule ] = ELAB_Booking_Defaults::inherits( $advisor_id, $rule ) ? $defaults[ $rule ] : ELAB_Booking_Defaults::clamp( $rule, get_post_meta( $advisor_id, $field[4], true ) );
		}
		$rules['weekly'] = is_array( $weekly ) ? $weekly : array();
		return $rules;
	}

	/** Published advisors that can take bookings right now. */
	public static function bookable_advisors() {
		$ids = get_posts( array(
			'post_type'   => ELAB_Post_Types::ADVISOR,
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'orderby'     => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
		) );
		$ids = array_map( 'intval', $ids );
		if ( ELAB_Google_Calendar::configured() ) {
			$ids = array_values( array_filter( $ids, array( 'ELAB_Google_Calendar', 'connected' ) ) );
		}
		return $ids;
	}

	public static function is_bookable( $advisor_id ) {
		return in_array( (int) $advisor_id, self::bookable_advisors(), true );
	}

	/**
	 * Free start timestamps for one advisor inside [$from, $to).
	 *
	 * @param bool $fresh Skip the Google free/busy cache (used right before booking).
	 * @return int[]|WP_Error
	 */
	public static function advisor_starts( $advisor_id, $from, $to, $fresh = false ) {
		$rules = self::rules( $advisor_id );
		$now = time();
		$earliest = max( (int) $from, $now + $rules['notice'] * HOUR_IN_SECONDS );
		$latest = min( (int) $to, $now + $rules['horizon'] * DAY_IN_SECONDS );
		if ( $earliest >= $latest ) {
			return array();
		}
		$duration = $rules['duration'] * MINUTE_IN_SECONDS;
		$buffer = $rules['buffer'] * MINUTE_IN_SECONDS;
		$step = ( $rules['interval'] ?: $rules['duration'] ) * MINUTE_IN_SECONDS;
		$blocked = self::booked_intervals( $advisor_id, $earliest - DAY_IN_SECONDS, $latest + DAY_IN_SECONDS );
		if ( ELAB_Google_Calendar::configured() ) {
			$utc = new DateTimeZone( 'UTC' );
			$busy = ELAB_Google_Calendar::busy(
				$advisor_id,
				( new DateTimeImmutable( '@' . ( $earliest - $buffer ) ) )->setTimezone( $utc ),
				( new DateTimeImmutable( '@' . ( $latest + $duration + $buffer ) ) )->setTimezone( $utc ),
				$fresh
			);
			if ( is_wp_error( $busy ) ) {
				return $busy;
			}
			foreach ( $busy as $period ) {
				$start = isset( $period['start'] ) ? strtotime( $period['start'] ) : false;
				$end = isset( $period['end'] ) ? strtotime( $period['end'] ) : false;
				if ( $start && $end ) {
					$blocked[] = array( $start, $end );
				}
			}
		}
		$zone = $rules['timezone'];
		$cursor = ( new DateTimeImmutable( '@' . $earliest ) )->setTimezone( $zone )->setTime( 0, 0 );
		$last_day = ( new DateTimeImmutable( '@' . $latest ) )->setTimezone( $zone )->format( 'Y-m-d' );
		$starts = array();
		while ( $cursor->format( 'Y-m-d' ) <= $last_day ) {
			$day = $cursor->format( 'Y-m-d' );
			if ( ! ELAB_Blackouts::is_blocked( $advisor_id, $cursor ) ) {
				foreach ( (array) ( $rules['weekly'][ (int) $cursor->format( 'N' ) ] ?? array() ) as $range ) {
					if ( ! is_array( $range ) || 2 !== count( $range ) || ! self::valid_time( $range[0] ) || ! self::valid_time( $range[1] ) ) {
						continue;
					}
					$range_start = ( new DateTimeImmutable( $day . ' ' . $range[0], $zone ) )->getTimestamp();
					$range_end = ( new DateTimeImmutable( $day . ' ' . $range[1], $zone ) )->getTimestamp();
					for ( $slot = $range_start; $slot + $duration <= $range_end; $slot += $step ) {
						if ( $slot < $earliest || $slot >= $latest || self::overlaps( $slot, $slot + $duration, $blocked, $buffer ) ) {
							continue;
						}
						$starts[ $slot ] = true;
					}
				}
			}
			$cursor = $cursor->modify( '+1 day' )->setTime( 0, 0 );
		}
		$starts = array_keys( $starts );
		sort( $starts );
		return $starts;
	}

	private static function valid_time( $value ) {
		return is_string( $value ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value );
	}

	private static function overlaps( $start, $end, $intervals, $buffer ) {
		foreach ( $intervals as $interval ) {
			if ( $start < $interval[1] + $buffer && $end + $buffer > $interval[0] ) {
				return true;
			}
		}
		return false;
	}

	/** Pending and confirmed bookings as [start, end] timestamps, using each booking's own length. */
	private static function booked_intervals( $advisor_id, $from, $to ) {
		$bookings = get_posts( array(
			'post_type'   => ELAB_Post_Types::BOOKING,
			'post_status' => array( 'private', 'publish' ),
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_query'  => array(
				array( 'key' => '_elab_advisor', 'value' => (int) $advisor_id ),
				array( 'key' => '_elab_status', 'value' => array( 'pending', 'confirmed' ), 'compare' => 'IN' ),
				array( 'key' => '_elab_start_utc', 'value' => array( gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ) ), 'compare' => 'BETWEEN', 'type' => 'DATETIME' ),
			),
		) );
		$advisor_duration = self::rules( $advisor_id )['duration'];
		$intervals = array();
		foreach ( $bookings as $booking_id ) {
			$start = strtotime( get_post_meta( $booking_id, '_elab_start_utc', true ) . ' UTC' );
			if ( ! $start ) {
				continue;
			}
			$minutes = (int) get_post_meta( $booking_id, '_elab_duration', true ) ?: $advisor_duration;
			$intervals[] = array( $start, $start + $minutes * MINUTE_IN_SECONDS );
		}
		return $intervals;
	}

	/** Upcoming booking count, used to spread automatic assignments. */
	public static function upcoming_load( $advisor_id ) {
		$now = time();
		return count( self::booked_intervals( $advisor_id, $now, $now + 30 * DAY_IN_SECONDS ) );
	}

	/**
	 * Free starts for several advisors. Advisors whose calendar is unreachable are skipped;
	 * the request only fails when every advisor fails.
	 *
	 * @return array<int, int[]>|WP_Error Start timestamp => advisor IDs.
	 */
	public static function collect( array $advisors, $from, $to, $fresh = false ) {
		$map = array();
		$error = null;
		foreach ( $advisors as $advisor_id ) {
			$starts = self::advisor_starts( $advisor_id, $from, $to, $fresh );
			if ( is_wp_error( $starts ) ) {
				$error = $starts;
				continue;
			}
			foreach ( $starts as $start ) {
				$map[ $start ][] = (int) $advisor_id;
			}
		}
		if ( ! $map && $error && 1 === count( $advisors ) ) {
			return $error;
		}
		if ( ! $map && $error ) {
			return new WP_Error( 'availability_unavailable', __( 'Availability could not be loaded. Please try again shortly.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		ksort( $map );
		return $map;
	}

	/** True when the advisor can take a call starting at $start (always checks Google live). */
	public static function is_available( $advisor_id, $start ) {
		$starts = self::advisor_starts( $advisor_id, $start, $start + 1, true );
		return is_wp_error( $starts ) ? $starts : in_array( (int) $start, $starts, true );
	}

	/** Shared request parsing for the public endpoints. */
	private function request_scope( WP_REST_Request $request ) {
		try {
			$zone = new DateTimeZone( (string) $request['timezone'] );
		} catch ( Exception $exception ) {
			return new WP_Error( 'invalid_timezone', __( 'Invalid time zone.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		$bookable = self::bookable_advisors();
		$advisor_id = absint( $request['advisor'] );
		if ( $advisor_id ) {
			if ( ! in_array( $advisor_id, $bookable, true ) ) {
				return new WP_Error( 'invalid_request', __( 'This advisor is not available for online booking.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
			}
			$bookable = array( $advisor_id );
		}
		return array( 'zone' => $zone, 'advisors' => $bookable );
	}

	/** GET /availability?month=YYYY-MM — number of free times per visitor date. */
	public function get_month( WP_REST_Request $request ) {
		$scope = $this->request_scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$month = (string) $request['month'];
		$first = preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01', $scope['zone'] ) : false;
		if ( ! $first ) {
			return new WP_Error( 'invalid_request', __( 'Invalid month.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		$now = new DateTimeImmutable( 'now', $scope['zone'] );
		$horizon = 0;
		foreach ( $scope['advisors'] as $advisor_id ) {
			$horizon = max( $horizon, self::rules( $advisor_id )['horizon'] );
		}
		$response = array(
			'month' => $month,
			'today' => $now->format( 'Y-m-d' ),
			'max'   => $scope['advisors'] ? $now->modify( '+' . $horizon . ' days' )->format( 'Y-m-d' ) : $now->format( 'Y-m-d' ),
			'days'  => new stdClass(),
		);
		$map = self::collect( $scope['advisors'], $first->getTimestamp(), $first->modify( '+1 month' )->getTimestamp() );
		if ( is_wp_error( $map ) ) {
			return $map;
		}
		$days = array();
		foreach ( array_keys( $map ) as $start ) {
			$date = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( $scope['zone'] )->format( 'Y-m-d' );
			$days[ $date ] = ( $days[ $date ] ?? 0 ) + 1;
		}
		if ( $days ) {
			$response['days'] = $days;
		}
		return rest_ensure_response( $response );
	}

	/** GET /slots?date=YYYY-MM-DD — free times on one visitor date, with the advisors free at each. */
	public function get_slots( WP_REST_Request $request ) {
		$scope = $this->request_scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$date = (string) $request['date'];
		$day = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $scope['zone'] ) : false;
		if ( ! $day || $day->format( 'Y-m-d' ) !== $date ) {
			return new WP_Error( 'invalid_request', __( 'Invalid advisor or date.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		$map = self::collect( $scope['advisors'], $day->getTimestamp(), $day->modify( '+1 day' )->getTimestamp() );
		if ( is_wp_error( $map ) ) {
			return $map;
		}
		$profiles = array();
		$slots = array();
		foreach ( $map as $start => $advisor_ids ) {
			$utc = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( new DateTimeZone( 'UTC' ) );
			$local = $utc->setTimezone( $scope['zone'] );
			$advisors = array();
			foreach ( $advisor_ids as $advisor_id ) {
				if ( ! isset( $profiles[ $advisor_id ] ) ) {
					$profiles[ $advisor_id ] = array(
						'id'       => $advisor_id,
						'name'     => get_the_title( $advisor_id ),
						'duration' => self::rules( $advisor_id )['duration'],
					);
				}
				$advisors[] = $profiles[ $advisor_id ];
			}
			$slots[] = array(
				'value'    => $utc->format( 'Y-m-d\TH:i:s\Z' ),
				'label'    => wp_date( get_option( 'time_format' ), $start, $scope['zone'] ) . ' (' . $local->format( 'T' ) . ')',
				'advisors' => $advisors,
			);
		}
		return rest_ensure_response( $slots );
	}
}
