<?php
/**
 * Uninstall handler for Alpha Ortto Integration.
 *
 * Runs only when the user deletes the plugin from the WordPress admin. It
 * removes everything this plugin stores so nothing (including the encrypted
 * Ortto API key) is left behind in the database.
 *
 * Cleaned up:
 *   - alpha_ortto_updates_key         Optional GitHub token for the updater.
 *   - alpha_ortto_gh_release          Cached "latest release" transient.
 *   - Gravity Forms add-on settings   The account-wide API key / region, plus
 *     and per-form feeds              the feeds mapped for slug "alpha-ortto".
 *
 * @package Alpha_Ortto_Integration
 */

// Bail unless WordPress is genuinely uninstalling this plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all of this plugin's data for a single site.
 */
function alpha_ortto_uninstall_site() {
	global $wpdb;

	// Our own options / transient.
	delete_option( 'alpha_ortto_updates_key' );
	delete_transient( 'alpha_ortto_gh_release' );

	// Gravity Forms Add-On Framework stores settings under these option keys,
	// keyed by the add-on slug ("alpha-ortto"). Remove them if present.
	delete_option( 'gravityformsaddon_alpha-ortto_settings' );
	delete_option( 'gravityformsaddon_alpha-ortto_version' );

	// Remove any feeds this add-on created. The GF feed table only exists if
	// Gravity Forms is (or was) installed, so guard against its absence.
	$feed_table = $wpdb->prefix . 'gf_addon_feed';

	$table_exists = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $feed_table )
	);

	if ( $table_exists === $feed_table ) {
		$wpdb->delete( $feed_table, array( 'addon_slug' => 'alpha-ortto' ), array( '%s' ) );
	}
}

// Handle multisite: clean each site, otherwise just the current site.
if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		alpha_ortto_uninstall_site();
		restore_current_blog();
	}
} else {
	alpha_ortto_uninstall_site();
}
