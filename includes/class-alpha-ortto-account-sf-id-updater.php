<?php
/**
 * Salesforce 15 -> 18 character ID sync for Ortto Accounts.
 *
 * Ortto's "dynamic webhook" (wait for response, update fields) journey
 * action only exists for Person journeys, not Account journeys, so there's
 * no built-in way to compute a value and write it straight back onto the
 * Account that triggered the journey. This instead accepts a call from an
 * Account journey's (classic) webhook action carrying the 15 character
 * Salesforce ID, computes the 18 character ID, and pushes it back onto the
 * Account itself via Ortto's v1/accounts/merge API -- matching the Account
 * by its existing 15 character ID field, so no Ortto-side account
 * identifier needs to be known by this endpoint.
 *
 * @package Alpha_Ortto_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Alpha_Ortto_Account_SF_ID_Updater {

	const REST_NAMESPACE = 'alpha-ortto/v1';
	const REST_ROUTE     = '/update-account-sf-id';

	/**
	 * Wire up the REST route.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the update-account-sf-id REST route.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_request' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'account_id' => array(
						'required' => false,
						'type'     => 'string',
					),
					'id'         => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Verify the caller sent the configured shared secret, and that
	 * everything needed to write the result back to Ortto is configured.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission( $request ) {

		$settings = self::get_settings();

		if ( '' === $settings['webhook_secret'] || '' === $settings['api_key']
			|| '' === $settings['field_15'] || '' === $settings['field_18'] ) {
			return new WP_Error(
				'alpha_ortto_account_sf_id_not_configured',
				'The Account Salesforce ID sync is not fully configured. Go to Forms -> Settings -> Ortto to set the webhook secret, API key, and both account field ids.',
				array( 'status' => 403 )
			);
		}

		$provided = $request->get_header( 'x-api-key' );

		if ( ! is_string( $provided ) || ! hash_equals( $settings['webhook_secret'], $provided ) ) {
			return new WP_Error(
				'alpha_ortto_account_sf_id_unauthorized',
				'Invalid or missing X-Api-Key header.',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle the REST request: convert the supplied 15 character ID and
	 * push the 18 character result back onto the matching Account.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_request( $request ) {

		$raw = $request->get_param( 'account_id' );
		if ( null === $raw || '' === $raw ) {
			$raw = $request->get_param( 'id' );
		}

		$id_15 = trim( (string) $raw );

		if ( 15 !== strlen( $id_15 ) || ! ctype_alnum( $id_15 ) ) {
			return new WP_Error(
				'alpha_ortto_account_sf_id_invalid',
				sprintf(
					'The account_id (or id) parameter must be the Account\'s 15 character alphanumeric Salesforce record ID. Received %d character value: "%s".',
					strlen( $id_15 ),
					$id_15
				),
				array( 'status' => 400 )
			);
		}

		$id_18 = Alpha_Ortto_SF_ID_Converter::convert_15_to_18( $id_15 );

		if ( is_wp_error( $id_18 ) ) {
			return $id_18;
		}

		$result = self::push_to_ortto( $id_15, $id_18 );

		if ( ! $result['success'] ) {
			return new WP_Error(
				'alpha_ortto_account_sf_id_upstream_error',
				$result['message'],
				array( 'status' => 502 )
			);
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
	 * Write the 18 character ID onto the Account that has the given 15
	 * character ID, via Ortto's v1/accounts/merge API.
	 *
	 * @param string $id_15 15 character Salesforce ID (also the merge key).
	 * @param string $id_18 18 character Salesforce ID to store.
	 *
	 * @return array {
	 *     @type bool   $success Whether Ortto accepted the request.
	 *     @type string $message Human-readable outcome (error detail on failure).
	 * }
	 */
	private static function push_to_ortto( $id_15, $id_18 ) {

		$settings = self::get_settings();

		$body = array(
			'accounts'       => array(
				array(
					'fields' => array(
						$settings['field_15'] => $id_15,
						$settings['field_18'] => $id_18,
					),
				),
			),
			'async'          => true,
			'merge_by'       => array( $settings['field_15'] ),
			'merge_strategy' => 2,
			'find_strategy'  => 0,
		);

		$region    = $settings['region'];
		$subdomain = $region ? $region . '.' : '';
		$url       = "https://api.{$subdomain}ap3api.com/v1/accounts/merge";

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'X-Api-Key'    => $settings['api_key'],
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => 'Ortto request failed: ' . $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'success' => false,
				'message' => 'Ortto returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ),
			);
		}

		return array(
			'success' => true,
			'message' => 'Sent to Ortto (HTTP ' . $code . ').',
		);
	}

	/**
	 * Read the settings this feature needs from the Ortto add-on's plugin
	 * settings.
	 *
	 * @return array
	 */
	private static function get_settings() {

		if ( ! class_exists( 'Alpha_Ortto_AddOn' ) ) {
			return array(
				'webhook_secret' => '',
				'api_key'        => '',
				'region'         => '',
				'field_15'       => '',
				'field_18'       => '',
			);
		}

		$settings = Alpha_Ortto_AddOn::get_instance()->get_plugin_settings();

		return array(
			'webhook_secret' => trim( (string) rgar( $settings, 'sf_id_converter_secret' ) ),
			'api_key'        => trim( (string) rgar( $settings, 'api_key' ) ),
			'region'         => trim( (string) rgar( $settings, 'region' ) ),
			'field_15'       => trim( (string) rgar( $settings, 'account_sf_id_15_field' ) ),
			'field_18'       => trim( (string) rgar( $settings, 'account_sf_id_18_field' ) ),
		);
	}
}
