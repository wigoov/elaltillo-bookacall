<?php
/** Private attachment storage and authorized download handling. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Private_Attachments {
	/** Store documents beside the WordPress document root, never in public uploads. */
	private static function storage_dir() {
		return defined( 'ELAB_PRIVATE_UPLOAD_DIR' ) ? (string) ELAB_PRIVATE_UPLOAD_DIR : dirname( untrailingslashit( ABSPATH ) ) . '/elaltillo-bookacall-private';
	}

	private static function safe_dir() {
		$dir = self::storage_dir();
		if ( ! preg_match( '#^(?:/|[A-Za-z]:/)#', wp_normalize_path( $dir ) ) ) {
			return false;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$real = realpath( $dir );
		$public_roots = array( realpath( ABSPATH ) );
		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$public_roots[] = realpath( (string) $_SERVER['DOCUMENT_ROOT'] );
		}
		foreach ( $public_roots as $root ) {
			if ( $root && $real && ( $real === $root || 0 === strpos( $real, trailingslashit( $root ) ) ) ) {
				return false;
			}
		}
		return $real && is_writable( $real ) ? $real : false;
	}
	/** Absolute private storage path when it is usable, or false. */
	public static function storage_ready() {
		return self::safe_dir();
	}

	/**
	 * Malware check before a file is kept. Uses ClamAV when ELAB_CLAMD_SOCKET is defined
	 * (a Unix socket path or host:port of clamd) and always lets other code decide through
	 * the `elab_scan_attachment` filter (return true to accept, false or WP_Error to reject).
	 * A configured scanner that cannot be reached rejects the file.
	 *
	 * @return true|WP_Error
	 */
	public static function scan( $path, $name ) {
		$result = true;
		if ( defined( 'ELAB_CLAMD_SOCKET' ) && ELAB_CLAMD_SOCKET ) {
			$result = self::clamd_scan( $path, (string) ELAB_CLAMD_SOCKET );
		}
		$result = apply_filters( 'elab_scan_attachment', $result, $path, $name );
		if ( true === $result ) {
			return true;
		}
		return is_wp_error( $result ) ? $result : new WP_Error( 'file_rejected', __( 'One of the files could not be accepted. Please send a different file.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
	}

	private static function clamd_scan( $path, $socket ) {
		$unavailable = new WP_Error( 'scan_unavailable', __( 'Documents cannot be checked right now. Please try again later or book without attachments.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		$target = 0 === strpos( $socket, '/' ) ? 'unix://' . $socket : 'tcp://' . $socket;
		$stream = @stream_socket_client( $target, $errno, $error, 5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure handled below.
		$file = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $stream || ! $file ) {
			return $unavailable;
		}
		stream_set_timeout( $stream, 30 );
		fwrite( $stream, "zINSTREAM\0" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		while ( ! feof( $file ) ) {
			$chunk = fread( $file, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( '' === $chunk || false === $chunk ) {
				break;
			}
			fwrite( $stream, pack( 'N', strlen( $chunk ) ) . $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		fwrite( $stream, pack( 'N', 0 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$reply = trim( (string) stream_get_contents( $stream ), "\0\r\n " );
		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( preg_match( '/: OK$/', $reply ) ) {
			return true;
		}
		return false !== strpos( $reply, 'FOUND' ) ? new WP_Error( 'file_infected', __( 'One of the files was flagged by our virus scanner and was not accepted.', 'elaltillo-bookacall' ), array( 'status' => 400 ) ) : $unavailable;
	}

	public function __construct() {
		add_action( 'admin_post_elab_download', array( $this, 'download' ) );
		add_action( 'before_delete_post', array( $this, 'delete_booking_files' ) );
	}

	public function upload() {
		if ( empty( $_FILES['attachments'] ) || empty( $_FILES['attachments']['name'][0] ) ) {
			return array();
		}
		$upload = $_FILES['attachments'];
		// A client may send a single file instead of attachments[]; normalise to lists so every
		// field is read the same way and malformed input is rejected cleanly.
		foreach ( array( 'name', 'tmp_name', 'error', 'size' ) as $key ) {
			$upload[ $key ] = isset( $upload[ $key ] ) ? array_values( (array) $upload[ $key ] ) : array();
		}
		$count = count( $upload['name'] );
		if ( ! $count || count( $upload['tmp_name'] ) !== $count || count( $upload['error'] ) !== $count || count( $upload['size'] ) !== $count ) {
			return new WP_Error( 'invalid_file', __( 'One of the files could not be accepted. Please send a different file.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		if ( $count > 3 ) {
			return new WP_Error( 'too_many_files', __( 'You can attach up to 3 files.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		$allowed = array( 'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		$dir = self::safe_dir();
		if ( ! $dir ) {
			return new WP_Error( 'upload_failed', __( 'Private attachment storage is unavailable. Ask the site administrator to configure a directory outside the web root.', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		@chmod( $dir, 0700 );
		$files = array();
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_string( $upload['name'][ $i ] ) || ! is_string( $upload['tmp_name'][ $i ] ) ) {
				$this->remove_files( $dir, $files );
				return new WP_Error( 'invalid_file', __( 'One of the files could not be accepted. Please send a different file.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
			}
			$name = sanitize_file_name( $upload['name'][ $i ] );
			$tmp = $upload['tmp_name'][ $i ];
			if ( UPLOAD_ERR_OK !== $upload['error'][ $i ] || $upload['size'][ $i ] > 10 * MB_IN_BYTES ) {
				$this->remove_files( $dir, $files );
				return new WP_Error( 'invalid_file', __( 'Each attachment must be no larger than 10 MB.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
			}
			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$detected = wp_check_filetype_and_ext( $tmp, $name, $allowed );
			if ( ! isset( $allowed[ $extension ] ) || empty( $detected['type'] ) || $detected['type'] !== $allowed[ $extension ] ) {
				$this->remove_files( $dir, $files );
				return new WP_Error( 'invalid_file_type', __( 'Only PDF, Word and Excel files are allowed.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
			}
			$scan = self::scan( $tmp, $name );
			if ( true !== $scan ) {
				$this->remove_files( $dir, $files );
				return is_wp_error( $scan ) ? $scan : new WP_Error( 'file_rejected', __( 'One of the files could not be accepted. Please send a different file.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
			}
			$stored_name = wp_unique_filename( $dir, wp_generate_password( 20, false ) . '.' . $extension );
			if ( ! move_uploaded_file( $tmp, trailingslashit( $dir ) . $stored_name ) ) {
				$this->remove_files( $dir, $files );
				return new WP_Error( 'upload_failed', __( 'Could not store an attachment.', 'elaltillo-bookacall' ), array( 'status' => 500 ) );
			}
			@chmod( trailingslashit( $dir ) . $stored_name, 0600 );
			$files[] = array( 'name' => $name, 'stored' => $stored_name );
		}
		return $files;
	}

	private function remove_files( $dir, $files ) {
		foreach ( $files as $file ) {
			if ( ! empty( $file['stored'] ) ) {
				wp_delete_file( trailingslashit( $dir ) . sanitize_file_name( $file['stored'] ) );
			}
		}
	}

	public function discard( $files ) {
		$this->remove_files( self::storage_dir(), $files );
	}

	public function download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to download booking attachments.', 'elaltillo-bookacall' ), '', array( 'response' => 403 ) );
		}
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$booking_id = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0;
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$booking_files = $booking_id ? get_post_meta( $booking_id, '_elab_files', true ) : array();
		$belongs_to_booking = is_array( $booking_files ) && (bool) array_filter( $booking_files, static function ( $item ) use ( $file ) { return isset( $item['stored'] ) && hash_equals( $item['stored'], $file ); } );
		if ( ! $file || ! $belongs_to_booking || ! wp_verify_nonce( $nonce, 'elab_download_' . $booking_id . '_' . $file ) ) {
			wp_die( esc_html__( 'Invalid download link.', 'elaltillo-bookacall' ), '', array( 'response' => 400 ) );
		}
		$base = realpath( self::storage_dir() );
		$path = $base ? realpath( trailingslashit( $base ) . $file ) : false;
		if ( ! $base || ! $path || 0 !== strpos( $path, trailingslashit( $base ) ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Attachment not found.', 'elaltillo-bookacall' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}

	public function delete_booking_files( $post_id ) {
		if ( ELAB_Post_Types::BOOKING !== get_post_type( $post_id ) ) {
			return;
		}
		$files = get_post_meta( $post_id, '_elab_files', true );
		$base = realpath( self::storage_dir() );
		if ( ! $base || ! is_array( $files ) ) {
			return;
		}
		$this->remove_files( $base, $files );
	}
}
