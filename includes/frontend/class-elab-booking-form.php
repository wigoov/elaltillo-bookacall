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
		$requested = ELAB_Booking_Manage::requested_booking();
		if ( $requested ) {
			return $this->render_manage( $requested[0], $requested[1] );
		}
		$has_advisors = (bool) ELAB_Availability::bookable_advisors();
		$confirm_mode = ELAB_Google_Calendar::configured();
		wp_enqueue_style( 'elab-booking', plugins_url( 'assets/css/bookacall.min.css', ELAB_FILE ), array(), self::asset_version( 'assets/css/bookacall.min.css' ) );
		if ( $has_advisors && ! wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			wp_enqueue_script( self::SCRIPT_HANDLE, plugins_url( 'assets/js/booking.min.js', ELAB_FILE ), array(), self::asset_version( 'assets/js/booking.min.js' ), true );
			$turnstile = ELAB_Security::turnstile_site_key();
			if ( $turnstile ) {
				// Loaded after our script so its onload callback finds the queue it renders.
				wp_enqueue_script( 'elab-turnstile', ELAB_Security::SCRIPT_URL . '&onload=elabTurnstileReady', array( self::SCRIPT_HANDLE ), null, array( 'in_footer' => true, 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare requires the unversioned URL.
			}
			// No REST nonce: the endpoints are public, and a nonce in cached HTML would expire
			// and break the calendar for logged-in visitors behind LiteSpeed or any page cache.
			wp_add_inline_script( self::SCRIPT_HANDLE, 'window.elabBooking = ' . wp_json_encode( array(
				'api'         => esc_url_raw( rest_url( 'elab/v1' ) ),
				'page'        => (int) get_queried_object_id(),
				'turnstile'   => $turnstile,
				'confirmMode' => $confirm_mode,
				'locale'      => determine_locale(),
				// PDF.js draws a thumbnail of a chosen PDF; it loads only when one is picked.
				'pdfjs'       => plugins_url( 'assets/js/vendor/pdfjs/pdf.min.js', ELAB_FILE ) . '?ver=' . rawurlencode( ELAB_VERSION ),
				'pdfWorker'   => plugins_url( 'assets/js/vendor/pdfjs/pdf.worker.min.js', ELAB_FILE ) . '?ver=' . rawurlencode( ELAB_VERSION ),
				'i18n'        => self::strings( $confirm_mode ),
			) ) . ';', 'before' );
		}
		ob_start();
		require ELAB_DIR . 'includes/frontend/templates/booking-form.php';
		return ob_get_clean();
	}

	private function render_manage( $booking_id, $token ) {
		wp_enqueue_style( 'elab-booking', plugins_url( 'assets/css/bookacall.min.css', ELAB_FILE ), array(), self::asset_version( 'assets/css/bookacall.min.css' ) );
		$booking_id = ELAB_Booking_Manage::verify( $booking_id, $token ) ? (int) $booking_id : 0;
		$result = isset( $_GET['elab_result'] ) ? sanitize_key( wp_unslash( $_GET['elab_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		ob_start();
		require ELAB_DIR . 'includes/frontend/templates/manage-booking.php';
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
			/* translators: shortest and longest call length, for "first available" when advisors differ. */
			'minutesRange'   => __( '%1$d–%2$d min', 'elaltillo-bookacall' ),
			'callWith'       => __( '%1$d-minute call with %2$s', 'elaltillo-bookacall' ),
			'callAny'        => __( '%d-minute call with the first available advisor', 'elaltillo-bookacall' ),
			/* translators: shortest and longest call length among the advisors free at that time. */
			'callAnyRange'   => __( '%1$d to %2$d-minute call with the first available advisor', 'elaltillo-bookacall' ),
			'prefer'         => __( 'Prefer someone?', 'elaltillo-bookacall' ),
			'anyone'         => __( 'First available', 'elaltillo-bookacall' ),
			'sending'        => $confirm_mode ? __( 'Booking your call…', 'elaltillo-bookacall' ) : __( 'Sending your request…', 'elaltillo-bookacall' ),
			'genericError'   => __( 'Something went wrong. Please try again.', 'elaltillo-bookacall' ),
			'taken'          => __( 'That time was just taken. Please choose another.', 'elaltillo-bookacall' ),
			'doneTitle'      => $confirm_mode ? __( 'Your call is booked', 'elaltillo-bookacall' ) : __( 'Request received', 'elaltillo-bookacall' ),
			'withAdvisor'    => __( 'with %s', 'elaltillo-bookacall' ),
			'botCheck'       => __( 'Please complete the security check.', 'elaltillo-bookacall' ),
			'invitedTo'      => __( 'We sent a Google Calendar invitation to %s. Accept it to keep the call in your calendar.', 'elaltillo-bookacall' ),
			'joinMeet'       => __( 'Join with Google Meet', 'elaltillo-bookacall' ),
			'filesChosen'    => __( '%d files selected', 'elaltillo-bookacall' ),
			'filesAddMore'   => __( 'Add another file', 'elaltillo-bookacall' ),
			'filesFull'      => __( 'You attached the maximum of %d files.', 'elaltillo-bookacall' ),
			'fileReady'      => __( 'Ready: it will be sent with your booking', 'elaltillo-bookacall' ),
			'fileOnePage'    => __( '1 page', 'elaltillo-bookacall' ),
			'filePages'      => __( '%d pages', 'elaltillo-bookacall' ),
			'fileRemove'     => __( 'Remove %s', 'elaltillo-bookacall' ),
			'filePreviewFailed' => __( 'We could not open this PDF. If it has a password, remove it so the advisor can read it.', 'elaltillo-bookacall' ),
			'file_type'      => __( '%s is not a PDF, Word, Excel or PowerPoint file.', 'elaltillo-bookacall' ),
			'file_size'      => __( '%s is larger than 10 MB.', 'elaltillo-bookacall' ),
			'file_duplicate' => __( '%s is already attached.', 'elaltillo-bookacall' ),
			'file_count'     => __( 'You can attach up to %2$d files; %1$s was not added.', 'elaltillo-bookacall' ),
			'guestAdded'     => __( '%s added.', 'elaltillo-bookacall' ),
			'guestRemove'    => __( 'Remove %s', 'elaltillo-bookacall' ),
			'guest_invalid'  => __( 'Enter a valid email address.', 'elaltillo-bookacall' ),
			'guest_self'     => __( 'That is your own email; you are already invited.', 'elaltillo-bookacall' ),
			'guest_duplicate' => __( 'That guest is already on the list.', 'elaltillo-bookacall' ),
			'guest_full'     => __( 'You can invite up to %d guests.', 'elaltillo-bookacall' ),
			'guest_empty'    => __( 'Type an email address first.', 'elaltillo-bookacall' ),
		);
	}
}
