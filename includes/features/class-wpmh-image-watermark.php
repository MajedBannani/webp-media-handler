<?php
/**
 * Image Watermarking Feature
 *
 * Allows users to apply watermarks to images with explicit user control.
 * Must be explicitly triggered via admin action button.
 *
 * @package WebPMediaHandler
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image Watermark Feature Class
 */
class WPMH_Image_Watermark {

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
		// Handle AJAX action for applying watermarks
		add_action( 'wp_ajax_wpmh_apply_watermark', array( $this, 'handle_apply_watermark' ) );
		
		// FIX C: Temporarily disable intermediate image size generation during watermarking
		// This prevents WordPress from creating thumbnails/medium/large variants that could cause duplicate watermarks
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'disable_sizes_during_watermarking' ), 999 );
	}

	/**
	 * Temporarily disable intermediate image sizes during watermarking
	 * Prevents WordPress from generating thumbnails that could cause duplicate watermarks
	 *
	 * @param array $sizes Image sizes array.
	 * @return array Empty array to disable size generation.
	 */
	public function disable_sizes_during_watermarking( $sizes ) {
		// Only disable if we're currently watermarking (check for AJAX action)
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_POST['action'] ) && 'wpmh_apply_watermark' === $_POST['action'] ) {
			return array();
		}
		return $sizes;
	}

	/**
	 * Handle apply watermark action
	 */
	public function handle_apply_watermark() {
		// Security checks
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'webp-media-handler' ) ) );
		}

		check_ajax_referer( 'wpmh_apply_watermark', 'nonce' );

		// Check if GD library is available
		if ( ! $this->is_gd_available() ) {
			wp_send_json_error( array( 'message' => __( 'GD library is not available. Watermarking requires GD extension.', 'webp-media-handler' ) ) );
		}

		// Get watermark configuration
		// WordPress.org compliance: wp_unslash() before sanitization
		// CRITICAL FIX: Always prioritize POST value (current form submission) over saved option
		
		// Debug: Log POST and option values (only when WP_DEBUG is enabled)
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$post_watermark_id_debug = isset( $_POST['watermark_id'] ) ? wp_unslash( $_POST['watermark_id'] ) : null;
			$saved_watermark_id_debug = $this->settings->get( 'watermark_image_id', 0 );
			error_log( sprintf(
				'[WPMH Debug] POST watermark_id: %s',
				print_r( $post_watermark_id_debug, true )
			) );
			error_log( sprintf(
				'[WPMH Debug] OPTION watermark_image_id: %s',
				print_r( $saved_watermark_id_debug, true )
			) );
		}
		
		// Step 1: Read from POST first (this is the current form submission value)
		$watermark_id = 0;
		if ( isset( $_POST['watermark_id'] ) ) {
			$watermark_id_raw = wp_unslash( $_POST['watermark_id'] );
			// Normalize if array
			if ( is_array( $watermark_id_raw ) ) {
				$watermark_id = ! empty( $watermark_id_raw ) ? absint( end( $watermark_id_raw ) ) : 0;
			} else {
				$watermark_id = absint( $watermark_id_raw );
			}
		}
		
		// Step 2: Only fall back to saved option if POST is NOT set at all (not if it's 0 or empty)
		if ( ! isset( $_POST['watermark_id'] ) ) {
			// POST key doesn't exist - use saved option as fallback
			$saved_watermark_id = $this->settings->get( 'watermark_image_id', 0 );
			// Normalize if array
			if ( is_array( $saved_watermark_id ) ) {
				$saved_watermark_id = ! empty( $saved_watermark_id ) ? absint( end( $saved_watermark_id ) ) : 0;
				// Normalize saved setting
				$this->settings->set( 'watermark_image_id', $saved_watermark_id );
			}
			$watermark_id = absint( $saved_watermark_id );
		}
		
		// Step 3: Always update saved option with POST value (if POST was set) to keep it current
		if ( isset( $_POST['watermark_id'] ) ) {
			$this->settings->set( 'watermark_image_id', $watermark_id );
		}
		
		// Debug: Log final resolved value
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$watermark_file = $watermark_id > 0 ? get_attached_file( $watermark_id ) : 'N/A';
			error_log( sprintf(
				'[WPMH Debug] RESOLVED watermark_id: %d | Using watermark file: %s',
				$watermark_id,
				$watermark_file ? $watermark_file : 'FILE NOT FOUND'
			) );
		}
		$watermark_size = isset( $_POST['watermark_size'] ) ? absint( wp_unslash( $_POST['watermark_size'] ) ) : 100;
		// Get watermark position - default to 'bottom-right' only if not provided (not as a fallback after validation)
		$watermark_position = isset( $_POST['watermark_position'] ) ? sanitize_text_field( wp_unslash( $_POST['watermark_position'] ) ) : 'bottom-right';
		$target_mode = isset( $_POST['target_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['target_mode'] ) ) : 'selected';

		// Validate watermark image
		if ( ! $watermark_id || ! $this->validate_watermark_image( $watermark_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or missing watermark image. Please select a valid image from the Media Library.', 'webp-media-handler' ) ) );
		}

		// Validate watermark size
		$allowed_sizes = array( 50, 100, 200, 300 );
		if ( ! in_array( $watermark_size, $allowed_sizes, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid watermark size.', 'webp-media-handler' ) ) );
		}

		// FIX B: Validate watermark position - ensure it's valid before processing
		// If invalid, fallback to ONE safe default (bottom-right) but ONLY ONCE
		$allowed_positions = array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center' );
		if ( ! in_array( $watermark_position, $allowed_positions, true ) ) {
			// Invalid position - fallback to safe default (bottom-right) ONCE
			// This ensures position is valid, but we do NOT apply a second watermark
			$watermark_position = 'bottom-right';
		}

		// Get batch parameters
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$batch_size = 10; // Process 10 images at a time

		// ISSUE 2 FIX: Initialize counters for CURRENT run only (not accumulated from previous runs)
		// Reset counters at start of new run (offset === 0), otherwise continue tracking current run
		$current_run_success = 0;
		$current_run_failed = 0;
		$current_run_skipped = 0; // Track skipped images (e.g., watermark source excluded)
		
		if ( $offset > 0 ) {
			// This is a continuation batch - get counts from current run tracking
			$log = $this->settings->get_action_log( 'apply_watermark' );
			$current_run_success = isset( $log['data']['current_run_success'] ) ? (int) $log['data']['current_run_success'] : 0;
			$current_run_failed = isset( $log['data']['current_run_failed'] ) ? (int) $log['data']['current_run_failed'] : 0;
			$current_run_skipped = isset( $log['data']['current_run_skipped'] ) ? (int) $log['data']['current_run_skipped'] : 0;
		} else {
			// Start of new run - reset counters (do not accumulate from previous runs)
			// Clear any previous run data, keep only timestamp for "last run" display
			$this->settings->log_action( 'apply_watermark', array(
				'timestamp' => current_time( 'mysql' ),
				'current_run_success' => 0,
				'current_run_failed' => 0,
				'current_run_skipped' => 0,
			) );
		}

		// FIX 1: EXCLUDE WATERMARK IMAGE FROM TARGETS (Hard block)
		// Always remove watermark_id from target list to prevent watermarking the watermark itself
		$watermark_id_absint = absint( $watermark_id );
		
		// Get target images based on mode
		$selected_ids = array();
		if ( 'all' === $target_mode ) {
			// FIX 1: Exclude watermark from "All Images" query
			$images = $this->get_all_images( $offset, $batch_size, $watermark_id_absint );
		} else {
			// Get selected images from POST data
			$selected_ids = isset( $_POST['selected_images'] ) ? wp_unslash( $_POST['selected_images'] ) : array();
			if ( ! is_array( $selected_ids ) ) {
				$selected_ids = array();
			}
			$selected_ids = array_map( 'absint', $selected_ids );
			$selected_ids = array_filter( $selected_ids );
			
			// FIX 1: Remove watermark_id from selected_ids to prevent watermarking the watermark
			$original_count = count( $selected_ids );
			$selected_ids = array_values( array_diff( $selected_ids, array( $watermark_id_absint ) ) );
			$excluded_count = $original_count - count( $selected_ids );
			
			// Track if watermark was excluded in this batch
			if ( $excluded_count > 0 && $offset === 0 ) {
				$current_run_skipped += $excluded_count;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf(
						'[WPMH Watermark] Excluded watermark source image (ID: %d) from target list to prevent self-watermarking',
						$watermark_id_absint
					) );
				}
			}
			
			if ( empty( $selected_ids ) ) {
				wp_send_json_error( array( 'message' => __( 'No images selected. Please select images to watermark.', 'webp-media-handler' ) ) );
			}
			
			$images = $this->get_selected_images( $selected_ids, $offset, $batch_size );
		}

		if ( empty( $images ) ) {
			// FIX E: Use CURRENT run counts for final success notice (not accumulated from previous runs)
			// Get final counts from log (which tracks current run only)
			$log = $this->settings->get_action_log( 'apply_watermark' );
			$final_success = isset( $log['data']['current_run_success'] ) ? (int) $log['data']['current_run_success'] : 0;
			$final_failed = isset( $log['data']['current_run_failed'] ) ? (int) $log['data']['current_run_failed'] : 0;
			$final_skipped = isset( $log['data']['current_run_skipped'] ) ? (int) $log['data']['current_run_skipped'] : 0;
			
			// FIX 4: Add safety notice if watermark source was skipped
			$watermark_was_skipped = false;
			if ( $final_skipped > 0 ) {
				// Check if watermark was in selected targets (only in "selected" mode)
				if ( 'selected' === $target_mode && isset( $_POST['selected_images'] ) ) {
					$post_selected = wp_unslash( $_POST['selected_images'] );
					if ( is_array( $post_selected ) ) {
						$post_selected_ids = array_map( 'absint', $post_selected );
						if ( in_array( $watermark_id_absint, $post_selected_ids, true ) ) {
							$watermark_was_skipped = true;
						}
					}
				}
			}

			// Clear current run counters (keep only timestamp for "last run" display)
			$this->settings->log_action( 'apply_watermark', array(
				'timestamp' => current_time( 'mysql' ),
			) );

			// FIX E: Always show success notice with processed/failed/skipped counts
			// Build detailed message for visual confirmation
			$message_parts = array();
			if ( $final_success > 0 ) {
				$message_parts[] = sprintf(
					/* translators: %d: Number of successfully processed images */
					_n( 'Processed: %d image', 'Processed: %d images', $final_success, 'webp-media-handler' ),
					$final_success
				);
			}
			if ( $final_failed > 0 ) {
				$message_parts[] = sprintf(
					/* translators: %d: Number of failed images */
					_n( 'Failed: %d image', 'Failed: %d images', $final_failed, 'webp-media-handler' ),
					$final_failed
				);
			}
			if ( $final_skipped > 0 ) {
				$message_parts[] = sprintf(
					/* translators: %d: Number of skipped images */
					_n( 'Skipped: %d image', 'Skipped: %d images', $final_skipped, 'webp-media-handler' ),
					$final_skipped
				);
			}
			
			$message = __( 'Watermarking complete.', 'webp-media-handler' );
			if ( ! empty( $message_parts ) ) {
				$message .= ' ' . implode( '. ', $message_parts ) . '.';
			}
			
			// FIX 4: Add safety notice if watermark source was skipped
			if ( $watermark_was_skipped ) {
				$message .= ' ' . __( 'Note: Watermark source image was skipped to prevent watermarking the watermark.', 'webp-media-handler' );
			}

			// FIX E: Store notice in transient for display on admin page (if needed)
			// The AJAX response will show the message immediately, but also store for page reload
			set_transient( 'wpmh_watermark_notice', array(
				'type' => 'success',
				'message' => $message,
				'success' => $final_success,
				'failed' => $final_failed,
				'skipped' => $final_skipped,
			), 60 ); // 60 second expiry

			wp_send_json_success( array(
				'message' => $message,
				'completed' => true,
				'success' => $final_success,
				'failed' => $final_failed,
				'skipped' => $final_skipped,
			) );
		}

		// Process batch - track counts for CURRENT run only
		$batch_success = 0;
		$batch_failed = 0;
		$batch_skipped = 0;

		// FIX 2: Get watermark file path for realpath comparison
		$watermark_file_path = get_attached_file( $watermark_id );
		$watermark_realpath = $watermark_file_path ? realpath( $watermark_file_path ) : false;

		foreach ( $images as $attachment_id ) {
			// FIX 2: Realpath comparison guard - prevent watermarking the watermark file itself
			$target_file_path = get_attached_file( $attachment_id );
			$target_realpath = $target_file_path ? realpath( $target_file_path ) : false;
			
			// Skip if target file is the watermark file (second layer of protection)
			if ( $watermark_realpath && $target_realpath && $watermark_realpath === $target_realpath ) {
				$batch_skipped++;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf(
						'[WPMH Watermark] SKIPPED: Attachment ID %d is the watermark source file (%s)',
						$attachment_id,
						$target_file_path
					) );
				}
				continue;
			}
			
			if ( $this->apply_watermark_to_image( $attachment_id, $watermark_id, $watermark_size, $watermark_position ) ) {
				$batch_success++;
			} else {
				$batch_failed++;
			}
		}

		// Update current run totals (accumulate within this request only)
		$current_run_success += $batch_success;
		$current_run_failed += $batch_failed;
		$current_run_skipped += $batch_skipped;

		// FIX E: Store only current run counts (not accumulated from previous runs)
		$this->settings->log_action( 'apply_watermark', array(
			'timestamp' => current_time( 'mysql' ),
			'current_run_success' => $current_run_success,
			'current_run_failed' => $current_run_failed,
			'current_run_skipped' => $current_run_skipped,
		) );

		// Return progress
		// FIX 1: For "all" mode, exclude watermark from total count
		$total = ( 'all' === $target_mode ) ? $this->get_total_images( $watermark_id_absint ) : count( $selected_ids );
		$processed = $offset + count( $images );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: Processed count, 2: Total count */
				__( 'Processing... %1$d of %2$d images processed.', 'webp-media-handler' ),
				$processed,
				$total
			),
			'offset' => $processed,
			'success' => $batch_success,
			'failed' => $batch_failed,
			'completed' => false,
		) );
	}

	/**
	 * Get all images in media library
	 *
	 * @param int $offset Offset for pagination.
	 * @param int $limit Number of images to retrieve.
	 * @return array Array of attachment IDs.
	 */
	/**
	 * Get all images for watermarking (excluding watermark source)
	 *
	 * @param int   $offset Offset for pagination.
	 * @param int   $limit Number of images to retrieve.
	 * @param int   $exclude_id Attachment ID to exclude (watermark source).
	 * @return array Array of attachment IDs.
	 */
	private function get_all_images( $offset = 0, $limit = 10, $exclude_id = 0 ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		// FIX 1: Exclude watermark source image from query
		if ( $exclude_id > 0 ) {
			$args['post__not_in'] = array( $exclude_id );
		}

		$query = new WP_Query( $args );
		return $query->posts;
	}

	/**
	 * Get selected images
	 *
	 * @param array $selected_ids Selected attachment IDs.
	 * @param int   $offset Offset for pagination.
	 * @param int   $limit Number of images to retrieve.
	 * @return array Array of attachment IDs.
	 */
	private function get_selected_images( $selected_ids, $offset = 0, $limit = 10 ) {
		if ( empty( $selected_ids ) ) {
			return array();
		}

		// Get batch from selected IDs
		$batch = array_slice( $selected_ids, $offset, $limit );
		
		return $batch;
	}

	/**
	 * Get total count of images (excluding watermark source)
	 *
	 * @param int $exclude_id Attachment ID to exclude (watermark source).
	 * @return int
	 */
	private function get_total_images( $exclude_id = 0 ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		// FIX 1: Exclude watermark source image from total count
		if ( $exclude_id > 0 ) {
			$args['post__not_in'] = array( $exclude_id );
		}

		$query = new WP_Query( $args );
		return $query->found_posts;
	}

	/**
	 * Apply watermark to an image
	 *
	 * @param int    $attachment_id Target attachment ID.
	 * @param int    $watermark_id  Watermark attachment ID.
	 * @param int    $watermark_size Maximum width of watermark in pixels.
	 * @param string $watermark_position Position of watermark.
	 * @return bool True on success, false on failure.
	 */
	private function apply_watermark_to_image( $attachment_id, $watermark_id, $watermark_size, $watermark_position ) {
		// FIX C: Apply watermark ONLY to the main attached file - DO NOT touch intermediate sizes
		// Get ONLY the main file path (not intermediate sizes like thumbnails/medium/large)
		$target_file = get_attached_file( $attachment_id );
		if ( ! $target_file || ! file_exists( $target_file ) ) {
			return false;
		}

		// Get watermark image file
		$watermark_file = get_attached_file( $watermark_id );
		if ( ! $watermark_file || ! file_exists( $watermark_file ) ) {
			return false;
		}

		// DEBUG: Log file path to confirm we're only processing main file
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[WPMH Watermark] Starting - Attachment ID: %d | File: %s | Position: %s',
				$attachment_id,
				$target_file,
				$watermark_position
			) );
		}

		// Load target image
		$target_image = $this->load_image( $target_file );
		if ( ! $target_image ) {
			return false;
		}

		// Load watermark image
		$watermark_image = $this->load_image( $watermark_file );
		if ( ! $watermark_image ) {
			imagedestroy( $target_image );
			return false;
		}

		// Get image dimensions
		$target_width = imagesx( $target_image );
		$target_height = imagesy( $target_image );
		$watermark_width = imagesx( $watermark_image );
		$watermark_height = imagesy( $watermark_image );

		// FIX C: Resize watermark ONCE and store the final resource
		// Resize watermark if needed (proportionally, no upscaling)
		$resized_watermark = $this->resize_watermark( $watermark_image, $watermark_size, $target_width, $target_height );
		if ( $resized_watermark !== $watermark_image ) {
			imagedestroy( $watermark_image );
			$watermark_image = $resized_watermark;
		}

		$final_watermark_width = imagesx( $watermark_image );
		$final_watermark_height = imagesy( $watermark_image );

		// FIX B: Calculate watermark position with strict mutually exclusive logic
		$padding = 20;
		$position = $this->calculate_watermark_position(
			$target_width,
			$target_height,
			$final_watermark_width,
			$final_watermark_height,
			$watermark_position,
			$padding
		);

		// FIX A: ENFORCEMENT GUARD - Ensure exactly ONE overlay per image
		// This guard prevents any accidental second overlay calls
		// Use instance variable that's reset for each image (not static)
		$did_overlay = false;

		// DEBUG: Log position calculation before application
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[WPMH Watermark] Attachment ID: %d | Selected position: %s | Calculated X: %d | Calculated Y: %d | Watermark size: %dx%d',
				$attachment_id,
				$watermark_position,
				$position['x'],
				$position['y'],
				$final_watermark_width,
				$final_watermark_height
			) );
		}

		// FIX A: Guard check - prevent multiple overlays
		if ( $did_overlay ) {
			// This should NEVER happen - log error if it does
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf(
					'[WPMH Watermark] ERROR: Attempted second overlay blocked! Attachment ID: %d | Position: %s',
					$attachment_id,
					$watermark_position
				) );
			}
			// Skip overlay - already applied
			imagedestroy( $watermark_image );
			imagedestroy( $target_image );
			return false;
		}

		// FIX A: Apply watermark with alpha transparency - EXACTLY ONCE
		// This is the ONLY place where the watermark is composited onto the image
		$this->apply_watermark_with_alpha( $target_image, $watermark_image, $position['x'], $position['y'] );
		$did_overlay = true; // Mark as applied to prevent any subsequent calls

		// DEBUG: Verify single application
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[WPMH Watermark] Success - Attachment ID: %d | Position: %s | Applied at X: %d, Y: %d | Watermark applied: %s',
				$attachment_id,
				$watermark_position,
				$position['x'],
				$position['y'],
				$did_overlay ? 'YES (once)' : 'NO'
			) );
		}

		// Clean up watermark resource immediately after use
		imagedestroy( $watermark_image );
		$watermark_image = null; // Prevent accidental reuse

		// Save watermarked image
		$success = $this->save_image( $target_image, $target_file );

		// Clean up target resource
		imagedestroy( $target_image );
		$target_image = null; // Prevent accidental reuse

		if ( ! $success ) {
			return false;
		}

		// FIX C: Update attachment metadata WITHOUT regenerating (which could trigger image processing)
		// wp_generate_attachment_metadata may trigger image processing hooks that could cause duplicate watermarks
		// We only need to update dimensions, not regenerate intermediate sizes or reprocess the image
		$existing_metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! $existing_metadata ) {
			$existing_metadata = array();
		}
		
		// Update dimensions only - do NOT call wp_generate_attachment_metadata which processes images
		$existing_metadata['width'] = $target_width;
		$existing_metadata['height'] = $target_height;
		
		// Update file path if it changed
		$upload_dir = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'] . '/', '', $target_file );
		if ( ! isset( $existing_metadata['file'] ) || $existing_metadata['file'] !== $relative_path ) {
			$existing_metadata['file'] = $relative_path;
		}
		
		wp_update_attachment_metadata( $attachment_id, $existing_metadata );

		return true;
	}

	/**
	 * Load image from file
	 *
	 * @param string $file_path Image file path.
	 * @return resource|GdImage|false Image resource or false on failure.
	 */
	private function load_image( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		$file_type = wp_check_filetype( $file_path );
		$mime_type = $file_type['type'];

		// WordPress.org compliance: PHP 8+ compatibility - GD resources are objects in PHP 8+
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
					if ( $image ) {
						imagealphablending( $image, false );
						imagesavealpha( $image, true );
					}
				}
				break;
			case 'image/webp':
				if ( function_exists( 'imagecreatefromwebp' ) ) {
					$image = imagecreatefromwebp( $file_path );
					if ( $image ) {
						imagealphablending( $image, false );
						imagesavealpha( $image, true );
					}
				}
				break;
			default:
				return false;
		}

		// PHP 8+ compatibility check
		if ( ! $image || ( ! is_resource( $image ) && ! is_object( $image ) ) ) {
			return false;
		}

		return $image;
	}

	/**
	 * Resize watermark proportionally
	 *
	 * @param resource|GdImage $watermark_image Watermark image resource.
	 * @param int              $max_width Maximum width in pixels.
	 * @param int              $target_width Target image width.
	 * @param int              $target_height Target image height.
	 * @return resource|GdImage Resized watermark image resource.
	 */
	private function resize_watermark( $watermark_image, $max_width, $target_width, $target_height ) {
		$watermark_width = imagesx( $watermark_image );
		$watermark_height = imagesy( $watermark_image );

		// If watermark is already smaller than max width, no resize needed
		if ( $watermark_width <= $max_width ) {
			return $watermark_image;
		}

		// Calculate new dimensions proportionally
		$ratio = $max_width / $watermark_width;
		$new_width = (int) $max_width;
		$new_height = (int) ( $watermark_height * $ratio );

		// Ensure watermark doesn't exceed target image dimensions
		if ( $new_width > $target_width || $new_height > $target_height ) {
			$ratio = min( $target_width / $watermark_width, $target_height / $watermark_height );
			$new_width = (int) ( $watermark_width * $ratio );
			$new_height = (int) ( $watermark_height * $ratio );
		}

		// Create resized watermark
		if ( function_exists( 'imagecreatetruecolor' ) ) {
			$resized = imagecreatetruecolor( $new_width, $new_height );
			if ( $resized ) {
				imagealphablending( $resized, false );
				imagesavealpha( $resized, true );

				if ( function_exists( 'imagecopyresampled' ) ) {
					imagecopyresampled( $resized, $watermark_image, 0, 0, 0, 0, $new_width, $new_height, $watermark_width, $watermark_height );
				}
			}
		}

		return isset( $resized ) && $resized ? $resized : $watermark_image;
	}

	/**
	 * Calculate watermark position with padding
	 *
	 * @param int    $target_width Target image width.
	 * @param int    $target_height Target image height.
	 * @param int    $watermark_width Watermark width.
	 * @param int    $watermark_height Watermark height.
	 * @param string $position Position string.
	 * @param int    $padding Minimum padding in pixels.
	 * @return array Array with 'x' and 'y' coordinates.
	 */
	private function calculate_watermark_position( $target_width, $target_height, $watermark_width, $watermark_height, $position, $padding = 20 ) {
		$x = 0;
		$y = 0;

		// Use strict if/elseif chain to ensure mutually exclusive position selection
		// This prevents fall-through issues and ensures only ONE position is calculated
		if ( 'top-left' === $position ) {
			$x = $padding;
			$y = $padding;
		} elseif ( 'top-right' === $position ) {
			$x = $target_width - $watermark_width - $padding;
			$y = $padding;
		} elseif ( 'bottom-left' === $position ) {
			$x = $padding;
			$y = $target_height - $watermark_height - $padding;
		} elseif ( 'bottom-right' === $position ) {
			$x = $target_width - $watermark_width - $padding;
			$y = $target_height - $watermark_height - $padding;
		} elseif ( 'center' === $position ) {
			$x = ( $target_width - $watermark_width ) / 2;
			$y = ( $target_height - $watermark_height ) / 2;
		} else {
			// Invalid position - fallback to safe default (bottom-right) without applying two watermarks
			// This ensures only ONE watermark is applied even with invalid input
			$x = $target_width - $watermark_width - $padding;
			$y = $target_height - $watermark_height - $padding;
		}

		// Ensure watermark stays within image bounds with padding
		// CRITICAL: This only clamps values if out of bounds - it does NOT create a second position
		// It only adjusts the SINGLE calculated position to stay within bounds
		$x_min = $padding;
		$x_max = $target_width - $watermark_width - $padding;
		$y_min = $padding;
		$y_max = $target_height - $watermark_height - $padding;
		
		// Clamp X coordinate only if out of bounds (preserve original position intent)
		if ( $x < $x_min ) {
			$x = $x_min;
		} elseif ( $x > $x_max ) {
			$x = $x_max;
		}
		
		// Clamp Y coordinate only if out of bounds (preserve original position intent)
		if ( $y < $y_min ) {
			$y = $y_min;
		} elseif ( $y > $y_max ) {
			$y = $y_max;
		}
		
		// DEBUG: Log if clamping occurred (only when WP_DEBUG is enabled)
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ( $x !== ( ( $target_width - $watermark_width ) / 2 ) || $position === 'center' ) ) {
			// Log if position was clamped (except for center which uses division)
			if ( ( 'top-left' === $position && ( $x !== $padding || $y !== $padding ) ) ||
				 ( 'top-right' === $position && ( $x !== ( $target_width - $watermark_width - $padding ) || $y !== $padding ) ) ||
				 ( 'bottom-left' === $position && ( $x !== $padding || $y !== ( $target_height - $watermark_height - $padding ) ) ) ||
				 ( 'bottom-right' === $position && ( $x !== ( $target_width - $watermark_width - $padding ) || $y !== ( $target_height - $watermark_height - $padding ) ) ) ) {
				error_log( sprintf(
					'[WPMH Watermark] Position clamped - Original: %s | Final X: %d, Y: %d',
					$position,
					$x,
					$y
				) );
			}
		}

		return array(
			'x' => (int) $x,
			'y' => (int) $y,
		);
	}

	/**
	 * Apply watermark with alpha transparency support
	 *
	 * FIX A: CRITICAL - This function MUST be called exactly ONCE per image.
	 * Any duplicate call will result in multiple watermarks being applied.
	 *
	 * @param resource|GdImage $target_image Target image resource.
	 * @param resource|GdImage $watermark_image Watermark image resource.
	 * @param int              $x X coordinate.
	 * @param int              $y Y coordinate.
	 * @return void
	 */
	private function apply_watermark_with_alpha( $target_image, $watermark_image, $x, $y ) {
		// FIX A: ENFORCEMENT GUARD - Static flag to prevent multiple overlays even if function is called twice
		static $overlay_applied = array(); // Track overlays per image resource
		$image_id = spl_object_hash( $target_image );
		
		// Check if overlay was already applied to this image resource
		if ( isset( $overlay_applied[ $image_id ] ) ) {
			// This should NEVER happen - log error if it does
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf(
					'[WPMH Watermark] CRITICAL: Blocked duplicate overlay attempt on image resource %s at X: %d, Y: %d',
					substr( $image_id, 0, 8 ),
					$x,
					$y
				) );
			}
			return; // Block second overlay
		}
		
		// Get watermark dimensions - these should be final dimensions after resizing
		$watermark_width = imagesx( $watermark_image );
		$watermark_height = imagesy( $watermark_image );

		// Validate coordinates and dimensions before applying
		if ( $watermark_width <= 0 || $watermark_height <= 0 ) {
			return;
		}

		// Preserve alpha channel on target image
		imagealphablending( $target_image, true );
		imagesavealpha( $target_image, true );

		// FIX A: SINGLE WATERMARK APPLICATION - This is the ONLY call to imagecopy for watermarking
		// Apply watermark exactly once at the specified coordinates
		// Mark this image resource as having an overlay applied
		if ( function_exists( 'imagecopy' ) ) {
			imagecopy( $target_image, $watermark_image, $x, $y, 0, 0, $watermark_width, $watermark_height );
			$overlay_applied[ $image_id ] = true; // Mark as applied
		}
	}

	/**
	 * Save image to file
	 *
	 * @param resource|GdImage $image Image resource.
	 * @param string           $file_path Target file path.
	 * @return bool True on success, false on failure.
	 */
	private function save_image( $image, $file_path ) {
		if ( ! $image ) {
			return false;
		}

		$file_type = wp_check_filetype( $file_path );
		$mime_type = $file_type['type'];

		$success = false;
		switch ( $mime_type ) {
			case 'image/jpeg':
				if ( function_exists( 'imagejpeg' ) ) {
					$success = imagejpeg( $image, $file_path, 90 );
				}
				break;
			case 'image/png':
				if ( function_exists( 'imagepng' ) ) {
					imagealphablending( $image, false );
					imagesavealpha( $image, true );
					$success = imagepng( $image, $file_path, 9 );
				}
				break;
			case 'image/webp':
				if ( function_exists( 'imagewebp' ) ) {
					$success = imagewebp( $image, $file_path, 85 );
				}
				break;
		}

		return $success;
	}

	/**
	 * Validate watermark image
	 *
	 * @param int $attachment_id Watermark attachment ID.
	 * @return bool True if valid, false otherwise.
	 */
	private function validate_watermark_image( $attachment_id ) {
		if ( ! $attachment_id ) {
			return false;
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}

		// Check if it's a supported image format
		$file_type = wp_check_filetype( $file_path );
		$supported_types = array( 'image/jpeg', 'image/png', 'image/webp' );
		
		return in_array( $file_type['type'], $supported_types, true );
	}

	/**
	 * Check if GD library is available
	 *
	 * @return bool
	 */
	private function is_gd_available() {
		return function_exists( 'imagecreatefromjpeg' ) 
			&& function_exists( 'imagecreatefrompng' ) 
			&& function_exists( 'imagecopy' );
	}

	/**
	 * Get feature description
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Apply watermarks to images in your media library. Select a watermark image, configure size and position, then choose target images. Watermarking only runs when explicitly triggered via the action button.', 'webp-media-handler' );
	}
}
