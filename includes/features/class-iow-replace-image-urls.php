<?php
/**
 * Replace Existing Image URLs with WebP
 *
 * Comprehensive database-wide URL replacement system with cursor-based pagination.
 * Processes tables using cursor-based queries to avoid timeouts and ensure reliable completion.
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
	 * Batch size for processing (smaller batches to avoid timeouts)
	 *
	 * @var int
	 */
	private $batch_size = 50;

	/**
	 * Maximum execution time per batch (seconds)
	 *
	 * @var int
	 */
	private $max_execution_time = 10;

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
		// New AJAX handlers for job-based processing
		add_action( 'wp_ajax_wpmh_start_replace_job', array( $this, 'handle_start_job' ) );
		add_action( 'wp_ajax_wpmh_run_replace_batch', array( $this, 'handle_run_batch' ) );
		add_action( 'wp_ajax_wpmh_reset_replace_job', array( $this, 'handle_reset_job' ) );
	}

	/**
	 * Get job state key for current user
	 *
	 * @return string Transient key.
	 */
	private function get_job_state_key() {
		$user_id = get_current_user_id();
		return 'wpmh_replace_job_state_' . $user_id;
	}

	/**
	 * Get job state
	 *
	 * @return array|false Job state or false if not found.
	 */
	private function get_job_state() {
		return get_transient( $this->get_job_state_key() );
	}

	/**
	 * Save job state
	 *
	 * @param array $state Job state.
	 * @param int   $expiration Expiration time in seconds (default: 1 hour).
	 */
	private function save_job_state( $state, $expiration = 3600 ) {
		set_transient( $this->get_job_state_key(), $state, $expiration );
	}

	/**
	 * Delete job state
	 */
	private function delete_job_state() {
		delete_transient( $this->get_job_state_key() );
	}

	/**
	 * Handle start job AJAX action
	 */
	public function handle_start_job() {
		// Clean output buffer to avoid any interference
		if ( ob_get_level() ) {
			ob_clean();
		}

		try {
			// Security checks
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'webp-media-handler' ) ) );
				return;
			}

			check_ajax_referer( 'wpmh_replace_image_urls', 'nonce' );

			// Get dry-run flag
			$dry_run = isset( $_POST['dry_run'] ) && '1' === $_POST['dry_run'];

			// Initialize job state - process theme_mods separately from options for special handling
			$tables = array( 'posts', 'theme_mods', 'options', 'postmeta', 'termmeta', 'usermeta', 'terms', 'term_taxonomy' );

			$state = array(
				'dry_run' => $dry_run,
				'tables' => $tables,
				'current_table_index' => 0,
				'current_table' => $tables[0],
				'last_id' => 0,
				'last_id_check' => 0, // For infinite loop detection
				'stats' => array(
					'replaced' => 0,
					'skipped_external' => 0,
					'skipped_no_webp' => 0,
					'tables' => array(),
				),
				'samples' => array(), // Max 10 samples for dry-run
				'started_at' => time(),
			);

			// Initialize table stats
			foreach ( $tables as $table ) {
				$state['stats']['tables'][ $table ] = array(
					'scanned' => 0,
					'updated' => 0,
				);
			}

			$this->save_job_state( $state );

			wp_send_json_success( array(
				'message' => __( 'Job started. Processing...', 'webp-media-handler' ),
				'stage' => 'started',
				'table' => $state['current_table'],
			) );

		} catch ( Exception $e ) {
			error_log( '[WPMH Replace URLs] Start job error: ' . $e->getMessage() );
			wp_send_json_error( array(
				'message' => __( 'Failed to start job: ', 'webp-media-handler' ) . $e->getMessage(),
			) );
		}
	}

	/**
	 * Handle reset job AJAX action
	 */
	public function handle_reset_job() {
		if ( ob_get_level() ) {
			ob_clean();
		}

		try {
			// Security checks
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'webp-media-handler' ) ) );
				return;
			}

			check_ajax_referer( 'wpmh_replace_image_urls', 'nonce' );

			$this->delete_job_state();

			wp_send_json_success( array(
				'message' => __( 'Job reset successfully.', 'webp-media-handler' ),
			) );

		} catch ( Exception $e ) {
			error_log( '[WPMH Replace URLs] Reset job error: ' . $e->getMessage() );
			wp_send_json_error( array(
				'message' => __( 'Failed to reset job: ', 'webp-media-handler' ) . $e->getMessage(),
			) );
		}
	}

	/**
	 * Handle run batch AJAX action
	 */
	public function handle_run_batch() {
		if ( ob_get_level() ) {
			ob_clean();
		}

		$start_time = microtime( true );
		$last_error = '';

		try {
			// Security checks
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'webp-media-handler' ) ) );
				return;
			}

			check_ajax_referer( 'wpmh_replace_image_urls', 'nonce' );

			// Get job state
			$state = $this->get_job_state();
			if ( false === $state ) {
				wp_send_json_error( array(
					'message' => __( 'Job state not found. Please start a new job.', 'webp-media-handler' ),
				) );
				return;
			}

			// Check for infinite loop (cursor not advancing)
			if ( $state['last_id'] === $state['last_id_check'] && $state['last_id'] > 0 ) {
				// Check if we've been stuck for more than 2 batches (safety check)
				if ( isset( $state['stuck_count'] ) && $state['stuck_count'] >= 2 ) {
					$this->delete_job_state();
					wp_send_json_error( array(
						'message' => __( 'Cursor did not advance; possible query issue. Stopping to prevent infinite loop.', 'webp-media-handler' ),
					) );
					return;
				}
				$state['stuck_count'] = isset( $state['stuck_count'] ) ? $state['stuck_count'] + 1 : 1;
			} else {
				$state['stuck_count'] = 0;
				$state['last_id_check'] = $state['last_id'];
			}

			// Process current table batch
			$method_name = 'process_' . $state['current_table'];
			if ( ! method_exists( $this, $method_name ) ) {
				wp_send_json_error( array(
					'message' => sprintf( __( 'Unknown table: %s', 'webp-media-handler' ), $state['current_table'] ),
				) );
				return;
			}

			$batch_result = call_user_func( array( $this, $method_name ), $state );

			// Check execution time
			$elapsed = microtime( true ) - $start_time;
			if ( $elapsed > $this->max_execution_time ) {
				// Save progress and return
				$this->save_job_state( $batch_result['state'] );
				wp_send_json_success( array(
					'message' => $batch_result['message'],
					'stage' => 'progress',
					'table' => $batch_result['state']['current_table'],
					'processed' => $batch_result['processed'],
					'total' => $batch_result['total'],
					'updated' => $batch_result['updated'],
					'replacements' => $batch_result['replacements'],
					'continue' => true,
					'execution_time' => $elapsed,
				) );
				return;
			}

			// Check if table is complete
			if ( $batch_result['completed'] ) {
				// Move to next table
				$state = $batch_result['state'];
				$state['current_table_index']++;
				$state['last_id'] = 0;
				$state['last_id_check'] = 0;
				$state['stuck_count'] = 0;

				if ( $state['current_table_index'] < count( $state['tables'] ) ) {
					$state['current_table'] = $state['tables'][ $state['current_table_index'] ];
					$this->save_job_state( $state );

					wp_send_json_success( array(
						'message' => sprintf(
							/* translators: 1: Completed table, 2: Next table */
							__( 'Completed %1$s. Processing %2$s...', 'webp-media-handler' ),
							$batch_result['state']['current_table'],
							$state['current_table']
						),
						'stage' => 'table_complete',
						'table' => $state['current_table'],
						'continue' => true,
					) );
					return;
				} else {
					// All tables completed
					$this->finalize_job( $state );
					wp_send_json_success( array(
						'message' => $this->build_completion_message( $state ),
						'stage' => 'complete',
						'stats' => $state['stats'],
						'samples' => $state['samples'],
						'continue' => false,
					) );
					return;
				}
			}

			// Save progress and continue
			$this->save_job_state( $batch_result['state'] );

			wp_send_json_success( array(
				'message' => $batch_result['message'],
				'stage' => 'progress',
				'table' => $batch_result['state']['current_table'],
				'processed' => $batch_result['processed'],
				'total' => $batch_result['total'],
				'updated' => $batch_result['updated'],
				'replacements' => $batch_result['replacements'],
				'continue' => true,
			) );

		} catch ( Exception $e ) {
			error_log( '[WPMH Replace URLs] Batch error: ' . $e->getMessage() );
			wp_send_json_error( array(
				'message' => __( 'Batch processing error: ', 'webp-media-handler' ) . $e->getMessage(),
				'last_error' => $e->getMessage(),
			) );
		}
	}

	/**
	 * Finalize job and save final stats
	 *
	 * @param array $state Job state.
	 */
	private function finalize_job( $state ) {
		// Calculate totals
		$total_replaced = 0;
		foreach ( $state['stats']['tables'] as $table_stats ) {
			$total_replaced += $table_stats['updated'];
		}
		$state['stats']['replaced'] = $total_replaced;

		// Save final log
		$this->settings->log_action( 'replace_image_urls', array( 'stats' => $state['stats'] ) );

		// Delete job state
		$this->delete_job_state();
	}

	/**
	 * Build completion message with detailed reporting
	 *
	 * @param array $state Job state.
	 * @return string Message.
	 */
	private function build_completion_message( $state ) {
		$mode = $state['dry_run'] ? __( 'Dry run completed', 'webp-media-handler' ) : __( 'URL replacement complete', 'webp-media-handler' );
		$message = $mode . '! ';

		// Detailed per-scope reporting
		$report_parts = array();

		// Posts
		if ( isset( $state['stats']['tables']['posts'] ) && $state['stats']['tables']['posts']['scanned'] > 0 ) {
			$posts_stats = $state['stats']['tables']['posts'];
			$report_parts[] = sprintf(
				/* translators: 1: Updated count, 2: Replacements count */
				__( 'Posts: %1$d updated, %2$d replacements', 'webp-media-handler' ),
				$posts_stats['updated'],
				$state['stats']['replaced']
			);
		}

		// Theme mods
		if ( isset( $state['stats']['tables']['theme_mods'] ) && $state['stats']['tables']['theme_mods']['updated'] > 0 ) {
			$report_parts[] = sprintf(
				/* translators: %d: Updated count */
				__( 'Theme mods: %d updated', 'webp-media-handler' ),
				$state['stats']['tables']['theme_mods']['updated']
			);
		}

		// Options
		if ( isset( $state['stats']['tables']['options'] ) && $state['stats']['tables']['options']['updated'] > 0 ) {
			$report_parts[] = sprintf(
				/* translators: %d: Updated count */
				__( 'Options: %d updated', 'webp-media-handler' ),
				$state['stats']['tables']['options']['updated']
			);
		}

		// Postmeta
		if ( isset( $state['stats']['tables']['postmeta'] ) && $state['stats']['tables']['postmeta']['updated'] > 0 ) {
			$report_parts[] = sprintf(
				/* translators: %d: Updated count */
				__( 'Postmeta: %d updated', 'webp-media-handler' ),
				$state['stats']['tables']['postmeta']['updated']
			);
		}

		// Usermeta
		if ( isset( $state['stats']['tables']['usermeta'] ) && $state['stats']['tables']['usermeta']['updated'] > 0 ) {
			$report_parts[] = sprintf(
				/* translators: %d: Updated count */
				__( 'Usermeta: %d updated', 'webp-media-handler' ),
				$state['stats']['tables']['usermeta']['updated']
			);
		}

		// Termmeta
		if ( isset( $state['stats']['tables']['termmeta'] ) && $state['stats']['tables']['termmeta']['updated'] > 0 ) {
			$report_parts[] = sprintf(
				/* translators: %d: Updated count */
				__( 'Termmeta: %d updated', 'webp-media-handler' ),
				$state['stats']['tables']['termmeta']['updated']
			);
		}

		// Terms/Term taxonomy
		if ( isset( $state['stats']['tables']['terms'] ) && $state['stats']['tables']['terms']['updated'] > 0 ) {
			$report_parts[] = sprintf(
				/* translators: %d: Updated count */
				__( 'Terms: %d updated', 'webp-media-handler' ),
				$state['stats']['tables']['terms']['updated']
			);
		}

		if ( ! empty( $report_parts ) ) {
			$message .= implode( '; ', $report_parts ) . '. ';
		}

		// Skip counts
		if ( $state['stats']['skipped_external'] > 0 || $state['stats']['skipped_no_webp'] > 0 ) {
			$skip_parts = array();
			if ( $state['stats']['skipped_external'] > 0 ) {
				$skip_parts[] = sprintf(
					/* translators: %d: Skipped count */
					__( '%d external URLs skipped', 'webp-media-handler' ),
					$state['stats']['skipped_external']
				);
			}
			if ( $state['stats']['skipped_no_webp'] > 0 ) {
				$skip_parts[] = sprintf(
					/* translators: %d: Skipped count */
					__( '%d URLs skipped (no WebP file)', 'webp-media-handler' ),
					$state['stats']['skipped_no_webp']
				);
			}
			if ( ! empty( $skip_parts ) ) {
				$message .= implode( ', ', $skip_parts ) . '.';
			}
		}

		return $message;
	}

	/**
	 * Process wp_posts table with cursor-based pagination
	 *
	 * @param array $state Job state.
	 * @return array Result array.
	 */
	private function process_posts( $state ) {
		global $wpdb;

		$dry_run = $state['dry_run'];
		$last_id = $state['last_id'];

		// Get total count (approximate, for progress)
		$total_query = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d";
		$total = $wpdb->get_var( $wpdb->prepare( $total_query, $last_id ) );

		// Get batch using cursor
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
				WHERE ID > %d
				  AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s
				       OR post_excerpt LIKE %s OR post_excerpt LIKE %s OR post_excerpt LIKE %s)
				ORDER BY ID ASC
				LIMIT %d",
				$last_id,
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				$this->batch_size
			)
		);

		if ( empty( $posts ) ) {
			return array(
				'completed' => true,
				'processed' => 0,
				'total' => 0,
				'updated' => 0,
				'replacements' => 0,
				'message' => sprintf( __( 'Completed %s.', 'webp-media-handler' ), 'posts' ),
				'state' => $state,
			);
		}

		$rows_updated = 0;
		$rows_scanned = count( $posts );
		$replacements = 0;

		foreach ( $posts as $post ) {
			$update_data = array();
			$has_changes = false;

			// Process post_content
			$new_content = $this->replace_urls_in_value( $post->post_content, $state );
			if ( $new_content !== $post->post_content ) {
				$update_data['post_content'] = $new_content;
				$has_changes = true;
				$replacements += $this->count_replacements( $post->post_content, $new_content );
			}

			// Process post_excerpt
			$new_excerpt = $this->replace_urls_in_value( $post->post_excerpt, $state );
			if ( $new_excerpt !== $post->post_excerpt ) {
				$update_data['post_excerpt'] = $new_excerpt;
				$has_changes = true;
				$replacements += $this->count_replacements( $post->post_excerpt, $new_excerpt );
			}

			if ( $has_changes ) {
				if ( ! $dry_run ) {
					wp_update_post( array_merge( array( 'ID' => $post->ID ), $update_data ) );
				}
				$rows_updated++;
			}

			// Update cursor
			$state['last_id'] = $post->ID;
		}

		// Update stats
		$state['stats']['tables']['posts']['scanned'] += $rows_scanned;
		$state['stats']['tables']['posts']['updated'] += $rows_updated;
		$state['stats']['replaced'] += $replacements;

		$completed = ( count( $posts ) < $this->batch_size );

		return array(
			'completed' => $completed,
			'processed' => $state['stats']['tables']['posts']['scanned'],
			'total' => $total,
			'updated' => $rows_updated,
			'replacements' => $replacements,
			'message' => sprintf(
				/* translators: 1: Table name, 2: Processed count, 3: Total count */
				__( 'Processing %1$s... %2$d rows processed.', 'webp-media-handler' ),
				'posts',
				$state['stats']['tables']['posts']['scanned']
			),
			'state' => $state,
		);
	}

	/**
	 * Process wp_postmeta table with cursor-based pagination
	 *
	 * @param array $state Job state.
	 * @return array Result array.
	 */
	private function process_postmeta( $state ) {
		global $wpdb;

		$dry_run = $state['dry_run'];
		$last_id = $state['last_id'];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE meta_id > %d
				  AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s
				       OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)
				ORDER BY meta_id ASC
				LIMIT %d",
				$last_id,
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size
			)
		);

		return $this->process_meta_table( $rows, 'postmeta', $state, $dry_run, 'update_post_meta', 'post_id' );
	}

	/**
	 * Process theme_mods (special handling for attachment IDs and URLs)
	 *
	 * @param array $state Job state.
	 * @return array Result array.
	 */
	private function process_theme_mods( $state ) {
		global $wpdb;

		$dry_run = $state['dry_run'];
		
		// Check if we've already processed theme_mods (one-time operation, not batched by cursor)
		if ( isset( $state['theme_mods_processed'] ) && $state['theme_mods_processed'] ) {
			return array(
				'completed' => true,
				'processed' => $state['stats']['tables']['theme_mods']['scanned'],
				'total' => $state['stats']['tables']['theme_mods']['scanned'],
				'updated' => $state['stats']['tables']['theme_mods']['updated'],
				'replacements' => 0,
				'message' => sprintf( __( 'Completed %s.', 'webp-media-handler' ), 'theme_mods' ),
				'state' => $state,
			);
		}

		// Get all theme_mods_* options
		$theme_mods_options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'theme_mods_' ) . '%'
			)
		);

		$rows_updated = 0;
		$rows_scanned = count( $theme_mods_options );
		$replacements = 0;

		foreach ( $theme_mods_options as $mod_option ) {
			$theme_mods = maybe_unserialize( $mod_option->option_value );
			
			if ( ! is_array( $theme_mods ) ) {
				continue;
			}

			$has_changes = false;
			$new_mods = $this->process_theme_mods_array( $theme_mods, $has_changes, $replacements );

			if ( $has_changes ) {
				if ( ! $dry_run ) {
					update_option( $mod_option->option_name, $new_mods );
				}
				$rows_updated++;
			}
		}

		// Mark as processed
		$state['theme_mods_processed'] = true;

		// Update stats
		$state['stats']['tables']['theme_mods']['scanned'] = $rows_scanned;
		$state['stats']['tables']['theme_mods']['updated'] += $rows_updated;
		$state['stats']['replaced'] += $replacements;

		return array(
			'completed' => true,
			'processed' => $rows_scanned,
			'total' => $rows_scanned,
			'updated' => $rows_updated,
			'replacements' => $replacements,
			'message' => sprintf(
				__( 'Processed %d theme mods, %d updated.', 'webp-media-handler' ),
				$rows_scanned,
				$rows_updated
			),
			'state' => $state,
		);
	}

	/**
	 * Process theme_mods array, handling attachment IDs and URLs
	 *
	 * @param array $mods Theme mods array.
	 * @param bool  $has_changes Output parameter - set to true if changes made.
	 * @param int   $replacements Output parameter - count of replacements.
	 * @return array Processed mods array.
	 */
	private function process_theme_mods_array( $mods, &$has_changes, &$replacements ) {
		if ( ! is_array( $mods ) ) {
			return $mods;
		}

		$processed = array();

		foreach ( $mods as $key => $value ) {
			if ( is_array( $value ) ) {
				$processed[ $key ] = $this->process_theme_mods_array( $value, $has_changes, $replacements );
			} elseif ( is_numeric( $value ) && $value > 0 ) {
				// Potential attachment ID - check if it's a valid attachment and has WebP
				$attachment_id = absint( $value );
				$attachment_file = get_attached_file( $attachment_id );
				
				if ( $attachment_file && file_exists( $attachment_file ) ) {
					// Check if WebP version exists
					$webp_file = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $attachment_file );
					if ( file_exists( $webp_file ) ) {
						// Get WebP URL - if the attachment has been converted to WebP, 
						// the URL should already point to WebP, but we'll use the WebP file URL
						$upload_dir = wp_upload_dir();
						$relative_path = str_replace( $upload_dir['basedir'], '', $webp_file );
						$webp_url = $upload_dir['baseurl'] . $relative_path;
						
						// Update the mod value to use the WebP URL instead of ID
						// This matches snippet behavior: convert IDs to WebP URLs when WebP exists
						$processed[ $key ] = $webp_url;
						$has_changes = true;
						$replacements++;
					} else {
						$processed[ $key ] = $value;
					}
				} else {
					// Not a valid attachment, keep as-is
					$processed[ $key ] = $value;
				}
			} elseif ( is_string( $value ) ) {
				// Process URL strings
				$new_value = $this->replace_urls_in_value( $value );
				if ( $new_value !== $value ) {
					$has_changes = true;
					$replacements += $this->count_replacements( $value, $new_value );
					$processed[ $key ] = $new_value;
				} else {
					$processed[ $key ] = $value;
				}
			} else {
				$processed[ $key ] = $value;
			}
		}

		return $processed;
	}

	/**
	 * Process wp_options table with cursor-based pagination
	 * Excludes theme_mods_* options as they're handled separately
	 *
	 * @param array $state Job state.
	 * @return array Result array.
	 */
	private function process_options( $state ) {
		global $wpdb;

		$dry_run = $state['dry_run'];
		$last_id = $state['last_id'];

			$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id as meta_id, option_name as meta_key, option_value as meta_value FROM {$wpdb->options}
				WHERE option_id > %d
				  AND option_name NOT IN ('active_plugins', 'cron', 'rewrite_rules')
				  AND option_name NOT LIKE %s
				  AND (option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s
				       OR option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s)
				ORDER BY option_id ASC
				LIMIT %d",
				$last_id,
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size
			)
		);

		$rows_updated = 0;
		$rows_scanned = count( $rows );
		$replacements = 0;

		if ( empty( $rows ) ) {
			return array(
				'completed' => true,
				'processed' => 0,
				'total' => 0,
				'updated' => 0,
				'replacements' => 0,
				'message' => sprintf( __( 'Completed %s.', 'webp-media-handler' ), 'options' ),
				'state' => $state,
			);
		}

		foreach ( $rows as $row ) {
			$new_value = $this->replace_urls_in_value( $row->meta_value, $state );
			if ( $new_value !== $row->meta_value ) {
				if ( ! $dry_run ) {
					update_option( $row->meta_key, $new_value );
				}
				$rows_updated++;
				$replacements += $this->count_replacements( $row->meta_value, $new_value );
			}

			$state['last_id'] = $row->meta_id;
		}

		// Update stats
		$state['stats']['tables']['options']['scanned'] += $rows_scanned;
		$state['stats']['tables']['options']['updated'] += $rows_updated;
		$state['stats']['replaced'] += $replacements;

		$completed = ( count( $rows ) < $this->batch_size );

		return array(
			'completed' => $completed,
			'processed' => $state['stats']['tables']['options']['scanned'],
			'total' => 0, // Options table doesn't have reliable total
			'updated' => $rows_updated,
			'replacements' => $replacements,
			'message' => sprintf(
				__( 'Processing %s... %d rows processed.', 'webp-media-handler' ),
				'options',
				$state['stats']['tables']['options']['scanned']
			),
			'state' => $state,
		);
	}

	/**
	 * Process wp_termmeta table
	 *
	 * @param array $state Job state.
	 * @return array Result array.
	 */
	private function process_termmeta( $state ) {
		global $wpdb;

		if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->termmeta}'" ) ) {
			return array(
				'completed' => true,
				'processed' => 0,
				'total' => 0,
				'updated' => 0,
				'replacements' => 0,
				'message' => sprintf( __( 'Skipped %s (table does not exist).', 'webp-media-handler' ), 'termmeta' ),
				'state' => $state,
			);
		}

		$last_id = $state['last_id'];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, term_id as post_id, meta_key, meta_value FROM {$wpdb->termmeta}
				WHERE meta_id > %d
				  AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s
				       OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)
				ORDER BY meta_id ASC
				LIMIT %d",
				$last_id,
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size
			)
		);

		return $this->process_meta_table( $rows, 'termmeta', $state, $state['dry_run'], 'update_term_meta', 'post_id' );
	}

	/**
	 * Process wp_usermeta table
	 *
	 * @param array $state Job state.
	 * @return array Result array.
	 */
	private function process_usermeta( $state ) {
		global $wpdb;

		$last_id = $state['last_id'];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id as meta_id, user_id as post_id, meta_key, meta_value FROM {$wpdb->usermeta}
				WHERE umeta_id > %d
				  AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s
				       OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)
				ORDER BY umeta_id ASC
				LIMIT %d",
				$last_id,
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size
			)
		);

		return $this->process_meta_table( $rows, 'usermeta', $state, $state['dry_run'], 'update_user_meta', 'post_id' );
	}

	/**
	 * Process wp_terms table
	 *
	 * @param array $state Job state.
	 * @return array Result array.
	 */
	private function process_terms( $state ) {
		global $wpdb;

		$dry_run = $state['dry_run'];
		$last_id = $state['last_id'];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, description FROM {$wpdb->terms}
				WHERE term_id > %d
				  AND (description LIKE %s OR description LIKE %s OR description LIKE %s
				       OR description LIKE %s OR description LIKE %s OR description LIKE %s)
				ORDER BY term_id ASC
				LIMIT %d",
				$last_id,
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size
			)
		);

		$rows_updated = 0;
		$rows_scanned = count( $rows );
		$replacements = 0;

		if ( empty( $rows ) ) {
			return array(
				'completed' => true,
				'processed' => 0,
				'total' => 0,
				'updated' => 0,
				'replacements' => 0,
				'message' => sprintf( __( 'Completed %s.', 'webp-media-handler' ), 'terms' ),
				'state' => $state,
			);
		}

		foreach ( $rows as $row ) {
			$new_description = $this->replace_urls_in_value( $row->description, $state );
			if ( $new_description !== $row->description ) {
				if ( ! $dry_run ) {
					$wpdb->update(
						$wpdb->terms,
						array( 'description' => $new_description ),
						array( 'term_id' => $row->term_id ),
						array( '%s' ),
						array( '%d' )
					);
				}
				$rows_updated++;
				$replacements += $this->count_replacements( $row->description, $new_description );
			}

			$state['last_id'] = $row->term_id;
		}

		// Update stats
		$state['stats']['tables']['terms']['scanned'] += $rows_scanned;
		$state['stats']['tables']['terms']['updated'] += $rows_updated;
		$state['stats']['replaced'] += $replacements;

		$completed = ( count( $rows ) < $this->batch_size );

		return array(
			'completed' => $completed,
			'processed' => $state['stats']['tables']['terms']['scanned'],
			'total' => 0,
			'updated' => $rows_updated,
			'replacements' => $replacements,
			'message' => sprintf(
				__( 'Processing %s... %d rows processed.', 'webp-media-handler' ),
				'terms',
				$state['stats']['tables']['terms']['scanned']
			),
			'state' => $state,
		);
	}

	/**
	 * Process wp_term_taxonomy table
	 *
	 * @param array $state Job state.
	 * @return array Result array.
	 */
	private function process_term_taxonomy( $state ) {
		global $wpdb;

		$dry_run = $state['dry_run'];
		$last_id = $state['last_id'];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_taxonomy_id, description FROM {$wpdb->term_taxonomy}
				WHERE term_taxonomy_id > %d
				  AND (description LIKE %s OR description LIKE %s OR description LIKE %s
				       OR description LIKE %s OR description LIKE %s OR description LIKE %s)
				ORDER BY term_taxonomy_id ASC
				LIMIT %d",
				$last_id,
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size
			)
		);

		$rows_updated = 0;
		$rows_scanned = count( $rows );
		$replacements = 0;

		if ( empty( $rows ) ) {
			return array(
				'completed' => true,
				'processed' => 0,
				'total' => 0,
				'updated' => 0,
				'replacements' => 0,
				'message' => sprintf( __( 'Completed %s.', 'webp-media-handler' ), 'term_taxonomy' ),
				'state' => $state,
			);
		}

		foreach ( $rows as $row ) {
			$new_description = $this->replace_urls_in_value( $row->description, $state );
			if ( $new_description !== $row->description ) {
				if ( ! $dry_run ) {
					$wpdb->update(
						$wpdb->term_taxonomy,
						array( 'description' => $new_description ),
						array( 'term_taxonomy_id' => $row->term_taxonomy_id ),
						array( '%s' ),
						array( '%d' )
					);
				}
				$rows_updated++;
				$replacements += $this->count_replacements( $row->description, $new_description );
			}

			$state['last_id'] = $row->term_taxonomy_id;
		}

		// Update stats
		$state['stats']['tables']['term_taxonomy']['scanned'] += $rows_scanned;
		$state['stats']['tables']['term_taxonomy']['updated'] += $rows_updated;
		$state['stats']['replaced'] += $replacements;

		$completed = ( count( $rows ) < $this->batch_size );

		return array(
			'completed' => $completed,
			'processed' => $state['stats']['tables']['term_taxonomy']['scanned'],
			'total' => 0,
			'updated' => $rows_updated,
			'replacements' => $replacements,
			'message' => sprintf(
				__( 'Processing %s... %d rows processed.', 'webp-media-handler' ),
				'term_taxonomy',
				$state['stats']['tables']['term_taxonomy']['scanned']
			),
			'state' => $state,
		);
	}

	/**
	 * Process meta table rows (postmeta, termmeta, usermeta)
	 *
	 * @param array  $rows Rows to process.
	 * @param string $table_name Table name.
	 * @param array  $state Job state.
	 * @param bool   $dry_run Dry run mode.
	 * @param string $update_func Update function name.
	 * @param string $id_field ID field name.
	 * @return array Result array.
	 */
	private function process_meta_table( $rows, $table_name, $state, $dry_run, $update_func, $id_field ) {
		$rows_updated = 0;
		$rows_scanned = count( $rows );
		$replacements = 0;

		if ( empty( $rows ) ) {
			return array(
				'completed' => true,
				'processed' => 0,
				'total' => 0,
				'updated' => 0,
				'replacements' => 0,
				'message' => sprintf( __( 'Completed %s.', 'webp-media-handler' ), $table_name ),
				'state' => $state,
			);
		}

		foreach ( $rows as $row ) {
			$new_value = $this->replace_urls_in_value( $row->meta_value, $state );
			if ( $new_value !== $row->meta_value ) {
				if ( ! $dry_run ) {
					call_user_func( $update_func, $row->$id_field, $row->meta_key, $new_value );
				}
				$rows_updated++;
				$replacements += $this->count_replacements( $row->meta_value, $new_value );
			}

			$state['last_id'] = $row->meta_id;
		}

		// Update stats
		$state['stats']['tables'][ $table_name ]['scanned'] += $rows_scanned;
		$state['stats']['tables'][ $table_name ]['updated'] += $rows_updated;
		$state['stats']['replaced'] += $replacements;

		$completed = ( count( $rows ) < $this->batch_size );

		return array(
			'completed' => $completed,
			'processed' => $state['stats']['tables'][ $table_name ]['scanned'],
			'total' => 0,
			'updated' => $rows_updated,
			'replacements' => $replacements,
			'message' => sprintf(
				__( 'Processing %s... %d rows processed.', 'webp-media-handler' ),
				$table_name,
				$state['stats']['tables'][ $table_name ]['scanned']
			),
			'state' => $state,
		);
	}

	/**
	 * Replace URLs in any value type (string, serialized, JSON)
	 *
	 * @param mixed $value Value to process.
	 * @param array $state Job state (for sample collection).
	 * @return mixed Processed value.
	 */
	private function replace_urls_in_value( $value, &$state = null ) {
		if ( empty( $value ) && $value !== '0' && $value !== 0 ) {
			return $value;
		}

		// Handle strings (check for serialized/JSON first)
		if ( is_string( $value ) ) {
			// Check if string is serialized data
			$unserialized = maybe_unserialize( $value );
			if ( $unserialized !== $value && ( is_array( $unserialized ) || is_object( $unserialized ) ) ) {
				// Value was serialized - process and re-serialize
				$processed = $this->replace_urls_recursive( $unserialized, $state );
				return maybe_serialize( $processed );
			}

			// Check if string is JSON data
			if ( $this->is_json( $value ) ) {
				$decoded = json_decode( $value, true );
				if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
					// Value was JSON - process and re-encode
					$processed = $this->replace_urls_recursive( $decoded, $state );
					return wp_json_encode( $processed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				}
			}

			// Plain string - process directly
			return $this->replace_urls_in_string( $value, $state );
		}

		// Handle arrays and objects recursively
		if ( is_array( $value ) || is_object( $value ) ) {
			return $this->replace_urls_recursive( $value, $state );
		}

		return $value;
	}

	/**
	 * Recursively replace URLs in arrays and objects
	 *
	 * @param mixed $data Data to process.
	 * @param array $state Job state (for sample collection).
	 * @return mixed Processed data.
	 */
	private function replace_urls_recursive( $data, &$state = null ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->replace_urls_recursive( $value, $state );
			}
			return $data;
		} elseif ( is_object( $data ) ) {
			$new_object = new \stdClass();
			foreach ( $data as $key => $value ) {
				$new_object->$key = $this->replace_urls_recursive( $value, $state );
			}
			return $new_object;
		} elseif ( is_string( $data ) ) {
			return $this->replace_urls_in_string( $data, $state );
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
		$trimmed = trim( $string );
		return ( '{' === $trimmed[0] || '[' === $trimmed[0] );
	}

	/**
	 * Replace URLs in a string
	 *
	 * @param string $content Content to process.
	 * @param array  $state Job state (for sample collection).
	 * @return string Processed content.
	 */
	private function replace_urls_in_string( $content, &$state = null ) {
		if ( empty( $content ) || ! is_string( $content ) ) {
			return $content;
		}

		// Handle srcset attribute
		if ( preg_match( '/srcset\s*=\s*["\']([^"\']+)["\']/i', $content ) ) {
			$content = preg_replace_callback(
				'/srcset\s*=\s*["\']([^"\']+)["\']/i',
				array( $this, 'replace_srcset_callback' ),
				$content
			);
		}

		// Handle CSS background-image
		$content = preg_replace_callback(
			'/background-image\s*:\s*url\s*\(\s*["\']?([^"\'()]+)["\']?\s*\)/i',
			array( $this, 'replace_css_url_callback' ),
			$content
		);

		// Main URL patterns
		$patterns = array(
			'/(https?:\/\/[^\s"\'<>\[\]{}()]+?)\.(jpg|jpeg|png)(\?[^\s"\'<>\[\]{}()]*)?(#[^\s"\'<>\[\]{}()]*)?/i',
			'/(\/[^\s"\'<>\[\]{}()]+?)\.(jpg|jpeg|png)(\?[^\s"\'<>\[\]{}()]*)?(#[^\s"\'<>\[\]{}()]*)?/i',
		);

		foreach ( $patterns as $pattern ) {
			$content = preg_replace_callback( $pattern, array( $this, 'replace_url_callback' ), $content );
		}

		return $content;
	}

	/**
	 * Callback for srcset replacement
	 *
	 * @param array $matches Matched srcset content.
	 * @return string Replacement srcset.
	 */
	private function replace_srcset_callback( $matches ) {
		$full_srcset = $matches[0];
		$srcset_content = $matches[1];

		$tokens = explode( ',', $srcset_content );
		$processed_tokens = array();

		foreach ( $tokens as $token ) {
			$token = trim( $token );
			if ( preg_match( '/^(.+?\.(jpg|jpeg|png)(\?[^\s]*)?(#[^\s]*)?)\s*(.+)$/i', $token, $token_matches ) ) {
				$url_part = $token_matches[1];
				$descriptor = isset( $token_matches[5] ) ? ' ' . trim( $token_matches[5] ) : '';
				
				$replaced_url = preg_replace_callback(
					'/(.+?)\.(jpg|jpeg|png)(\?[^\s]*)?(#[^\s]*)?$/i',
					array( $this, 'replace_url_callback' ),
					$url_part
				);
				$processed_tokens[] = $replaced_url . $descriptor;
			} else {
				$processed_tokens[] = preg_replace_callback(
					'/(.+?)\.(jpg|jpeg|png)(\?[^\s]*)?(#[^\s]*)?$/i',
					array( $this, 'replace_url_callback' ),
					$token
				);
			}
		}

		return 'srcset="' . implode( ', ', $processed_tokens ) . '"';
	}

	/**
	 * Callback for CSS URL replacement
	 *
	 * @param array $matches Matched CSS URL.
	 * @return string Replacement CSS.
	 */
	private function replace_css_url_callback( $matches ) {
		$full_match = $matches[0];
		$url = $matches[1];

		$replaced_url = preg_replace_callback(
			'/(.+?)\.(jpg|jpeg|png)(\?[^\s]*)?(#[^\s]*)?$/i',
			array( $this, 'replace_url_callback' ),
			$url
		);

		return str_replace( $url, $replaced_url, $full_match );
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
		$extension = strtolower( $matches[2] );
		$query = isset( $matches[3] ) ? $matches[3] : '';
		$fragment = isset( $matches[4] ) ? $matches[4] : '';

		$original_url = $base_url . '.' . $extension . $query . $fragment;

		// Skip if already .webp
		if ( preg_match( '/\.webp($|[?#])/i', $original_url ) ) {
			return $full_url;
		}

		$upload_dir = wp_upload_dir();
		$home_url = home_url();
		$site_url = site_url();
		$cdn_base_url = apply_filters( 'wpmh_cdn_base_url', '' );

		$upload_baseurl_lower = strtolower( $upload_dir['baseurl'] );
		$home_url_lower = strtolower( $home_url );
		$site_url_lower = strtolower( $site_url );
		$original_url_lower = strtolower( $original_url );
		
		$is_upload_url = strpos( $original_url_lower, $upload_baseurl_lower ) !== false;
		$is_home_url = strpos( $original_url_lower, $home_url_lower ) !== false;
		$is_site_url = strpos( $original_url_lower, $site_url_lower ) !== false;
		$is_cdn_url = ! empty( $cdn_base_url ) && strpos( $original_url_lower, strtolower( $cdn_base_url ) ) !== false;
		$is_relative_url = ( '/' === substr( $original_url, 0, 1 ) );
		
		if ( ! $is_upload_url && ! $is_home_url && ! $is_site_url && ! $is_cdn_url && ! $is_relative_url ) {
			return $full_url; // External URL, skip
		}

		// Convert URL to file path
		$file_path = '';
		if ( $is_upload_url ) {
			$file_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $original_url );
		} elseif ( $is_cdn_url ) {
			$file_path = str_replace( $cdn_base_url, $upload_dir['basedir'], $original_url );
		} elseif ( $is_home_url || $is_site_url ) {
			$relative_path = str_replace( array( $home_url, $site_url ), '', $original_url );
			$file_path = ABSPATH . ltrim( $relative_path, '/' );
		} elseif ( $is_relative_url ) {
			$file_path = ABSPATH . ltrim( $original_url, '/' );
		}

		// Remove query string and fragment
		$path_parts = explode( '?', $file_path, 2 );
		$file_path = $path_parts[0];
		$path_parts = explode( '#', $file_path, 2 );
		$file_path = $path_parts[0];

		// Normalize path separators
		$file_path = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $file_path );

		// Check file existence
		$require_local_file = apply_filters( 'wpmh_require_local_file', true );
		if ( $require_local_file && ! file_exists( $file_path ) ) {
			return $full_url;
		}

		// Check if WebP version exists
		$webp_path = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $file_path );

		if ( $require_local_file && ! file_exists( $webp_path ) ) {
			return $full_url;
		}

		// Convert back to URL
		if ( $is_upload_url ) {
			$webp_url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $webp_path );
		} elseif ( $is_cdn_url ) {
			$webp_url = str_replace( $upload_dir['basedir'], $cdn_base_url, $webp_path );
		} elseif ( $is_home_url || $is_site_url ) {
			$relative_path = str_replace( ABSPATH, '', $webp_path );
			$webp_url = ( $is_home_url ? $home_url : $site_url ) . '/' . str_replace( '\\', '/', ltrim( $relative_path, '/' ) );
		} elseif ( $is_relative_url ) {
			$relative_path = str_replace( ABSPATH, '', $webp_path );
			$webp_url = '/' . str_replace( '\\', '/', ltrim( $relative_path, '/' ) );
		} else {
			return $full_url;
		}

		$webp_url = str_replace( '\\', '/', $webp_url );
		
		return $webp_url . $query . $fragment;
	}

	/**
	 * Count URL replacements
	 *
	 * @param string $original Original content.
	 * @param string $new New content.
	 * @return int Replacement count.
	 */
	private function count_replacements( $original, $new ) {
		if ( ! is_string( $original ) || ! is_string( $new ) ) {
			return 0;
		}

		$original_count = 0;
		$extensions = array( '.jpg', '.jpeg', '.png' );
		foreach ( $extensions as $ext ) {
			$original_count += substr_count( strtolower( $original ), strtolower( $ext ) );
		}

		$new_count = 0;
		foreach ( $extensions as $ext ) {
			$new_count += substr_count( strtolower( $new ), strtolower( $ext ) );
		}

		$webp_count = substr_count( strtolower( $new ), '.webp' );

		return max( 0, $original_count - $new_count + $webp_count );
	}

	/**
	 * Get feature description
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'One-time action to replace JPG/JPEG/PNG image URLs with WebP URLs throughout the WordPress database. Uses cursor-based pagination for reliable completion. Handles serialized data, JSON data, srcset attributes, CSS background-image, and Gutenberg block attributes. Only replaces URLs if the corresponding WebP file exists. Never replaces external URLs. Supports dry-run mode. This action must be explicitly triggered.', 'webp-media-handler' );
	}
}
