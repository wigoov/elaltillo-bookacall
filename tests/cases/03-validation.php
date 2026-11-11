<?php
/** Booking validation, one rule per test: ELAB_Booking_Service::validate(), parse_guests(), visitor_locale(). */

use ELAB_Test as T;

$request = static function ( array $fields = array() ) {
	$request = new WP_REST_Request( 'POST', '/elab/v1/bookings' );
	$request->set_body_params( $fields + array(
		'start'    => gmdate( 'Y-m-d\TH:00:00\Z', strtotime( '+3 days' ) ),
		'name'     => 'Ana',
		'email'    => 'ana@example.test',
		'timezone' => 'America/Bogota',
		'consent'  => '1',
	) );
	return $request;
};

return array(
	'A complete request returns clean data' => function () use ( $request ) {
		$data = ELAB_Booking_Service::validate( $request( array( 'guests' => 'b@example.test', 'locale' => 'es_CO', 'name' => ' Ana <b>Ruiz</b> ' ) ) );
		T::check( is_array( $data ), 'Valid request accepted' );
		T::same( 'Ana Ruiz', $data['name'], 'Name is stripped of tags' );
		T::same( array( 'b@example.test' ), $data['guests'], 'Guests parsed' );
		T::same( 'es_CO', $data['locale'], 'Spanish meeting → Spanish emails' );
		T::same( 'es', $data['language'], 'Meeting language defaults to Spanish' );
		T::check( $data['start_utc'] instanceof DateTimeImmutable, 'Start parsed' );
	},

	'Consent is required' => function () use ( $request ) {
		T::error( 'consent_required', ELAB_Booking_Service::validate( $request( array( 'consent' => '' ) ) ), 'No consent' );
	},

	'Unknown meeting languages are rejected' => function () use ( $request ) {
		T::error( 'invalid_booking', ELAB_Booking_Service::validate( $request( array( 'language' => 'xx' ) ) ), 'Language xx' );
	},

	'Name, email and start are required and well formed' => function () use ( $request ) {
		T::error( 'invalid_booking', ELAB_Booking_Service::validate( $request( array( 'name' => '' ) ) ), 'Empty name' );
		T::error( 'invalid_booking', ELAB_Booking_Service::validate( $request( array( 'email' => 'not-an-email' ) ) ), 'Bad email' );
		T::error( 'invalid_booking', ELAB_Booking_Service::validate( $request( array( 'start' => '2026-01-01 10:00' ) ) ), 'Start not in UTC ISO format' );
	},

	'Overlong fields are rejected' => function () use ( $request ) {
		T::error( 'invalid_booking', ELAB_Booking_Service::validate( $request( array( 'name' => str_repeat( 'a', 121 ) ) ) ), 'Name over 120' );
		T::error( 'invalid_booking', ELAB_Booking_Service::validate( $request( array( 'sector' => str_repeat( 'a', 121 ) ) ) ), 'Sector over 120' );
		T::error( 'invalid_booking', ELAB_Booking_Service::validate( $request( array( 'message' => str_repeat( 'a', 5001 ) ) ) ), 'Message over 5000' );
	},

	'Unknown time zones are rejected' => function () use ( $request ) {
		T::error( 'invalid_time', ELAB_Booking_Service::validate( $request( array( 'timezone' => 'Mars/Olympus' ) ) ), 'Mars' );
	},

	'Arrays where text is expected are rejected' => function () use ( $request ) {
		T::error( 'invalid_booking', ELAB_Booking_Service::validate( $request( array( 'name' => array( 'x' ) ) ) ), 'Array name' );
	},

	'Guests: comma, semicolon, space and line separated lists are accepted' => function () {
		T::same( array( 'a@example.test', 'b@example.test', 'c@example.test' ), ELAB_Booking_Service::parse_guests( "a@example.test; b@example.test,\nc@example.test" ), 'Three guests' );
		T::same( array(), ELAB_Booking_Service::parse_guests( '' ), 'No guests is fine' );
		T::same( array( 'a@example.test' ), ELAB_Booking_Service::parse_guests( array( 'a@example.test' ) ), 'An array is accepted' );
	},

	'Guests: duplicates and the visitor’s own address are dropped' => function () {
		T::same( array( 'a@example.test' ), ELAB_Booking_Service::parse_guests( 'a@example.test, A@EXAMPLE.TEST, me@example.test', 'Me@Example.test' ), 'Deduplicated, self removed' );
	},

	'Guests: an invalid address is rejected and named' => function () {
		$result = ELAB_Booking_Service::parse_guests( 'good@example.test, not-an-email' );
		T::error( 'invalid_guests', $result, 'Invalid guest' );
		T::contains( 'not-an-email', $result->get_error_message(), 'Message names the bad address' );
		T::error( 'invalid_guests', ELAB_Booking_Service::parse_guests( 'x<script>@example.test' ), 'Address with markup' );
	},

	'Guests: at most five' => function () {
		$six = implode( ',', array_map( static function ( $i ) { return "g{$i}@example.test"; }, range( 1, 6 ) ) );
		T::error( 'invalid_guests', ELAB_Booking_Service::parse_guests( $six ), 'Six guests refused' );
		T::same( 5, count( ELAB_Booking_Service::parse_guests( implode( ',', array_map( static function ( $i ) { return "g{$i}@example.test"; }, range( 1, 5 ) ) ) ) ), 'Five accepted' );
		T::error( 'invalid_guests', ELAB_Booking_Service::parse_guests( str_repeat( 'a', 1300 ) ), 'Huge input refused' );
	},

	'Guests: an invalid list stops the whole booking' => function () use ( $request ) {
		T::error( 'invalid_guests', ELAB_Booking_Service::validate( $request( array( 'guests' => 'nope' ) ) ), 'validate() refuses' );
	},

	'Visitor language: only installed languages, otherwise the site language' => function () {
		T::same( 'en_US', ELAB_Booking_Service::visitor_locale( 'en_US' ), 'English' );
		if ( in_array( 'es_CO', get_available_languages(), true ) ) {
			T::same( 'es_CO', ELAB_Booking_Service::visitor_locale( 'es_CO' ), 'Spanish (Colombia)' );
		}
		T::same( get_locale(), ELAB_Booking_Service::visitor_locale( 'xx_XX' ), 'Unknown falls back' );
		T::same( get_locale(), ELAB_Booking_Service::visitor_locale( array( 'es_CO' ) ), 'Non-string falls back' );
	},

	'Visitor emails follow the meeting language chosen in the form' => function () use ( $request ) {
		$english = ELAB_Booking_Service::validate( $request( array( 'language' => 'en', 'locale' => 'es_CO' ) ) );
		T::same( 'en_US', $english['locale'], 'English chosen on a Spanish page → English emails' );
		if ( in_array( 'es_CO', get_available_languages(), true ) ) {
			$spanish = ELAB_Booking_Service::validate( $request( array( 'language' => 'es', 'locale' => 'en_US' ) ) );
			T::same( 'es_CO', $spanish['locale'], 'Español chosen on an English page → Spanish emails' );
		}
		T::same( 'en_US', ELAB_Booking_Service::locale_for_language( 'fr', 'en_US' ), 'No French translation → page language' );
	},
);
