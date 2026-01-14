<?php
/**
 * Auto Convert Newly Uploaded Images to WebP Feature
 *
 * When enabled, automatically converts newly uploaded JPEG/PNG images to WebP format.
 * Only affects new uploads, never touches existing media.
 *
 * @package WebPMediaHandler
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto WebP Convert Feature Class
 */
class WPMH_Auto_WebP_Convert {

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
		// Only hook if feature is enabled
		if ( $this->settings->get( 'auto_webp_convert' ) ) {
			// Check if WebP is supported
			if ( $this->is_webp_supported() ) {
				add_filter( 'wp_handle_upload', array( $this, 'convert_uploaded_image' ), 10, 2 );
				// Ensure attachment post is updated with correct MIME type after creation
				add_action( 'add_attachment', array( $this, 'update_attachment_after_creation' ), 10, 1 );
			}
		}
	}

	/**
	 * Check if WebP is supported by GD library
	 *
	 * @return bool
	 */
	private function is_webp_supported() {
		if ( ! function_exists( 'imagecreatefromjpeg' ) || ! function_exists( 'imagewebp' ) ) {
			return false;
		}

		// Check if imagewebp function exists and works
		return function_exists( 'imagewebp' );
	}

	/**
	 * Convert uploaded image to WebP
	 *
	 * @param array $upload Array of upload data.
	 * @param string $context Upload context.
	 * @return array
	 */
	public function convert_uploaded_image( $upload, $context = 'upload' ) {
		// Only process in upload context
		if ( 'upload' !== $context ) {
			return $upload;
		}

		// Check if file is an image
		if ( ! isset( $upload['file'] ) || ! file_exists( $upload['file'] ) ) {
			return $upload;
		}

		// Get file info
		$file_path = $upload['file'];
		$file_type = wp_check_filetype( $file_path );

		// Only process JPEG and PNG
		if ( ! in_array( $file_type['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
			return $upload;
		}

		// Convert to WebP
		$webp_path = $this->convert_to_webp( $file_path, $file_type['type'] );

		if ( $webp_path && file_exists( $webp_path ) ) {
			// Delete original file
			// WordPress.org compliance: Proper error handling instead of error suppression
			if ( file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}

			// Update upload data
			$upload['file']   = $webp_path;
			$upload['type']   = 'image/webp';
			$upload['url']    = str_replace( basename( $upload['url'] ), basename( $webp_path ), $upload['url'] );
		}

		return $upload;
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

		// Convert to WebP with quality 85 (good balance)
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
	 * Update attachment MIME type after creation
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function update_attachment_after_creation( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return;
		}

		// Check if file is WebP
		if ( preg_match( '/\.webp$/i', $file_path ) ) {
			// Update attachment post MIME type
			wp_update_post( array(
				'ID'             => $attachment_id,
				'post_mime_type' => 'image/webp',
			) );

			// Regenerate attachment metadata
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
	}

	/**
	 * Get feature description
	 *
	 * @return string
	 */
	public function get_description() {
		$supported = $this->is_webp_supported() ? __( 'WebP is supported.', 'webp-media-handler' ) : __( 'WebP is not supported by your server.', 'webp-media-handler' );
		return sprintf(
			/* translators: %s: WebP support status */
			__( 'Automatically converts newly uploaded JPEG and PNG images to WebP format. Only affects new uploads, never touches existing media. %s', 'webp-media-handler' ),
			$supported
		);
	}
}
