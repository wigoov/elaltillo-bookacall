<?php
/** Shortcode renderer for the public booking calendar. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_Form {
	const SCRIPT_HANDLE = 'elab-booking';

	public function __construct() {
		add_shortcode( 'elaltillo_bookacall', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_page_style' ) );
	}

	/** Release version plus file time, so rebuilt assets are never served from a stale cache. */
	private static function asset_version( $relative ) {
		$mtime = @filemtime( ELAB_DIR . $relative );
		return ELAB_VERSION . ( $mtime ? '.' . $mtime : '' );
	}

	public function enqueue_page_style() {
		$post = get_queried_object();
		if ( is_page( 'appointment' ) || ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'elaltillo_bookacall' ) ) ) {
			wp_enqueue_style( 'elab-booking', plugins_url( 'assets/css/bookacall.min.css', ELAB_FILE ), array(), self::asset_version( 'assets/css/bookacall.min.css' ) );
		}
	}

	public function render() {
		$has_advisors = (bool) ELAB_Availability::bookable_advisors();
		$confirm_mode = ELAB_Google_Calendar::configured();
		wp_enqueue_style( 'elab-booking', plugins_url( 'assets/css/bookacall.min.css', ELAB_FILE ), array(), self::asset_version( 'assets/css/bookacall.min.css' ) );
		if ( $has_advisors && ! wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			wp_enqueue_script( self::SCRIPT_HANDLE, plugins_url( 'assets/js/booking.min.js', ELAB_FILE ), array(), self::asset_version( 'assets/js/booking.min.js' ), true );
			wp_add_inline_script( self::SCRIPT_HANDLE, 'window.elabBooking = ' . wp_json_encode( array(
				'api'         => esc_url_raw( rest_url( 'elab/v1' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'confirmMode' => $confirm_mode,
				'i18n'        => self::strings( $confirm_mode ),
			) ) . ';', 'before' );
		}
		ob_start();
		require ELAB_DIR . 'includes/frontend/templates/booking-form.php';
		return ob_get_clean();
	}

	/** Strings used by the calendar script. %s/%d placeholders are filled in JavaScript. */
	private static function strings( $confirm_mode ) {
		return array(
			'prevMonth'      => __( 'Previous month', 'elaltillo-bookacall' ),
			'nextMonth'      => __( 'Next month', 'elaltillo-bookacall' ),
			'dayOpen'        => __( '%s, times available', 'elaltillo-bookacall' ),
			'dayClosed'      => __( '%s, no times available', 'elaltillo-bookacall' ),
			'loadingTimes'   => __( 'Loading times…', 'elaltillo-bookacall' ),
			'pickDay'        => __( 'Pick a highlighted day to see its times.', 'elaltillo-bookacall' ),
			'noTimes'        => __( 'No times left on this day. Try another one.', 'elaltillo-bookacall' ),
			'noMonth'        => __( 'No openings this month.', 'elaltillo-bookacall' ),
			'noneAtAll'      => __( 'There are no openings right now. Please check back soon.', 'elaltillo-bookacall' ),
			'loadError'      => __( 'Availability could not be loaded. Please try again.', 'elaltillo-bookacall' ),
			'retry'          => __( 'Try again', 'elaltillo-bookacall' ),
			'timezone'       => __( 'Times in %s', 'elaltillo-bookacall' ),
			'morning'        => __( 'Morning', 'elaltillo-bookacall' ),
			'afternoon'      => __( 'Afternoon', 'elaltillo-bookacall' ),
			'evening'        => __( 'Evening', 'elaltillo-bookacall' ),
			'tzNoMatch'      => __( 'No time zones match your search.', 'elaltillo-bookacall' ),
			'tzUseLocal'     => __( 'Use my location · %s', 'elaltillo-bookacall' ),
			'changeTimezone' => __( 'Change', 'elaltillo-bookacall' ),
			'timesCount'     => __( '%d times', 'elaltillo-bookacall' ),
			'minutes'        => __( '%d min', 'elaltillo-bookacall' ),
			'callWith'       => __( '%1$d-minute call with %2$s', 'elaltillo-bookacall' ),
			'callAny'        => __( '%d-minute call with the first available advisor', 'elaltillo-bookacall' ),
			'prefer'         => __( 'Prefer someone?', 'elaltillo-bookacall' ),
			'anyone'         => __( 'First available', 'elaltillo-bookacall' ),
			'sending'        => $confirm_mode ? __( 'Booking your call…', 'elaltillo-bookacall' ) : __( 'Sending your request…', 'elaltillo-bookacall' ),
			'genericError'   => __( 'Something went wrong. Please try again.', 'elaltillo-bookacall' ),
			'taken'          => __( 'That time was just taken. Please choose another.', 'elaltillo-bookacall' ),
			'doneTitle'      => $confirm_mode ? __( 'Your call is booked', 'elaltillo-bookacall' ) : __( 'Request received', 'elaltillo-bookacall' ),
			'withAdvisor'    => __( 'with %s', 'elaltillo-bookacall' ),
			'addGoogle'      => __( 'Open in Google Calendar', 'elaltillo-bookacall' ),
			'addIcs'         => __( 'Apple, Outlook or other calendar', 'elaltillo-bookacall' ),
			'filesChosen'    => __( '%d files selected', 'elaltillo-bookacall' ),
		);
	}
}
