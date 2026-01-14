<?php
/**
 * Settings Manager
 *
 * Handles all plugin settings and options storage.
 *
 * @package ImageOptimizationWebP
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Manager Class
 */
class IOW_Settings_Manager {

	/**
	 * Option name for plugin settings
	 *
	 * @var string
	 */
	const OPTION_NAME = 'iow_settings';

	/**
	 * Option name for action execution timestamps
	 *
	 * @var string
	 */
	const ACTION_LOGS_OPTION = 'iow_action_logs';

	/**
	 * Default settings
	 *
	 * @var array
	 */
	private $defaults = array(
		'disable_image_sizes' => false,
		'auto_webp_convert'   => false,
	);

	/**
	 * Get all settings
	 *
	 * @return array
	 */
	public function get_all() {
		$settings = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( $settings, $this->defaults );
	}

	/**
	 * Get a specific setting
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value if not set.
	 * @return mixed
	 */
	public function get( $key, $default = false ) {
		$settings = $this->get_all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Set a specific setting
	 *
	 * @param string $key Setting key.
	 * @param mixed  $value Setting value.
	 * @return bool
	 */
	public function set( $key, $value ) {
		$settings = $this->get_all();
		$settings[ $key ] = $value;
		return update_option( self::OPTION_NAME, $settings );
	}

	/**
	 * Update multiple settings at once
	 *
	 * @param array $new_settings Settings to update.
	 * @return bool
	 */
	public function update( $new_settings ) {
		$settings = $this->get_all();
		$settings = array_merge( $settings, $new_settings );
		return update_option( self::OPTION_NAME, $settings );
	}

	/**
	 * Initialize default settings
	 */
	public function init_defaults() {
		$current = get_option( self::OPTION_NAME, array() );
		if ( empty( $current ) ) {
			update_option( self::OPTION_NAME, $this->defaults );
		}
	}

	/**
	 * Log action execution
	 *
	 * @param string $action Action name.
	 * @param array  $data Additional data to store.
	 * @return bool
	 */
	public function log_action( $action, $data = array() ) {
		$logs = get_option( self::ACTION_LOGS_OPTION, array() );
		$logs[ $action ] = array(
			'timestamp' => current_time( 'mysql' ),
			'data'      => $data,
		);
		return update_option( self::ACTION_LOGS_OPTION, $logs );
	}

	/**
	 * Get action log
	 *
	 * @param string $action Action name.
	 * @return array|false
	 */
	public function get_action_log( $action ) {
		$logs = get_option( self::ACTION_LOGS_OPTION, array() );
		return isset( $logs[ $action ] ) ? $logs[ $action ] : false;
	}
}
