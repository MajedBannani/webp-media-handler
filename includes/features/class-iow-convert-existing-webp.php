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
			$current_log = $this->settings->get_action_log( 'convert_existing_webp' );
			$converted_count = isset( $current_log['data']['converted'] ) ? $current_log['data']['converted'] : 0;
			$skipped_count = isset( $current_log['data']['skipped'] ) ? $current_log['data']['skipped'] : 0;
			$failed_count = isset( $current_log['data']['failed'] ) ? $current_log['data']['failed'] : 0;

			$message = sprintf(
				/* translators: 1: Converted count, 2: Skipped count, 3: Failed count */
				__( 'Conversion complete! Converted: %1$d, Skipped: %2$d, Failed: %3$d.', 'webp-media-handler' ),
				$converted_count,
				$skipped_count,
				$failed_count
			);

			wp_send_json_success( array(
				'message' => $message,
				'completed' => true,
			) );
		}

		// Convert batch with detailed error tracking
		$converted = 0;
		$skipped = 0;
		$failed = 0;

		foreach ( $images as $attachment_id ) {
			$result = $this->convert_attachment_to_webp( $attachment_id );
			
			if ( is_array( $result ) ) {
				if ( isset( $result['success'] ) && $result['success'] ) {
					$converted++;
				} elseif ( isset( $result['skipped'] ) && $result['skipped'] ) {
					$skipped++;
				} else {
					$failed++;
				}
			} else {
				// Legacy return (bool) - treat as failure for safety
				$failed++;
			}
		}

		// Update log with detailed stats
		$current_log = $this->settings->get_action_log( 'convert_existing_webp' );
		$current_converted = isset( $current_log['data']['converted'] ) ? $current_log['data']['converted'] : 0;
		$current_skipped = isset( $current_log['data']['skipped'] ) ? $current_log['data']['skipped'] : 0;
		$current_failed = isset( $current_log['data']['failed'] ) ? $current_log['data']['failed'] : 0;
		
		$this->settings->log_action( 'convert_existing_webp', array(
			'converted' => $current_converted + $converted,
			'skipped'   => $current_skipped + $skipped,
			'failed'    => $current_failed + $failed,
		) );

		// Return progress with detailed stats
		$total = $this->get_total_convertible_images();
		$processed = $offset + count( $images );

		$message = sprintf(
			/* translators: 1: Processed count, 2: Total count, 3: Converted count, 4: Skipped count, 5: Failed count */
			__( 'Processing... %1$d/%2$d images. Converted: %3$d, Skipped: %4$d, Failed: %5$d.', 'webp-media-handler' ),
			$processed,
			$total,
			$converted,
			$skipped,
			$failed
		);

		wp_send_json_success( array(
			'message' => $message,
			'offset' => $processed,
			'converted' => $converted,
			'skipped' => $skipped,
			'failed' => $failed,
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
	 * @return array Result array with 'success', 'skipped', or 'error' keys.
	 */
	private function convert_attachment_to_webp( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return array(
				'success' => false,
				'error' => __( 'Source file does not exist.', 'webp-media-handler' ),
			);
		}

		// Get file info
		$file_type = wp_check_filetype( $file_path );
		if ( ! in_array( $file_type['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
			return array(
				'success' => false,
				'skipped' => true,
				'error' => __( 'Unsupported file type.', 'webp-media-handler' ),
			);
		}

		// Check if WebP already exists (skip if it does)
		$webp_path = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $file_path );
		if ( file_exists( $webp_path ) && filesize( $webp_path ) > 0 ) {
			return array(
				'success' => false,
				'skipped' => true,
				'error' => __( 'WebP already exists.', 'webp-media-handler' ),
			);
		}

		// Convert to WebP (returns array with 'webp_path' or 'error')
		$result = $this->convert_to_webp( $file_path, $file_type['type'] );

		if ( ! is_array( $result ) || ! isset( $result['webp_path'] ) ) {
			// Conversion failed - original file remains intact
			$error_msg = isset( $result['error'] ) ? $result['error'] : __( 'Conversion failed.', 'webp-media-handler' );
			return array(
				'success' => false,
				'error' => $error_msg,
			);
		}

		$webp_path = $result['webp_path'];

		// CRITICAL: Validate WebP file before any destructive operations
		// Note: Validation already happened in convert_to_webp() before atomic rename,
		// but double-check here as fail-safe
		$validation = $this->validate_webp_file( $webp_path );
		if ( ! $validation['valid'] ) {
			// Delete bad WebP file (fail-safe check), keep original intact
			if ( file_exists( $webp_path ) ) {
				wp_delete_file( $webp_path );
			}
			return array(
				'success' => false,
				'error' => $validation['error'] . ' (fail-safe validation failed)',
			);
		}

		// CRITICAL: Do NOT delete original by default - keep both files
		// Users can manually clean up originals if desired
		// Only delete original if explicitly configured to do so (future enhancement)
		// if ( file_exists( $file_path ) && apply_filters( 'wpmh_delete_original_after_conversion', false ) ) {
		//     wp_delete_file( $file_path );
		// }

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

		return array(
			'success' => true,
		);
	}

	/**
	 * Convert image to WebP format using atomic temp file approach
	 * Uses WP_Image_Editor as primary method, falls back to GD if needed
	 * CRITICAL: Writes to temp file first, validates, then atomically renames
	 *
	 * @param string $file_path Original file path.
	 * @param string $mime_type Original MIME type.
	 * @return array Result array with 'webp_path' on success or 'error' on failure.
	 */
	private function convert_to_webp( $file_path, $mime_type ) {
		if ( ! file_exists( $file_path ) ) {
			return array( 'error' => __( 'Source file does not exist.', 'webp-media-handler' ) );
		}

		// Generate WebP file path
		$webp_path = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $file_path );
		
		// Use temp file for atomic operation (target.webp.tmp)
		$temp_path = $webp_path . '.tmp';

		// Try WP_Image_Editor first (preferred method)
		$editor = wp_get_image_editor( $file_path );
		if ( ! is_wp_error( $editor ) ) {
			$saved = $editor->save( $temp_path, 'image/webp' );
			
			if ( ! is_wp_error( $saved ) && isset( $saved['path'] ) ) {
				// WP_Image_Editor may return a different path (normalized), use it
				$actual_temp_path = $saved['path'];
				
				// If WP_Image_Editor saved to a different path, use that as our temp
				// (This can happen if WP normalizes the path)
				$temp_to_validate = ( $actual_temp_path !== $temp_path && file_exists( $actual_temp_path ) ) 
					? $actual_temp_path 
					: $temp_path;
				
				// CRITICAL: Validate temp file before atomic rename
				$validation = $this->validate_webp_file( $temp_to_validate );
				if ( $validation['valid'] ) {
					// If temp path differs, first move to our standard temp path
					if ( $temp_to_validate !== $temp_path ) {
						if ( ! @rename( $temp_to_validate, $temp_path ) ) {
							// Move failed - clean up
							@unlink( $temp_to_validate );
							return array( 'error' => __( 'Failed to move WebP file to temp path.', 'webp-media-handler' ) );
						}
					}
					
					// Atomic rename: temp -> final
					if ( @rename( $temp_path, $webp_path ) ) {
						return array( 'webp_path' => $webp_path );
					} else {
						// Rename failed - clean up temp
						@unlink( $temp_path );
						return array( 'error' => __( 'Failed to rename temp WebP file to final path.', 'webp-media-handler' ) );
					}
				} else {
					// Validation failed - clean up temp file(s)
					@unlink( $temp_to_validate );
					if ( $temp_to_validate !== $temp_path && file_exists( $temp_path ) ) {
						@unlink( $temp_path );
					}
					return array( 'error' => $validation['error'] );
				}
			}
			
			// WP_Image_Editor failed, fall through to GD
		}

		// Fallback to GD library
		return $this->convert_to_webp_gd( $file_path, $mime_type, $webp_path, $temp_path );
	}

	/**
	 * Convert image to WebP using GD library (fallback method)
	 * CRITICAL: Writes to temp file first, validates, then atomically renames
	 *
	 * @param string $file_path Original file path.
	 * @param string $mime_type Original MIME type.
	 * @param string $webp_path Target WebP file path.
	 * @param string $temp_path Temp file path (defaults to webp_path.tmp if not provided).
	 * @return array Result array with 'webp_path' on success or 'error' on failure.
	 */
	private function convert_to_webp_gd( $file_path, $mime_type, $webp_path, $temp_path = null ) {
		if ( $temp_path === null ) {
			$temp_path = $webp_path . '.tmp';
		}
		// Check GD WebP support
		if ( ! function_exists( 'imagewebp' ) ) {
			return array( 'error' => __( 'GD library WebP support not available.', 'webp-media-handler' ) );
		}

		// Create image resource based on type
		$image = false;
		switch ( $mime_type ) {
			case 'image/jpeg':
				if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
					return array( 'error' => __( 'GD JPEG support not available.', 'webp-media-handler' ) );
				}
				$image = @imagecreatefromjpeg( $file_path );
				if ( ! $image ) {
					return array( 'error' => __( 'Failed to load JPEG image.', 'webp-media-handler' ) );
				}
				break;

			case 'image/png':
				if ( ! function_exists( 'imagecreatefrompng' ) ) {
					return array( 'error' => __( 'GD PNG support not available.', 'webp-media-handler' ) );
				}
				$image = @imagecreatefrompng( $file_path );
				if ( ! $image ) {
					return array( 'error' => __( 'Failed to load PNG image.', 'webp-media-handler' ) );
				}
				// CRITICAL: Preserve PNG transparency correctly
				// Convert palette images to true color for proper alpha support
				if ( imageistruecolor( $image ) === false ) {
					imagepalettetotruecolor( $image );
				}
				imagealphablending( $image, false );
				imagesavealpha( $image, true );
				break;

			default:
				return array( 'error' => sprintf( __( 'Unsupported MIME type: %s', 'webp-media-handler' ), $mime_type ) );
		}

		// PHP 8+ compatibility: GD resources are objects in PHP 8+, resources in PHP 7.x
		if ( ! $image || ( ! is_resource( $image ) && ! is_object( $image ) ) ) {
			return array( 'error' => __( 'Failed to create image resource.', 'webp-media-handler' ) );
		}

		// Convert to WebP with quality 85 - write to TEMP file first
		$quality = 85;
		$success = @imagewebp( $image, $temp_path, $quality );

		// Free memory immediately
		imagedestroy( $image );

		// CRITICAL: Check return value and file existence
		if ( ! $success ) {
			// Clean up any partial temp file
			if ( file_exists( $temp_path ) ) {
				@unlink( $temp_path );
			}
			return array( 'error' => __( 'Failed to write WebP file (imagewebp returned false).', 'webp-media-handler' ) );
		}

		// Verify temp file was created
		if ( ! file_exists( $temp_path ) ) {
			return array( 'error' => __( 'WebP temp file was not created.', 'webp-media-handler' ) );
		}

		// CRITICAL: Validate temp file before atomic rename
		$validation = $this->validate_webp_file( $temp_path );
		if ( ! $validation['valid'] ) {
			// Validation failed - delete temp file, keep original intact
			@unlink( $temp_path );
			return array( 'error' => $validation['error'] );
		}

		// Atomic rename: temp -> final WebP path
		if ( ! @rename( $temp_path, $webp_path ) ) {
			// Rename failed - clean up temp
			@unlink( $temp_path );
			return array( 'error' => __( 'Failed to rename temp WebP file to final path.', 'webp-media-handler' ) );
		}

		return array( 'webp_path' => $webp_path );
	}

	/**
	 * Validate WebP file after creation
	 *
	 * @param string $webp_path WebP file path to validate.
	 * @return array Validation result with 'valid' bool and optional 'error' message.
	 */
	private function validate_webp_file( $webp_path ) {
		// Check 1: File exists
		if ( ! file_exists( $webp_path ) ) {
			return array(
				'valid' => false,
				'error' => __( 'WebP file does not exist after creation.', 'webp-media-handler' ),
			);
		}

		// Check 2: File size > 0
		$file_size = filesize( $webp_path );
		if ( $file_size === false || $file_size <= 0 ) {
			return array(
				'valid' => false,
				'error' => __( 'WebP file is empty (0 bytes).', 'webp-media-handler' ),
			);
		}

		// Check 3: Valid image dimensions (preferred validation)
		$image_info = @getimagesize( $webp_path );
		if ( $image_info === false || ! isset( $image_info[0] ) || ! isset( $image_info[1] ) ) {
			return array(
				'valid' => false,
				'error' => __( 'WebP file is not a valid image (getimagesize failed).', 'webp-media-handler' ),
			);
		}

		// Check 4: MIME type should be image/webp
		if ( isset( $image_info['mime'] ) && $image_info['mime'] !== 'image/webp' ) {
			return array(
				'valid' => false,
				'error' => sprintf( __( 'WebP file has invalid MIME type: %s', 'webp-media-handler' ), $image_info['mime'] ),
			);
		}

		// All validation checks passed
		return array( 'valid' => true );
	}

	/**
	 * Check if WebP is supported (WP_Image_Editor or GD library)
	 *
	 * @return bool
	 */
	private function is_webp_supported() {
		// Check if GD library has WebP support (most reliable check)
		if ( function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagewebp' ) ) {
			return true;
		}

		// Check if Imagick is available and supports WebP
		if ( class_exists( 'Imagick' ) ) {
			$imagick = new Imagick();
			$formats = $imagick->queryFormats();
			if ( in_array( 'WEBP', $formats, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get feature description
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'One-time action to convert all existing JPEG and PNG images in your media library to WebP format. This will replace the original files and update all attachment metadata. This action must be explicitly triggered and cannot be undone automatically. ⚠️ Warning: This modifies your files. We recommend backing up your media library before running this action.', 'webp-media-handler' );
	}
}
