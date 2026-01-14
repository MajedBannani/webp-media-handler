<?php
/**
 * Convert Existing Media Library Images to WebP
 *
 * One-time action to convert existing JPEG/PNG attachments to WebP format.
 * Must be explicitly triggered via admin action button.
 *
 * @package WebPMediaHandler
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert Existing WebP Feature Class
 */
class WPMH_Convert_Existing_WebP {

	/**
	 * Settings manager instance
	 *
	 * @var WPMH_Settings_Manager
	 */
	private $settings;

	/**
	 * Constructor
	 *
	 * @param WPMH_Settings_Manager $settings Settings manager instance.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Handle AJAX action for converting existing images
		add_action( 'wp_ajax_wpmh_convert_existing_webp', array( $this, 'handle_convert_action' ) );
	}

	/**
	 * Handle convert existing images action
	 */
	public function handle_convert_action() {
		// Security checks
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'webp-media-handler' ) ) );
		}

		check_ajax_referer( 'wpmh_convert_existing_webp', 'nonce' );

		// Check if WebP is supported
		if ( ! $this->is_webp_supported() ) {
			wp_send_json_error( array( 'message' => __( 'WebP is not supported by your server.', 'webp-media-handler' ) ) );
		}

		// Get batch parameters
		// WordPress.org compliance: wp_unslash() before sanitization
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$batch_size = 10; // Process 10 images at a time

		// Get images to convert
		$images = $this->get_images_to_convert( $offset, $batch_size );

		if ( empty( $images ) ) {
			// Get total count for final message
			$total = $this->get_total_convertible_images();
			$converted = $this->settings->get_action_log( 'convert_existing_webp' );
			$converted_count = isset( $converted['data']['converted'] ) ? $converted['data']['converted'] : 0;

			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: %d: Number of converted images */
					__( 'Conversion complete! Converted %d images to WebP.', 'webp-media-handler' ),
					$converted_count
				),
				'completed' => true,
			) );
		}

		// Convert batch
		$converted = 0;
		$errors = 0;

		foreach ( $images as $attachment_id ) {
			if ( $this->convert_attachment_to_webp( $attachment_id ) ) {
				$converted++;
			} else {
				$errors++;
			}
		}

		// Update log
		$current_log = $this->settings->get_action_log( 'convert_existing_webp' );
		$current_converted = isset( $current_log['data']['converted'] ) ? $current_log['data']['converted'] : 0;
		$this->settings->log_action( 'convert_existing_webp', array(
			'converted' => $current_converted + $converted,
			'errors'    => isset( $current_log['data']['errors'] ) ? $current_log['data']['errors'] + $errors : $errors,
		) );

		// Return progress
		$total = $this->get_total_convertible_images();
		$processed = $offset + count( $images );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: Processed count, 2: Total count */
				__( 'Processing... %1$d of %2$d images processed.', 'webp-media-handler' ),
				$processed,
				$total
			),
			'offset' => $processed,
			'converted' => $converted,
			'errors' => $errors,
			'completed' => false,
		) );
	}

	/**
	 * Get images that need to be converted
	 *
	 * @param int $offset Offset for pagination.
	 * @param int $limit Number of images to retrieve.
	 * @return array Array of attachment IDs.
	 */
	private function get_images_to_convert( $offset = 0, $limit = 10 ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png' ),
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		$query = new WP_Query( $args );
		return $query->posts;
	}

	/**
	 * Get total count of convertible images
	 *
	 * @return int
	 */
	private function get_total_convertible_images() {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png' ),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$query = new WP_Query( $args );
		return $query->found_posts;
	}

	/**
	 * Convert attachment to WebP
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True on success, false on failure.
	 */
	private function convert_attachment_to_webp( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}

		// Get file info
		$file_type = wp_check_filetype( $file_path );
		if ( ! in_array( $file_type['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
			return false;
		}

		// Convert to WebP
		$webp_path = $this->convert_to_webp( $file_path, $file_type['type'] );

		if ( ! $webp_path || ! file_exists( $webp_path ) ) {
			return false;
		}

		// Delete original file
		// WordPress.org compliance: Proper error handling instead of error suppression
		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}

		// Update attachment metadata
		$attachment_data = array(
			'ID'             => $attachment_id,
			'post_mime_type' => 'image/webp',
		);
		wp_update_post( $attachment_data );

		// Update attachment meta
		$upload_dir = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'] . '/', '', $webp_path );
		update_attached_file( $attachment_id, $relative_path );

		// Regenerate attachment metadata
		$metadata = wp_generate_attachment_metadata( $attachment_id, $webp_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return true;
	}

	/**
	 * Convert image to WebP format
	 *
	 * @param string $file_path Original file path.
	 * @param string $mime_type Original MIME type.
	 * @return string|false WebP file path on success, false on failure.
	 */
	private function convert_to_webp( $file_path, $mime_type ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		// Create image resource based on type
		// WordPress.org compliance: Proper error handling instead of error suppression
		$image = false;
		switch ( $mime_type ) {
			case 'image/jpeg':
				if ( function_exists( 'imagecreatefromjpeg' ) ) {
					$image = imagecreatefromjpeg( $file_path );
				}
				break;
			case 'image/png':
				if ( function_exists( 'imagecreatefrompng' ) ) {
					$image = imagecreatefrompng( $file_path );
					// Preserve transparency for PNG
					if ( $image ) {
						imagealphablending( $image, false );
						imagesavealpha( $image, true );
					}
				}
				break;
			default:
				return false;
		}

		// WordPress.org compliance: PHP 8+ compatibility - GD resources are objects in PHP 8+, resources in PHP 7.x
		if ( ! $image || ( ! is_resource( $image ) && ! is_object( $image ) ) ) {
			return false;
		}

		// Generate WebP file path
		$webp_path = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $file_path );

		// Convert to WebP with quality 85
		// WordPress.org compliance: Proper error handling instead of error suppression
		$quality = 85;
		if ( ! function_exists( 'imagewebp' ) ) {
			imagedestroy( $image );
			return false;
		}
		$success = imagewebp( $image, $webp_path, $quality );

		// Free memory
		imagedestroy( $image );

		if ( ! $success || ! file_exists( $webp_path ) ) {
			return false;
		}

		return $webp_path;
	}

	/**
	 * Check if WebP is supported by GD library
	 *
	 * @return bool
	 */
	private function is_webp_supported() {
		return function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagewebp' );
	}

	/**
	 * Get feature description
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'One-time action to convert all existing JPEG and PNG images in your media library to WebP format. This will replace the original files and update all attachment metadata. This action must be explicitly triggered and cannot be undone automatically.', 'webp-media-handler' );
	}
}
