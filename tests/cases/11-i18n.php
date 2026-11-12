<?php
/** Spanish: every text a visitor, guest or advisor reads is translated, and each email goes out in its reader's language. */

use ELAB_Test as T;

$po = static function () {
	$file = dirname( __DIR__, 2 ) . '/languages/elaltillo-bookacall-es_CO.po';
	T::check( is_readable( $file ), 'Spanish .po exists' );
	$entries = array();
	$current = array( 'refs' => '' );
	$key = '';
	foreach ( explode( "\n", file_get_contents( $file ) ) as $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' === trim( $line ) ) {
			if ( isset( $current['msgid'] ) ) {
				$entries[] = $current;
			}
			$current = array( 'refs' => '' );
			continue;
		}
		if ( 0 === strpos( $line, '#:' ) ) {
			$current['refs'] .= ' ' . substr( $line, 2 );
		} elseif ( preg_match( '/^(msgid_plural|msgid|msgstr\[0\]|msgstr) "(.*)"$/', $line, $m ) ) {
			$key = $m[1];
			$current[ $key ] = stripcslashes( $m[2] );
		} elseif ( '"' === ( $line[0] ?? '' ) && $key ) {
			$current[ $key ] .= stripcslashes( substr( $line, 1, -1 ) );
		}
	}
	if ( isset( $current['msgid'] ) ) {
		$entries[] = $current;
	}
	return array_values( array_filter( $entries, static function ( $entry ) { return '' !== $entry['msgid']; } ) );
};
$spanish = static function () {
	if ( ! in_array( 'es_CO', get_available_languages(), true ) ) {
		throw new ELAB_Test_Failure( 'Spanish (Colombia) is not installed on this site' );
	}
};

return array(
	'Every text visitors, guests and advisors read is translated' => function () use ( $po ) {
		$public = '#includes/(frontend/|notifications/|bookings/class-elab-booking-(service|manage|guard)\.php|bookings/class-elab-(rest-controller|availability)\.php|files/|google/class-elab-booking-sync\.php)#';
		$missing = array();
		foreach ( $po() as $entry ) {
			$translated = $entry['msgstr'] ?? ( $entry['msgstr[0]'] ?? '' );
			if ( preg_match( $public, $entry['refs'] ) && '' === $translated ) {
				$missing[] = $entry['msgid'];
			}
		}
		T::same( array(), $missing, 'Untranslated public texts' );
	},

	'Translations keep every placeholder' => function () use ( $po ) {
		$broken = array();
		foreach ( $po() as $entry ) {
			$translated = $entry['msgstr'] ?? ( $entry['msgstr[0]'] ?? '' );
			if ( '' === $translated ) {
				continue;
			}
			preg_match_all( '/%(\d\$)?[sd]/', $entry['msgid'], $source );
			preg_match_all( '/%(\d\$)?[sd]/', $translated, $target );
			$a = $source[0];
			$b = $target[0];
			sort( $a );
			sort( $b );
			if ( $a !== $b ) {
				$broken[] = $entry['msgid'] . ' → ' . $translated;
			}
		}
		T::same( array(), $broken, 'Placeholder mismatch' );
	},

	'The visitor’s emails follow the meeting language chosen in the form' => function () use ( $spanish ) {
		$spanish();
		$advisor = T::advisor();
		$booking = T::booking_from( T::book( $advisor, T::slot_values( $advisor )[0], array( 'language' => 'es' ) ) );
		T::same( 'es_CO', get_post_meta( $booking, '_elab_locale', true ), 'Stored language' );
		T::same( 1, count( T::mail_with_subject( 'Recibimos tu solicitud de llamada' ) ), 'Spanish subject' );
		$english = T::booking_from( T::book( $advisor, T::slot_values( $advisor )[0], array( 'language' => 'en' ) ) );
		T::same( 'en_US', get_post_meta( $english, '_elab_locale', true ), 'English chosen' );
		T::same( 1, count( T::mail_with_subject( 'We received your call request' ) ), 'English subject' );
	},

	'Advisor emails are in Spanish by default, English when set on the profile' => function () use ( $spanish ) {
		$spanish();
		$advisor = T::advisor();
		delete_post_meta( $advisor, ELAB_Notifications::META_ADVISOR_LOCALE );
		T::same( 'es_CO', ELAB_Notifications::advisor_locale( $advisor ), 'Default Spanish' );
		ELAB_Notifications::booking_notice( T::booking( $advisor ), 'confirmed' );
		T::same( 'Nueva llamada agendada', end( T::$mail )['subject'], 'Spanish subject' );
		update_post_meta( $advisor, ELAB_Notifications::META_ADVISOR_LOCALE, 'en_US' );
		ELAB_Notifications::booking_notice( T::booking( $advisor ), 'confirmed' );
		T::same( 'New call booked', end( T::$mail )['subject'], 'English when chosen' );
	},

	'Advisor and visitor of the same call each read their own language' => function () use ( $spanish ) {
		$spanish();
		$advisor = T::advisor();
		delete_post_meta( $advisor, ELAB_Notifications::META_ADVISOR_LOCALE );
		$booking = T::booking( $advisor, array( '_elab_locale' => 'en_US' ) );
		ELAB_Booking_Manage::cancel( $booking );
		T::same( 1, count( T::mail_with_subject( 'Llamada cancelada' ) ), 'Advisor in Spanish' );
		T::same( 1, count( T::mail_with_subject( 'Your call was cancelled' ) ), 'Visitor in English' );
	},

	'Spanish dates read naturally' => function () use ( $spanish ) {
		$spanish();
		$booking = T::booking( T::advisor(), array( '_elab_start_utc' => '2030-11-19 15:00:00' ) );
		$when = ELAB_Notifications::in_locale( 'es_CO', static function () use ( $booking ) {
			return ELAB_Notifications::when( $booking, 'America/Bogota' );
		} );
		T::contains( 'martes, 19 de noviembre de 2030', $when, 'Spanish date' );
	},

	'The site language is restored after every email' => function () use ( $spanish ) {
		$spanish();
		$before = determine_locale();
		ELAB_Notifications::booking_notice( T::booking( T::advisor( array( ELAB_Notifications::META_ADVISOR_LOCALE => 'es_CO' ) ) ), 'confirmed' );
		T::same( $before, determine_locale(), 'Locale restored' );
	},

	'The email-language choice on the advisor profile is saved' => function () {
		$advisor = T::advisor();
		$_POST = array( 'elab_nonce' => wp_create_nonce( ELAB_Advisor_Admin::NONCE_ACTION ), 'elab_email' => 'x@example.test', 'elab_timezone' => 'Europe/Lisbon', 'elab_email_locale' => 'en_US', 'elab_day_active' => array( 1 => '1' ), 'elab_availability' => array( 1 => array( array( 'start' => '09:00', 'end' => '12:00' ) ) ) );
		( new ELAB_Advisor_Admin() )->save( $advisor );
		T::same( 'en_US', get_post_meta( $advisor, ELAB_Notifications::META_ADVISOR_LOCALE, true ), 'Saved' );
		$_POST['elab_email_locale'] = 'xx_XX';
		( new ELAB_Advisor_Admin() )->save( $advisor );
		T::same( 'en_US', get_post_meta( $advisor, ELAB_Notifications::META_ADVISOR_LOCALE, true ), 'Unknown language ignored' );
	},

	'A multilingual plugin forcing the page language cannot override the reader’s language' => function () {
		// TranslatePress filters both at 99999 with the language of the page being viewed.
		$page = static function () { return 'en_US'; };
		add_filter( 'locale', $page, 99999 );
		add_filter( 'determine_locale', $page, 99999 );
		try {
			$inside = ELAB_Notifications::in_locale( 'es_CO', static function () {
				return __( 'Your call is booked', 'elaltillo-bookacall' );
			} );
			$after = __( 'Your call is booked', 'elaltillo-bookacall' );
		} finally {
			remove_filter( 'locale', $page, 99999 );
			remove_filter( 'determine_locale', $page, 99999 );
		}
		T::same( 'Tu llamada está agendada', $inside, 'Written in the reader’s language' );
		T::same( 'Your call is booked', $after, 'The page language is back afterwards' );
	},
);
