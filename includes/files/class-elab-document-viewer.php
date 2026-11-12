<?php
/**
 * Read-only viewer for booking attachments. Nothing is downloaded and nothing in the file runs.
 *
 * - Word, Excel and PowerPoint: the server turns the file into escaped HTML (ELAB_Office_Preview).
 *   The page has no scripts at all and is sandboxed by its Content-Security-Policy, so even if
 *   something slipped into the HTML it could not run, submit, navigate or download.
 * - PDF: drawn page by page onto canvases by the bundled PDF.js (this site only, no CDN). Its
 *   core never runs the PDF's own JavaScript, forms or links are not drawn, and the page may
 *   only load this plugin's scripts and connect nowhere.
 *
 * Only administrators open it, through a nonce-protected link for one file of one booking.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Document_Viewer {
	const ACTION = 'elab_view';

	public function __construct() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'view' ) );
	}

	public static function url( $booking_id, $stored ) {
		return wp_nonce_url( add_query_arg( array( 'action' => self::ACTION, 'booking' => (int) $booking_id, 'file' => $stored ), admin_url( 'admin-post.php' ) ), self::ACTION . '_' . (int) $booking_id . '_' . $stored );
	}

	public function view() {
		$booking_id = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0;
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view booking attachments.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		if ( ! wp_verify_nonce( $nonce, self::ACTION . '_' . $booking_id . '_' . $file ) ) {
			wp_die( esc_html__( 'Invalid link.', 'elaltillo-bookacall' ), '', array( 'response' => 400 ) );
		}
		$page = self::page( $booking_id, $file );
		if ( is_wp_error( $page ) ) {
			wp_die( esc_html( $page->get_error_message() ), '', array( 'response' => (int) ( $page->get_error_data()['status'] ?? 400 ) ) );
		}
		nocache_headers();
		foreach ( $page['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $page['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts below.
		exit;
	}

	/** Every header of a viewer page: no download, no sniffing, no framing, no referrer. */
	private static function headers( $policy ) {
		return array(
			'Content-Type'            => 'text/html; charset=utf-8',
			'Content-Security-Policy' => $policy,
			'X-Content-Type-Options'  => 'nosniff',
			'X-Frame-Options'         => 'DENY',
			'Referrer-Policy'         => 'no-referrer',
			'Cross-Origin-Opener-Policy' => 'same-origin',
			'X-Robots-Tag'            => 'noindex, nofollow',
		);
	}

	/**
	 * The viewer page for one attachment of one booking.
	 *
	 * @return array{headers:array,html:string}|WP_Error
	 */
	public static function page( $booking_id, $file ) {
		$item = ELAB_Private_Attachments::find( $booking_id, $file );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$extension = strtolower( pathinfo( $item['path'], PATHINFO_EXTENSION ) );
		$name = $item['name'];
		if ( 'pdf' === $extension ) {
			$script = plugins_url( 'assets/js/pdf-viewer.js', ELAB_FILE ) . '?ver=' . rawurlencode( ELAB_VERSION );
			$worker = plugins_url( 'assets/js/vendor/pdfjs/pdf.worker.min.js', ELAB_FILE ) . '?ver=' . rawurlencode( ELAB_VERSION );
			$data = base64_encode( (string) file_get_contents( $item['path'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Private local file.
			$body = '<main class="pdf" data-elab-pdf data-worker="' . esc_url( $worker ) . '" data-pages="' . esc_attr__( '%d pages', 'elaltillo-bookacall' ) . '" data-page="' . esc_attr__( 'Page %d', 'elaltillo-bookacall' ) . '" data-error="' . esc_attr__( 'This PDF could not be shown. It may be damaged or protected with a password.', 'elaltillo-bookacall' ) . '"><p class="status" data-status>' . esc_html__( 'Loading the document…', 'elaltillo-bookacall' ) . '</p></main>'
				// Data only: browsers never run a script element of this type.
				. '<script type="application/octet-stream" id="elab-pdf-data">' . $data . '</script>'
				. '<script type="module" src="' . esc_url( $script ) . '"></script>';
			$policy = "default-src 'none'; script-src 'self'; worker-src 'self'; img-src data: blob:; font-src data: blob:; style-src 'unsafe-inline'; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";
			return array( 'headers' => self::headers( $policy ), 'html' => self::document( $name, $body ) );
		}
		$html = ELAB_Office_Preview::to_html( $item['path'], $extension );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		// No script may run at all; sandbox also blocks forms, navigation, popups and downloads.
		$policy = "sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";
		return array( 'headers' => self::headers( $policy ), 'html' => self::document( $name, '<main>' . $html . '</main>' ) );
	}

	private static function document( $name, $body ) {
		$notice = esc_html__( 'Safe view: the file is not downloaded and nothing in it runs. Office documents are shown simplified (text, tables and pictures).', 'elaltillo-bookacall' );
		return '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $name ) . '</title><style>'
			. ':root{color-scheme:light;--ink:#282C33;--soft:#6b6f76;--line:#e4ddd5;--paper:#FFFAF7;--bg:#f3eee8}'
			. '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif}'
			. 'header{position:sticky;top:0;z-index:1;background:var(--paper);border-bottom:1px solid var(--line);padding:12px 24px}header h1{margin:0;font-size:16px;font-weight:600;word-break:break-all}header p{margin:2px 0 0;font-size:12px;color:var(--soft)}'
			. 'main{max-width:980px;margin:24px auto;padding:0 16px}article,.slide,.sheet{background:var(--paper);border:1px solid var(--line);border-radius:12px;padding:28px 32px;margin:0 0 20px}'
			. '.doc h1{font-size:26px}.doc h2{font-size:21px}.doc h3{font-size:18px}.doc p{margin:0 0 10px}.doc .gap{margin:0 0 6px}.doc .item{padding-left:12px}'
			. 'img{max-width:100%;height:auto;display:block;margin:12px 0}.scroll{overflow-x:auto}table{border-collapse:collapse;margin:12px 0;font-size:14px}td,th{border:1px solid var(--line);padding:6px 10px;vertical-align:top;text-align:left}th{background:#f7f2ec;font-weight:600;color:var(--soft)}'
			. '.cells td{white-space:pre-wrap}.slide-number,.note,.status{font-size:12px;color:var(--soft);text-transform:uppercase;letter-spacing:.08em}.book,.deck{background:none;border:0;padding:0}'
			. '.pdf canvas{display:block;margin:0 auto 20px;background:#fff;box-shadow:0 10px 30px -18px rgba(40,44,51,.5);max-width:100%;height:auto}'
			. '@media (max-width:600px){article,.slide,.sheet{padding:18px}header{padding:10px 16px}}'
			. '</style></head><body><header><h1>' . esc_html( $name ) . '</h1><p>' . $notice . '</p></header>' . $body . '</body></html>';
	}
}
