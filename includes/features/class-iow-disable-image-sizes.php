<?php
/**
 * Disable WordPress Default Image Sizes Feature
 *
 * When enabled, disables all intermediate image sizes, big image scaling,
 * and removes image size choices from media library.
 *
 * @package WebPMediaHandler
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Disable Image Sizes Feature Class
 */
class WPMH_Disable_Image_Sizes {

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
		// Only hook if feature is enabled
		if ( $this->settings->get( 'disable_image_sizes' ) ) {
			// Disable intermediate image sizes
			add_filter( 'intermediate_image_sizes', '__return_empty_array', 999 );
			add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 999 );

			// Disable big image scaling
			add_filter( 'big_image_size_threshold', '__return_false', 999 );

			// Disable medium_large explicitly
			add_filter( 'intermediate_image_sizes', array( $this, 'remove_medium_large' ), 999 );

			// Disable WooCommerce image sizes if WooCommerce is active
			if ( class_exists( 'WooCommerce' ) ) {
				add_filter( 'woocommerce_get_image_size_thumbnail', '__return_false', 999 );
				add_filter( 'woocommerce_get_image_size_single', '__return_false', 999 );
				add_filter( 'woocommerce_get_image_size_gallery_thumbnail', '__return_false', 999 );
			}

			// Remove image size choices from media library
			add_filter( 'image_size_names_choose', '__return_empty_array', 999 );
		}
	}

	/**
	 * Remove medium_large from image sizes
	 *
	 * @param array $sizes Image sizes array.
	 * @return array
	 */
	public function remove_medium_large( $sizes ) {
		$key = array_search( 'medium_large', $sizes, true );
		if ( false !== $key ) {
			unset( $sizes[ $key ] );
		}
		return $sizes;
	}

	/**
	 * Get feature description
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Disables all intermediate image sizes (thumbnail, medium, large, etc.), big image scaling, and removes image size choices from the media library. When disabled, WordPress will generate only the original uploaded image size.', 'webp-media-handler' );
	}
}
