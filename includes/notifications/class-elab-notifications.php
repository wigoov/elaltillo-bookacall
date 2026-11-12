<?php
/**
 * Every email the plugin sends: to the advisor (new, cancelled, waiting for Google, schedule),
 * to the visitor (booked, confirmed, cancelled) and to the visitor's guests when Google is not
 * carrying the news. All share the ELAB_Mailer layout.
 *
 * Each email is written in its reader's language: the visitor's (and guests') in the meeting
 * language chosen in the form, the advisor's in the language set on their profile (Spanish by
 * default).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Notifications {
	const META_BOOKING_MAIL = '_elab_advisor_mail_status';
	const META_VISITOR_MAIL = '_elab_visitor_mail_status';
	const META_SCHEDULE_MAIL = '_elab_schedule_mail_status';
	const META_ADVISOR_LOCALE = '_elab_email_locale';
	const META_GUESTS_TOLD = '_elab_guests_told';
	private static $schedule_queue = array();
	private static $schedule_hooked = false;

	public static function queue_schedule_summary( $advisor_id ) {
		self::$schedule_queue[ (int) $advisor_id ] = true;
		if ( ! self::$schedule_hooked ) {
			add_action( 'shutdown', array( __CLASS__, 'send_queued_summaries' ) );
			self::$schedule_hooked = true;
		}
	}

	public static function send_queued_summaries() {
		foreach ( array_keys( self::$schedule_queue ) as $advisor_id ) {
			self::schedule_summary( $advisor_id );
		}
		self::$schedule_queue = array();
	}

	/* ---------- Language ---------- */

	/** Languages an email can be written in: English plus every installed translation. */
	public static function locales() {
		$names = array( 'en_US' => 'English' );
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		$installed = function_exists( 'wp_get_available_translations' ) ? wp_get_available_translations() : array();
		foreach ( get_available_languages() as $locale ) {
			$names[ $locale ] = $installed[ $locale ]['native_name'] ?? $locale;
		}
		return $names;
	}

	/** Advisors read their emails in the language set on their profile, Spanish when available. */
	public static function advisor_locale( $advisor_id ) {
		$saved = (string) get_post_meta( $advisor_id, self::META_ADVISOR_LOCALE, true );
		$locales = self::locales();
		if ( isset( $locales[ $saved ] ) ) {
			return $saved;
		}
		return isset( $locales['es_CO'] ) ? 'es_CO' : get_locale();
	}

	/** Run $callback with WordPress switched to $locale, then switch back. */
	public static function in_locale( $locale, callable $callback ) {
		if ( ! $locale || $locale === determine_locale() ) {
			return $callback();
		}
		$switched = switch_to_locale( $locale );
		// Multilingual plugins (TranslatePress filters "locale" and "determine_locale" at 99999)
		// would keep forcing the page's language over the switch. While this email or page is
		// written, its reader's language wins, and the plugin's strings are reloaded in it.
		$force = static function () use ( $locale ) {
			return $locale;
		};
		add_filter( 'locale', $force, PHP_INT_MAX );
		add_filter( 'determine_locale', $force, PHP_INT_MAX );
		self::reload_strings();
		// Load this plugin's own file for that language directly, without relying on
		// WordPress finding it (a cached list of language files can miss it on some servers).
		$file = ELAB_DIR . 'languages/elaltillo-bookacall-' . $locale . '.mo';
		if ( is_readable( $file ) ) {
			load_textdomain( 'elaltillo-bookacall', $file, $locale );
		}
		try {
			return $callback();
		} finally {
			remove_filter( 'locale', $force, PHP_INT_MAX );
			remove_filter( 'determine_locale', $force, PHP_INT_MAX );
			if ( $switched ) {
				restore_previous_locale();
			}
			self::reload_strings();
		}
	}

	/** Drop the plugin's loaded strings so the next one loads in the current language. */
	private static function reload_strings() {
		unload_textdomain( 'elaltillo-bookacall', true );
	}

	/* ---------- Shared pieces ---------- */

	public static function advisor_email( $advisor_id ) {
		$email = sanitize_email( (string) get_post_meta( $advisor_id, '_elab_email', true ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Where the advisor reconnects Google: their personal emailed link, which needs no
	 * WordPress login. Works from cron.
	 */
	public static function reconnect_url( $advisor_id ) {
		return ELAB_Google_Calendar::invite_url( $advisor_id ) ?: ( (int) get_post_meta( $advisor_id, '_elab_wp_user', true ) ? admin_url( 'profile.php#elab-google' ) : admin_url( 'post.php?post=' . (int) $advisor_id . '&action=edit' ) );
	}

	/** Sent from the advisor's admin screen: a link to connect Google without signing in to WordPress. */
	public static function connect_invite( $advisor_id ) {
		$email = self::advisor_email( $advisor_id );
		$url = ELAB_Google_Calendar::invite_url( $advisor_id );
		if ( ! $email || ! $url ) {
			return false;
		}
		return self::in_locale( self::advisor_locale( $advisor_id ), static function () use ( $advisor_id, $email, $url ) {
			return ELAB_Mailer::send( $email, sprintf( __( 'Connect your Google Calendar to %s', 'elaltillo-bookacall' ), get_bloginfo( 'name' ) ), array(
				'title'  => __( 'Connect your Google Calendar', 'elaltillo-bookacall' ),
				'intro'  => sprintf( __( 'Connect your Google account so visitors can book calls with you on %s and every booked call appears in your calendar automatically.', 'elaltillo-bookacall' ), get_bloginfo( 'name' ) ),
				'rows'   => array(
					__( 'Google account to use', 'elaltillo-bookacall' ) => $email,
					/* translators: Date format for emails, see https://www.php.net/manual/datetime.format.php */
					__( 'Link valid until', 'elaltillo-bookacall' )      => wp_date( _x( 'l, F j, Y', 'email date', 'elaltillo-bookacall' ), ELAB_Google_Calendar::invite_expires( $advisor_id ) ),
				),
				'button' => array( __( 'Connect Google Calendar', 'elaltillo-bookacall' ), $url ),
				'body'   => array(
					sprintf( __( 'The site creates one calendar in your account, “%s”, and can only add and remove your booked calls there. It cannot see or change any other calendar or event.', 'elaltillo-bookacall' ), ELAB_Google_Calendar::CALENDAR_NAME ),
					__( 'You do not need a website account. This link is personal: please do not forward it.', 'elaltillo-bookacall' ),
				),
			) );
		} );
	}

	private static function zone( $name ) {
		try {
			return new DateTimeZone( $name ?: 'UTC' );
		} catch ( Exception $exception ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/** "martes 17 de noviembre de 2026, 10:00 a. m. (Europe/Lisbon)", in the current language. */
	public static function when( $booking_id, $zone ) {
		$zone = $zone instanceof DateTimeZone ? $zone : self::zone( (string) $zone );
		$start = strtotime( (string) get_post_meta( $booking_id, '_elab_start_utc', true ) . ' UTC' );
		/* translators: Date format for emails, see https://www.php.net/manual/datetime.format.php */
		$format = _x( 'l, F j, Y', 'email date', 'elaltillo-bookacall' );
		return wp_date( $format . ', ' . get_option( 'time_format' ), $start, $zone ) . ' (' . str_replace( '_', ' ', $zone->getName() ) . ')';
	}

	private static function language( $booking_id ) {
		return ELAB_Booking_Service::language_label( get_post_meta( $booking_id, '_elab_language', true ) ?: ELAB_Booking_Service::DEFAULT_LANGUAGE );
	}

	/* ---------- Advisor ---------- */

	public static function schedule_summary( $advisor_id ) {
		$email = self::advisor_email( $advisor_id );
		if ( ! $email || ! ELAB_Google_Calendar::connected( $advisor_id ) ) {
			return false;
		}
		$sent = self::in_locale( self::advisor_locale( $advisor_id ), static function () use ( $advisor_id, $email ) {
			$timezone = (string) get_post_meta( $advisor_id, '_elab_timezone', true ) ?: 'UTC';
			$rules = ELAB_Availability::rules( $advisor_id );
			$availability = json_decode( (string) get_post_meta( $advisor_id, '_elab_availability', true ), true );
			$availability = is_array( $availability ) ? $availability : array();
			$rows = array(
				__( 'Time zone', 'elaltillo-bookacall' )           => str_replace( '_', ' ', $timezone ),
				__( 'Meeting length', 'elaltillo-bookacall' )      => sprintf( __( '%d minutes', 'elaltillo-bookacall' ), $rules['duration'] ),
				__( 'Minimum notice', 'elaltillo-bookacall' )      => sprintf( __( '%d hours', 'elaltillo-bookacall' ), $rules['notice'] ),
				__( 'Bookable up to', 'elaltillo-bookacall' )      => sprintf( __( '%d days ahead', 'elaltillo-bookacall' ), $rules['horizon'] ),
				__( 'Break between calls', 'elaltillo-bookacall' ) => sprintf( __( '%d minutes', 'elaltillo-bookacall' ), $rules['buffer'] ),
			);
			// 2023-01-02 was a Monday: format weekday names in the reader's language.
			for ( $number = 1; $number <= 7; $number++ ) {
				$ranges = array();
				foreach ( (array) ( $availability[ $number ] ?? array() ) as $range ) {
					if ( is_array( $range ) && isset( $range[0], $range[1] ) ) {
						$ranges[] = $range[0] . '–' . $range[1];
					}
				}
				$day = ucfirst( wp_date( 'l', strtotime( '2023-01-0' . ( $number + 1 ) . ' 12:00 UTC' ), new DateTimeZone( 'UTC' ) ) );
				$rows[ $day ] = $ranges ? implode( ', ', $ranges ) : __( 'Unavailable', 'elaltillo-bookacall' );
			}
			$dates = array();
			$personal = get_post_meta( $advisor_id, ELAB_Blackouts::META, true );
			foreach ( is_array( $personal ) ? $personal : array() as $rule ) {
				$dates[] = $rule['start'] . ( $rule['end'] !== $rule['start'] ? ' → ' . $rule['end'] : '' ) . ( ! empty( $rule['annual'] ) ? ' ' . __( '(every year)', 'elaltillo-bookacall' ) : '' );
			}
			$rows[ __( 'Days off', 'elaltillo-bookacall' ) ] = $dates ? implode( ', ', $dates ) : __( 'None', 'elaltillo-bookacall' );
			return ELAB_Mailer::send( $email, __( 'Your call availability has been updated', 'elaltillo-bookacall' ), array(
				'title' => __( 'Your availability was updated', 'elaltillo-bookacall' ),
				'intro' => sprintf( __( 'These are the times visitors can book with %s.', 'elaltillo-bookacall' ), get_the_title( $advisor_id ) ),
				'rows'  => $rows,
				'body'  => array( __( 'These hours are managed in WordPress and are not added to your calendar. Only booked calls appear there, in the separate “El Altillo · Citas” calendar.', 'elaltillo-bookacall' ) ),
			) );
		} );
		update_post_meta( $advisor_id, self::META_SCHEDULE_MAIL, $sent ? 'sent' : 'failed' );
		return $sent;
	}

	/**
	 * Advisor email for a booking.
	 *
	 * @param string $status 'confirmed', 'pending', 'cancelled' or 'synced' (the queued event reached Google).
	 */
	public static function booking_notice( $booking_id, $status ) {
		$advisor_id = (int) get_post_meta( $booking_id, '_elab_advisor', true );
		$email = self::advisor_email( $advisor_id );
		if ( ! $email ) {
			update_post_meta( $booking_id, self::META_BOOKING_MAIL, 'failed' );
			return false;
		}
		$sent = self::in_locale( self::advisor_locale( $advisor_id ), static function () use ( $booking_id, $status, $advisor_id, $email ) {
			return ELAB_Mailer::send( $email, ...self::booking_notice_message( $booking_id, $status, $advisor_id ) );
		} );
		update_post_meta( $booking_id, self::META_BOOKING_MAIL, $sent ? 'sent' : 'failed' );
		return $sent;
	}

	/** @return array [subject, message] in the current language. */
	public static function booking_notice_message( $booking_id, $status, $advisor_id ) {
		$name = (string) get_post_meta( $booking_id, '_elab_name', true );
		$waiting = ELAB_Booking_Sync::CREATE === ELAB_Booking_Sync::state( $booking_id );
		$broken = $waiting && ! ELAB_Google_Calendar::connected( $advisor_id );
		$titles = array(
			'confirmed' => array( __( 'New call booked', 'elaltillo-bookacall' ), sprintf( __( '%s booked a call with you.', 'elaltillo-bookacall' ), $name ) ),
			'pending'   => array( __( 'New call request', 'elaltillo-bookacall' ), sprintf( __( '%s requested a call. Confirm it from the booking record.', 'elaltillo-bookacall' ), $name ) ),
			'cancelled' => array( __( 'Call cancelled', 'elaltillo-bookacall' ), array(
				'visitor' => sprintf( __( '%s cancelled the call from their private link. The time is free again.', 'elaltillo-bookacall' ), $name ),
				'google'  => __( 'The event was deleted from your Google Calendar, so the call was cancelled on the site too. The time is free again and the visitor was emailed.', 'elaltillo-bookacall' ),
				'declined' => sprintf( __( '%s declined the calendar invitation, so the call was cancelled and the time is free again.', 'elaltillo-bookacall' ), $name ),
			)[ get_post_meta( $booking_id, '_elab_cancelled_by', true ) ] ?? __( 'This call was cancelled. The time is free again.', 'elaltillo-bookacall' ) ),
			'synced'    => array( __( 'Call added to your calendar', 'elaltillo-bookacall' ), __( 'Google is reachable again: this call is now in your calendar and the visitor received the invitation.', 'elaltillo-bookacall' ) ),
		);
		list( $title, $intro ) = $titles[ $status ] ?? $titles['pending'];
		$notice = '';
		$button = array();
		$body = array();
		if ( $waiting ) {
			$notice = $broken
				? __( 'The call is saved and the time is reserved, but your Google Calendar connection is not working, so the event and the visitor’s invitation could not be created. Reconnect Google: everything waiting is added automatically.', 'elaltillo-bookacall' )
				: __( 'The call is saved and the time is reserved. Google did not answer, so the calendar event will be created automatically within the hour.', 'elaltillo-bookacall' );
			if ( $broken ) {
				$button = array( __( 'Reconnect Google Calendar', 'elaltillo-bookacall' ), self::reconnect_url( $advisor_id ) );
			}
		} elseif ( 'cancelled' === $status && get_post_meta( $booking_id, ELAB_Booking_Sync::META_MANUAL, true ) ) {
			$notice = ELAB_Booking_Sync::by_advisor( $booking_id )
				? __( 'You added this call to your calendar yourself, so the site cannot remove it. Please delete that event in your calendar.', 'elaltillo-bookacall' )
				: __( 'This call was created in your main Google Calendar before the dedicated calendar existed. Please delete that event there by hand.', 'elaltillo-bookacall' );
		} elseif ( 'cancelled' === $status && ELAB_Booking_Sync::DELETE === ELAB_Booking_Sync::state( $booking_id ) ) {
			$notice = ELAB_Google_Calendar::connected( $advisor_id )
				? __( 'Google did not answer, so the event will be removed from your calendar automatically as soon as it does.', 'elaltillo-bookacall' )
				: __( 'Your Google Calendar is not connected, so the event stays in your calendar until you reconnect; the site then removes it automatically.', 'elaltillo-bookacall' );
		}
		$body[] = __( 'The sector, message and attachments are in the private booking record, visible only to site administrators.', 'elaltillo-bookacall' );
		$subject = $broken ? __( 'Action needed: new call waiting for Google Calendar', 'elaltillo-bookacall' ) : $title;
		return array( $subject, array(
			'title'     => $title,
			'intro'     => $intro,
			'notice'    => $notice,
			'rows'      => array(
				__( 'When', 'elaltillo-bookacall' )          => self::when( $booking_id, (string) get_post_meta( $advisor_id, '_elab_timezone', true ) ),
				__( 'Length', 'elaltillo-bookacall' )        => sprintf( __( '%d minutes', 'elaltillo-bookacall' ), ELAB_Booking_Admin::duration( $booking_id ) ),
				__( 'Language', 'elaltillo-bookacall' )      => self::language( $booking_id ),
				__( 'Visitor', 'elaltillo-bookacall' )       => $name,
				__( 'Visitor email', 'elaltillo-bookacall' ) => (string) get_post_meta( $booking_id, '_elab_contact_email', true ),
				__( 'Guests', 'elaltillo-bookacall' )        => implode( ', ', ELAB_Booking_Sync::guests( $booking_id ) ),
				__( 'Video call', 'elaltillo-bookacall' )    => 'cancelled' === $status ? '' : (string) get_post_meta( $booking_id, '_elab_meet_url', true ),
			),
			'button'    => $button,
			'body'      => $body,
		) );
	}

	/**
	 * Google stopped accepting the advisor's access. Tell the advisor and the site admin
	 * once, with the calls that are waiting. Bookings keep working in WordPress meanwhile.
	 */
	public static function connection_lost( $advisor_id ) {
		$subject_name = get_the_title( $advisor_id );
		$recipients = array_unique( array_filter( array( self::advisor_email( $advisor_id ), sanitize_email( (string) get_option( 'admin_email' ) ) ) ) );
		$advisor_email = self::advisor_email( $advisor_id );
		foreach ( $recipients as $recipient ) {
			$to_advisor = $recipient === $advisor_email;
			// Both read it in the advisor's email language (Spanish by default), whatever the page was.
			self::in_locale( self::advisor_locale( $advisor_id ), static function () use ( $advisor_id, $subject_name, $recipient, $to_advisor ) {
				$rows = array();
				foreach ( ELAB_Booking_Sync::pending( $advisor_id ) as $booking_id ) {
					if ( ELAB_Booking_Sync::CREATE === ELAB_Booking_Sync::state( $booking_id ) ) {
						$rows[ self::when( $booking_id, (string) get_post_meta( $advisor_id, '_elab_timezone', true ) ) ] = (string) get_post_meta( $booking_id, '_elab_name', true );
					}
				}
				$why = __( 'This happens when access is removed in the Google account, the password changes, or the Google app is still in Testing mode (access then expires after 7 days). Reconnecting takes one click, needs no website login, and adds any waiting calls automatically.', 'elaltillo-bookacall' );
				if ( $to_advisor ) {
					ELAB_Mailer::send( $recipient, __( 'Reconnect your Google Calendar', 'elaltillo-bookacall' ), array(
						'title'  => __( 'Reconnect your Google Calendar', 'elaltillo-bookacall' ),
						'intro'  => __( 'Google stopped accepting your connection.', 'elaltillo-bookacall' ),
						'notice' => __( 'Until you reconnect, you do not appear in the booking calendar and visitors cannot book new calls with you. Calls already booked are kept.', 'elaltillo-bookacall' ),
						'rows'   => $rows,
						'button' => array( __( 'Reconnect Google Calendar', 'elaltillo-bookacall' ), self::reconnect_url( $advisor_id ) ),
						'body'   => array( $why ),
					) );
					return;
				}
				ELAB_Mailer::send( $recipient, sprintf( __( '%s needs to reconnect Google Calendar', 'elaltillo-bookacall' ), $subject_name ), array(
					'title'  => sprintf( __( '%s needs to reconnect Google Calendar', 'elaltillo-bookacall' ), $subject_name ),
					'intro'  => sprintf( __( 'Google stopped accepting the connection of %s. They were emailed a link to reconnect.', 'elaltillo-bookacall' ), $subject_name ),
					'notice' => sprintf( __( 'Until they reconnect, %s does not appear in the booking calendar. Calls already booked are kept.', 'elaltillo-bookacall' ), $subject_name ),
					'rows'   => $rows,
					'button' => array( __( 'Open the advisor', 'elaltillo-bookacall' ), admin_url( 'post.php?post=' . (int) $advisor_id . '&action=edit#elab-google-connection' ) ),
					'body'   => array( $why, __( 'From the advisor screen you can send them a new connection link.', 'elaltillo-bookacall' ) ),
				) );
			} );
		}
	}

	/** The connection was removed from the site (Disconnect). The advisor hears it from us. */
	public static function disconnected( $advisor_id ) {
		$email = self::advisor_email( $advisor_id );
		if ( ! $email ) {
			return false;
		}
		return self::in_locale( self::advisor_locale( $advisor_id ), static function () use ( $advisor_id, $email ) {
			$upcoming = get_posts( array( 'post_type' => ELAB_Post_Types::BOOKING, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_query' => array(
				array( 'key' => '_elab_advisor', 'value' => (int) $advisor_id ),
				array( 'key' => '_elab_status', 'value' => array( 'pending', 'confirmed' ), 'compare' => 'IN' ),
				array( 'key' => '_elab_start_utc', 'value' => gmdate( 'Y-m-d H:i:s' ), 'compare' => '>', 'type' => 'DATETIME' ),
			) ) );
			return ELAB_Mailer::send( $email, __( 'Google Calendar disconnected', 'elaltillo-bookacall' ), array(
				'title'  => __( 'Your Google Calendar was disconnected', 'elaltillo-bookacall' ),
				'intro'  => sprintf( __( 'Your Google Calendar is no longer connected to %s.', 'elaltillo-bookacall' ), get_bloginfo( 'name' ) ),
				'notice' => __( 'Visitors cannot book calls with you until you connect it again.', 'elaltillo-bookacall' ),
				'rows'   => array( __( 'Calls already booked', 'elaltillo-bookacall' ) => (string) count( $upcoming ) ),
				'button' => array( __( 'Connect Google Calendar', 'elaltillo-bookacall' ), self::reconnect_url( $advisor_id ) ),
				'body'   => array( __( 'Calls already booked are kept and stay in your “El Altillo · Citas” calendar. If one is cancelled while you are disconnected, the site removes its event once you connect again.', 'elaltillo-bookacall' ) ),
			) );
		} );
	}

	/* ---------- Visitor and guests ---------- */

	private static function visitor_rows( $booking_id, $with_guests = true ) {
		return array(
			__( 'When', 'elaltillo-bookacall' )       => self::when( $booking_id, (string) get_post_meta( $booking_id, '_elab_visitor_timezone', true ) ),
			__( 'Advisor', 'elaltillo-bookacall' )    => get_the_title( (int) get_post_meta( $booking_id, '_elab_advisor', true ) ),
			__( 'Length', 'elaltillo-bookacall' )     => sprintf( __( '%d minutes', 'elaltillo-bookacall' ), ELAB_Booking_Admin::duration( $booking_id ) ),
			__( 'Language', 'elaltillo-bookacall' )   => self::language( $booking_id ),
			__( 'Guests', 'elaltillo-bookacall' )     => $with_guests ? implode( ', ', ELAB_Booking_Sync::guests( $booking_id ) ) : '',
			__( 'Video call', 'elaltillo-bookacall' ) => (string) get_post_meta( $booking_id, '_elab_meet_url', true ),
		);
	}

	private static function manage_button( $booking_id ) {
		$url = ELAB_Booking_Manage::url( $booking_id );
		return $url ? array( __( 'View or cancel my call', 'elaltillo-bookacall' ), $url ) : array();
	}

	/** $build returns [subject, message]; it runs in the visitor's language. */
	private static function send_visitor( $booking_id, callable $build ) {
		$email = sanitize_email( (string) get_post_meta( $booking_id, '_elab_contact_email', true ) );
		if ( ! is_email( $email ) ) {
			update_post_meta( $booking_id, self::META_VISITOR_MAIL, 'failed' );
			return false;
		}
		$sent = self::in_locale( (string) get_post_meta( $booking_id, '_elab_locale', true ), static function () use ( $email, $build ) {
			return ELAB_Mailer::send( $email, ...$build() );
		} );
		update_post_meta( $booking_id, self::META_VISITOR_MAIL, $sent ? 'sent' : 'failed' );
		return $sent;
	}

	/** Guests get the news from the site only when no Google invitation carries it. No private link. */
	private static function send_guests( $booking_id, callable $build ) {
		$sent = 0;
		foreach ( ELAB_Booking_Sync::guests( $booking_id ) as $guest ) {
			$sent += (int) self::in_locale( (string) get_post_meta( $booking_id, '_elab_locale', true ), static function () use ( $guest, $build ) {
				return ELAB_Mailer::send( $guest, ...$build() );
			} );
		}
		return $sent;
	}

	/** Sent right after booking: what was booked and the private link to cancel. */
	public static function visitor_booked( $booking_id ) {
		return self::send_visitor( $booking_id, static function () use ( $booking_id ) {
			$confirmed = 'confirmed' === get_post_meta( $booking_id, '_elab_status', true );
			$invited = (bool) get_post_meta( $booking_id, ELAB_Google_Calendar::META_EVENT, true );
			$body = array();
			if ( $confirmed ) {
				$body[] = $invited ? __( 'Google Calendar also sends you an invitation for this call.', 'elaltillo-bookacall' ) : __( 'Your calendar invitation will follow in a separate email shortly.', 'elaltillo-bookacall' );
			}
			if ( ELAB_Booking_Sync::guests( $booking_id ) && $invited ) {
				$body[] = __( 'Your guests receive their own Google Calendar invitation.', 'elaltillo-bookacall' );
			}
			$body[] = __( 'If your plans change, use the button above so the time is freed for someone else.', 'elaltillo-bookacall' );
			return array( $confirmed ? __( 'Your call is booked', 'elaltillo-bookacall' ) : __( 'We received your call request', 'elaltillo-bookacall' ), array(
				'title'  => $confirmed ? __( 'Your call is booked', 'elaltillo-bookacall' ) : __( 'We received your request', 'elaltillo-bookacall' ),
				'intro'  => $confirmed ? __( 'Thank you. Here are the details of your call.', 'elaltillo-bookacall' ) : __( 'We will confirm this time by email shortly.', 'elaltillo-bookacall' ),
				'rows'   => self::visitor_rows( $booking_id ),
				'button' => self::manage_button( $booking_id ),
				'body'   => $body,
			) );
		} );
	}

	/** The advisor moved the call in Google Calendar: the new time, in the visitor's language. */
	public static function visitor_moved( $booking_id ) {
		return self::send_visitor( $booking_id, static function () use ( $booking_id ) {
			return array( __( 'Your call has a new time', 'elaltillo-bookacall' ), array(
				'title'  => __( 'Your call has a new time', 'elaltillo-bookacall' ),
				'intro'  => __( 'The advisor moved your call. Here are the new details; your calendar invitation is updated too.', 'elaltillo-bookacall' ),
				'rows'   => self::visitor_rows( $booking_id ),
				'button' => self::manage_button( $booking_id ),
				'body'   => array( __( 'If the new time does not suit you, use the button above to cancel so the time is freed.', 'elaltillo-bookacall' ) ),
			) );
		} );
	}

	/** Sent when there is no Google invitation to carry the change (manual confirmation, or Google unreachable). */
	public static function visitor_notice( $booking_id, $status ) {
		$host = get_the_title( (int) get_post_meta( $booking_id, '_elab_advisor', true ) );
		if ( 'confirmed' === $status ) {
			$start = new DateTimeImmutable( (string) get_post_meta( $booking_id, '_elab_start_utc', true ), new DateTimeZone( 'UTC' ) );
			$end = $start->modify( '+' . ELAB_Booking_Admin::duration( $booking_id ) . ' minutes' );
			$add = static function () use ( $host, $start, $end ) {
				return 'https://calendar.google.com/calendar/render?' . http_build_query( array(
					'action' => 'TEMPLATE',
					'text'   => sprintf( __( 'Call with %s', 'elaltillo-bookacall' ), $host ),
					'dates'  => $start->format( 'Ymd\THis\Z' ) . '/' . $end->format( 'Ymd\THis\Z' ),
				), '', '&', PHP_QUERY_RFC3986 );
			};
			if ( ELAB_Booking_Sync::guests( $booking_id ) ) {
				update_post_meta( $booking_id, self::META_GUESTS_TOLD, 1 );
			}
			self::send_guests( $booking_id, static function () use ( $booking_id, $add ) {
				$name = (string) get_post_meta( $booking_id, '_elab_name', true );
				return array( sprintf( __( '%s invited you to a call', 'elaltillo-bookacall' ), $name ), array(
					'title' => sprintf( __( '%s invited you to a call', 'elaltillo-bookacall' ), $name ),
					'intro' => __( 'Here are the details. If you cannot attend, let the person who invited you know.', 'elaltillo-bookacall' ),
					'rows'  => self::visitor_rows( $booking_id, false ) + array( __( 'Your calendar', 'elaltillo-bookacall' ) => array( __( 'Add to Google Calendar', 'elaltillo-bookacall' ), $add() ) ),
				) );
			} );
			return self::send_visitor( $booking_id, static function () use ( $booking_id, $add ) {
				return array( __( 'Your call is confirmed', 'elaltillo-bookacall' ), array(
					'title'  => __( 'Your call is confirmed', 'elaltillo-bookacall' ),
					'intro'  => __( 'See you then. Here are the details.', 'elaltillo-bookacall' ),
					'rows'   => self::visitor_rows( $booking_id ) + array( __( 'Your calendar', 'elaltillo-bookacall' ) => array( __( 'Add to Google Calendar', 'elaltillo-bookacall' ), $add() ) ),
					'button' => self::manage_button( $booking_id ),
				) );
			} );
		}
		$cancelled = static function ( $for_visitor ) use ( $booking_id ) {
			$rows = self::visitor_rows( $booking_id, $for_visitor );
			unset( $rows[ __( 'Video call', 'elaltillo-bookacall' ) ] );
			if ( ! $for_visitor ) {
				// A guest did not book this call: tell them it is off, without offering to rebook it.
				$name = (string) get_post_meta( $booking_id, '_elab_name', true );
				return array( __( 'A call you were invited to was cancelled', 'elaltillo-bookacall' ), array(
					'title' => __( 'A call you were invited to was cancelled', 'elaltillo-bookacall' ),
					'intro' => sprintf( __( 'The call %s invited you to will not take place.', 'elaltillo-bookacall' ), $name ),
					'rows'  => $rows,
				) );
			}
			return array( __( 'Your call was cancelled', 'elaltillo-bookacall' ), array(
				'title'  => __( 'Your call was cancelled', 'elaltillo-bookacall' ),
				'intro'  => __( 'This call will not take place.', 'elaltillo-bookacall' ),
				'rows'   => $rows,
				'button' => array( __( 'Book another time', 'elaltillo-bookacall' ), ELAB_Booking_Manage::booking_page_url( $booking_id ) ),
			) );
		};
		// Only guests who heard about the call (from Google or from us) hear that it was cancelled.
		if ( get_post_meta( $booking_id, self::META_GUESTS_TOLD, true ) ) {
			self::send_guests( $booking_id, static function () use ( $cancelled ) {
				return $cancelled( false );
			} );
		}
		return self::send_visitor( $booking_id, static function () use ( $cancelled ) {
			return $cancelled( true );
		} );
	}
}
