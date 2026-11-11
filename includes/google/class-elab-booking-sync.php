<?php
/**
 * Keeps WordPress the source of truth when Google is unreachable.
 *
 * A booking is always saved here first. If its Google event cannot be created (the advisor
 * is disconnected, access expired, or Google failed), the booking is queued and retried every
 * hour and right after the advisor reconnects. Cancellations work the same way: the call is
 * cancelled here at once and its Google event is removed as soon as Google answers.
 *
 * While a call waits, the advisor can add it to their calendar by hand from the email. That
 * takes the call off the queue first, so there is only ever one event: the site's or the
 * advisor's, never both. Both paths hold the same per-booking lock.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_Sync {
	const META = '_elab_google_sync';
	const META_ERROR = '_elab_google_sync_error';
	const META_MANUAL = '_elab_google_manual';
	const META_BY_ADVISOR = '_elab_google_by_advisor';
	const CRON = 'elab_google_sync';
	const ACTION = 'elab_advisor_event';
	const CREATE = 'create';
	const DELETE = 'delete';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_advisor_event' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_advisor_event' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** What is pending for this booking in Google: '', 'create' or 'delete'. */
	public static function state( $booking_id ) {
		return (string) get_post_meta( $booking_id, self::META, true );
	}

	/** Extra people invited by the visitor. */
	public static function guests( $booking_id ) {
		$guests = get_post_meta( $booking_id, '_elab_guests', true );
		return is_array( $guests ) ? array_values( array_filter( $guests, 'is_email' ) ) : array();
	}

	/** When the advisor added this call to their calendar by hand (0 if they did not). */
	public static function by_advisor( $booking_id ) {
		return (int) get_post_meta( $booking_id, self::META_BY_ADVISOR, true );
	}

	/** Short admin label for what Google still owes this booking, or '' when in sync. */
	public static function label( $booking_id ) {
		if ( self::CREATE === self::state( $booking_id ) ) {
			return __( 'Waiting for Google', 'elaltillo-bookacall' );
		}
		if ( self::DELETE === self::state( $booking_id ) ) {
			return __( 'Removing from Google', 'elaltillo-bookacall' );
		}
		if ( get_post_meta( $booking_id, self::META_MANUAL, true ) ) {
			return __( 'Remove from Google by hand', 'elaltillo-bookacall' );
		}
		return self::by_advisor( $booking_id ) ? __( 'Added by the advisor', 'elaltillo-bookacall' ) : '';
	}

	public static function queue_create( $booking_id, WP_Error $error ) {
		update_post_meta( $booking_id, self::META, self::CREATE );
		update_post_meta( $booking_id, self::META_ERROR, $error->get_error_code() );
	}

	private static function lock( $booking_id ) {
		$name = 'elab_sync_lock_' . (int) $booking_id;
		$held = (int) get_option( $name );
		if ( $held && $held < time() - MINUTE_IN_SECONDS ) {
			delete_option( $name );
		}
		return add_option( $name, time(), '', 'no' ) ? $name : false;
	}

	/**
	 * The only way the site creates a booking's Google event. Never creates a second one:
	 * not when an event already exists, not when the advisor added it by hand, not while
	 * another request is doing it.
	 *
	 * @return array|WP_Error The event, array( 'skipped' => reason ), or the Google error.
	 */
	public static function create_for( $booking_id ) {
		if ( get_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT, true ) ) {
			return array( 'skipped' => 'exists' );
		}
		if ( self::by_advisor( $booking_id ) ) {
			return array( 'skipped' => 'advisor' );
		}
		$lock = self::lock( $booking_id );
		if ( ! $lock ) {
			return new WP_Error( 'google_busy', __( 'This call is being added to Google right now.', 'elaltillo-bookacall' ), array( 'status' => 409 ) );
		}
		try {
			if ( self::by_advisor( $booking_id ) || get_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT, true ) ) {
				return array( 'skipped' => 'race' );
			}
			$advisor_id = (int) get_post_meta( $booking_id, '_elab_advisor', true );
			$start = new DateTimeImmutable( (string) get_post_meta( $booking_id, '_elab_start_utc', true ), new DateTimeZone( 'UTC' ) );
			// Event title and description go to the visitor: write them in the visitor's language.
			$event = ELAB_Notifications::in_locale( (string) get_post_meta( $booking_id, '_elab_locale', true ), static function () use ( $advisor_id, $booking_id, $start ) {
				return ELAB_Google_Calendar::create_event( $advisor_id, $booking_id, $start, ELAB_Booking_Admin::duration( $booking_id ), (string) get_post_meta( $booking_id, '_elab_contact_email', true ) );
			} );
			if ( ! is_wp_error( $event ) && self::guests( $booking_id ) ) {
				update_post_meta( $booking_id, ELAB_Notifications::META_GUESTS_TOLD, 1 ); // Google invited them.
			}
			return $event;
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Remove the booking's Google event, or queue the removal.
	 *
	 * @return string 'deleted' (Google notified the guests), 'queued' (retried later),
	 *                'manual' (an event the site cannot reach, to remove by hand) or 'none'.
	 */
	public static function remove_event( $booking_id ) {
		$advisor_id = (int) get_post_meta( $booking_id, '_elab_advisor', true );
		$event_id = (string) get_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT, true );
		if ( self::CREATE === self::state( $booking_id ) ) {
			// Never reached Google: nothing to remove.
			self::clear( $booking_id );
			return 'none';
		}
		if ( '' === $event_id ) {
			if ( self::by_advisor( $booking_id ) ) {
				// The advisor made this event in their own calendar; the site cannot see it.
				update_post_meta( $booking_id, self::META_MANUAL, '1' );
				return 'manual';
			}
			return 'none';
		}
		$result = ELAB_Google_Calendar::delete_event( $advisor_id, $event_id, $booking_id );
		if ( ! is_wp_error( $result ) ) {
			delete_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT );
			self::clear( $booking_id );
			return 'deleted';
		}
		if ( 'google_event_legacy' === $result->get_error_code() ) {
			delete_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT );
			update_post_meta( $booking_id, self::META_MANUAL, '1' );
			self::clear( $booking_id );
			return 'manual';
		}
		if ( 'google_event_not_ours' === $result->get_error_code() ) {
			// Never touch something that is not ours; just forget the reference.
			delete_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT );
			self::clear( $booking_id );
			return 'none';
		}
		update_post_meta( $booking_id, self::META, self::DELETE );
		update_post_meta( $booking_id, self::META_ERROR, $result->get_error_code() );
		return 'queued';
	}

	private static function clear( $booking_id ) {
		delete_post_meta( $booking_id, self::META );
		delete_post_meta( $booking_id, self::META_ERROR );
	}

	/** Bookings waiting for Google, optionally for one advisor. */
	public static function pending( $advisor_id = 0 ) {
		$meta = array( array( 'key' => self::META, 'value' => array( self::CREATE, self::DELETE ), 'compare' => 'IN' ) );
		if ( $advisor_id ) {
			$meta[] = array( 'key' => '_elab_advisor', 'value' => (int) $advisor_id );
		}
		return get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'any', 'numberposts' => 200, 'fields' => 'ids', 'meta_query' => $meta ) );
	}

	/**
	 * Upcoming calls whose event the advisor deleted in Google Calendar are cancelled here
	 * too: the time is freed and the visitor and advisor are emailed.
	 *
	 * @return int Calls cancelled.
	 */
	public static function reconcile( $advisor_id = 0 ) {
		$meta = array(
			array( 'key' => '_elab_status', 'value' => 'confirmed' ),
			array( 'key' => ELAB_Google_Calendar::META_EVENT, 'compare' => 'EXISTS' ),
			array( 'key' => '_elab_start_utc', 'value' => gmdate( 'Y-m-d H:i:s' ), 'compare' => '>', 'type' => 'DATETIME' ),
		);
		if ( $advisor_id ) {
			$meta[] = array( 'key' => '_elab_advisor', 'value' => (int) $advisor_id );
		}
		$cancelled = 0;
		foreach ( get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'any', 'numberposts' => 200, 'fields' => 'ids', 'meta_query' => $meta ) ) as $booking_id ) {
			if ( ! ELAB_Google_Calendar::connected( (int) get_post_meta( $booking_id, '_elab_advisor', true ) ) || true !== ELAB_Google_Calendar::event_deleted( $booking_id ) ) {
				continue;
			}
			$lock = self::lock( $booking_id );
			if ( ! $lock ) {
				continue;
			}
			try {
				if ( 'confirmed' === get_post_meta( $booking_id, '_elab_status', true ) && get_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT, true ) ) {
					ELAB_Booking_Manage::cancelled_in_google( $booking_id );
					$cancelled++;
				}
			} finally {
				delete_option( $lock );
			}
		}
		return $cancelled;
	}

	/**
	 * Retry everything queued (for one advisor after they reconnect, or all from cron), then
	 * cancel the calls the advisor deleted in Google.
	 *
	 * @return array{created:int,deleted:int,waiting:int,cancelled:int}
	 */
	public static function run( $advisor_id = 0 ) {
		$done = array( 'created' => 0, 'deleted' => 0, 'waiting' => 0, 'cancelled' => 0 );
		foreach ( self::pending( (int) $advisor_id ) as $booking_id ) {
			$owner = (int) get_post_meta( $booking_id, '_elab_advisor', true );
			if ( ! ELAB_Google_Calendar::connected( $owner ) ) {
				$done['waiting']++;
				continue;
			}
			if ( self::DELETE === self::state( $booking_id ) ) {
				$done[ 'deleted' === self::remove_event( $booking_id ) ? 'deleted' : 'waiting' ]++;
				continue;
			}
			$status = (string) get_post_meta( $booking_id, '_elab_status', true );
			$start = strtotime( get_post_meta( $booking_id, '_elab_start_utc', true ) . ' UTC' );
			if ( 'cancelled' === $status || ! $start || $start <= time() || self::by_advisor( $booking_id ) ) {
				self::clear( $booking_id );
				continue;
			}
			$event = self::create_for( $booking_id );
			if ( is_wp_error( $event ) ) {
				update_post_meta( $booking_id, self::META_ERROR, $event->get_error_code() );
				$done['waiting']++;
				continue;
			}
			self::clear( $booking_id );
			if ( ! empty( $event['skipped'] ) ) {
				continue;
			}
			update_post_meta( $booking_id, '_elab_status', 'confirmed' );
			ELAB_Notifications::booking_notice( $booking_id, 'synced' );
			if ( 'pending' === $status ) {
				ELAB_Notifications::visitor_notice( $booking_id, 'confirmed' );
			}
			$done['created']++;
		}
		$done['cancelled'] = self::reconcile( (int) $advisor_id );
		return $done;
	}

	/* ---------- The advisor adds a waiting call to their calendar by hand ---------- */

	private static function signature( $booking_id ) {
		$advisor_id = (int) get_post_meta( $booking_id, '_elab_advisor', true );
		return substr( hash_hmac( 'sha256', 'elab-advisor-event|' . (int) $booking_id . '|' . $advisor_id, wp_salt( 'auth' ) ), 0, 40 );
	}

	public static function valid_link( $booking_id, $sig ) {
		return $booking_id && is_string( $sig ) && ELAB_Post_Types::BOOKING === get_post_type( $booking_id ) && hash_equals( self::signature( $booking_id ), $sig );
	}

	/** Private link for the advisor's email: opens a confirmation page, never acts on its own. */
	public static function advisor_url( $booking_id ) {
		return add_query_arg( array( 'action' => self::ACTION, 'booking' => (int) $booking_id, 'sig' => self::signature( $booking_id ) ), admin_url( 'admin-post.php' ) );
	}

	/** Google Calendar "new event" page, filled in, with the visitor and guests invited. */
	public static function google_template_url( $booking_id ) {
		$advisor_id = (int) get_post_meta( $booking_id, '_elab_advisor', true );
		$start = new DateTimeImmutable( (string) get_post_meta( $booking_id, '_elab_start_utc', true ), new DateTimeZone( 'UTC' ) );
		$end = $start->modify( '+' . ELAB_Booking_Admin::duration( $booking_id ) . ' minutes' );
		$name = (string) get_post_meta( $booking_id, '_elab_name', true );
		$details = array(
			sprintf( __( 'Call booked through %s.', 'elaltillo-bookacall' ), get_bloginfo( 'name' ) ),
			sprintf( __( 'Meeting language: %s', 'elaltillo-bookacall' ), ELAB_Booking_Service::language_label( get_post_meta( $booking_id, '_elab_language', true ) ?: ELAB_Booking_Service::DEFAULT_LANGUAGE ) ),
		);
		$args = array(
			'action'  => 'TEMPLATE',
			'text'    => sprintf( __( '%1$s · %2$s and %3$s', 'elaltillo-bookacall' ), get_bloginfo( 'name' ), $name ?: __( 'Visitor', 'elaltillo-bookacall' ), get_the_title( $advisor_id ) ),
			'dates'   => $start->format( 'Ymd\THis\Z' ) . '/' . $end->format( 'Ymd\THis\Z' ),
			'details' => implode( "\n", $details ),
			'add'     => implode( ',', array_merge( array( (string) get_post_meta( $booking_id, '_elab_contact_email', true ) ), self::guests( $booking_id ) ) ),
		);
		return 'https://calendar.google.com/calendar/render?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * GET shows what will happen; POST takes the call off the queue and opens Google.
	 * The two-step page keeps email link scanners from acting for the advisor.
	 */
	public static function handle_advisor_event() {
		// phpcs:disable WordPress.Security.NonceVerification -- The HMAC signature authenticates this link.
		$booking_id = isset( $_REQUEST['booking'] ) ? absint( $_REQUEST['booking'] ) : 0;
		$sig = isset( $_REQUEST['sig'] ) ? sanitize_key( wp_unslash( $_REQUEST['sig'] ) ) : '';
		// phpcs:enable
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		if ( ! self::valid_link( $booking_id, $sig ) ) {
			self::page( 403, __( 'This link is not valid', 'elaltillo-bookacall' ), __( 'Open the button from the most recent email about this call.', 'elaltillo-bookacall' ) );
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( 'claimed' === self::claim( $booking_id ) ) {
				wp_redirect( self::google_template_url( $booking_id ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Fixed Google Calendar host.
				exit;
			}
			wp_safe_redirect( self::advisor_url( $booking_id ) );
			exit;
		}
		// The advisor reads this page in the language of their emails.
		$locale = ELAB_Notifications::advisor_locale( (int) get_post_meta( $booking_id, '_elab_advisor', true ) );
		$html = ELAB_Notifications::in_locale( $locale, static function () use ( $booking_id ) {
			$view = self::advisor_page( $booking_id );
			return ELAB_Mailer::page( array( 'title' => $view[0], 'intro' => $view[1], 'rows' => $view[2], 'button' => $view[3], 'form' => $view[4] ) );
		} );
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ELAB_Mailer.
		exit;
	}

	/**
	 * Hand the event over to the advisor, unless the site already created it.
	 *
	 * @return string 'claimed', 'exists', 'closed' or 'busy'.
	 */
	public static function claim( $booking_id ) {
		$status = (string) get_post_meta( $booking_id, '_elab_status', true );
		$start = strtotime( get_post_meta( $booking_id, '_elab_start_utc', true ) . ' UTC' );
		if ( 'cancelled' === $status || ! $start || $start <= time() ) {
			return 'closed';
		}
		$lock = self::lock( $booking_id );
		if ( ! $lock ) {
			return 'busy';
		}
		try {
			if ( get_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT, true ) ) {
				return 'exists';
			}
			if ( ! self::by_advisor( $booking_id ) ) {
				update_post_meta( $booking_id, self::META_BY_ADVISOR, time() );
			}
			self::clear( $booking_id );
			return 'claimed';
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * What the advisor's page says for this booking right now.
	 *
	 * @return array [title, intro, rows, button|null, form|null]
	 */
	public static function advisor_page( $booking_id ) {
		$advisor_id = (int) get_post_meta( $booking_id, '_elab_advisor', true );
		$rows = array(
			__( 'When', 'elaltillo-bookacall' )    => ELAB_Notifications::when( $booking_id, (string) get_post_meta( $advisor_id, '_elab_timezone', true ) ),
			__( 'Visitor', 'elaltillo-bookacall' ) => (string) get_post_meta( $booking_id, '_elab_name', true ),
			__( 'Guests', 'elaltillo-bookacall' )  => implode( ', ', self::guests( $booking_id ) ),
		);
		$status = (string) get_post_meta( $booking_id, '_elab_status', true );
		$start = strtotime( get_post_meta( $booking_id, '_elab_start_utc', true ) . ' UTC' );
		if ( 'cancelled' === $status || ! $start || $start <= time() ) {
			return array( __( 'Nothing to add', 'elaltillo-bookacall' ), __( 'This call was cancelled or has already taken place.', 'elaltillo-bookacall' ), $rows, null, null );
		}
		if ( get_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT, true ) ) {
			return array( __( 'Already in your calendar', 'elaltillo-bookacall' ), __( 'Google answered again and the site added this call to your “El Altillo · Citas” calendar. There is nothing left to do: do not add it again.', 'elaltillo-bookacall' ), $rows, null, null );
		}
		if ( self::by_advisor( $booking_id ) ) {
			return array( __( 'You already took care of this call', 'elaltillo-bookacall' ), sprintf( __( 'You chose to add it to your calendar yourself on %s, so the site will not add it. Only open Google Calendar again if you did not save the event.', 'elaltillo-bookacall' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), self::by_advisor( $booking_id ) ) ), $rows, array( __( 'Open Google Calendar again', 'elaltillo-bookacall' ), self::google_template_url( $booking_id ) ), null );
		}
		return array( __( 'Add this call to your calendar', 'elaltillo-bookacall' ), __( 'Google Calendar opens with everything filled in and the visitor invited: select Save and send the invitations. From that moment the site stops trying to add this call, so it appears only once.', 'elaltillo-bookacall' ), $rows, null, array( __( 'Open Google Calendar', 'elaltillo-bookacall' ), self::advisor_url( $booking_id ) ) );
	}

	private static function page( $code, $title, $intro, $rows = array(), $button = null, $form = null ) {
		status_header( $code );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo ELAB_Mailer::page( array( 'title' => $title, 'intro' => $intro, 'rows' => $rows, 'button' => $button, 'form' => $form ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ELAB_Mailer.
		exit;
	}
}
