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
	 * Debug log file path
	 *
	 * @var string|null
	 */
	private $debug_log_file = null;

	/**
	 * Protected keys that must remain as attachment IDs (never converted to URLs)
	 * These are WordPress core theme_mod keys that must always be integers
	 *
	 * @var array
	 */
	private $protected_id_keys = array(
		'custom_logo',
		'site_icon',
		'header_image',
		'background_image',
	);

	/**
	 * Audit log storage
	 *
	 * @var array
	 */
	private $audit_log = array();

	/**
	 * Constructor
	 *
	 * @param WPMH_Settings_Manager $settings Settings manager instance.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
		$this->init_debug_log();
		$this->init_hooks();
	}

	/**
	 * Initialize debug log file path
	 */
	private function init_debug_log() {
		$upload_dir = wp_upload_dir();
		$log_dir = $upload_dir['basedir'] . '/webp-media-handler';
		
		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}
		
		$this->debug_log_file = $log_dir . '/replace-urls-debug.log';
	}

	/**
	 * Write debug log entry
	 *
	 * @param array $data Debug data to log.
	 */
	private function write_debug_log( $data ) {
		if ( ! $this->debug_log_file ) {
			return;
		}

		$entry = array(
			'timestamp' => current_time( 'mysql' ),
			'data' => $data,
		);

		$log_line = wp_json_encode( $entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" . str_repeat( '-', 80 ) . "\n";
		file_put_contents( $this->debug_log_file, $log_line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Build comprehensive error payload
	 *
	 * @param Exception|Error|string $error Error object or message.
	 * @param array                  $context Additional context data.
	 * @return array Error payload.
	 */
	private function build_error_payload( $error, $context = array() ) {
		$payload = array(
			'error_message' => is_string( $error ) ? $error : $error->getMessage(),
			'error_type' => is_object( $error ) ? get_class( $error ) : 'string',
			'timestamp' => current_time( 'mysql' ),
		);

		if ( $error instanceof Exception || $error instanceof Error ) {
			$payload['file'] = $error->getFile();
			$payload['line'] = $error->getLine();
			$payload['stack'] = $error->getTraceAsString();
		}

		// Add context
		$payload = array_merge( $payload, $context );

		return $payload;
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// New AJAX handlers for job-based processing
		add_action( 'wp_ajax_wpmh_start_replace_job', array( $this, 'handle_start_job' ) );
		add_action( 'wp_ajax_wpmh_run_replace_batch', array( $this, 'handle_run_batch' ) );
		add_action( 'wp_ajax_wpmh_reset_replace_job', array( $this, 'handle_reset_job' ) );
		add_action( 'wp_ajax_wpmh_rollback_replace', array( $this, 'handle_rollback' ) );
		add_action( 'wp_ajax_wpmh_view_replace_log', array( $this, 'handle_view_log' ) );
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
		// Also clear any job locks
		delete_transient( $this->get_job_state_key() . '_lock' );
	}

	/**
	 * Handle start job AJAX action
	 */
	public function handle_start_job() {
		// Clean output buffer to avoid any interference
		if ( ob_get_level() ) {
			ob_clean();
		}

		$last_step = 'init';
		$context = array(
			'last_step' => 'init',
			'action' => 'wpmh_start_replace_job',
		);

		try {
			$last_step = 'security_check_permissions';
			$context['last_step'] = $last_step;

			// Security checks
			if ( ! current_user_can( 'manage_options' ) ) {
				$error_msg = __( 'Insufficient permissions.', 'webp-media-handler' );
				$payload = $this->build_error_payload( $error_msg, $context );
				$this->write_debug_log( $payload );
				
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[WPMH Replace URLs] Permission denied: ' . $error_msg );
				}

				wp_send_json_error( array_merge(
					array( 'message' => $error_msg ),
					array( 'debug' => $payload, 'no_retry' => true )
				) );
				return;
			}

			$last_step = 'security_check_nonce';
			$context['last_step'] = $last_step;

			check_ajax_referer( 'wpmh_replace_image_urls', 'nonce' );

			// Get dry-run flag
			$dry_run = isset( $_POST['dry_run'] ) && '1' === $_POST['dry_run'];

			// Initialize job state - process theme_mods separately from options for special handling
			$tables = array( 'posts', 'theme_mods', 'options', 'postmeta', 'termmeta', 'usermeta', 'terms', 'term_taxonomy' );

			// Initialize audit log
			$this->audit_log = array();

			$state = array(
				'dry_run' => $dry_run,
				'tables' => $tables,
				'current_table_index' => 0,
				'current_table' => $tables[0],
				'last_id' => 0,
				'last_id_check' => 0, // For infinite loop detection
				'stuck_count' => 0,
				'stats' => array(
					'replaced' => 0,
					'skipped_external' => 0,
					'skipped_no_webp' => 0,
					'tables' => array(),
				),
				'samples' => array(), // Max 10 samples for dry-run
				'started_at' => time(),
				'audit_log' => array(), // Store audit log in state
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

		$context = array(
			'last_step' => 'init',
			'action' => 'wpmh_reset_replace_job',
		);

		try {
			// Security checks
			if ( ! current_user_can( 'manage_options' ) ) {
				$error_msg = __( 'Insufficient permissions.', 'webp-media-handler' );
				$payload = $this->build_error_payload( $error_msg, $context );
				$this->write_debug_log( $payload );
				wp_send_json_error( array_merge(
					array( 'message' => $error_msg ),
					array( 'debug' => $payload, 'no_retry' => true )
				) );
				return;
			}

			check_ajax_referer( 'wpmh_replace_image_urls', 'nonce' );

			// Delete job state and all related transients
			$this->delete_job_state();
			
			// Clear any retry counters or locks
			$state_key = $this->get_job_state_key();
			delete_transient( $state_key . '_lock' );
			delete_transient( $state_key . '_retry_count' );

			wp_send_json_success( array(
				'message' => __( 'Job reset successfully. All state cleared.', 'webp-media-handler' ),
			) );

		} catch ( Exception $e ) {
			$context['last_step'] = 'exception';
			$payload = $this->build_error_payload( $e, $context );
			$this->write_debug_log( $payload );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[WPMH Replace URLs] Reset job error: ' . $e->getMessage() );
			}

			wp_send_json_error( array_merge(
				array( 'message' => __( 'Failed to reset job: ', 'webp-media-handler' ) . $e->getMessage() ),
				array( 'debug' => $payload )
			) );
		} catch ( Error $e ) {
			$context['last_step'] = 'fatal_error';
			$payload = $this->build_error_payload( $e, $context );
			$this->write_debug_log( $payload );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[WPMH Replace URLs] Reset job fatal error: ' . $e->getMessage() );
			}

			wp_send_json_error( array_merge(
				array( 'message' => __( 'Fatal error resetting job: ', 'webp-media-handler' ) . $e->getMessage() ),
				array( 'debug' => $payload )
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
		$last_step = 'init';
		$context = array(
			'last_step' => 'init',
			'action' => 'wpmh_run_replace_batch',
		);

		try {
			$last_step = 'security_check_permissions';
			$context['last_step'] = $last_step;

			// Security checks
			if ( ! current_user_can( 'manage_options' ) ) {
				$error_msg = __( 'Insufficient permissions.', 'webp-media-handler' );
				$payload = $this->build_error_payload( $error_msg, $context );
				$this->write_debug_log( $payload );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[WPMH Replace URLs] Permission denied in batch: ' . $error_msg );
				}

				wp_send_json_error( array_merge(
					array( 'message' => $error_msg ),
					array( 'debug' => $payload, 'no_retry' => true )
				) );
				return;
			}

			$last_step = 'security_check_nonce';
			$context['last_step'] = $last_step;

			check_ajax_referer( 'wpmh_replace_image_urls', 'nonce' );

			$last_step = 'get_job_state';
			$context['last_step'] = $last_step;

			// Get job state
			$state = $this->get_job_state();
			if ( false === $state ) {
				$error_msg = __( 'Job state not found. Please start a new job.', 'webp-media-handler' );
				$payload = $this->build_error_payload( $error_msg, $context );
				$this->write_debug_log( $payload );

				wp_send_json_error( array_merge(
					array( 'message' => $error_msg ),
					array( 'debug' => $payload, 'no_retry' => true )
				) );
				return;
			}

			$last_step = 'validate_state';
			$context['last_step'] = $last_step;

			// Validate state structure
			if ( ! isset( $state['tables'] ) || ! is_array( $state['tables'] ) ) {
				$error_msg = __( 'Invalid job state: missing tables array.', 'webp-media-handler' );
				$payload = $this->build_error_payload( $error_msg, array_merge( $context, array( 'state_keys' => array_keys( $state ) ) ) );
				$this->write_debug_log( $payload );

				$this->delete_job_state();
				wp_send_json_error( array_merge(
					array( 'message' => $error_msg ),
					array( 'debug' => $payload, 'no_retry' => true )
				) );
				return;
			}

			// Ensure required state fields exist with defaults
			$state['current_table_index'] = isset( $state['current_table_index'] ) ? (int) $state['current_table_index'] : 0;
			$state['last_id'] = isset( $state['last_id'] ) ? (int) $state['last_id'] : 0;
			$state['last_id_check'] = isset( $state['last_id_check'] ) ? (int) $state['last_id_check'] : 0;
			$state['stuck_count'] = isset( $state['stuck_count'] ) ? (int) $state['stuck_count'] : 0;
			$state['current_table'] = isset( $state['current_table'] ) ? $state['current_table'] : ( isset( $state['tables'][0] ) ? $state['tables'][0] : 'posts' );

			// Validate batch_size
			$this->batch_size = max( 10, min( 500, $this->batch_size ) );

			$context['current_table'] = $state['current_table'];
			$context['current_table_index'] = $state['current_table_index'];
			$context['last_id'] = $state['last_id'];
			$context['batch_size'] = $this->batch_size;

			$last_step = 'check_cursor_stuck';
			$context['last_step'] = $last_step;
			
			// Include DB errors in context if any
			global $wpdb;
			if ( ! empty( $wpdb->last_error ) ) {
				$context['db_last_error'] = $wpdb->last_error;
				$context['db_last_query'] = $wpdb->last_query;
			}

			// Check for infinite loop (cursor not advancing)
			if ( $state['last_id'] === $state['last_id_check'] && $state['last_id'] > 0 ) {
				// Check if we've been stuck for more than 2 batches (safety check)
				if ( $state['stuck_count'] >= 2 ) {
					$error_msg = __( 'Cursor did not advance; possible query issue. Stopping to prevent infinite loop.', 'webp-media-handler' );
					$payload = $this->build_error_payload( $error_msg, array_merge( $context, array(
						'cursor_stuck' => true,
						'last_id' => $state['last_id'],
						'last_id_check' => $state['last_id_check'],
						'stuck_count' => $state['stuck_count'],
					) ) );
					$this->write_debug_log( $payload );

					$this->delete_job_state();
					wp_send_json_error( array_merge(
						array( 'message' => $error_msg ),
						array( 'debug' => $payload, 'no_retry' => true )
					) );
					return;
				}
				$state['stuck_count']++;
			} else {
				$state['stuck_count'] = 0;
				$state['last_id_check'] = $state['last_id'];
			}

			$last_step = 'validate_method';
			$context['last_step'] = $last_step;

			// Process current table batch
			$method_name = 'process_' . $state['current_table'];
			if ( ! method_exists( $this, $method_name ) ) {
				$error_msg = sprintf( __( 'Unknown table: %s', 'webp-media-handler' ), $state['current_table'] );
				$payload = $this->build_error_payload( $error_msg, array_merge( $context, array(
					'method_name' => $method_name,
					'available_methods' => array_filter( get_class_methods( $this ), function( $m ) { return strpos( $m, 'process_' ) === 0; } ),
				) ) );
				$this->write_debug_log( $payload );

				wp_send_json_error( array_merge(
					array( 'message' => $error_msg ),
					array( 'debug' => $payload, 'no_retry' => true )
				) );
				return;
			}

			$last_step = 'restore_audit_log';
			$context['last_step'] = $last_step;

			// Restore audit log from state
			if ( ! empty( $state['audit_log'] ) ) {
				$this->audit_log = $state['audit_log'];
			} else {
				$this->audit_log = array();
			}

			$last_step = 'call_process_method';
			$context['last_step'] = $last_step;
			$context['method_name'] = $method_name;

			$batch_result = call_user_func( array( $this, $method_name ), $state );

			// Validate batch_result structure
			if ( ! is_array( $batch_result ) || ! isset( $batch_result['state'] ) ) {
				$error_msg = __( 'Invalid batch result: missing state.', 'webp-media-handler' );
				$payload = $this->build_error_payload( $error_msg, array_merge( $context, array(
					'batch_result_type' => gettype( $batch_result ),
					'batch_result_keys' => is_array( $batch_result ) ? array_keys( $batch_result ) : 'not_array',
				) ) );
				$this->write_debug_log( $payload );

				wp_send_json_error( array_merge(
					array( 'message' => $error_msg ),
					array( 'debug' => $payload, 'no_retry' => false )
				) );
				return;
			}

			// Save audit log back to state
			$batch_result['state']['audit_log'] = $this->audit_log;

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

				if ( $state['current_table_index'] < ( is_countable( $state['tables'] ) ? count( $state['tables'] ) : 0 ) ) {
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

			$last_step = 'success';
			$context['last_step'] = $last_step;

		} catch ( Exception $e ) {
			$last_step = 'exception_' . $last_step;
			$context['last_step'] = $last_step;
			$context['exception_class'] = get_class( $e );

			// Include DB errors in error message if available
			global $wpdb;
			$error_message = __( 'Batch processing error: ', 'webp-media-handler' ) . $e->getMessage();
			if ( ! empty( $wpdb->last_error ) ) {
				$error_message .= ' [DB Error: ' . esc_html( $wpdb->last_error ) . ']';
				$context['db_last_error'] = $wpdb->last_error;
				$context['db_last_query'] = $wpdb->last_query;
			}
			
			$payload = $this->build_error_payload( $e, $context );
			$this->write_debug_log( $payload );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[WPMH Replace URLs] Batch error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
			}

			wp_send_json_error( array_merge(
				array( 'message' => $error_message ),
				array( 'debug' => $payload, 'no_retry' => false )
			) );
		} catch ( Error $e ) {
			$last_step = 'fatal_error_' . $last_step;
			$context['last_step'] = $last_step;
			$context['error_class'] = get_class( $e );

			$payload = $this->build_error_payload( $e, $context );
			$this->write_debug_log( $payload );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[WPMH Replace URLs] Batch fatal error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
			}

			wp_send_json_error( array_merge(
				array( 'message' => __( 'Fatal error in batch: ', 'webp-media-handler' ) . $e->getMessage() ),
				array( 'debug' => $payload, 'no_retry' => false )
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

		// Save audit log to file and option
		if ( ! empty( $state['audit_log'] ) ) {
			$this->save_audit_log( $state['audit_log'], $state['dry_run'] );
		}

		// Save final log
		$this->settings->log_action( 'replace_image_urls', array( 'stats' => $state['stats'] ) );

		// Delete job state
		$this->delete_job_state();
	}

	/**
	 * Save audit log to file and option
	 *
	 * @param array $audit_log Audit log entries.
	 * @param bool  $dry_run Whether this was a dry run.
	 */
	private function save_audit_log( $audit_log, $dry_run = false ) {
		$upload_dir = wp_upload_dir();
		$log_dir = $upload_dir['basedir'] . '/webp-media-handler';
		
		// Create directory if it doesn't exist
		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		$log_file = $log_dir . '/replace-urls-last-run.json';
		
		$log_data = array(
			'timestamp' => current_time( 'mysql' ),
			'dry_run' => $dry_run,
			'changes' => $audit_log,
			'summary' => $this->build_audit_summary( $audit_log ),
		);

		// Save to file
		file_put_contents( $log_file, wp_json_encode( $log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		// Save lightweight summary to option
		update_option( 'wpmh_replace_urls_last_run_summary', $log_data['summary'], false );
	}

	/**
	 * Build audit summary (counts per table + top changed keys)
	 *
	 * @param array $audit_log Audit log entries.
	 * @return array Summary data.
	 */
	private function build_audit_summary( $audit_log ) {
		$summary = array(
			'total_changes' => is_countable( $audit_log ) ? count( $audit_log ) : 0,
			'by_table' => array(),
			'top_keys' => array(),
		);

		$key_counts = array();

		foreach ( $audit_log as $entry ) {
			$table = $entry['table'];
			if ( ! isset( $summary['by_table'][ $table ] ) ) {
				$summary['by_table'][ $table ] = 0;
			}
			$summary['by_table'][ $table ]++;

			// Track option/meta keys
			$key_name = '';
			if ( 'options' === $table && isset( $entry['option_name'] ) ) {
				$key_name = $entry['option_name'];
			} elseif ( in_array( $table, array( 'postmeta', 'usermeta', 'termmeta' ), true ) && isset( $entry['meta_key'] ) ) {
				$key_name = $table . ':' . $entry['meta_key'];
			} elseif ( 'theme_mods' === $table && isset( $entry['mod_key'] ) ) {
				$key_name = 'theme_mods:' . $entry['mod_key'];
			}

			if ( $key_name ) {
				if ( ! isset( $key_counts[ $key_name ] ) ) {
					$key_counts[ $key_name ] = 0;
				}
				$key_counts[ $key_name ]++;
			}
		}

		// Get top 20 changed keys
		arsort( $key_counts );
		$summary['top_keys'] = array_slice( $key_counts, 0, 20, true );

		return $summary;
	}

	/**
	 * Add entry to audit log
	 *
	 * @param array $entry Audit log entry.
	 */
	private function add_audit_entry( $entry ) {
		// Limit audit log size to prevent memory issues (keep last 10000 entries)
		if ( ( is_countable( $this->audit_log ) ? count( $this->audit_log ) : 0 ) >= 10000 ) {
			$this->audit_log = array_slice( $this->audit_log, -9000 ); // Keep last 9000, allow 1000 more
		}
		$this->audit_log[] = $entry;
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

		// Get total count (approximate, for progress) - matches snippet: ALL post statuses/types
		$total_query = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d AND (post_content REGEXP '\\.(jpg|jpeg|png)' OR post_excerpt REGEXP '\\.(jpg|jpeg|png)')";
		$total = $wpdb->get_var( $wpdb->prepare( $total_query, $last_id ) );

		// Get batch using cursor - matches snippet pattern but for ALL post statuses/types
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
				WHERE ID > %d
				  AND (post_content REGEXP '\\.(jpg|jpeg|png)' OR post_excerpt REGEXP '\\.(jpg|jpeg|png)')
				ORDER BY ID ASC
				LIMIT %d",
				$last_id,
				$this->batch_size
			),
			OBJECT
		);

		// PHP 8+ strictness: $wpdb->get_results() can return null on error
		if ( ! is_array( $posts ) ) {
			// Log DB error for debugging
			$db_error_context = array(
				'last_step' => 'db_query_posts',
				'action' => 'wpmh_run_replace_batch',
				'table' => 'posts',
				'db_last_error' => $wpdb->last_error,
				'db_last_query' => $wpdb->last_query,
			);
			$this->write_debug_log( array_merge( $db_error_context, array(
				'error_message' => 'DB query returned null/false in process_posts',
				'error_type' => 'DBQueryError',
			) ) );
			$posts = array(); // Normalize to empty array
		}

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
		$rows_scanned = is_countable( $posts ) ? count( $posts ) : 0;
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
				// Audit log
				$audit_entry = array(
					'table' => 'posts',
					'primary_key' => $post->ID,
					'column' => 'post_content/post_excerpt',
					'before' => $this->truncate_for_log( $original_content . "\n---EXCERPT---\n" . $original_excerpt ),
					'after' => $this->truncate_for_log( $new_content . "\n---EXCERPT---\n" . $new_excerpt ),
					'replacements' => $replacements,
				);
				$this->add_audit_entry( $audit_entry );
				$state['audit_log'][] = $audit_entry;

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

		$completed = ( ( is_countable( $posts ) ? count( $posts ) : 0 ) < $this->batch_size );

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
			),
			OBJECT
		);

		// PHP 8+ strictness: normalize null/false to empty array
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

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
			),
			OBJECT
		);

		// PHP 8+ strictness: $wpdb->get_results() can return null on error
		if ( ! is_array( $theme_mods_options ) ) {
			// Log DB error for debugging
			$db_error_context = array(
				'last_step' => 'db_query_theme_mods',
				'action' => 'wpmh_run_replace_batch',
				'table' => 'theme_mods',
				'db_last_error' => $wpdb->last_error,
				'db_last_query' => $wpdb->last_query,
			);
			$this->write_debug_log( array_merge( $db_error_context, array(
				'error_message' => 'DB query returned null/false in process_theme_mods',
				'error_type' => 'DBQueryError',
			) ) );
			$theme_mods_options = array(); // Normalize to empty array
		}

		$rows_updated = 0;
		$rows_scanned = is_countable( $theme_mods_options ) ? count( $theme_mods_options ) : 0;
		$replacements = 0;

		foreach ( $theme_mods_options as $mod_option ) {
			$theme_mods = maybe_unserialize( $mod_option->option_value );
			
			if ( ! is_array( $theme_mods ) ) {
				continue;
			}

			$has_changes = false;
			$new_mods = $this->process_theme_mods_array( $theme_mods, $has_changes, $replacements, $mod_option->option_name, $state );

			if ( $has_changes ) {
				if ( ! $dry_run ) {
					// Extract theme name from option_name (theme_mods_{theme_name})
					$theme_name = str_replace( 'theme_mods_', '', $mod_option->option_name );
					$current_theme = get_option( 'stylesheet' );
					
					// Snippet uses get_theme_mods() which only gets active theme mods
					// For active theme, use set_theme_mod() for each changed key (like snippet)
					// For other themes, update the option directly
					if ( $theme_name === $current_theme ) {
						// Active theme - use set_theme_mod() like snippet
						foreach ( $new_mods as $key => $new_value ) {
							if ( ! isset( $theme_mods[ $key ] ) || $theme_mods[ $key ] !== $new_value ) {
								set_theme_mod( $key, $new_value );
							}
						}
					} else {
						// Other theme - update option directly (can't use set_theme_mod for inactive themes)
						update_option( $mod_option->option_name, $new_mods );
					}
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
	/**
	 * Process theme_mods array, handling attachment IDs and URLs
	 * CRITICAL: custom_logo and other protected keys must ALWAYS remain as integer attachment IDs
	 *
	 * @param array $mods Theme mods array.
	 * @param bool  $has_changes Output parameter - set to true if changes made.
	 * @param int   $replacements Output parameter - count of replacements.
	 * @param string $theme_mod_option_name Optional theme mod option name for audit logging.
	 * @return array Processed mods array.
	 */
	private function process_theme_mods_array( $mods, &$has_changes, &$replacements, $theme_mod_option_name = '', &$state = null ) {
		if ( ! is_array( $mods ) ) {
			return $mods;
		}

		$processed = array();
		$upload_dir = wp_upload_dir();

		// Allow filtering of protected keys
		$protected_keys = apply_filters( 'wpmh_protected_theme_mod_keys', $this->protected_id_keys );

		foreach ( $mods as $key => $value ) {
			// CRITICAL FIX: Protect known WordPress core ID keys (must remain integers)
			// Root cause: custom_logo was being converted from integer ID to URL string, breaking theme logo display
			if ( in_array( $key, $protected_keys, true ) ) {
				// This key must ALWAYS remain as an integer attachment ID
				if ( is_numeric( $value ) && $value > 0 ) {
					$attachment_id = absint( $value );
					$attachment_file = get_attached_file( $attachment_id );
					
					if ( $attachment_file && file_exists( $attachment_file ) && preg_match( '/\.(jpg|jpeg|png)$/i', $attachment_file ) ) {
						// Check if WebP version exists and has an attachment ID
						$webp_file = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $attachment_file );
						if ( file_exists( $webp_file ) ) {
							$webp_url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $webp_file );
							$webp_id = attachment_url_to_postid( $webp_url );
							
							if ( $webp_id && $webp_id > 0 ) {
								// Only update if we have a valid WebP attachment ID
								$processed[ $key ] = $webp_id;
								$has_changes = true;
								$replacements++;
								
			// Audit log (store in state)
				$audit_entry = array(
					'table' => 'theme_mods',
					'option_name' => $theme_mod_option_name,
					'mod_key' => $key,
					'primary_key' => $theme_mod_option_name . ':' . $key,
					'column' => 'theme_mod',
					'before' => $attachment_id,
					'after' => $webp_id,
					'replacements' => 1,
					'note' => 'Protected key updated: attachment ID converted to WebP attachment ID',
				);
				$this->add_audit_entry( $audit_entry );
				$state['audit_log'][] = $audit_entry;
								continue;
							}
						}
					}
					// Keep original ID if WebP attachment not found
					$processed[ $key ] = $value;
					continue;
				} else {
					// Protected key but not numeric - keep as-is (may already be corrupted, don't make it worse)
					$processed[ $key ] = $value;
					continue;
				}
			}

			// Process nested arrays
			if ( is_array( $value ) ) {
				$processed[ $key ] = $this->process_theme_mods_array( $value, $has_changes, $replacements, $theme_mod_option_name, $state );
			} elseif ( is_numeric( $value ) && $value > 0 ) {
				// Non-protected attachment ID - check if it's a valid attachment and has WebP
				$attachment_id = absint( $value );
				$attachment_file = get_attached_file( $attachment_id );
				
				if ( ! $attachment_file || ! file_exists( $attachment_file ) ) {
					$processed[ $key ] = $value;
					continue;
				}

				// Check if it's an image file
				if ( ! preg_match( '/\.(jpg|jpeg|png)$/i', $attachment_file ) ) {
					$processed[ $key ] = $value;
					continue;
				}

				// Check if WebP version exists
				$webp_file = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $attachment_file );
				if ( ! file_exists( $webp_file ) ) {
					$processed[ $key ] = $value;
					continue;
				}

				// Convert WebP file path to URL
				$webp_url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $webp_file );

				// Try to find WebP attachment ID (like snippet does)
				$webp_id = attachment_url_to_postid( $webp_url );
				if ( $webp_id && $webp_id > 0 ) {
					// Update to WebP attachment ID (must be valid ID, not URL)
					$processed[ $key ] = $webp_id;
					$has_changes = true;
					$replacements++;
					
					// Audit log (store in state)
					$audit_entry = array(
						'table' => 'theme_mods',
						'option_name' => $theme_mod_option_name,
						'mod_key' => $key,
						'primary_key' => $theme_mod_option_name . ':' . $key,
						'column' => 'theme_mod',
						'before' => $attachment_id,
						'after' => $webp_id,
						'replacements' => 1,
					);
					$this->add_audit_entry( $audit_entry );
				} else {
					// No attachment ID found for WebP URL, keep original ID (never convert to URL)
					$processed[ $key ] = $value;
				}
			} elseif ( is_string( $value ) && ! empty( $value ) ) {
				// Direct URL string - but skip if it looks like it might be builder JSON/config
				// Root cause analysis: Builder JSON (Bricks/Elementor) was being corrupted by URL replacement
				// because URLs inside JSON were replaced, breaking JSON structure or escaping
				if ( $this->should_skip_string_replacement( $key, $value, 'theme_mods' ) ) {
					$processed[ $key ] = $value;
					continue;
				}

				$original_value = $value;
				$new_value = $this->replace_urls_in_value( $value );
				if ( $new_value !== $original_value ) {
					$processed[ $key ] = $new_value;
					$has_changes = true;
					$replacements += $this->count_replacements( $original_value, $new_value );
					
					// Audit log (store in state)
					$audit_entry = array(
						'table' => 'theme_mods',
						'option_name' => $theme_mod_option_name,
						'mod_key' => $key,
						'primary_key' => $theme_mod_option_name . ':' . $key,
						'column' => 'theme_mod',
						'before' => $this->truncate_for_log( $original_value ),
						'after' => $this->truncate_for_log( $new_value ),
						'replacements' => $this->count_replacements( $original_value, $new_value ),
					);
					$this->add_audit_entry( $audit_entry );
					$state['audit_log'][] = $audit_entry;
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
				$wpdb->esc_like( 'theme_mods_' ) . '%',
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size
			),
			OBJECT
		);

		// PHP 8+ strictness: $wpdb->get_results() can return null on error
		if ( ! is_array( $rows ) ) {
			// Log DB error for debugging
			$db_error_context = array(
				'last_step' => 'db_query_options',
				'action' => 'wpmh_run_replace_batch',
				'table' => 'options',
				'db_last_error' => $wpdb->last_error,
				'db_last_query' => $wpdb->last_query,
			);
			$this->write_debug_log( array_merge( $db_error_context, array(
				'error_message' => 'DB query returned null/false in process_options',
				'error_type' => 'DBQueryError',
			) ) );
			$rows = array(); // Normalize to empty array for safe iteration
		}

		$rows_updated = 0;
		$rows_scanned = is_countable( $rows ) ? count( $rows ) : 0;
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
			// Check if this option key should be skipped (builder JSON protection)
			if ( $this->should_skip_string_replacement( $row->meta_key, $row->meta_value, 'options' ) ) {
				$state['last_id'] = $row->meta_id;
				continue;
			}

			$original_value = $row->meta_value;
			$new_value = $this->replace_urls_in_value( $original_value, $state );
			
			if ( $new_value !== $original_value ) {
				// Audit log
				$audit_entry = array(
					'table' => 'options',
					'option_name' => $row->meta_key,
					'primary_key' => $row->meta_key,
					'column' => 'option_value',
					'before' => $this->truncate_for_log( $original_value ),
					'after' => $this->truncate_for_log( $new_value ),
					'replacements' => $this->count_replacements( $original_value, $new_value ),
				);
				$this->add_audit_entry( $audit_entry );
				$state['audit_log'][] = $audit_entry;

				if ( ! $dry_run ) {
					update_option( $row->meta_key, $new_value );
				}
				$rows_updated++;
				$replacements += $this->count_replacements( $original_value, $new_value );
			}

			$state['last_id'] = $row->meta_id;
		}

		// Update stats
		$state['stats']['tables']['options']['scanned'] += $rows_scanned;
		$state['stats']['tables']['options']['updated'] += $rows_updated;
		$state['stats']['replaced'] += $replacements;

		$completed = ( ( is_countable( $rows ) ? count( $rows ) : 0 ) < $this->batch_size );

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
			),
			OBJECT
		);

		// PHP 8+ strictness: normalize null/false to empty array
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

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
			),
			OBJECT
		);

		// PHP 8+ strictness: normalize null/false to empty array
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

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
			),
			OBJECT
		);

		// PHP 8+ strictness: normalize null/false to empty array
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$rows_updated = 0;
		$rows_scanned = is_countable( $rows ) ? count( $rows ) : 0;
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
			$original_description = $row->description;
			$new_description = $this->replace_urls_in_value( $original_description, $state );
			
			if ( $new_description !== $original_description ) {
				// Audit log
				$audit_entry = array(
					'table' => 'terms',
					'primary_key' => $row->term_id,
					'column' => 'description',
					'before' => $this->truncate_for_log( $original_description ),
					'after' => $this->truncate_for_log( $new_description ),
					'replacements' => $this->count_replacements( $original_description, $new_description ),
				);
				$this->add_audit_entry( $audit_entry );
				$state['audit_log'][] = $audit_entry;

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
				$replacements += $this->count_replacements( $original_description, $new_description );
			}

			$state['last_id'] = $row->term_id;
		}

		// Update stats
		$state['stats']['tables']['terms']['scanned'] += $rows_scanned;
		$state['stats']['tables']['terms']['updated'] += $rows_updated;
		$state['stats']['replaced'] += $replacements;

		$completed = ( ( is_countable( $rows ) ? count( $rows ) : 0 ) < $this->batch_size );

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
			),
			OBJECT
		);

		// PHP 8+ strictness: normalize null/false to empty array
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$rows_updated = 0;
		$rows_scanned = is_countable( $rows ) ? count( $rows ) : 0;
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
			$original_description = $row->description;
			$new_description = $this->replace_urls_in_value( $original_description, $state );
			
			if ( $new_description !== $original_description ) {
				// Audit log
				$audit_entry = array(
					'table' => 'term_taxonomy',
					'primary_key' => $row->term_taxonomy_id,
					'column' => 'description',
					'before' => $this->truncate_for_log( $original_description ),
					'after' => $this->truncate_for_log( $new_description ),
					'replacements' => $this->count_replacements( $original_description, $new_description ),
				);
				$this->add_audit_entry( $audit_entry );
				$state['audit_log'][] = $audit_entry;

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
				$replacements += $this->count_replacements( $original_description, $new_description );
			}

			$state['last_id'] = $row->term_taxonomy_id;
		}

		// Update stats
		$state['stats']['tables']['term_taxonomy']['scanned'] += $rows_scanned;
		$state['stats']['tables']['term_taxonomy']['updated'] += $rows_updated;
		$state['stats']['replaced'] += $replacements;

		$completed = ( ( is_countable( $rows ) ? count( $rows ) : 0 ) < $this->batch_size );

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
		// PHP 8+ strictness: normalize null/false to empty array
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		
		$rows_updated = 0;
		$rows_scanned = is_countable( $rows ) ? count( $rows ) : 0;
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

		$completed = ( $rows_scanned < $this->batch_size );

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
	 * Enhanced with builder JSON protection to prevent corruption
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
				// Root cause: Serialized PHP data must be unserialized, processed recursively, then re-serialized
				// to avoid breaking serialized string length markers
				$processed = $this->replace_urls_recursive( $unserialized, $state );
				return maybe_serialize( $processed );
			}

			// Check if string is JSON data
			// Root cause: Builder JSON (Bricks/Elementor) was being corrupted by direct string replacement
			// URLs inside JSON were replaced, breaking JSON structure, escaping, or replacing IDs that must remain numeric
			if ( $this->is_json( $value ) ) {
				$decoded = json_decode( $value, true );
				if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
					// Value was JSON - process and re-encode
					// Only replace URLs in string fields, preserve numeric IDs
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
	 * Recursively replace URLs in arrays and objects (matches snippet walker pattern)
	 *
	 * @param mixed $data Data to process.
	 * @param array $state Job state (for sample collection).
	 * @return mixed Processed data.
	 */
	private function replace_urls_recursive( $data, &$state = null ) {
		// Snippet pattern: if (is_string($item)) return $replace_with_webp($item)
		if ( is_string( $data ) ) {
			return $this->replace_urls_in_string( $data, $state );
		}

		// Snippet pattern: if (is_array($item)) foreach ($item as $k => $v) $item[$k] = $walker($v)
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->replace_urls_recursive( $value, $state );
			}
			return $data;
		} elseif ( is_object( $data ) ) {
			// Handle objects (not in snippet, but needed for WordPress data)
			$new_object = new \stdClass();
			foreach ( $data as $key => $value ) {
				$new_object->$key = $this->replace_urls_recursive( $value, $state );
			}
			return $new_object;
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

		// Main URL patterns - matches snippet pattern: /(https?:\/\/[^\s"\')]+?\.(jpg|jpeg|png))/i
		// Extended to handle relative URLs and preserve query strings/hashes
		$patterns = array(
			'/(https?:\/\/[^\s"\'<>\[\]{}()]+?\.(jpg|jpeg|png))/i',
			'/(\/[^\s"\'<>\[\]{}()]+?\.(jpg|jpeg|png))/i',
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
	 * Callback for URL replacement (matches snippet behavior)
	 *
	 * @param array $matches Matched URL parts.
	 * @return string Replacement URL or original if WebP doesn't exist.
	 */
	private function replace_url_callback( $matches ) {
		$original_url = $matches[0]; // Full matched URL

		// Skip if already .webp
		if ( preg_match( '/\.webp($|[?#])/i', $original_url ) ) {
			return $original_url;
		}

		$upload_dir = wp_upload_dir();
		$home_url = home_url();
		$site_url = site_url();
		$cdn_base_url = apply_filters( 'wpmh_cdn_base_url', '' );

		// Check if URL belongs to this site (like snippet - only checks upload_dir baseurl)
		// Snippet only processes URLs from upload_dir, but we extend to home_url/site_url for completeness
		$upload_baseurl_lower = strtolower( $upload_dir['baseurl'] );
		$home_url_lower = strtolower( $home_url );
		$site_url_lower = strtolower( $site_url );
		$original_url_lower = strtolower( $original_url );
		
		$is_upload_url = strpos( $original_url_lower, $upload_baseurl_lower ) !== false;
		$is_home_url = strpos( $original_url_lower, $home_url_lower ) !== false;
		$is_site_url = strpos( $original_url_lower, $site_url_lower ) !== false;
		$is_cdn_url = ! empty( $cdn_base_url ) && strpos( $original_url_lower, strtolower( $cdn_base_url ) ) !== false;
		
		// Snippet only processes upload URLs, but requirements say to also check home_url/site_url
		if ( ! $is_upload_url && ! $is_home_url && ! $is_site_url && ! $is_cdn_url ) {
			return $original_url; // External URL, skip
		}

		// Convert URL to file path (like snippet)
		$file_path = '';
		if ( $is_upload_url ) {
			$file_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $original_url );
		} elseif ( $is_cdn_url ) {
			$file_path = str_replace( $cdn_base_url, $upload_dir['basedir'], $original_url );
		} elseif ( $is_home_url || $is_site_url ) {
			$relative_path = str_replace( array( $home_url, $site_url ), '', $original_url );
			$file_path = ABSPATH . ltrim( $relative_path, '/' );
		}

		// Remove query string and fragment from path
		$path_parts = explode( '?', $file_path, 2 );
		$file_path = $path_parts[0];
		$path_parts = explode( '#', $file_path, 2 );
		$file_path = $path_parts[0];

		// Normalize path separators
		$file_path = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $file_path );

		// Check if WebP version exists (like snippet)
		$webp_path = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $file_path );
		if ( ! file_exists( $webp_path ) ) {
			return $original_url;
		}

		// Replace extension in URL (preserving query string/hash like snippet)
		$webp_url = preg_replace( '/\.(jpg|jpeg|png)(\?|#|$)/i', '.webp$2', $original_url );
		
		return $webp_url;
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
	 * Check if string replacement should be skipped
	 * Protects builder JSON/config from corruption
	 *
	 * @param string $key Key name (option_name, meta_key, etc.).
	 * @param string $value Value to check.
	 * @param string $context Context (options, theme_mods, postmeta, etc.).
	 * @return bool True if should skip replacement.
	 */
	private function should_skip_string_replacement( $key, $value, $context ) {
		// Check filter hook for custom skip logic
		if ( apply_filters( 'wpmh_replace_skip_key', false, $key, $value, $context ) ) {
			return true;
		}

		// Skip known builder/config keys that contain JSON
		$builder_keys = array(
			'bricks_page_content',
			'_elementor_data',
			'_elementor_css',
			'nav_menu_options',
			'widget_',
			'sidebars_widgets',
		);

		foreach ( $builder_keys as $builder_key ) {
			if ( strpos( $key, $builder_key ) !== false ) {
				// This is likely builder JSON - be very conservative
				// Only process if it's clearly a simple URL string, not JSON
				if ( $this->is_json( $value ) || strlen( $value ) > 1000 ) {
					return true; // Skip large JSON/config strings
				}
			}
		}

		// Skip if it looks like JSON (large structured data)
		if ( $this->is_json( $value ) && strlen( $value ) > 500 ) {
			return true; // Skip large JSON structures
		}

		return false;
	}

	/**
	 * Truncate value for audit log (prevent huge log entries)
	 *
	 * @param mixed $value Value to truncate.
	 * @param int   $max_length Maximum length.
	 * @return string|int Truncated value.
	 */
	private function truncate_for_log( $value, $max_length = 500 ) {
		if ( is_numeric( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return '[Non-string: ' . gettype( $value ) . ']';
		}
		if ( strlen( $value ) <= $max_length ) {
			return $value;
		}
		return substr( $value, 0, $max_length ) . '... [truncated]';
	}

	/**
	 * Handle rollback AJAX action
	 */
	public function handle_rollback() {
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

			$upload_dir = wp_upload_dir();
			$log_file = $upload_dir['basedir'] . '/webp-media-handler/replace-urls-last-run.json';

			if ( ! file_exists( $log_file ) ) {
				wp_send_json_error( array(
					'message' => __( 'No log file found. Nothing to rollback.', 'webp-media-handler' ),
				) );
				return;
			}

			$log_data = json_decode( file_get_contents( $log_file ), true );
			if ( ! $log_data || empty( $log_data['changes'] ) ) {
				wp_send_json_error( array(
					'message' => __( 'Log file is empty or invalid.', 'webp-media-handler' ),
				) );
				return;
			}

			// Only allow rollback if it was NOT a dry-run
			if ( ! empty( $log_data['dry_run'] ) ) {
				wp_send_json_error( array(
					'message' => __( 'Cannot rollback a dry-run. No changes were made.', 'webp-media-handler' ),
				) );
				return;
			}

			$restored = 0;
			$errors = 0;

			foreach ( $log_data['changes'] as $entry ) {
				try {
					$restored += $this->rollback_entry( $entry );
				} catch ( Exception $e ) {
					$errors++;
					error_log( '[WPMH Rollback] Error restoring entry: ' . $e->getMessage() );
				}
			}

			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: 1: Restored count, 2: Errors count */
					__( 'Rollback complete. %1$d entries restored. %2$d errors.', 'webp-media-handler' ),
					$restored,
					$errors
				),
			) );

		} catch ( Exception $e ) {
			error_log( '[WPMH Replace URLs] Rollback error: ' . $e->getMessage() );
			wp_send_json_error( array(
				'message' => __( 'Rollback error: ', 'webp-media-handler' ) . $e->getMessage(),
			) );
		}
	}

	/**
	 * Rollback a single audit log entry
	 *
	 * @param array $entry Audit log entry.
	 * @return int 1 if restored, 0 if not.
	 */
	private function rollback_entry( $entry ) {
		global $wpdb;

		$table = $entry['table'];
		$before_value = $entry['before'];

		switch ( $table ) {
			case 'posts':
				$post_id = $entry['primary_key'];
				$column = $entry['column'];
				if ( strpos( $column, 'post_content' ) !== false ) {
					wp_update_post( array(
						'ID' => $post_id,
						'post_content' => $before_value,
					) );
				}
				if ( strpos( $column, 'post_excerpt' ) !== false ) {
					wp_update_post( array(
						'ID' => $post_id,
						'post_excerpt' => $before_value,
					) );
				}
				return 1;

			case 'theme_mods':
				$option_name = $entry['option_name'];
				$mod_key = $entry['mod_key'];
				$theme_name = str_replace( 'theme_mods_', '', $option_name );
				$current_theme = get_option( 'stylesheet' );
				
				if ( $theme_name === $current_theme ) {
					set_theme_mod( $mod_key, $before_value );
				} else {
					// For inactive themes, update option directly
					$theme_mods = maybe_unserialize( get_option( $option_name, array() ) );
					if ( is_array( $theme_mods ) ) {
						$theme_mods[ $mod_key ] = $before_value;
						update_option( $option_name, $theme_mods );
					}
				}
				return 1;

			case 'options':
				$option_name = $entry['option_name'];
				update_option( $option_name, $before_value );
				return 1;

			case 'postmeta':
				$post_id = $entry['post_id'];
				$meta_key = $entry['meta_key'];
				update_post_meta( $post_id, $meta_key, $before_value );
				return 1;

			case 'usermeta':
				$user_id = $entry['user_id'] ?? $entry['post_id'];
				$meta_key = $entry['meta_key'];
				update_user_meta( $user_id, $meta_key, $before_value );
				return 1;

			case 'termmeta':
				$term_id = $entry['term_id'] ?? $entry['post_id'];
				$meta_key = $entry['meta_key'];
				update_term_meta( $term_id, $meta_key, $before_value );
				return 1;

			case 'terms':
			case 'term_taxonomy':
				$term_id = $entry['primary_key'];
				$wpdb->update(
					$wpdb->$table,
					array( 'description' => $before_value ),
					array( 'term_id' => $term_id ),
					array( '%s' ),
					array( '%d' )
				);
				return 1;
		}

		return 0;
	}

	/**
	 * Handle view log AJAX action
	 */
	public function handle_view_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'webp-media-handler' ) ) );
			return;
		}

		$upload_dir = wp_upload_dir();
		$log_file = $upload_dir['basedir'] . '/webp-media-handler/replace-urls-last-run.json';

		if ( ! file_exists( $log_file ) ) {
			wp_send_json_error( array(
				'message' => __( 'No log file found.', 'webp-media-handler' ),
			) );
			return;
		}

		$log_data = json_decode( file_get_contents( $log_file ), true );
		if ( ! $log_data ) {
			wp_send_json_error( array(
				'message' => __( 'Log file is invalid.', 'webp-media-handler' ),
			) );
			return;
		}

		wp_send_json_success( array(
			'log' => $log_data,
			'summary' => isset( $log_data['summary'] ) ? $log_data['summary'] : array(),
		) );
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
