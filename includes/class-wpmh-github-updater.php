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
		
		// Clear cache when update is complete
		add_action( 'upgrader_process_complete', array( $this, 'clear_transient_cache' ), 10, 2 );
	}

	/**
	 * Check for plugin updates
	 *
	 * @param object $transient Update transient object.
	 * @return object Modified transient object.
	 */
	public function check_for_update( $transient ) {
		// Only run in admin area
		if ( ! is_admin() ) {
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

		// Extract version from tag (remove leading 'v' if present)
		$latest_version = $this->normalize_version( $release_data->tag_name );

		// Compare versions
		if ( version_compare( $this->current_version, $latest_version, '<' ) ) {
			// Prepare update data
			if ( ! isset( $transient->response ) ) {
				$transient->response = array();
			}

			$transient->response[ $this->plugin_basename ] = (object) array(
				'slug'        => dirname( $this->plugin_basename ),
				'plugin'      => $this->plugin_basename,
				'new_version' => $latest_version,
				'url'         => 'https://github.com/MajedBannani/webp-media-handler',
				'package'     => $zip_asset_url,
			);
		}

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
	 * Prefers assets named exactly "webp-media-handler.zip",
	 * falls back to any asset ending in ".zip".
	 *
	 * @param object $release_data Release data from GitHub API.
	 * @return string|false ZIP asset browser_download_url, or false if not found.
	 */
	private function get_zip_asset_url( $release_data ) {
		if ( ! isset( $release_data->assets ) || ! is_array( $release_data->assets ) ) {
			return false;
		}

		$preferred_asset = null;
		$fallback_asset = null;

		// Search through assets
		foreach ( $release_data->assets as $asset ) {
			if ( ! isset( $asset->name ) || ! isset( $asset->browser_download_url ) ) {
				continue;
			}

			// Check if asset is a ZIP file
			if ( '.zip' !== substr( strtolower( $asset->name ), -4 ) ) {
				continue;
			}

			// Prefer exact match: webp-media-handler.zip
			if ( 'webp-media-handler.zip' === $asset->name ) {
				$preferred_asset = $asset;
				break; // Found preferred asset, stop searching
			}

			// Keep first ZIP asset as fallback
			if ( null === $fallback_asset ) {
				$fallback_asset = $asset;
			}
		}

		// Return preferred asset if found, otherwise fallback
		if ( $preferred_asset && isset( $preferred_asset->browser_download_url ) ) {
			return $preferred_asset->browser_download_url;
		}

		if ( $fallback_asset && isset( $fallback_asset->browser_download_url ) ) {
			return $fallback_asset->browser_download_url;
		}

		// No valid ZIP asset found
		return false;
	}

	/**
	 * Normalize version string by removing leading 'v' if present
	 *
	 * @param string $version Version string.
	 * @return string Normalized version string.
	 */
	private function normalize_version( $version ) {
		return ltrim( $version, 'v' );
	}

	/**
	 * Clear transient cache after plugin update
	 *
	 * @param WP_Upgrader $upgrader WP_Upgrader instance.
	 * @param array       $options  Array of bulk item update data.
	 */
	public function clear_transient_cache( $upgrader, $options ) {
		if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			if ( isset( $options['plugins'] ) && in_array( $this->plugin_basename, $options['plugins'], true ) ) {
				delete_transient( $this->transient_name );
			}
		}
	}
}
