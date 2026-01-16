<?php
/**
 * Replace Existing Image URLs with WebP
 *
 * Comprehensive database-wide URL replacement system that processes:
 * - wp_posts (post_content, post_excerpt)
 * - wp_postmeta (meta_value)
 * - wp_options (option_value including theme_mods_*)
 * - wp_termmeta (meta_value)
 * - wp_usermeta (meta_value)
 * - wp_terms (description)
 * - wp_term_taxonomy (description)
 *
 * Handles serialized PHP data, JSON data, HTML/CSS (srcset, background-image), and Gutenberg blocks.
 * Only replaces URLs if corresponding .webp file exists. Never replaces external URLs.
 * Supports dry-run mode for safe testing.
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
	 * Batch size for processing
	 *
	 * @var int
	 */
	private $batch_size = 200;

	/**
	 * Dry run mode flag
	 *
	 * @var bool
	 */
	private $dry_run = false;

	/**
	 * Processing statistics
	 *
	 * @var array
	 */
	private $stats = array();

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

		// Get parameters
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$table = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';
		$dry_run = isset( $_POST['dry_run'] ) && '1' === $_POST['dry_run'];

		$this->dry_run = $dry_run;

		// Initialize statistics on first run
		if ( 0 === $offset && empty( $table ) ) {
			$this->stats = array(
				'replaced' => 0,
				'skipped_external' => 0,
				'skipped_no_webp' => 0,
				'tables' => array(),
			);
			$this->settings->log_action( 'replace_image_urls', array( 'stats' => $this->stats ) );
		} else {
			$log = $this->settings->get_action_log( 'replace_image_urls' );
			$this->stats = isset( $log['data']['stats'] ) ? $log['data']['stats'] : $this->stats;
		}

		// Process tables in sequence
		$tables = array( 'posts', 'postmeta', 'options', 'termmeta', 'usermeta', 'terms', 'term_taxonomy' );

		if ( empty( $table ) ) {
			$table = $tables[0];
		}

		$table_index = array_search( $table, $tables, true );
		if ( false === $table_index ) {
			wp_send_json_error( array( 'message' => __( 'Invalid table.', 'webp-media-handler' ) ) );
		}

		// Process current table
		$result = $this->process_table( $table, $offset );

		if ( $result['completed'] ) {
			// Move to next table
			$table_index++;
			if ( $table_index < count( $tables ) ) {
				$next_table = $tables[ $table_index ];
				wp_send_json_success( array(
					'message' => sprintf(
						/* translators: 1: Current table, 2: Next table */
						__( 'Completed %1$s. Processing %2$s...', 'webp-media-handler' ),
						$table,
						$next_table
					),
					'offset' => 0,
					'table' => $next_table,
					'completed' => false,
				) );
			} else {
				// All tables completed
				$this->finalize_stats();
				wp_send_json_success( array(
					'message' => $this->build_completion_message(),
					'completed' => true,
					'stats' => $this->stats,
				) );
			}
		} else {
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: 1: Table name, 2: Processed count, 3: Total count */
					__( 'Processing %1$s... %2$d of %3$d rows processed.', 'webp-media-handler' ),
					$table,
					$result['processed'],
					$result['total']
				),
				'offset' => $result['processed'],
				'table' => $table,
				'completed' => false,
			) );
		}
	}

	/**
	 * Process a specific table
	 *
	 * @param string $table_name Table name to process.
	 * @param int    $offset Offset for pagination.
	 * @return array Result array with completion status.
	 */
	private function process_table( $table_name, $offset = 0 ) {
		global $wpdb;

		$method_name = 'process_' . $table_name;
		if ( method_exists( $this, $method_name ) ) {
			return call_user_func( array( $this, $method_name ), $offset );
		}

		return array( 'completed' => true, 'processed' => 0, 'total' => 0 );
	}

	/**
	 * Process wp_posts table
	 *
	 * @param int $offset Offset for pagination.
	 * @return array Result array.
	 */
	private function process_posts( $offset = 0 ) {
		global $wpdb;

		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} 
				WHERE (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s
				       OR post_excerpt LIKE %s OR post_excerpt LIKE %s OR post_excerpt LIKE %s)
				LIMIT %d OFFSET %d",
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				$this->batch_size,
				$offset
			)
		);

		$rows_updated = 0;
		$rows_scanned = count( $posts );

		foreach ( $posts as $post ) {
			$update_data = array();
			$has_changes = false;

			// Process post_content
			$new_content = $this->replace_urls_in_value( $post->post_content );
			if ( $new_content !== $post->post_content ) {
				$update_data['post_content'] = $new_content;
				$has_changes = true;
			}

			// Process post_excerpt
			$new_excerpt = $this->replace_urls_in_value( $post->post_excerpt );
			if ( $new_excerpt !== $post->post_excerpt ) {
				$update_data['post_excerpt'] = $new_excerpt;
				$has_changes = true;
			}

			if ( $has_changes ) {
				if ( ! $this->dry_run ) {
					wp_update_post( array_merge( array( 'ID' => $post->ID ), $update_data ) );
				}
				$rows_updated++;
			}
		}

		$this->update_table_stats( 'posts', $rows_scanned, $rows_updated );

		$processed = $offset + count( $posts );
		return array(
			'completed' => $processed >= $total,
			'processed' => $processed,
			'total' => $total,
		);
	}

	/**
	 * Process wp_postmeta table
	 *
	 * @param int $offset Offset for pagination.
	 * @return array Result array.
	 */
	private function process_postmeta( $offset = 0 ) {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
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

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s
				       OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)
				LIMIT %d OFFSET %d",
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size,
				$offset
			)
		);

		return $this->process_meta_table( $rows, 'postmeta', $total, $offset );
	}

	/**
	 * Process wp_options table
	 *
	 * @param int $offset Offset for pagination.
	 * @return array Result array.
	 */
	private function process_options( $offset = 0 ) {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
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

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id as meta_id, option_name as meta_key, option_value as meta_value FROM {$wpdb->options}
				WHERE option_name NOT IN ('active_plugins', 'cron', 'rewrite_rules')
				  AND (option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s
				       OR option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s)
				LIMIT %d OFFSET %d",
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size,
				$offset
			)
		);

		$rows_updated = 0;
		$rows_scanned = count( $rows );

		foreach ( $rows as $row ) {
			$new_value = $this->replace_urls_in_value( $row->meta_value );
			if ( $new_value !== $row->meta_value ) {
				if ( ! $this->dry_run ) {
					update_option( $row->meta_key, $new_value );
				}
				$rows_updated++;
			}
		}

		$this->update_table_stats( 'options', $rows_scanned, $rows_updated );

		$processed = $offset + count( $rows );
		return array(
			'completed' => $processed >= $total,
			'processed' => $processed,
			'total' => $total,
		);
	}

	/**
	 * Process wp_termmeta table
	 *
	 * @param int $offset Offset for pagination.
	 * @return array Result array.
	 */
	private function process_termmeta( $offset = 0 ) {
		global $wpdb;

		if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->termmeta}'" ) ) {
			return array( 'completed' => true, 'processed' => 0, 'total' => 0 );
		}

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->termmeta}
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

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, term_id as post_id, meta_key, meta_value FROM {$wpdb->termmeta}
				WHERE (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s
				       OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)
				LIMIT %d OFFSET %d",
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size,
				$offset
			)
		);

		return $this->process_meta_table( $rows, 'termmeta', $total, $offset );
	}

	/**
	 * Process wp_usermeta table
	 *
	 * @param int $offset Offset for pagination.
	 * @return array Result array.
	 */
	private function process_usermeta( $offset = 0 ) {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta}
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

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id as meta_id, user_id as post_id, meta_key, meta_value FROM {$wpdb->usermeta}
				WHERE (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s
				       OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)
				LIMIT %d OFFSET %d",
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size,
				$offset
			)
		);

		return $this->process_meta_table( $rows, 'usermeta', $total, $offset );
	}

	/**
	 * Process wp_terms table (description field)
	 *
	 * @param int $offset Offset for pagination.
	 * @return array Result array.
	 */
	private function process_terms( $offset = 0 ) {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->terms}
				WHERE (description LIKE %s OR description LIKE %s OR description LIKE %s
				       OR description LIKE %s OR description LIKE %s OR description LIKE %s)",
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%'
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, description FROM {$wpdb->terms}
				WHERE (description LIKE %s OR description LIKE %s OR description LIKE %s
				       OR description LIKE %s OR description LIKE %s OR description LIKE %s)
				LIMIT %d OFFSET %d",
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size,
				$offset
			)
		);

		$rows_updated = 0;
		$rows_scanned = count( $rows );

		foreach ( $rows as $row ) {
			$new_description = $this->replace_urls_in_value( $row->description );
			if ( $new_description !== $row->description ) {
				if ( ! $this->dry_run ) {
					$wpdb->update(
						$wpdb->terms,
						array( 'description' => $new_description ),
						array( 'term_id' => $row->term_id ),
						array( '%s' ),
						array( '%d' )
					);
				}
				$rows_updated++;
			}
		}

		$this->update_table_stats( 'terms', $rows_scanned, $rows_updated );

		$processed = $offset + count( $rows );
		return array(
			'completed' => $processed >= $total,
			'processed' => $processed,
			'total' => $total,
		);
	}

	/**
	 * Process wp_term_taxonomy table (description field)
	 *
	 * @param int $offset Offset for pagination.
	 * @return array Result array.
	 */
	private function process_term_taxonomy( $offset = 0 ) {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy}
				WHERE (description LIKE %s OR description LIKE %s OR description LIKE %s
				       OR description LIKE %s OR description LIKE %s OR description LIKE %s)",
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%'
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_taxonomy_id, description FROM {$wpdb->term_taxonomy}
				WHERE (description LIKE %s OR description LIKE %s OR description LIKE %s
				       OR description LIKE %s OR description LIKE %s OR description LIKE %s)
				LIMIT %d OFFSET %d",
				'%' . $wpdb->esc_like( '.jpg' ) . '%',
				'%' . $wpdb->esc_like( '.jpeg' ) . '%',
				'%' . $wpdb->esc_like( '.png' ) . '%',
				'%' . $wpdb->esc_like( '.JPG' ) . '%',
				'%' . $wpdb->esc_like( '.JPEG' ) . '%',
				'%' . $wpdb->esc_like( '.PNG' ) . '%',
				$this->batch_size,
				$offset
			)
		);

		$rows_updated = 0;
		$rows_scanned = count( $rows );

		foreach ( $rows as $row ) {
			$new_description = $this->replace_urls_in_value( $row->description );
			if ( $new_description !== $row->description ) {
				if ( ! $this->dry_run ) {
					$wpdb->update(
						$wpdb->term_taxonomy,
						array( 'description' => $new_description ),
						array( 'term_taxonomy_id' => $row->term_taxonomy_id ),
						array( '%s' ),
						array( '%d' )
					);
				}
				$rows_updated++;
			}
		}

		$this->update_table_stats( 'term_taxonomy', $rows_scanned, $rows_updated );

		$processed = $offset + count( $rows );
		return array(
			'completed' => $processed >= $total,
			'processed' => $processed,
			'total' => $total,
		);
	}

	/**
	 * Process meta table rows (postmeta, termmeta, usermeta)
	 *
	 * @param array  $rows Rows to process.
	 * @param string $table_name Table name for stats.
	 * @param int    $total Total rows in table.
	 * @param int    $offset Current offset.
	 * @return array Result array.
	 */
	private function process_meta_table( $rows, $table_name, $total, $offset ) {
		global $wpdb;

		$rows_updated = 0;
		$rows_scanned = count( $rows );

		foreach ( $rows as $row ) {
			$new_value = $this->replace_urls_in_value( $row->meta_value );
			if ( $new_value !== $row->meta_value ) {
				if ( ! $this->dry_run ) {
					switch ( $table_name ) {
						case 'postmeta':
							update_post_meta( $row->post_id, $row->meta_key, $new_value );
							break;
						case 'termmeta':
							update_term_meta( $row->post_id, $row->meta_key, $new_value );
							break;
						case 'usermeta':
							update_user_meta( $row->post_id, $row->meta_key, $new_value );
							break;
					}
				}
				$rows_updated++;
			}
		}

		$this->update_table_stats( $table_name, $rows_scanned, $rows_updated );

		$processed = $offset + count( $rows );
		return array(
			'completed' => $processed >= $total,
			'processed' => $processed,
			'total' => $total,
		);
	}

	/**
	 * Update table statistics
	 *
	 * @param string $table_name Table name.
	 * @param int    $scanned Rows scanned.
	 * @param int    $updated Rows updated.
	 */
	private function update_table_stats( $table_name, $scanned, $updated ) {
		if ( ! isset( $this->stats['tables'][ $table_name ] ) ) {
			$this->stats['tables'][ $table_name ] = array(
				'scanned' => 0,
				'updated' => 0,
			);
		}
		$this->stats['tables'][ $table_name ]['scanned'] += $scanned;
		$this->stats['tables'][ $table_name ]['updated'] += $updated;

		// Save stats
		$this->settings->log_action( 'replace_image_urls', array( 'stats' => $this->stats ) );
	}

	/**
	 * Finalize statistics
	 */
	private function finalize_stats() {
		// Calculate totals
		$total_replaced = 0;
		foreach ( $this->stats['tables'] as $table_stats ) {
			$total_replaced += $table_stats['updated'];
		}
		$this->stats['replaced'] = $total_replaced;

		$this->settings->log_action( 'replace_image_urls', array( 'stats' => $this->stats ) );
	}

	/**
	 * Build completion message with statistics
	 *
	 * @return string Completion message.
	 */
	private function build_completion_message() {
		$mode = $this->dry_run ? __( 'Dry run completed', 'webp-media-handler' ) : __( 'URL replacement complete', 'webp-media-handler' );
		
		$message = $mode . '! ';
		
		// Table breakdown
		$table_messages = array();
		foreach ( $this->stats['tables'] as $table => $stats ) {
			if ( $stats['scanned'] > 0 ) {
				$table_messages[] = sprintf(
					/* translators: 1: Table name, 2: Scanned count, 3: Updated count */
					__( '%1$s: %2$d scanned, %3$d updated', 'webp-media-handler' ),
					$table,
					$stats['scanned'],
					$stats['updated']
				);
			}
		}

		if ( ! empty( $table_messages ) ) {
			$message .= implode( '; ', $table_messages ) . '. ';
		}

		// Total replacements
		$message .= sprintf(
			/* translators: %d: Total replacements */
			__( 'Total replacements: %d.', 'webp-media-handler' ),
			$this->stats['replaced']
		);

		return $message;
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

			// Plain string - process directly (handles srcset, HTML, CSS, etc.)
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
	 * Replace URLs in a string (handles srcset, HTML attributes, CSS, etc.)
	 *
	 * @param string $content Content to process.
	 * @return string Processed content.
	 */
	private function replace_urls_in_string( $content ) {
		if ( empty( $content ) || ! is_string( $content ) ) {
			return $content;
		}

		// Handle srcset attribute (special case: multiple URLs with descriptors)
		if ( preg_match( '/srcset\s*=\s*["\']([^"\']+)["\']/i', $content ) ) {
			$content = preg_replace_callback(
				'/srcset\s*=\s*["\']([^"\']+)["\']/i',
				array( $this, 'replace_srcset_callback' ),
				$content
			);
		}

		// Handle CSS background-image: url(...)
		$content = preg_replace_callback(
			'/background-image\s*:\s*url\s*\(\s*["\']?([^"\'()]+)["\']?\s*\)/i',
			array( $this, 'replace_css_url_callback' ),
			$content
		);

		// IMPROVED: Case-insensitive regex matching .jpg, .jpeg, .png with query strings and hashes
		// Pattern matches:
		// - Absolute URLs: http:// or https://
		// - Relative URLs: /path/to/image
		// - Extensions: .jpg, .jpeg, .png (case-insensitive: JPG, JPEG, PNG, etc.)
		// - Query strings: ?param=value
		// - URL fragments: #hash
		// - Works in HTML attributes, JSON, Gutenberg blocks, etc.
		$patterns = array(
			// Absolute URLs with protocol (https:// or http://)
			'/(https?:\/\/[^\s"\'<>\[\]{}()]+?)\.(jpg|jpeg|png)(\?[^\s"\'<>\[\]{}()]*)?(#[^\s"\'<>\[\]{}()]*)?/i',
			// Relative URLs starting with / (site-relative paths)
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

		// Split srcset by commas, process each URL token
		$tokens = explode( ',', $srcset_content );
		$processed_tokens = array();

		foreach ( $tokens as $token ) {
			$token = trim( $token );
			// Extract URL (before space/descriptor) and descriptor
			if ( preg_match( '/^(.+?\.(jpg|jpeg|png)(\?[^\s]*)?(#[^\s]*)?)\s*(.+)$/i', $token, $token_matches ) ) {
				$url_part = $token_matches[1];
				$descriptor = isset( $token_matches[5] ) ? ' ' . trim( $token_matches[5] ) : '';
				
				// Replace URL part
				$replaced_url = preg_replace_callback(
					'/(.+?)\.(jpg|jpeg|png)(\?[^\s]*)?(#[^\s]*)?$/i',
					array( $this, 'replace_url_callback' ),
					$url_part
				);
				$processed_tokens[] = $replaced_url . $descriptor;
			} else {
				// No descriptor, just URL
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

		// Replace URL in CSS url()
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
	 * @param array $matches Matched URL parts from regex.
	 * @return string Replacement URL or original if WebP doesn't exist.
	 */
	private function replace_url_callback( $matches ) {
		$full_url = $matches[0];
		$base_url = $matches[1];
		$extension = strtolower( $matches[2] ); // Normalize to lowercase
		$query = isset( $matches[3] ) ? $matches[3] : '';
		$fragment = isset( $matches[4] ) ? $matches[4] : '';

		// Reconstruct the full original URL
		$original_url = $base_url . '.' . $extension . $query . $fragment;

		// Skip if already .webp
		if ( preg_match( '/\.webp($|[?#])/i', $original_url ) ) {
			return $full_url;
		}

		// Get allowed URL bases (upload dir, home URL, site URL)
		$upload_dir = wp_upload_dir();
		$home_url = home_url();
		$site_url = site_url();

		// Optional: Check for CDN base URL from settings (future extension)
		$cdn_base_url = apply_filters( 'wpmh_cdn_base_url', '' );

		// Check if URL is from this site (case-insensitive comparison)
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
			$this->stats['skipped_external']++;
			return $full_url; // External URL, skip
		}

		// Convert URL to file path
		$file_path = '';
		if ( $is_upload_url ) {
			// URL is in uploads directory
			$file_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $original_url );
		} elseif ( $is_cdn_url ) {
			// CDN URL - map to local uploads directory
			$file_path = str_replace( $cdn_base_url, $upload_dir['basedir'], $original_url );
		} elseif ( $is_home_url || $is_site_url ) {
			// URL is relative to site root
			$relative_path = str_replace( array( $home_url, $site_url ), '', $original_url );
			$file_path = ABSPATH . ltrim( $relative_path, '/' );
		} elseif ( $is_relative_url ) {
			// Relative URL starting with /
			$file_path = ABSPATH . ltrim( $original_url, '/' );
		}

		// Remove query string and fragment from file path
		$path_parts = explode( '?', $file_path, 2 );
		$file_path = $path_parts[0];
		$path_parts = explode( '#', $file_path, 2 );
		$file_path = $path_parts[0];

		// Normalize path separators
		$file_path = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $file_path );

		// Check if original file exists (safety check)
		// Allow skipping file existence check if CDN mode is enabled (optional setting)
		$require_local_file = apply_filters( 'wpmh_require_local_file', true );
		if ( $require_local_file && ! file_exists( $file_path ) ) {
			$this->stats['skipped_no_webp']++;
			return $full_url;
		}

		// Check if WebP version exists (case-insensitive extension replacement)
		$webp_path = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $file_path );

		if ( $require_local_file && ! file_exists( $webp_path ) ) {
			$this->stats['skipped_no_webp']++;
			return $full_url;
		}

		// Convert back to URL (preserve original URL structure)
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

		// Normalize URL separators
		$webp_url = str_replace( '\\', '/', $webp_url );
		
		// Preserve query string and fragment
		$this->stats['replaced']++;
		return $webp_url . $query . $fragment;
	}

	/**
	 * Get feature description
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'One-time action to replace JPG/JPEG/PNG image URLs with WebP URLs throughout the WordPress database (posts, postmeta, options, termmeta, usermeta, terms, term_taxonomy). Handles serialized data, JSON data, srcset attributes, CSS background-image, and Gutenberg block attributes. Only replaces URLs if the corresponding WebP file exists. Never replaces external URLs. Supports dry-run mode. This action must be explicitly triggered.', 'webp-media-handler' );
	}
}
