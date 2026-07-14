<?php
/**
 * Salesforce 15 -> 18 character ID converter, exposed as a REST endpoint so
 * an Ortto webhook can resolve the 18 character (case-insensitive) ID used
 * for deduping from whatever 15 character ID an upstream system sends.
 *
 * @package Alpha_Ortto_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Alpha_Ortto_SF_ID_Converter {

	const REST_NAMESPACE = 'alpha-ortto/v1';
	const REST_ROUTE     = '/convert-id';

	/**
	 * Suffix lookup table used by Salesforce's case-safe ID algorithm.
	 */
	const SUFFIX_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345';

	/**
	 * Wire up the REST route.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the convert-id REST route.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'handle_request' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Verify the caller sent the configured shared secret as an X-Api-Key
	 * header. The endpoint is disabled entirely (403) if no secret has been
	 * configured, so it can't be left open by accident.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission( $request ) {

		$secret = self::get_configured_secret();

		if ( '' === $secret ) {
			return new WP_Error(
				'alpha_ortto_sf_id_not_configured',
				'The Salesforce ID converter webhook secret has not been configured. Go to Forms -> Settings -> Ortto to set one.',
				array( 'status' => 403 )
			);
		}

		$provided = $request->get_header( 'x-api-key' );

		if ( ! is_string( $provided ) || ! hash_equals( $secret, $provided ) ) {
			return new WP_Error(
				'alpha_ortto_sf_id_unauthorized',
				'Invalid or missing X-Api-Key header.',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle the REST request: convert the supplied ID and return it.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_request( $request ) {

		$id_15 = trim( (string) $request->get_param( 'id' ) );

		$id_18 = self::convert_15_to_18( $id_15 );

		if ( is_wp_error( $id_18 ) ) {
			return $id_18;
		}

		return new WP_REST_Response(
			array(
				'id_15' => $id_15,
				'id_18' => $id_18,
			),
			200
		);
	}

	/**
	 * Convert a 15 character (case-sensitive) Salesforce ID to its 18
	 * character (case-safe) equivalent. If an 18 character ID is passed in
	 * already, it's returned as-is.
	 *
	 * @param string $id Salesforce record ID.
	 *
	 * @return string|WP_Error
	 */
	public static function convert_15_to_18( $id ) {

		if ( 18 === strlen( $id ) && ctype_alnum( $id ) ) {
			return $id;
		}

		if ( 15 !== strlen( $id ) || ! ctype_alnum( $id ) ) {
			return new WP_Error(
				'alpha_ortto_sf_id_invalid',
				'The id parameter must be a 15 or 18 character alphanumeric Salesforce record ID.',
				array( 'status' => 400 )
			);
		}

		$suffix = '';

		for ( $block = 0; $block < 3; $block++ ) {
			$chunk = substr( $id, $block * 5, 5 );
			$bits  = 0;

			for ( $i = 0; $i < 5; $i++ ) {
				if ( ctype_upper( $chunk[ $i ] ) ) {
					$bits |= ( 1 << $i );
				}
			}

			$suffix .= self::SUFFIX_CHARS[ $bits ];
		}

		return $id . $suffix;
	}

	/**
	 * Read the configured webhook secret from the Ortto add-on's plugin
	 * settings.
	 *
	 * @return string
	 */
	private static function get_configured_secret() {

		if ( ! class_exists( 'Alpha_Ortto_AddOn' ) ) {
			return '';
		}

		$settings = Alpha_Ortto_AddOn::get_instance()->get_plugin_settings();

		return trim( (string) rgar( $settings, 'sf_id_converter_secret' ) );
	}
}
