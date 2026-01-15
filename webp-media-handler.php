<?php
/**
 * Plugin Name: WebP Media Handler
 * Plugin URI: https://github.com/MajedBannani/webp-media-handler
 * Description: Handle WebP image conversion and optimization with explicit control. Disable default image sizes, auto-convert new uploads, and convert existing media with clear action buttons.
 * Version: 1.0.6
 * Author: Majed Talal
 * Author URI: https://github.com/MajedBannani
 * Update URI: https://github.com/MajedBannani/webp-media-handler
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: webp-media-handler
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package WebPMediaHandler
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'WPMH_VERSION', '1.0.0' );
define( 'WPMH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPMH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class
 */
final class WebP_Media_Handler {

	/**
	 * Plugin instance
	 *
	 * @var WebP_Media_Handler
	 */
	private static $instance = null;

	/**
	 * Settings manager instance
	 *
	 * @var WPMH_Settings_Manager
	 */
	public $settings;

	/**
	 * Feature instances
	 *
	 * @var array
	 */
	private $features = array();

	/**
	 * Get plugin instance
	 *
	 * @return WebP_Media_Handler
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize plugin
	 */
	private function init() {
		// Load dependencies
		$this->load_dependencies();

		// Initialize settings manager
		$this->settings = new WPMH_Settings_Manager();

		// Initialize features
		$this->init_features();

		// Initialize admin
		if ( is_admin() ) {
			$this->init_admin();
		}

		// Load hooks
		$this->load_hooks();

		// Load plugin text domain
		$this->load_textdomain();
	}

	/**
	 * Load plugin text domain
	 */
	private function load_textdomain() {
		load_plugin_textdomain(
			'webp-media-handler',
			false,
			dirname( WPMH_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Load plugin dependencies
	 */
	private function load_dependencies() {
		require_once WPMH_PLUGIN_DIR . 'includes/class-wpmh-settings-manager.php';
		require_once WPMH_PLUGIN_DIR . 'includes/class-wpmh-admin.php';
		// FIX: File names use 'iow' prefix but classes inside are correctly named WPMH_*
		require_once WPMH_PLUGIN_DIR . 'includes/features/class-iow-disable-image-sizes.php';
		require_once WPMH_PLUGIN_DIR . 'includes/features/class-iow-auto-webp-convert.php';
		require_once WPMH_PLUGIN_DIR . 'includes/features/class-iow-convert-existing-webp.php';
		require_once WPMH_PLUGIN_DIR . 'includes/features/class-iow-replace-image-urls.php';

		// Load GitHub updater only in admin area
		if ( is_admin() ) {
			require_once WPMH_PLUGIN_DIR . 'includes/class-wpmh-github-updater.php';
		}
	}

	/**
	 * Initialize feature classes
	 */
	private function init_features() {
		$this->features['disable_image_sizes'] = new WPMH_Disable_Image_Sizes( $this->settings );
		$this->features['auto_webp_convert']   = new WPMH_Auto_WebP_Convert( $this->settings );
		$this->features['convert_existing']     = new WPMH_Convert_Existing_WebP( $this->settings );
		$this->features['replace_urls']         = new WPMH_Replace_Image_URLs( $this->settings );
	}

	/**
	 * Initialize admin interface
	 */
	private function init_admin() {
		new WPMH_Admin( $this->settings, $this->features );
		
		// Initialize GitHub updater
		new WPMH_GitHub_Updater( WPMH_PLUGIN_BASENAME, WPMH_VERSION );
	}

	/**
	 * Load plugin hooks
	 */
	private function load_hooks() {
		// Register activation/deactivation hooks
		// WordPress.org compliance: Use static callback for activation hook
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );
	}

	/**
	 * Plugin activation
	 * 
	 * WordPress.org compliance: Settings initialized on activation, not on every page load.
	 * Static method required for activation hook callback.
	 */
	public static function activate() {
		// Initialize default settings
		// Note: Settings manager must be instantiated before calling init_defaults
		require_once WPMH_PLUGIN_DIR . 'includes/class-wpmh-settings-manager.php';
		$settings = new WPMH_Settings_Manager();
		$settings->init_defaults();
	}

	/**
	 * Plugin deactivation
	 * 
	 * WordPress.org compliance: Static method required for deactivation hook callback.
	 */
	public static function deactivate() {
		// Clean up if needed
		// Note: We do NOT restore image sizes automatically per requirements
	}
}

/**
 * Initialize plugin
 */
function wpmh_init() {
	return WebP_Media_Handler::get_instance();
}

// Start the plugin
wpmh_init();
