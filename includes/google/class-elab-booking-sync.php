<?php
/**
 * Keeps WordPress the source of truth when Google is unreachable.
 *
 * A booking is always saved here first. If its Google event cannot be created (the advisor
 * is disconnected, access expired, or Google failed), the booking is queued and retried every
 * hour and right after the advisor reconnects. Cancellations work the same way: the call is
 * cancelled here at once and its Google event is removed as soon as Google answers.
 *
 * The only way to bring a waiting call into Google is to reconnect: the site then creates
 * every waiting event itself, so it stays in sync (cancel here or delete in Google). Calls an
 * advisor added by hand under an earlier version are still respected and never duplicated.
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
	const OLD_ACTION = 'elab_advisor_event';
	const CREATE = 'create';
	const DELETE = 'delete';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( 'admin_post_nopriv_' . self::OLD_ACTION, array( __CLASS__, 'handle_old_link' ) );
		add_action( 'admin_post_' . self::OLD_ACTION, array( __CLASS__, 'handle_old_link' ) );
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

	/** When the advisor added this call by hand under an earlier version (0 if they did not). */
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

	/* ---------- Links from older emails ---------- */

	/**
	 * Emails sent before the manual step was removed carry a link here. It changes nothing
	 * and explains that the call is added when the advisor reconnects.
	 */
	public static function handle_old_link() {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		self::page( 410, ...self::old_link_message() );
	}

	/** @return string[] [title, text] of the page old manual links open. */
	public static function old_link_message() {
		return array( __( 'This option is no longer available', 'elaltillo-bookacall' ), __( 'Calls waiting for Google are added to your calendar automatically as soon as you reconnect Google Calendar. Open the most recent email about this call to reconnect.', 'elaltillo-bookacall' ) );
	}

	private static function page( $code, $title, $intro, $rows = array(), $button = null, $form = null ) {
		status_header( $code );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo ELAB_Mailer::page( array( 'title' => $title, 'intro' => $intro, 'rows' => $rows, 'button' => $button, 'form' => $form ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ELAB_Mailer.
		exit;
	}
}
