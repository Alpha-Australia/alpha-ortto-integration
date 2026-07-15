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
 * Account itself via Ortto's v1/accounts/merge API.
 *
 * The Account is matched by Ortto's own internal account id (which every
 * Account-journey webhook call includes automatically, as the reserved
 * top-level "account_id" payload key) rather than by the Intercom-synced
 * 15 character ID field: that field belongs to the Intercom data source
 * integration, and Ortto rejects any merge that lists it in "fields" at
 * all -- even to set its own unchanged value -- with "can not apply
 * mutation for <field>". Matching by Ortto's internal id sidesteps that
 * entirely, and needs no plugin setting to know which field holds the
 * Salesforce ID, since the value arrives directly in the webhook payload.
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
	 * The webhook payload key (Ortto calls this the field's "key_name")
	 * that must carry the Account's 15 character Salesforce ID. Deliberately
	 * long and specific -- something generic like "id" or "account_id"
	 * collides with keys Ortto's webhook envelope already uses for its own
	 * purposes (the delivery's own internal id, the Ortto account id, etc).
	 */
	const PAYLOAD_KEY = 'id_to_convert';

	/**
	 * Reserved top-level payload key Ortto automatically includes on every
	 * Account-journey webhook call: Ortto's own internal id for the Account
	 * that triggered the journey. Not configurable -- this isn't something
	 * the caller maps, it's part of Ortto's fixed webhook envelope.
	 */
	const ACCOUNT_ID_KEY = 'account_id';

	/**
	 * The Ortto field id used to merge_by / set when targeting an Account
	 * by its own internal id, per Ortto's accounts/merge API.
	 */
	const ORTTO_ACCOUNT_ID_FIELD = 'str:o:account_id';

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

		if ( '' === $settings['webhook_secret'] || '' === $settings['api_key'] || '' === $settings['field_18'] ) {
			return new WP_Error(
				'alpha_ortto_account_sf_id_not_configured',
				'The Account Salesforce ID sync is not fully configured. Go to Forms -> Settings -> Ortto to set the webhook secret, API key, and the 18 character account field id.',
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

		$id_15 = trim( (string) self::extract_payload_value( $request, self::PAYLOAD_KEY ) );

		if ( 15 !== strlen( $id_15 ) || ! ctype_alnum( $id_15 ) ) {
			return new WP_Error(
				'alpha_ortto_account_sf_id_invalid',
				sprintf(
					'The "%s" field must be the Account\'s 15 character alphanumeric Salesforce record ID. Received %d character value: "%s".',
					self::PAYLOAD_KEY,
					strlen( $id_15 ),
					$id_15
				),
				array( 'status' => 400 )
			);
		}

		$ortto_account_id = trim( (string) self::extract_payload_value( $request, self::ACCOUNT_ID_KEY ) );

		if ( '' === $ortto_account_id ) {
			return new WP_Error(
				'alpha_ortto_account_sf_id_missing_account',
				'Missing "' . self::ACCOUNT_ID_KEY . '" in the webhook payload -- this endpoint relies on it to identify the Account. Account-journey webhooks include it automatically; a Person-journey or Test-webhook payload may not.',
				array( 'status' => 400 )
			);
		}

		$id_18 = Alpha_Ortto_SF_ID_Converter::convert_15_to_18( $id_15 );

		if ( is_wp_error( $id_18 ) ) {
			return $id_18;
		}

		$result = self::push_to_ortto( $ortto_account_id, $id_18 );

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
	 * Pull a value out of the request's JSON body.
	 *
	 * Ortto's standard webhook payload nests any mapped field inside the
	 * top-level "contact" object -- e.g. { "contact": { "id_to_convert": "..." },
	 * "id": "<the webhook delivery's own internal id>", ... } -- so a plain
	 * WP_REST_Request::get_param() call matches unrelated top-level keys
	 * Ortto already uses for its own purposes rather than the mapped field.
	 * A real Account-journey run, though, sends a flat payload with no
	 * "contact" wrapper at all. Look inside "contact" first if present,
	 * otherwise read the top level directly.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string           $key     Key to read.
	 *
	 * @return string|null
	 */
	private static function extract_payload_value( $request, $key ) {

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$source = ( array_key_exists( 'contact', $body ) && is_array( $body['contact'] ) )
			? $body['contact']
			: $body;

		return ! empty( $source[ $key ] ) ? $source[ $key ] : null;
	}

	/**
	 * Write the 18 character ID onto the given Account, via Ortto's
	 * v1/accounts/merge API -- but only if the destination field is
	 * currently empty, so a re-delivered or re-run webhook never clobbers
	 * a value that's already there (e.g. one set manually, or by some
	 * other process).
	 *
	 * Matches by Ortto's own internal account id rather than any
	 * Salesforce-ID field, since Ortto rejects merges that list an
	 * Intercom-synced field in "fields" at all, even unchanged.
	 *
	 * @param string $ortto_account_id Ortto's internal id for the Account.
	 * @param string $id_18            18 character Salesforce ID to store.
	 *
	 * @return array {
	 *     @type bool   $success Whether Ortto accepted the request (or there was nothing to do).
	 *     @type string $message Human-readable outcome (error detail on failure).
	 * }
	 */
	private static function push_to_ortto( $ortto_account_id, $id_18 ) {

		$settings = self::get_settings();

		$existing = self::get_existing_18_value( $ortto_account_id, $settings );

		if ( is_wp_error( $existing ) ) {
			return array(
				'success' => false,
				'message' => $existing->get_error_message(),
			);
		}

		if ( ! empty( $existing ) ) {
			return array(
				'success' => true,
				'message' => 'Skipped: the destination field already has a value ("' . $existing . '").',
			);
		}

		$body = array(
			'accounts'       => array(
				array(
					'fields' => array(
						self::ORTTO_ACCOUNT_ID_FIELD => $ortto_account_id,
						$settings['field_18']        => $id_18,
					),
				),
			),
			'async'          => true,
			'merge_by'       => array( self::ORTTO_ACCOUNT_ID_FIELD ),
			'merge_strategy' => 2,
			'find_strategy'  => 0,
		);

		$response = wp_remote_post(
			self::api_url( $settings, '/v1/accounts/merge' ),
			array(
				'headers' => self::api_headers( $settings ),
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
	 * Look up the Account by Ortto's internal id and return its current
	 * value for the 18 character field, via Ortto's v1/accounts/get-by-ids
	 * API. Returns an empty string if the Account can't be found yet, or
	 * if the field isn't set on it.
	 *
	 * @param string $ortto_account_id Ortto's internal id for the Account.
	 * @param array  $settings         Settings from get_settings().
	 *
	 * @return string|WP_Error
	 */
	private static function get_existing_18_value( $ortto_account_id, $settings ) {

		$body = array(
			'account_ids' => array( $ortto_account_id ),
			'fields'      => array( $settings['field_18'] ),
		);

		$response = wp_remote_post(
			self::api_url( $settings, '/v1/accounts/get-by-ids' ),
			array(
				'headers' => self::api_headers( $settings ),
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'alpha_ortto_account_sf_id_lookup_failed',
				'Ortto lookup request failed: ' . $response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'alpha_ortto_account_sf_id_lookup_failed',
				'Ortto lookup returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response )
			);
		}

		$data     = json_decode( wp_remote_retrieve_body( $response ), true );
		$accounts = is_array( $data ) ? rgar( $data, 'accounts' ) : array();

		if ( empty( $accounts ) || ! is_array( $accounts[0] ) ) {
			return '';
		}

		return (string) rgars( $accounts[0], 'fields/' . $settings['field_18'] );
	}

	/**
	 * Build a regional Ortto API URL for the given path.
	 *
	 * @param array  $settings Settings from get_settings().
	 * @param string $path     API path, e.g. "/v1/accounts/merge".
	 *
	 * @return string
	 */
	private static function api_url( $settings, $path ) {
		$subdomain = $settings['region'] ? $settings['region'] . '.' : '';
		return "https://api.{$subdomain}ap3api.com{$path}";
	}

	/**
	 * Build the standard headers for an outbound Ortto API request.
	 *
	 * @param array $settings Settings from get_settings().
	 *
	 * @return array
	 */
	private static function api_headers( $settings ) {
		return array(
			'X-Api-Key'    => $settings['api_key'],
			'Content-Type' => 'application/json',
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
				'field_18'       => '',
			);
		}

		$settings = Alpha_Ortto_AddOn::get_instance()->get_plugin_settings();

		return array(
			'webhook_secret' => trim( (string) rgar( $settings, 'sf_id_converter_secret' ) ),
			'api_key'        => trim( (string) rgar( $settings, 'api_key' ) ),
			'region'         => trim( (string) rgar( $settings, 'region' ) ),
			'field_18'       => trim( (string) rgar( $settings, 'account_sf_id_18_field' ) ),
		);
	}
}
