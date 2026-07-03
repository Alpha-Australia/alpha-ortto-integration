<?php
/**
 * Plugin Name: Alpha Ortto Integration
 * Plugin URI: https://github.com/Alpha-Australia/alpha-ortto-integration
 * Description: Adds an "Ortto" feed tab to each Gravity Form, letting you map fields to Ortto person fields and send contacts to Ortto directly on submission (no automatic blur-capture, no separate Webhooks feed required).
 * Version: 1.0.0
 * Author: Alpha Australia
 * Author URI: https://alpha.org.au
 * Text Domain: alpha-ortto-integration
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * This has to live in its own plugin (not the theme) because Gravity Forms'
 * Add-On Framework registers itself on the `gform_loaded` action, which
 * fires while plugins are loading -- before WordPress ever touches the
 * active theme's functions.php. Code in the theme would simply never see
 * that hook fire.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ALPHA_ORTTO_ADDON_VERSION', '1.0.0' );

require_once __DIR__ . '/includes/class-alpha-ortto-addon-bootstrap.php';

add_action( 'gform_loaded', array( 'Alpha_Ortto_AddOn_Bootstrap', 'load' ), 5 );

/**
 * Wire up the GitHub-based updater so new releases published on GitHub show
 * up as normal plugin updates in the WordPress dashboard.
 *
 * The repository is public, so no token is required. An optional token can be
 * stored in the `alpha_ortto_updates_key` option to raise the GitHub API rate
 * limit or support a private repo.
 */
add_action( 'admin_init', 'alpha_ortto_init_updater' );

function alpha_ortto_init_updater() {
	require_once __DIR__ . '/includes/class-alpha-ortto-updater.php';

	$updater = new Alpha_Ortto_Updater( __FILE__ );
	$updater->set_username( 'Alpha-Australia' );
	$updater->set_repository( 'alpha-ortto-integration' );

	$token = get_option( 'alpha_ortto_updates_key', null );
	if ( $token ) {
		$updater->authorize( $token );
	}

	$updater->initialize();
}

/**
 * Helper for retrieving the add-on instance elsewhere, if ever needed.
 *
 * @return Alpha_Ortto_AddOn
 */
function alpha_ortto_addon() {
	return Alpha_Ortto_AddOn::get_instance();
}
