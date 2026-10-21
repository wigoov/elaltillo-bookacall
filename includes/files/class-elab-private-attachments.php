<?php
/** Private attachment storage and authorized download handling. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Private_Attachments {
	public function __construct() {
		add_action( 'admin_post_elab_download', array( $this, 'download' ) );
		add_action( 'before_delete_post', array( $this, 'delete_booking_files' ) );
	}

	public function upload() {
		if ( empty( $_FILES['attachments'] ) || empty( $_FILES['attachments']['name'][0] ) ) {
			return array();
		}
		$upload = $_FILES['attachments'];
		$count = count( (array) $upload['name'] );
		if ( $count > 3 ) {
			return new WP_Error( 'too_many_files', __( 'You can attach up to 3 files.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
		}
		$allowed = array( 'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		$dir = trailingslashit( WP_CONTENT_DIR ) . 'uploads/elaltillo-bookacall-private';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'upload_failed', __( 'Could not store attachments.', 'elaltillo-bookacall' ), array( 'status' => 500 ) );
		}
		$this->protect_directory( $dir );
		$files = array();
		for ( $i = 0; $i < $count; $i++ ) {
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
			$stored_name = wp_unique_filename( $dir, wp_generate_password( 20, false ) . '.' . $extension );
			if ( ! move_uploaded_file( $tmp, trailingslashit( $dir ) . $stored_name ) ) {
				$this->remove_files( $dir, $files );
				return new WP_Error( 'upload_failed', __( 'Could not store an attachment.', 'elaltillo-bookacall' ), array( 'status' => 500 ) );
			}
			$files[] = array( 'name' => $name, 'stored' => $stored_name );
		}
		return $files;
	}

	private function protect_directory( $dir ) {
		if ( file_exists( $dir . '/.htaccess' ) ) {
			return;
		}
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		file_put_contents( $dir . '/web.config', '<configuration><system.webServer><security><authorization><remove users="*" roles="" verbs="" /><add accessType="Deny" users="*" /></authorization></security></system.webServer></configuration>' );
		file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
	}

	private function remove_files( $dir, $files ) {
		foreach ( $files as $file ) {
			if ( ! empty( $file['stored'] ) ) {
				wp_delete_file( trailingslashit( $dir ) . sanitize_file_name( $file['stored'] ) );
			}
		}
	}

	public function discard( $files ) {
		$this->remove_files( trailingslashit( WP_CONTENT_DIR ) . 'uploads/elaltillo-bookacall-private', $files );
	}

	public function download() {
		if ( ! current_user_can( 'edit_posts' ) ) {
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
		$base = realpath( trailingslashit( WP_CONTENT_DIR ) . 'uploads/elaltillo-bookacall-private' );
		$path = realpath( trailingslashit( $base ) . $file );
		if ( ! $base || ! $path || 0 !== strpos( $path, trailingslashit( $base ) ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Attachment not found.', 'elaltillo-bookacall' ), '', array( 'response' => 404 ) );
		}
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
		$base = realpath( trailingslashit( WP_CONTENT_DIR ) . 'uploads/elaltillo-bookacall-private' );
		if ( ! $base || ! is_array( $files ) ) {
			return;
		}
		$this->remove_files( $base, $files );
	}
}
