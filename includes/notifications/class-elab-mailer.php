<?php
/**
 * Simple, readable HTML emails with a plain-text alternative.
 *
 * One layout for every message: a heading, an optional highlighted notice, a two-column
 * table of details, short paragraphs and at most one button. Inline styles only, so it
 * renders the same in Gmail, Outlook and Apple Mail.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Mailer {
	/**
	 * @param string $to      Recipient.
	 * @param string $subject Subject line.
	 * @param array  $message {title, intro, notice, rows (label => value or [text, url]), body (string[]), button [label, url], secondary [label, url], form [label, url], footer}.
	 */
	public static function send( $to, $subject, array $message ) {
		$html = self::html( $message );
		$text = self::text( $message );
		$alt = static function ( $phpmailer ) use ( $text ) {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		};
		add_action( 'phpmailer_init', $alt );
		$sent = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', $alt );
		return $sent;
	}

	/** A full web page in the same layout, for links opened from emails. */
	public static function page( array $message ) {
		$message['footer'] = $message['footer'] ?? get_bloginfo( 'name' );
		return self::html( $message );
	}

	private static function html( array $m ) {
		$font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;";
		$out = '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $m['title'] ?? '' ) . '</title></head>';
		$out .= '<body style="margin:0;padding:0;background:#f3eee8;">';
		$out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3eee8;"><tr><td align="center" style="padding:32px 12px;">';
		$out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFAF7;border:1px solid #e4ddd5;border-radius:16px;">';
		$out .= '<tr><td style="padding:28px 32px 4px;' . $font . 'color:#282C33;">';
		$out .= '<p style="margin:0 0 20px;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#7b7e84;">' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
		$out .= '<h1 style="margin:0 0 10px;font-size:22px;line-height:1.3;font-weight:600;">' . esc_html( $m['title'] ?? '' ) . '</h1>';
		if ( ! empty( $m['intro'] ) ) {
			$out .= '<p style="margin:0 0 4px;font-size:15px;line-height:1.55;color:#4a4d53;">' . esc_html( $m['intro'] ) . '</p>';
		}
		$out .= '</td></tr>';
		if ( ! empty( $m['notice'] ) ) {
			$out .= '<tr><td style="padding:16px 32px 0;' . $font . '"><div style="background:#FFFD88;border-radius:10px;padding:14px 16px;font-size:14px;line-height:1.5;color:#282C33;">' . esc_html( $m['notice'] ) . '</div></td></tr>';
		}
		if ( ! empty( $m['rows'] ) ) {
			$out .= '<tr><td style="padding:20px 32px 0;' . $font . '"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
			foreach ( $m['rows'] as $label => $value ) {
				if ( is_array( $value ) ) {
					// [link text, url].
					$cell = '<a href="' . esc_url( $value[1] ) . '" style="color:#282C33;">' . esc_html( $value[0] ) . '</a>';
				} elseif ( '' === (string) $value ) {
					continue;
				} else {
					$cell = preg_match( '#^https://#', $value ) ? '<a href="' . esc_url( $value ) . '" style="color:#282C33;">' . esc_html( $value ) . '</a>' : esc_html( $value );
				}
				$out .= '<tr><td style="padding:10px 12px 10px 0;border-top:1px solid #ece6df;font-size:13px;color:#7b7e84;width:38%;vertical-align:top;">' . esc_html( $label ) . '</td>';
				$out .= '<td style="padding:10px 0;border-top:1px solid #ece6df;font-size:15px;color:#282C33;vertical-align:top;">' . $cell . '</td></tr>';
			}
			$out .= '</table></td></tr>';
		}
		if ( ! empty( $m['button'] ) && ! empty( $m['button'][1] ) ) {
			$out .= '<tr><td style="padding:24px 32px 0;' . $font . '"><a href="' . esc_url( $m['button'][1] ) . '" style="display:inline-block;background:#282C33;color:#FFFAF7;text-decoration:none;padding:12px 24px;border-radius:999px;font-size:14px;font-weight:600;">' . esc_html( $m['button'][0] ) . '</a></td></tr>';
		}
		if ( ! empty( $m['secondary'][1] ) ) {
			$out .= '<tr><td style="padding:12px 32px 0;' . $font . '"><a href="' . esc_url( $m['secondary'][1] ) . '" style="display:inline-block;border:1px solid #282C33;color:#282C33;text-decoration:none;padding:11px 23px;border-radius:999px;font-size:14px;font-weight:600;">' . esc_html( $m['secondary'][0] ) . '</a></td></tr>';
		}
		if ( ! empty( $m['form'] ) ) {
			// [label, action url]: a POST button, so link scanners never act on it.
			$out .= '<tr><td style="padding:24px 32px 0;' . $font . '"><form method="post" action="' . esc_url( $m['form'][1] ) . '" style="margin:0;"><button type="submit" style="cursor:pointer;border:0;background:#282C33;color:#FFFAF7;padding:12px 24px;border-radius:999px;font-size:14px;font-weight:600;' . $font . '">' . esc_html( $m['form'][0] ) . '</button></form></td></tr>';
		}
		foreach ( (array) ( $m['body'] ?? array() ) as $paragraph ) {
			$out .= '<tr><td style="padding:16px 32px 0;' . $font . 'font-size:14px;line-height:1.55;color:#4a4d53;">' . esc_html( $paragraph ) . '</td></tr>';
		}
		$out .= '<tr><td style="padding:28px 32px 28px;' . $font . 'font-size:12px;line-height:1.5;color:#9a9da2;">' . esc_html( $m['footer'] ?? sprintf( __( 'Sent by %s.', 'elaltillo-bookacall' ), wp_parse_url( home_url(), PHP_URL_HOST ) ) ) . '</td></tr>';
		$out .= '</table></td></tr></table></body></html>';
		return $out;
	}

	private static function text( array $m ) {
		$lines = array( $m['title'] ?? '' );
		if ( ! empty( $m['intro'] ) ) {
			$lines[] = $m['intro'];
		}
		if ( ! empty( $m['notice'] ) ) {
			$lines[] = '';
			$lines[] = $m['notice'];
		}
		if ( ! empty( $m['rows'] ) ) {
			$lines[] = '';
			foreach ( $m['rows'] as $label => $value ) {
				if ( is_array( $value ) ) {
					$lines[] = $label . ': ' . $value[1];
				} elseif ( '' !== (string) $value ) {
					$lines[] = $label . ': ' . $value;
				}
			}
		}
		if ( ! empty( $m['button'][1] ) ) {
			$lines[] = '';
			$lines[] = $m['button'][0] . ': ' . $m['button'][1];
		}
		if ( ! empty( $m['secondary'][1] ) ) {
			$lines[] = $m['secondary'][0] . ': ' . $m['secondary'][1];
		}
		foreach ( (array) ( $m['body'] ?? array() ) as $paragraph ) {
			$lines[] = '';
			$lines[] = $paragraph;
		}
		return implode( "\n", $lines );
	}
}
