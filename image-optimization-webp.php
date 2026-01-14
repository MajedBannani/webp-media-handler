<?php
/**
 * Plugin Name: Image Optimization & WebP Migration
 * Plugin URI: https://wordpress.org/plugins/image-optimization-webp/
 * Description: Optimize images and migrate to WebP format with explicit control over all operations. Disable default image sizes, auto-convert new uploads, and convert existing media with clear action buttons.
 * Version: 1.0.0
 * Author: Majed Talal
 * Author URI: https://github.com/majedtalal
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: image-optimization-webp
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package ImageOptimizationWebP
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'IOW_VERSION', '1.0.0' );
define( 'IOW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'IOW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'IOW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class
 */
final class Image_Optimization_WebP {

	/**
	 * Plugin instance
	 *
	 * @var Image_Optimization_WebP
	 */
	private static $instance = null;

	/**
	 * Settings manager instance
	 *
	 * @var IOW_Settings_Manager
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
	 * @return Image_Optimization_WebP
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
		$this->settings = new IOW_Settings_Manager();

		// Initialize features
		$this->init_features();

		// Initialize admin
		if ( is_admin() ) {
			$this->init_admin();
		}

		// Load hooks
		$this->load_hooks();
	}

	/**
	 * Load plugin dependencies
	 */
	private function load_dependencies() {
		require_once IOW_PLUGIN_DIR . 'includes/class-iow-settings-manager.php';
		require_once IOW_PLUGIN_DIR . 'includes/class-iow-admin.php';
		require_once IOW_PLUGIN_DIR . 'includes/features/class-iow-disable-image-sizes.php';
		require_once IOW_PLUGIN_DIR . 'includes/features/class-iow-auto-webp-convert.php';
		require_once IOW_PLUGIN_DIR . 'includes/features/class-iow-convert-existing-webp.php';
		require_once IOW_PLUGIN_DIR . 'includes/features/class-iow-replace-image-urls.php';
	}

	/**
	 * Initialize feature classes
	 */
	private function init_features() {
		$this->features['disable_image_sizes'] = new IOW_Disable_Image_Sizes( $this->settings );
		$this->features['auto_webp_convert']   = new IOW_Auto_WebP_Convert( $this->settings );
		$this->features['convert_existing']     = new IOW_Convert_Existing_WebP( $this->settings );
		$this->features['replace_urls']         = new IOW_Replace_Image_URLs( $this->settings );
	}

	/**
	 * Initialize admin interface
	 */
	private function init_admin() {
		new IOW_Admin( $this->settings, $this->features );
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
		require_once IOW_PLUGIN_DIR . 'includes/class-iow-settings-manager.php';
		$settings = new IOW_Settings_Manager();
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
function iow_init() {
	return Image_Optimization_WebP::get_instance();
}

// Start the plugin
iow_init();
