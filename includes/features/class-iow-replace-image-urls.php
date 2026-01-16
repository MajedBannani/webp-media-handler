<?php
/**
 * Replace Existing Image URLs with WebP
 *
 * One-time action to replace JPG/JPEG/PNG URLs with WebP URLs throughout the WordPress database:
 * - wp_posts.post_content and post_excerpt
 * - wp_postmeta.meta_value (all post meta fields)
 * - wp_options.option_value (all options including theme mods)
 * - Handles serialized PHP data, JSON data, and Gutenberg block attributes
 * Only replaces if WebP file exists. Never replaces external URLs.
 *
 * @package WebPMediaHandler
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replace Image URLs Feature Class
 */
class WPMH_Replace_Image_URLs {

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
		// Handle AJAX action for replacing URLs
		add_action( 'wp_ajax_wpmh_replace_image_urls', array( $this, 'handle_replace_action' ) );
	}

	/**
	 * Handle replace URLs action
	 */
	public function handle_replace_action() {
		// Security checks
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'webp-media-handler' ) ) );
		}

		check_ajax_referer( 'wpmh_replace_image_urls', 'nonce' );

		// Get batch parameters
		// WordPress.org compliance: wp_unslash() before sanitization
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$batch_size = 50; // Process 50 posts at a time

		// Initialize replacement counter
		if ( 0 === $offset ) {
			$this->settings->log_action( 'replace_image_urls', array( 'replaced' => 0 ) );
		}

		// Process posts (content and excerpt)
		$posts_result = $this->replace_urls_in_posts( $offset, $batch_size );

		if ( $posts_result['completed'] ) {
			// Process postmeta
			$this->replace_urls_in_postmeta();

			// Process theme_mods
			$this->replace_urls_in_theme_mods();

			// Process wp_options
			$this->replace_urls_in_options();

			// Get final summary
			$log = $this->settings->get_action_log( 'replace_image_urls' );
			$total_replaced = isset( $log['data']['replaced'] ) ? $log['data']['replaced'] : 0;

			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: %d: Number of replaced URLs */
					__( 'URL replacement complete! Replaced %d image URLs with WebP versions.', 'webp-media-handler' ),
					$total_replaced
				),
				'completed' => true,
			) );
		} else {
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: 1: Processed count, 2: Total count */
					__( 'Processing posts... %1$d of %2$d posts processed.', 'webp-media-handler' ),
					$posts_result['processed'],
					$posts_result['total']
				),
				'offset' => $posts_result['processed'],
				'completed' => false,
			) );
		}
	}

	/**
	 * Replace URLs in post content and excerpt
	 *
	 * @param int $offset Offset for pagination.
	 * @param int $limit Number of posts to process.
	 * @return array Result array with completion status.
	 */
	private function replace_urls_in_posts( $offset = 0, $limit = 50 ) {
		global $wpdb;

		$args = array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'fields'         => 'ids',
		);

		$query = new WP_Query( $args );
		$posts = $query->posts;
		$total = $query->found_posts;

		$replaced_count = 0;

		foreach ( $posts as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$update_data = array( 'ID' => $post_id );
			$has_changes = false;

			// Process post_content
			$original_content = $post->post_content;
			$new_content = $this->replace_urls_in_value( $original_content );
			if ( $original_content !== $new_content ) {
				$update_data['post_content'] = $new_content;
				$has_changes = true;
				$replaced_count += $this->count_replacements( $original_content, $new_content );
			}

			// Process post_excerpt
			$original_excerpt = $post->post_excerpt;
			$new_excerpt = $this->replace_urls_in_value( $original_excerpt );
			if ( $original_excerpt !== $new_excerpt ) {
				$update_data['post_excerpt'] = $new_excerpt;
				$has_changes = true;
				$replaced_count += $this->count_replacements( $original_excerpt, $new_excerpt );
			}

			if ( $has_changes ) {
				// WordPress.org compliance: wp_update_post sanitizes fields automatically
				wp_update_post( $update_data );
			}
		}

		// Update log
		$current_log = $this->settings->get_action_log( 'replace_image_urls' );
		$current_replaced = isset( $current_log['data']['replaced'] ) ? (int) $current_log['data']['replaced'] : 0;
		$this->settings->log_action( 'replace_image_urls', array(
			'replaced' => $current_replaced + $replaced_count,
		) );

		$processed = $offset + count( $posts );

		return array(
			'completed' => $processed >= $total,
			'processed' => $processed,
			'total'     => $total,
		);
	}

	/**
	 * Replace URLs in postmeta
	 *
	 * WordPress.org compliance: Direct database query required because:
	 * - WordPress core APIs require knowing meta keys in advance
	 * - We need to search for meta values containing image URLs without knowing their keys
	 * - Query uses prepared statements and properly escaped
	 */
	private function replace_urls_in_postmeta() {
		global $wpdb;

		// Search for postmeta containing image extensions (case-insensitive)
		$postmeta = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_key, meta_value 
				FROM {$wpdb->postmeta} 
				WHERE (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s 
				       OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)",
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%'
			)
		);

		$replaced_count = 0;

		foreach ( $postmeta as $meta ) {
			$original_value = $meta->meta_value;
			$new_value = $this->replace_urls_in_value( $original_value );

			if ( $original_value !== $new_value ) {
				// Update meta value
				update_post_meta( $meta->post_id, $meta->meta_key, $new_value );
				$replaced_count += $this->count_replacements( $original_value, $new_value );
			}
		}

		// Update log
		$current_log = $this->settings->get_action_log( 'replace_image_urls' );
		$current_replaced = isset( $current_log['data']['replaced'] ) ? (int) $current_log['data']['replaced'] : 0;
		$this->settings->log_action( 'replace_image_urls', array(
			'replaced' => $current_replaced + $replaced_count,
		) );
	}

	/**
	 * Replace URLs in theme mods
	 * 
	 * WordPress.org compliance: Direct database query required because:
	 * - WordPress core APIs (get_theme_mod, get_option) don't provide bulk access to all theme mods
	 * - We need to process all theme_mods_* options across all themes, not just the active theme
	 * - Query is read-only, uses prepared statements, and properly escaped
	 * - Caching is intentionally NOT used: this is a one-time, user-triggered maintenance action and must
	 *   reflect the current database state at execution time (Plugin Check reviewer note).
	 */
	private function replace_urls_in_theme_mods() {
		global $wpdb;

		// Plugin Check: Direct database query justification
		// - This is a one-time, user-triggered maintenance action (not automatic or front-end exposed)
		// - Operation scans and updates theme_mods_* options across all themes, not just active theme
		// - WordPress core APIs (get_theme_mod, get_option) don't provide bulk access to all theme mods
		// - get_alloptions() doesn't include all options and isn't suitable for this cross-theme use case
		// - Query is read-only, uses prepared statements ($wpdb->prepare), and properly escaped
		// - Safety: Executed only after current_user_can('manage_options') and nonce verification in handle_replace_action()
		// - No caching: Object caching is intentionally NOT used because:
		//   * This is a destructive operation that must reflect current database state at execution time
		//   * Caching would risk stale results during URL replacement, potentially missing or corrupting data
		//   * Operation is not performance-critical (manual, infrequent, user-triggered maintenance action)
		$theme_mods = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'theme_mods_' ) . '%'
			)
		);

		$replaced_count = 0;

		foreach ( $theme_mods as $mod ) {
			$original_value = $mod->option_value;
			$new_value = $this->replace_urls_in_value( $original_value );

			if ( $original_value !== $new_value ) {
				update_option( $mod->option_name, $new_value );
				$replaced_count += $this->count_replacements( $original_value, $new_value );
			}
		}

		// Update log
		$current_log = $this->settings->get_action_log( 'replace_image_urls' );
		$current_replaced = isset( $current_log['data']['replaced'] ) ? $current_log['data']['replaced'] : 0;
		$this->settings->log_action( 'replace_image_urls', array(
			'replaced' => $current_replaced + $replaced_count,
		) );
	}

	/**
	 * Replace URLs in wp_options
	 * 
	 * WordPress.org compliance: Direct database query required because:
	 * - WordPress core APIs (get_option) require knowing option names in advance
	 * - We need to search for options containing image URLs without knowing their names
	 * - get_alloptions() doesn't include all options and isn't suitable for this use case
	 * - Query is read-only, uses prepared statements, and properly escaped
	 * - Caching is intentionally NOT used: this is a one-time, user-triggered maintenance action and must
	 *   reflect the current database state at execution time (Plugin Check reviewer note).
	 */
	private function replace_urls_in_options() {
		global $wpdb;

		// Plugin Check: Direct database query justification
		// - This is a one-time, user-triggered maintenance action (not automatic or front-end exposed)
		// - Operation scans and updates wp_options for image URLs in post_content and serialized data
		// - WordPress core APIs (get_option) require knowing option names in advance
		// - We need to search for options containing image URLs without knowing their names
		// - get_alloptions() doesn't include all options and isn't suitable for this search use case
		// - Query is read-only, uses prepared statements ($wpdb->prepare), and properly escaped
		// - Safety: Executed only after current_user_can('manage_options') and nonce verification in handle_replace_action()
		// - No caching: Object caching is intentionally NOT used because:
		//   * This is a destructive operation that must reflect current database state at execution time
		//   * Caching would risk stale results during URL replacement, potentially missing or corrupting data
		//   * Operation is not performance-critical (manual, infrequent, user-triggered maintenance action)
		// Search for options containing image extensions (case-insensitive)
		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} 
				WHERE (option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s 
				       OR option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s)",
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%'
			)
		);

		$replaced_count = 0;

		foreach ( $options as $option ) {
			// Skip certain options that shouldn't be modified
			$skip_options = array( 'active_plugins', 'cron', 'rewrite_rules' );
			if ( in_array( $option->option_name, $skip_options, true ) ) {
				continue;
			}

			$original_value = $option->option_value;
			$new_value = $this->replace_urls_in_value( $original_value );

			if ( $original_value !== $new_value ) {
				update_option( $option->option_name, $new_value );
				$replaced_count += $this->count_replacements( $original_value, $new_value );
			}
		}

		// Update log
		$current_log = $this->settings->get_action_log( 'replace_image_urls' );
		$current_replaced = isset( $current_log['data']['replaced'] ) ? $current_log['data']['replaced'] : 0;
		$this->settings->log_action( 'replace_image_urls', array(
			'replaced' => $current_replaced + $replaced_count,
		) );
	}

	/**
	 * Replace URLs in any value type (string, serialized, JSON)
	 * This is the main entry point that handles all data types safely
	 *
	 * @param mixed $value Value to process.
	 * @return mixed Processed value.
	 */
	private function replace_urls_in_value( $value ) {
		if ( empty( $value ) && $value !== '0' && $value !== 0 ) {
			return $value;
		}

		// Handle strings (check for serialized/JSON first)
		if ( is_string( $value ) ) {
			// Check if string is serialized data
			$unserialized = maybe_unserialize( $value );
			if ( $unserialized !== $value && ( is_array( $unserialized ) || is_object( $unserialized ) ) ) {
				// Value was serialized - process and re-serialize
				$processed = $this->replace_urls_recursive( $unserialized );
				return maybe_serialize( $processed );
			}

			// Check if string is JSON data
			if ( $this->is_json( $value ) ) {
				$decoded = json_decode( $value, true );
				if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
					// Value was JSON - process and re-encode
					$processed = $this->replace_urls_recursive( $decoded );
					return wp_json_encode( $processed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				}
			}

			// Plain string - process directly
			return $this->replace_urls_in_string( $value );
		}

		// Handle arrays and objects recursively
		if ( is_array( $value ) || is_object( $value ) ) {
			return $this->replace_urls_recursive( $value );
		}

		return $value;
	}

	/**
	 * Recursively replace URLs in arrays and objects
	 *
	 * @param mixed $data Data to process.
	 * @return mixed Processed data.
	 */
	private function replace_urls_recursive( $data ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->replace_urls_recursive( $value );
			}
			return $data;
		} elseif ( is_object( $data ) ) {
			// Create new object to avoid modifying original
			$new_object = new \stdClass();
			foreach ( $data as $key => $value ) {
				$new_object->$key = $this->replace_urls_recursive( $value );
			}
			return $new_object;
		} elseif ( is_string( $data ) ) {
			return $this->replace_urls_in_string( $data );
		}

		return $data;
	}

	/**
	 * Check if a string is valid JSON
	 *
	 * @param string $string String to check.
	 * @return bool True if valid JSON.
	 */
	private function is_json( $string ) {
		if ( ! is_string( $string ) || strlen( $string ) < 2 ) {
			return false;
		}
		// Quick check: JSON must start with { or [
		$trimmed = trim( $string );
		return ( '{' === $trimmed[0] || '[' === $trimmed[0] );
	}

	/**
	 * Replace URLs in a string
	 *
	 * @param string $content Content to process.
	 * @return string Processed content.
	 */
	private function replace_urls_in_string( $content ) {
		if ( empty( $content ) || ! is_string( $content ) ) {
			return $content;
		}

		// IMPROVED: Case-insensitive regex matching .jpg, .jpeg, .png with query strings
		// Pattern matches:
		// - Absolute URLs: http:// or https://
		// - Relative URLs: /path/to/image
		// - Extensions: .jpg, .jpeg, .png (case-insensitive: JPG, JPEG, PNG, etc.)
		// - Query strings: ?param=value&other=val
		// - Works in HTML attributes, JSON, Gutenberg blocks, etc.
		$patterns = array(
			// Absolute URLs with protocol (https:// or http://)
			'/(https?:\/\/[^\s"\'<>\[\]{}()]+?)\.(jpg|jpeg|png)(\?[^\s"\'<>\[\]{}()]*)?/i',
			// Relative URLs starting with / (site-relative paths)
			'/(\/[^\s"\'<>\[\]{}()]+?)\.(jpg|jpeg|png)(\?[^\s"\'<>\[\]{}()]*)?/i',
		);

		foreach ( $patterns as $pattern ) {
			$content = preg_replace_callback( $pattern, array( $this, 'replace_url_callback' ), $content );
		}

		return $content;
	}

	/**
	 * Count URL replacements between original and new content
	 *
	 * @param string $original Original content.
	 * @param string $new New content.
	 * @return int Number of replacements.
	 */
	private function count_replacements( $original, $new ) {
		if ( ! is_string( $original ) || ! is_string( $new ) ) {
			return 0;
		}

		// Count image extensions in original (case-insensitive)
		$original_count = 0;
		$extensions = array( '.jpg', '.jpeg', '.png' );
		foreach ( $extensions as $ext ) {
			$original_count += substr_count( strtolower( $original ), strtolower( $ext ) );
		}

		// Count image extensions in new (case-insensitive)
		$new_count = 0;
		foreach ( $extensions as $ext ) {
			$new_count += substr_count( strtolower( $new ), strtolower( $ext ) );
		}

		// Count .webp in new (replaced URLs)
		$webp_count = substr_count( strtolower( $new ), '.webp' );

		// Approximate replacement count
		return max( 0, $original_count - $new_count + $webp_count );
	}

	/**
	 * Callback for URL replacement
	 *
	 * @param array $matches Matched URL parts from regex.
	 * @return string Replacement URL or original if WebP doesn't exist.
	 */
	private function replace_url_callback( $matches ) {
		$full_url = $matches[0];
		$base_url = $matches[1];
		$extension = strtolower( $matches[2] ); // Normalize to lowercase
		$query = isset( $matches[3] ) ? $matches[3] : '';

		// Reconstruct the full original URL
		$original_url = $base_url . '.' . $extension . $query;

		// Skip external URLs (not from this site)
		$upload_dir = wp_upload_dir();
		$home_url = home_url();
		
		// Check if URL is from this site (case-insensitive comparison)
		$upload_baseurl_lower = strtolower( $upload_dir['baseurl'] );
		$home_url_lower = strtolower( $home_url );
		$original_url_lower = strtolower( $original_url );
		
		$is_upload_url = strpos( $original_url_lower, $upload_baseurl_lower ) !== false;
		$is_home_url = strpos( $original_url_lower, $home_url_lower ) !== false;
		
		// Also check for relative URLs (starting with /)
		$is_relative_url = ( '/' === substr( $original_url, 0, 1 ) );
		
		if ( ! $is_upload_url && ! $is_home_url && ! $is_relative_url ) {
			return $full_url; // External URL, skip
		}

		// Convert URL to file path
		$file_path = '';
		if ( $is_upload_url ) {
			// URL is in uploads directory
			$file_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $original_url );
		} elseif ( $is_home_url ) {
			// URL is relative to site root
			$relative_path = str_replace( $home_url, '', $original_url );
			$file_path = ABSPATH . ltrim( $relative_path, '/' );
		} elseif ( $is_relative_url ) {
			// Relative URL starting with /
			$file_path = ABSPATH . ltrim( $original_url, '/' );
		}

		// Remove query string from file path
		$file_path = strtok( $file_path, '?' );

		// Normalize path separators
		$file_path = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $file_path );

		// Check if original file exists (safety check)
		if ( ! file_exists( $file_path ) ) {
			return $full_url;
		}

		// Check if WebP version exists (case-insensitive extension replacement)
		$webp_path = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $file_path );

		if ( file_exists( $webp_path ) ) {
			// Convert back to URL (preserve original URL structure)
			if ( $is_upload_url ) {
				$webp_url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $webp_path );
			} elseif ( $is_home_url ) {
				$relative_path = str_replace( ABSPATH, '', $webp_path );
				$webp_url = $home_url . '/' . str_replace( '\\', '/', ltrim( $relative_path, '/' ) );
			} elseif ( $is_relative_url ) {
				$relative_path = str_replace( ABSPATH, '', $webp_path );
				$webp_url = '/' . str_replace( '\\', '/', ltrim( $relative_path, '/' ) );
			} else {
				return $full_url;
			}

			// Normalize URL separators
			$webp_url = str_replace( '\\', '/', $webp_url );
			
			return $webp_url . $query;
		}

		return $full_url;
	}


	/**
	 * Get feature description
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'One-time action to replace JPG/JPEG/PNG image URLs with WebP URLs throughout the WordPress database (posts, postmeta, options, theme mods). Handles serialized data, JSON data, and Gutenberg block attributes. Only replaces URLs if the corresponding WebP file exists. Never replaces external URLs. This action must be explicitly triggered.', 'webp-media-handler' );
	}
}
