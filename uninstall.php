<?php
/**
 * Uninstall script
 *
 * Cleans up plugin data when the plugin is deleted.
 *
 * @package WebPMediaHandler
 */

// Exit if uninstall not called from WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options
delete_option( 'wpmh_settings' );
delete_option( 'wpmh_action_logs' );

// Delete site options in multisite
if ( is_multisite() ) {
	delete_site_option( 'wpmh_settings' );
	delete_site_option( 'wpmh_action_logs' );
}
