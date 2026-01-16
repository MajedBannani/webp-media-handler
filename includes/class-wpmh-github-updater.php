<?php
/**
 * GitHub Updater
 *
 * Enables WordPress to check for plugin updates from GitHub Releases.
 *
 * @package WebPMediaHandler
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Updater Class
 */
class WPMH_GitHub_Updater {

	/**
	 * GitHub API endpoint for latest release
	 *
	 * @var string
	 */
	private $api_url = 'https://api.github.com/repos/MajedBannani/webp-media-handler/releases/latest';

	/**
	 * Plugin basename
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Current plugin version
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Transient name for caching release data
	 *
	 * @var string
	 */
	private $transient_name = 'wpmh_github_release_data';

	/**
	 * Constructor
	 *
	 * @param string $plugin_basename Plugin basename.
	 * @param string $current_version Current plugin version.
	 */
	public function __construct( $plugin_basename, $current_version ) {
		$this->plugin_basename = $plugin_basename;
		$this->current_version = $current_version;
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Hook into WordPress update system
		add_filter( 'site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api_filter' ), 10, 3 );
		
		// Validate ZIP structure before installation to prevent folder mismatches
		// WordPress requires the extracted folder to match the installed plugin folder exactly
		// Otherwise it treats the update as a different plugin and deactivates it
		add_filter( 'upgrader_source_selection', array( $this, 'filter_source_selection' ), 10, 4 );
		
		// Clear cache when update is complete
		add_action( 'upgrader_process_complete', array( $this, 'clear_transient_cache' ), 10, 2 );
	}

	/**
	 * Check for plugin updates
	 *
	 * @param object|false $transient Update transient object or false.
	 * @return object|false Modified transient object or false.
	 */
	public function check_for_update( $transient ) {
		// FIX A1 & B1: MUST immediately return if not object or checked is empty
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Only run in admin area
		if ( ! is_admin() ) {
			return $transient;
		}

		// FIX A2: Ensure we have the correct plugin basename key
		// The plugin_basename must match exactly what WordPress uses (e.g., 'webp-media-handler/webp-media-handler.php')
		if ( ! isset( $transient->checked[ $this->plugin_basename ] ) ) {
			// Plugin not in checked array - skip
			return $transient;
		}

		// FIX B2: Get LOCAL version from WordPress checked array, not our stored value
		$local_version = $transient->checked[ $this->plugin_basename ] ?? '';
		if ( empty( $local_version ) ) {
			return $transient;
		}

		// Get latest release data
		$release_data = $this->get_latest_release();

		if ( ! $release_data || is_wp_error( $release_data ) ) {
			return $transient;
		}

		// Find ZIP asset from release
		$zip_asset_url = $this->get_zip_asset_url( $release_data );
		if ( ! $zip_asset_url ) {
			// No valid ZIP asset found - do not provide update
			return $transient;
		}

		// FIX B3: Normalize versions FIRST, before ANY logic
		$remote_version = ltrim( trim( $release_data->tag_name ), 'v' );
		$local_version  = trim( $local_version );

		// FIX C1: HARD STOP CONDITION - If remote <= local, return immediately
		if ( version_compare( $remote_version, $local_version, '<=' ) ) {
			// Ensure any previous injected entry is removed
			if ( isset( $transient->response ) && is_array( $transient->response ) ) {
				unset( $transient->response[ $this->plugin_basename ] );
			}
			// DO NOT create update object
			// RETURN $transient immediately
			return $transient;
		}

		// FIX C2: ONLY if remote_version > local_version - Build update object
		// FIX E: Do not initialize response array unless needed
		if ( ! isset( $transient->response ) ) {
			$transient->response = array();
		}

		// FIX A3: Use correct plugin basename key
		$transient->response[ $this->plugin_basename ] = (object) array(
			'slug'        => dirname( $this->plugin_basename ),
			'plugin'      => $this->plugin_basename,
			'new_version' => $remote_version,
			'url'         => 'https://github.com/MajedBannani/webp-media-handler',
			'package'     => $zip_asset_url,
		);

		return $transient;
	}

	/**
	 * Filter plugin information for the update details popup
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The type of information being requested from the Plugin Installation API.
	 * @param object             $args Plugin API arguments.
	 * @return false|object|array Modified result.
	 */
	public function plugins_api_filter( $result, $action, $args ) {
		// Only process our plugin
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$plugin_slug = dirname( $this->plugin_basename );
		if ( $plugin_slug !== $args->slug ) {
			return $result;
		}

		// Get latest release data
		$release_data = $this->get_latest_release();

		if ( ! $release_data || is_wp_error( $release_data ) ) {
			return $result;
		}

		// Find ZIP asset from release
		$zip_asset_url = $this->get_zip_asset_url( $release_data );
		if ( ! $zip_asset_url ) {
			// No valid ZIP asset found - return original result
			return $result;
		}

		$latest_version = $this->normalize_version( $release_data->tag_name );

		// Prepare plugin information
		$result = (object) array(
			'name'          => 'WebP Media Handler',
			'slug'          => $plugin_slug,
			'version'       => $latest_version,
			'author'        => '<a href="https://github.com/MajedBannani">Majed Talal</a>',
			'homepage'      => 'https://github.com/MajedBannani/webp-media-handler',
			'short_description' => 'Handle WebP image conversion and optimization with explicit control.',
			'download_link' => $zip_asset_url,
			'sections'      => array(
				'description' => __( 'Handle WebP image conversion and optimization with explicit control. Disable default image sizes, auto-convert new uploads, and convert existing media with clear action buttons.', 'webp-media-handler' ),
			),
		);

		return $result;
	}

	/**
	 * Get latest release data from GitHub
	 *
	 * @return object|WP_Error Release data object or WP_Error on failure.
	 */
	private function get_latest_release() {
		// Check cache first
		$cached_data = get_transient( $this->transient_name );
		if ( false !== $cached_data ) {
			return $cached_data;
		}

		// Fetch from GitHub API
		$response = wp_remote_get(
			$this->api_url,
			array(
				'timeout'    => 10,
				'user-agent' => 'WordPress; ' . home_url(),
			)
		);

		// Handle errors
		if ( is_wp_error( $response ) ) {
			// Cache error for a short time to avoid repeated failed requests
			set_transient( $this->transient_name, $response, 5 * MINUTE_IN_SECONDS );
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$error = new WP_Error(
				'github_api_error',
				sprintf(
					/* translators: %d: HTTP response code */
					__( 'GitHub API returned error code %d', 'webp-media-handler' ),
					$response_code
				)
			);
			// Cache error for a short time
			set_transient( $this->transient_name, $error, 5 * MINUTE_IN_SECONDS );
			return $error;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );

		if ( ! $data || ! isset( $data->tag_name ) || ! isset( $data->assets ) || ! is_array( $data->assets ) ) {
			$error = new WP_Error(
				'invalid_github_response',
				__( 'Invalid response from GitHub API', 'webp-media-handler' )
			);
			set_transient( $this->transient_name, $error, 5 * MINUTE_IN_SECONDS );
			return $error;
		}

		// Cache successful response for 12 hours
		set_transient( $this->transient_name, $data, 12 * HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Get ZIP asset URL from release data
	 *
	 * REQUIRES assets named exactly "webp-media-handler.zip".
	 * Does NOT fall back to other ZIPs to prevent folder structure mismatches.
	 * GitHub-generated source ZIPs (zipball_url) extract to versioned folders
	 * like webp-media-handler-v1.0.x/ which cause plugin deactivation.
	 *
	 * @param object $release_data Release data from GitHub API.
	 * @return string|false ZIP asset browser_download_url, or false if not found.
	 */
	private function get_zip_asset_url( $release_data ) {
		if ( ! isset( $release_data->assets ) || ! is_array( $release_data->assets ) ) {
			return false;
		}

		// Search for the exact asset name - no fallbacks to prevent folder mismatches
		foreach ( $release_data->assets as $asset ) {
			if ( ! isset( $asset->name ) || ! isset( $asset->browser_download_url ) ) {
				continue;
			}

			// Only accept the exact asset name: webp-media-handler.zip
			// This ensures the ZIP was built by our GitHub Action with correct structure
			if ( 'webp-media-handler.zip' === $asset->name ) {
				return $asset->browser_download_url;
			}
		}

		// No valid ZIP asset found - do not fall back to zipball_url or other ZIPs
		return false;
	}

	/**
	 * Validate extracted ZIP structure before WordPress installs it
	 *
	 * WordPress requires the extracted folder name to match the installed plugin folder
	 * exactly. If there's a mismatch, WordPress treats it as a different plugin and
	 * deactivates the original, causing "Plugin file does not exist" errors.
	 *
	 * This filter runs after WordPress extracts the ZIP but before it moves files.
	 * We validate that the extracted folder is exactly "webp-media-handler" and
	 * contains the main plugin file at the correct path.
	 *
	 * @param string|WP_Error $source        Source directory path.
	 * @param string          $remote_source Remote source directory.
	 * @param WP_Upgrader     $upgrader      Upgrader instance.
	 * @param array           $hook_extra    Hook extra arguments.
	 * @return string|WP_Error Source directory or WP_Error if validation fails.
	 */
	public function filter_source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
		// Only validate our plugin updates
		if ( ! isset( $upgrader->skin->plugin ) || $upgrader->skin->plugin !== $this->plugin_basename ) {
			return $source;
		}

		// If source is already an error, return it
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		// Get the extracted folder name
		$extracted_folder = basename( wp_normalize_path( untrailingslashit( $source ) ) );

		// WordPress requires exact folder name match: webp-media-handler
		if ( 'webp-media-handler' !== $extracted_folder ) {
			return new WP_Error(
				'wpmh_github_folder_mismatch',
				sprintf(
					/* translators: %s: Extracted folder name */
					__( 'Update package extracted to incorrect folder "%s". Expected "webp-media-handler". Update aborted to prevent plugin deactivation.', 'webp-media-handler' ),
					esc_html( $extracted_folder )
				)
			);
		}

		// Verify main plugin file exists at the correct path
		$main_file = trailingslashit( $source ) . 'webp-media-handler.php';
		if ( ! file_exists( $main_file ) ) {
			return new WP_Error(
				'wpmh_github_missing_main_file',
				__( 'Update package is missing the main plugin file (webp-media-handler.php). Update aborted.', 'webp-media-handler' )
			);
		}

		// Validation passed - allow WordPress to proceed with installation
		return $source;
	}

	/**
	 * Normalize version string by removing leading 'v' and trimming whitespace
	 *
	 * HARD NORMALIZATION: Ensures accurate version comparison by normalizing GitHub tags
	 * (e.g. "v1.0.13") to match installed version format (e.g. "1.0.13").
	 *
	 * @param string|mixed $version Version string (may be mixed type from API).
	 * @return string Normalized version string (plain string, guaranteed).
	 */
	private function normalize_version( $version ) {
		// Cast to string first to handle any mixed types
		$version = (string) $version;
		// Trim whitespace from both ends
		$version = trim( $version );
		// Remove leading 'v' or 'V' (case-insensitive)
		$version = ltrim( $version, 'vV' );
		// Final trim to ensure no remaining whitespace
		return trim( $version );
	}

	/**
	 * Clear transient cache after plugin update
	 *
	 * Clears both the GitHub release data cache and WordPress update transient
	 * to ensure update notifications disappear after successful update.
	 *
	 * @param WP_Upgrader $upgrader WP_Upgrader instance.
	 * @param array       $options  Array of bulk item update data.
	 */
	public function clear_transient_cache( $upgrader, $options ) {
		// Only process plugin updates
		if ( 'update' !== $options['action'] || 'plugin' !== $options['type'] ) {
			return;
		}

		// Check if this plugin was updated
		$plugin_updated = false;
		
		// Single plugin update
		if ( isset( $options['plugin'] ) && $options['plugin'] === $this->plugin_basename ) {
			$plugin_updated = true;
		}
		
		// Bulk plugin update
		if ( isset( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			if ( in_array( $this->plugin_basename, $options['plugins'], true ) ) {
				$plugin_updated = true;
			}
		}

		if ( $plugin_updated ) {
			// Clear GitHub release data cache
			delete_transient( $this->transient_name );
			
			// Clear WordPress update transient to remove update notifications
			// This ensures WordPress re-checks for updates on next page load
			delete_site_transient( 'update_plugins' );
		}
	}
}
