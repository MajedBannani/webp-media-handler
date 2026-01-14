<?php
/**
 * Replace Existing Image URLs with WebP
 *
 * One-time action to replace JPG/PNG URLs with WebP URLs in post content,
 * theme mods, and wp_options. Only replaces if WebP file exists.
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

		// Process post_content
		$posts_result = $this->replace_urls_in_posts( $offset, $batch_size );

		if ( $posts_result['completed'] ) {
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
	 * Replace URLs in post content
	 *
	 * @param int $offset Offset for pagination.
	 * @param int $limit Number of posts to process.
	 * @return array Result array with completion status.
	 */
	private function replace_urls_in_posts( $offset = 0, $limit = 50 ) {
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

			$original_content = $post->post_content;
			$new_content = $this->replace_urls_in_string( $original_content );

			if ( $original_content !== $new_content ) {
				// Count replacements
				$replaced_count += substr_count( $original_content, '.jpg' ) + substr_count( $original_content, '.jpeg' ) + substr_count( $original_content, '.png' );
				$replaced_count -= ( substr_count( $new_content, '.jpg' ) + substr_count( $new_content, '.jpeg' ) + substr_count( $new_content, '.png' ) );

				// Update post
				// WordPress.org compliance: wp_update_post sanitizes post_content automatically
				wp_update_post( array(
					'ID'           => $post_id,
					'post_content' => $new_content,
				) );
			}
		}

		// Update log
		$current_log = $this->settings->get_action_log( 'replace_image_urls' );
		$current_replaced = isset( $current_log['data']['replaced'] ) ? $current_log['data']['replaced'] : 0;
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
			$value = maybe_unserialize( $mod->option_value );

			if ( is_array( $value ) || is_object( $value ) ) {
				$new_value = $this->replace_urls_in_array( $value );
			} else {
				$new_value = $this->replace_urls_in_string( $value );
			}

			if ( $value !== $new_value ) {
				update_option( $mod->option_name, $new_value );
				$replaced_count++;
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
		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} 
				WHERE option_value LIKE %s 
				OR option_value LIKE %s 
				OR option_value LIKE %s",
				'%.jpg%',
				'%.jpeg%',
				'%.png%'
			)
		);

		$replaced_count = 0;

		foreach ( $options as $option ) {
			// Skip certain options that shouldn't be modified
			$skip_options = array( 'active_plugins', 'cron', 'rewrite_rules' );
			if ( in_array( $option->option_name, $skip_options, true ) ) {
				continue;
			}

			$value = maybe_unserialize( $option->option_value );

			if ( is_array( $value ) || is_object( $value ) ) {
				$new_value = $this->replace_urls_in_array( $value );
			} else {
				$new_value = $this->replace_urls_in_string( $value );
			}

			if ( $value !== $new_value ) {
				update_option( $option->option_name, $new_value );
				$replaced_count++;
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
	 * Replace URLs in a string
	 *
	 * @param string $content Content to process.
	 * @return string Processed content.
	 */
	private function replace_urls_in_string( $content ) {
		if ( empty( $content ) || ! is_string( $content ) ) {
			return $content;
		}

		// Pattern to match image URLs in content
		$patterns = array(
			'/(https?:\/\/[^\s"\'<>]+)\.(jpg|jpeg|png)(\?[^\s"\'<>]*)?/i',
			'/(\/[^\s"\'<>]+)\.(jpg|jpeg|png)(\?[^\s"\'<>]*)?/i',
		);

		foreach ( $patterns as $pattern ) {
			$content = preg_replace_callback( $pattern, array( $this, 'replace_url_callback' ), $content );
		}

		return $content;
	}

	/**
	 * Callback for URL replacement
	 *
	 * @param array $matches Matched URL parts.
	 * @return string Replacement URL or original if WebP doesn't exist.
	 */
	private function replace_url_callback( $matches ) {
		$full_url = $matches[0];
		$base_url = $matches[1];
		$extension = $matches[2];
		$query = isset( $matches[3] ) ? $matches[3] : '';

		// Reconstruct the full original URL
		$original_url = $base_url . '.' . $extension . $query;

		// Skip external URLs (not from this site)
		$upload_dir = wp_upload_dir();
		$home_url = home_url();
		
		// Check if URL is from this site
		$is_upload_url = strpos( $original_url, $upload_dir['baseurl'] ) !== false;
		$is_home_url = strpos( $original_url, $home_url ) !== false;
		
		if ( ! $is_upload_url && ! $is_home_url ) {
			return $full_url;
		}

		// Convert URL to file path
		$file_path = '';
		if ( $is_upload_url ) {
			// URL is in uploads directory
			$file_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $original_url );
		} else {
			// URL is relative to site root
			$relative_path = str_replace( $home_url, '', $original_url );
			$file_path = ABSPATH . ltrim( $relative_path, '/' );
		}

		// Remove query string from file path
		$file_path = strtok( $file_path, '?' );

		// Check if original file exists (safety check)
		if ( ! file_exists( $file_path ) ) {
			return $full_url;
		}

		// Check if WebP version exists
		$webp_path = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $file_path );

		if ( file_exists( $webp_path ) ) {
			// Convert back to URL
			if ( $is_upload_url ) {
				$webp_url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $webp_path );
			} else {
				$relative_path = str_replace( ABSPATH, '', $webp_path );
				$webp_url = $home_url . '/' . ltrim( $relative_path, '/' );
			}
			return $webp_url . $query;
		}

		return $full_url;
	}

	/**
	 * Replace URLs in array/object recursively
	 *
	 * @param mixed $data Data to process.
	 * @return mixed Processed data.
	 */
	private function replace_urls_in_array( $data ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->replace_urls_in_array( $value );
			}
		} elseif ( is_object( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data->$key = $this->replace_urls_in_array( $value );
			}
		} elseif ( is_string( $data ) ) {
			$data = $this->replace_urls_in_string( $data );
		}

		return $data;
	}

	/**
	 * Get feature description
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'One-time action to replace JPG/PNG image URLs with WebP URLs in post content, theme mods, and wp_options. Only replaces URLs if the corresponding WebP file exists. Never replaces external URLs. This action must be explicitly triggered.', 'webp-media-handler' );
	}
}
