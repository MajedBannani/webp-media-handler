<?php
/**
 * Uninstall script
 *
 * Cleans up all plugin data from the WordPress database when the plugin is deleted.
 * Does NOT delete any media files, images, or attachments.
 *
 * @package WebPMediaHandler
 */

// Exit if uninstall not called from WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Summary of what is removed on uninstall:
 *
 * OPTIONS (removed):
 * - wpmh_settings: Plugin settings (feature toggles, configuration)
 * - wpmh_action_logs: Action execution timestamps and log data
 *
 * TRANSIENTS (removed):
 * - wpmh_github_release_data: GitHub updater release cache
 * - wpmh_watermark_flash_{user_id}: User-specific watermark flash notices (pattern-based)
 *
 * SITE OPTIONS (multisite, removed):
 * - wpmh_settings: Network-level settings (if any)
 * - wpmh_action_logs: Network-level logs (if any)
 *
 * NOT DELETED (by design):
 * - Media files, images, WebP files, or attachments
 * - WordPress core transients (e.g., update_plugins)
 * - User meta (plugin doesn't store user-specific data)
 * - Cron hooks (plugin doesn't register cron jobs)
 * - Custom tables (plugin doesn't create any)
 */

// List of all option keys used by the plugin
$plugin_options = array(
	'wpmh_settings',      // Main plugin settings
	'wpmh_action_logs',   // Action execution logs
);

// List of all transient keys used by the plugin
$plugin_transients = array(
	'wpmh_github_release_data', // GitHub updater cache
);

// Pattern for user-specific transients (watermark flash notices)
$transient_pattern = 'wpmh_watermark_flash_%';

/**
 * Delete all transients matching a pattern
 * Handles both regular and site transients
 *
 * @param string $pattern Transient name pattern with % wildcard.
 * @param bool   $is_site_transient Whether to delete site transients.
 */
function wpmh_delete_transients_by_pattern( $pattern, $is_site_transient = false ) {
	global $wpdb;

	$like_pattern = $wpdb->esc_like( $pattern );
	
	if ( $is_site_transient ) {
		$option_name = '_site_transient_timeout_' . $like_pattern;
		$transient_name = '_site_transient_' . $like_pattern;
	} else {
		$option_name = '_transient_timeout_' . $like_pattern;
		$transient_name = '_transient_' . $like_pattern;
	}

	// Delete transient timeouts
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$option_name
		)
	);

	// Delete transient values
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$transient_name
		)
	);
}

/**
 * Cleanup plugin data for a single site
 */
function wpmh_cleanup_site() {
	global $plugin_options, $plugin_transients, $transient_pattern;

	// Delete all plugin options
	foreach ( $plugin_options as $option_name ) {
		delete_option( $option_name );
	}

	// Delete all plugin transients
	foreach ( $plugin_transients as $transient_name ) {
		delete_transient( $transient_name );
		delete_site_transient( $transient_name ); // Also delete site transients if exists
	}

	// Delete user-specific watermark flash transients (pattern-based)
	wpmh_delete_transients_by_pattern( $transient_pattern, false );
	wpmh_delete_transients_by_pattern( $transient_pattern, true );
}

// Cleanup for single site installation
if ( ! is_multisite() ) {
	wpmh_cleanup_site();
} else {
	// Multisite: cleanup all sites
	global $wpdb;

	// Get all site IDs
	$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );

	foreach ( $blog_ids as $blog_id ) {
		switch_to_blog( $blog_id );
		wpmh_cleanup_site();
		restore_current_blog();
	}

	// Delete network-level site options (if any)
	foreach ( $plugin_options as $option_name ) {
		delete_site_option( $option_name );
	}

	// Delete network-level transients (if any)
	foreach ( $plugin_transients as $transient_name ) {
		delete_site_transient( $transient_name );
	}
}
