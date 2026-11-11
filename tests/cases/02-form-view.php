<?php
/** Public views: the booking form (shortcode) and the private "manage my call" page. */

use ELAB_Test as T;

$render_form = static function () {
	foreach ( array( 'elab-turnstile', ELAB_Booking_Form::SCRIPT_HANDLE ) as $handle ) {
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}
	return do_shortcode( '[elaltillo_bookacall]' );
};
$config = static function () {
	$inline = wp_scripts()->get_data( ELAB_Booking_Form::SCRIPT_HANDLE, 'before' );
	$code = is_array( $inline ) ? implode( "\n", $inline ) : (string) $inline;
	T::check( (bool) preg_match( '/window\.elabBooking = (\{.*\});/s', $code, $match ), 'Calendar configuration is printed' );
	return json_decode( $match[1], true );
};

return array(
	'The form has the three steps, the time picker and every field' => function () use ( $render_form ) {
		T::advisor();
		$html = $render_form();
		foreach ( array( 'data-elab-step="time"', 'data-elab-step="details"', 'data-elab-step="done"', 'data-elab-days', 'data-elab-times', 'data-elab-tz-toggle', 'name="name"', 'name="email"', 'name="language"', 'name="sector"', 'name="message"', 'name="attachments[]"', 'name="consent"', 'name="website"' ) as $piece ) {
			T::contains( $piece, $html, 'Form contains ' . $piece );
		}
	},

	'Guests field appears only while the bot check is on' => function () use ( $render_form ) {
		T::advisor();
		T::lacks( 'data-elab-guests', $render_form(), 'Hidden with Turnstile off' );
		T::turnstile_on();
		$html = $render_form();
		foreach ( array( 'data-elab-guests', 'data-elab-guest-input', 'data-elab-guest-add', 'data-elab-guest-list', 'name="guests"', 'Up to 3 people' ) as $piece ) {
			T::contains( $piece, $html, 'Guests field contains ' . $piece );
		}
	},

	'Spanish is the default meeting language' => function () use ( $render_form ) {
		T::advisor();
		T::check( (bool) preg_match( '/value="es"[^>]*checked/', $render_form() ), 'The Español option is checked' );
	},

	'The script receives the API address, the page language and every message' => function () use ( $render_form, $config ) {
		T::advisor();
		$render_form();
		$data = $config();
		T::contains( '/elab/v1', $data['api'], 'API address' );
		T::same( determine_locale(), $data['locale'], 'Page language is sent with the booking' );
		foreach ( array( 'guestAdded', 'guestRemove', 'guest_invalid', 'guest_self', 'guest_duplicate', 'guest_full', 'invitedTo', 'taken' ) as $key ) {
			T::check( ! empty( $data['i18n'][ $key ] ), 'Message ' . $key . ' is available' );
		}
		T::check( empty( $data['nonce'] ), 'No nonce in cacheable HTML' );
	},

	'Without Google the form asks for a request; with Google it confirms' => function () use ( $render_form ) {
		T::advisor();
		T::contains( 'Request call', $render_form(), 'Request mode button' );
		$advisor = T::connect( T::advisor() );
		T::contains( 'Confirm call', $render_form(), 'Confirm mode button' );
		T::contains( 'calendar invitation', $render_form(), 'Confirm mode explains the invitation' );
	},

	'With nobody bookable the page says so and loads no script' => function () use ( $render_form ) {
		$html = $render_form();
		T::contains( 'There are no openings right now', $html, 'Message shown' );
		T::check( ! wp_script_is( ELAB_Booking_Form::SCRIPT_HANDLE, 'enqueued' ), 'Script not loaded' );
	},

	'The consent checkbox links the privacy policy when there is one' => function () use ( $render_form ) {
		T::advisor();
		$policy = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $policy && 'publish' === get_post_status( $policy ) ) {
			T::contains( get_permalink( $policy ), $render_form(), 'Policy link' );
		} else {
			T::contains( 'I agree that my details are used to arrange this call.', $render_form(), 'Plain consent text' );
		}
	},

	'Manage page: a wrong or missing token shows “not valid” and no booking data' => function () use ( $render_form ) {
		$booking = T::booking( T::advisor() );
		ELAB_Booking_Manage::issue_token( $booking );
		$_GET['elab_booking'] = (string) $booking;
		$_COOKIE[ ELAB_Booking_Manage::COOKIE . $booking ] = 'wrong';
		$html = $render_form();
		T::contains( 'This link is not valid', $html, 'Invalid link message' );
		T::lacks( 'Test visitor', $html, 'No visitor data leaks' );
	},

	'Manage page: the right cookie shows the call and the cancel form' => function () use ( $render_form ) {
		$booking = T::booking( T::advisor() );
		$_GET['elab_booking'] = (string) $booking;
		$_COOKIE[ ELAB_Booking_Manage::COOKIE . $booking ] = ELAB_Booking_Manage::issue_token( $booking );
		$html = $render_form();
		T::contains( 'Cancel call', $html, 'Cancel button' );
		T::contains( ELAB_Booking_Manage::ACTION, $html, 'Cancel form action' );
	},

	'Manage page: a cancelled call shows its state and no cancel button' => function () use ( $render_form ) {
		$booking = T::booking( T::advisor(), array( '_elab_status' => 'cancelled' ) );
		$_GET['elab_booking'] = (string) $booking;
		$_COOKIE[ ELAB_Booking_Manage::COOKIE . $booking ] = ELAB_Booking_Manage::issue_token( $booking );
		$html = $render_form();
		T::contains( 'Cancelled', $html, 'Cancelled state' );
		T::lacks( 'name="booking"', $html, 'No cancel form' );
	},

	'Manage page: a past call cannot be cancelled online' => function () use ( $render_form ) {
		$booking = T::booking( T::advisor(), array( '_elab_start_utc' => gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) ) ) );
		$_GET['elab_booking'] = (string) $booking;
		$_COOKIE[ ELAB_Booking_Manage::COOKIE . $booking ] = ELAB_Booking_Manage::issue_token( $booking );
		T::lacks( 'name="booking"', $render_form(), 'No cancel form' );
	},

	'The private link never keeps the secret in the address' => function () {
		$booking = T::booking( T::advisor() );
		$url = ELAB_Booking_Manage::url( $booking );
		T::contains( 'elab_token=', $url, 'Emailed link carries the token once' );
		T::lacks( 'elab_token', ELAB_Booking_Manage::public_url( $booking ), 'Page link has no token' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		T::check( ELAB_Booking_Manage::verify( $booking, $query['elab_token'] ), 'Token verifies for its booking' );
		T::check( ! ELAB_Booking_Manage::verify( $booking + 1, $query['elab_token'] ), 'Token does not open another booking' );
	},

	'The meeting language is chosen in step 1, before the times' => function () use ( $render_form ) {
		T::advisor();
		$html = $render_form();
		$time_step = substr( $html, strpos( $html, 'data-elab-step="time"' ), strpos( $html, 'data-elab-step="details"' ) - strpos( $html, 'data-elab-step="time"' ) );
		T::contains( 'data-elab-languages', $time_step, 'Language chooser in the time step' );
		T::contains( 'name="language" data-elab-language-value value="es"', $html, 'Form carries the chosen language' );
		T::lacks( 'type="radio" name="language"', $html, 'No second language question in step 2' );
	},

	'With a single offered language there is nothing to choose' => function () use ( $render_form ) {
		T::advisor( array( ELAB_Availability::META_LANGUAGES => array( 'en' ) ) );
		$html = $render_form();
		T::lacks( 'data-elab-languages', $html, 'No chooser' );
		T::contains( 'data-elab-language-value value="en"', $html, 'English preset' );
	},
);
