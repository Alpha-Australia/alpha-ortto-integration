<?php
/**
 * Alpha Ortto Add-On.
 *
 * A Gravity Forms Feed Add-On that sends form submissions to Ortto's
 * v1/person/merge API directly (via wp_remote_post), bypassing the
 * Webhooks add-on entirely. Editors configure it per-form under
 * Forms -> [Form] -> Settings -> Ortto, and configure the shared API
 * key once under Forms -> Settings -> Ortto.
 *
 * @package Alpha_Ortto_Integration
 */

if ( ! class_exists( 'GFForms' ) ) {
	return;
}

GFForms::include_feed_addon_framework();

class Alpha_Ortto_AddOn extends GFFeedAddOn {

	protected $_version                  = ALPHA_ORTTO_ADDON_VERSION;
	protected $_min_gravityforms_version = '2.5';
	protected $_slug                     = 'alpha-ortto';
	protected $_path                     = 'alpha-ortto-integration/alpha-ortto-integration.php';
	protected $_full_path                = __FILE__;
	protected $_title                    = 'Ortto';
	protected $_short_title              = 'Ortto';

	protected $_capabilities_settings_page = 'manage_options';
	protected $_capabilities_form_settings = 'manage_options';
	protected $_capabilities_uninstall     = 'manage_options';

	private static $_instance = null;

	/**
	 * @return Alpha_Ortto_AddOn
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Use a built-in dashicon rather than a custom icon asset.
	 */
	public function get_menu_icon() {
		return 'dashicons-email-alt2';
	}

	// # PLUGIN (GLOBAL) SETTINGS ---------------------------------------------------------------

	/**
	 * Configures the account-wide Ortto settings tab (Forms -> Settings -> Ortto).
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'title'  => 'Ortto API Settings',
				'fields' => array(
					array(
						'name'     => 'api_key',
						'label'    => 'Ortto Private API Key',
						'type'     => 'text',
						'class'    => 'large',
						'encrypt'  => true,
						'required' => true,
						'tooltip'  => 'In Ortto, go to Data sources -> your Custom API data source -> Configuration, and copy the Private API key.',
					),
					array(
						'name'    => 'region',
						'label'   => 'Ortto Region',
						'type'    => 'select',
						'choices' => array(
							array(
								'label' => 'Default (Global)',
								'value' => '',
							),
							array(
								'label' => 'Australia',
								'value' => 'au',
							),
							array(
								'label' => 'Europe',
								'value' => 'eu',
							),
						),
						'tooltip' => 'Only relevant if your Ortto account is on a regional instance (check Ortto Settings -> Data sources -> API key).',
					),
				),
			),
		);
	}

	/**
	 * Prevent new feeds being created (and show a notice) until the API key is set.
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		$settings = $this->get_plugin_settings();
		return ! empty( rgar( $settings, 'api_key' ) );
	}

	// # FEED (PER-FORM) SETTINGS ---------------------------------------------------------------

	/**
	 * Configures the feed edit page under Forms -> [Form] -> Settings -> Ortto.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		return array(
			array(
				'title'  => 'Ortto Feed Settings',
				'fields' => array(
					array(
						'name'     => 'feedName',
						'label'    => 'Feed name',
						'type'     => 'text',
						'class'    => 'medium',
						'required' => true,
					),
					array(
						'name'          => 'mergeBy',
						'label'         => 'Merge by',
						'type'          => 'text',
						'class'         => 'medium',
						'default_value' => 'str::email',
						'tooltip'       => 'The Ortto field used to find/match an existing contact when this form is submitted. Usually str::email.',
					),
					array(
						'name'        => 'fieldMap',
						'label'       => 'Field mapping',
						'type'        => 'generic_map',
						'key_field'   => array(
							'title'       => 'Ortto field',
							'placeholder' => 'str::email',
						),
						'value_field' => array(
							'title' => 'Gravity Forms value',
						),
						'tooltip'     => 'Left column: an Ortto person field (str::email, str::first, str::last, or a custom field like str:cm:your-field that already exists in Ortto). Also supports the special keys location.source_ip (sends the value for geolocation) and tag (applies a tag to the contact). Right column: pick the Gravity Forms field or entry meta (IP address, date created, form title, etc.) to pull the value from.',
					),
					array(
						'type'           => 'feed_condition',
						'name'           => 'feedCondition',
						'label'          => 'Condition',
						'checkbox_label' => 'Enable Condition',
						'instructions'   => 'Send this entry to Ortto if',
					),
				),
			),
		);
	}

	/**
	 * Columns shown on the feed list (Forms -> [Form] -> Settings -> Ortto).
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feedName' => 'Name',
			'mergeBy'  => 'Merge by',
		);
	}

	// # FEED PROCESSING -------------------------------------------------------------------------

	/**
	 * Build the Ortto payload from the feed's field mapping and send it to
	 * Ortto's v1/person/merge endpoint.
	 *
	 * @param array $feed  The Feed Object currently being processed.
	 * @param array $entry The Entry Object currently being processed.
	 * @param array $form  The Form Object currently being processed.
	 *
	 * @return bool
	 */
	public function process_feed( $feed, $entry, $form ) {

		$settings = $this->get_plugin_settings();
		$api_key  = rgar( $settings, 'api_key' );

		if ( empty( $api_key ) ) {
			$this->add_feed_error( 'Ortto API key is not configured. Go to Forms -> Settings -> Ortto to add it.', $feed, $entry, $form );
			return false;
		}

		$mappings = $this->get_generic_map_fields( $feed, 'fieldMap' );

		$fields   = array();
		$location = array();
		$tags     = array();

		foreach ( $mappings as $mapping ) {
			$ortto_field = trim( rgar( $mapping, 'custom_key' ) );
			$source      = rgar( $mapping, 'value' );

			if ( '' === $ortto_field || '' === $source ) {
				continue;
			}

			$value = $this->get_field_value( $form, $entry, $source );

			if ( '' === $value || null === $value ) {
				continue;
			}

			if ( 'location.source_ip' === $ortto_field ) {
				$location['source_ip'] = $value;
			} elseif ( 'tag' === $ortto_field ) {
				$tags[] = $value;
			} else {
				$fields[ $ortto_field ] = $value;
			}
		}

		if ( empty( $fields ) ) {
			$this->add_feed_error( 'No Ortto fields resolved to a value for this entry; nothing was sent.', $feed, $entry, $form );
			return false;
		}

		$person = array( 'fields' => $fields );

		if ( ! empty( $location ) ) {
			$person['location'] = $location;
		}

		if ( ! empty( $tags ) ) {
			$person['tags'] = $tags;
		}

		$merge_by = rgar( $feed['meta'], 'mergeBy' );
		if ( empty( $merge_by ) ) {
			$merge_by = 'str::email';
		}

		$body = array(
			'people'         => array( $person ),
			'async'          => true,
			'merge_by'       => array( $merge_by ),
			'merge_strategy' => 2,
			'find_strategy'  => 0,
		);

		$region    = rgar( $settings, 'region' );
		$subdomain = $region ? $region . '.' : '';
		$url       = "https://api.{$subdomain}ap3api.com/v1/person/merge";

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'X-Api-Key'    => $api_key,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->add_feed_error( 'Ortto request failed: ' . $response->get_error_message(), $feed, $entry, $form );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$this->add_feed_error( 'Ortto returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ), $feed, $entry, $form );
			return false;
		}

		return true;
	}
}
