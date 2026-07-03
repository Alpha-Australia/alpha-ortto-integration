<?php
/**
 * Bootstraps the Ortto add-on once Gravity Forms has loaded.
 *
 * Registered on the `gform_loaded` action so the add-on only spins up when
 * Gravity Forms (and its Feed Add-On Framework) is available.
 *
 * @package Alpha_Ortto_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Alpha_Ortto_AddOn_Bootstrap {

	/**
	 * Load and register the add-on if the Feed Add-On Framework is available.
	 */
	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}

		require_once __DIR__ . '/class-alpha-ortto-addon.php';

		GFAddOn::register( 'Alpha_Ortto_AddOn' );
	}
}
