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
	 * Entry meta key under which per-feed send status is stored.
	 */
	const STATUS_META_KEY = 'alpha_ortto_status';

	/**
	 * Entry meta key under which per-feed "Form Submit" activity send
	 * status is stored (separate from STATUS_META_KEY, which only ever
	 * covers the person merge call).
	 */
	const ACTIVITY_STATUS_META_KEY = 'alpha_ortto_activity_status';

	/**
	 * Default Ortto custom activity id used for the "Form Submit" activity,
	 * unless a feed overrides it. Must already exist in Ortto (CDP ->
	 * Activities) before events sent to it will be accepted.
	 */
	const DEFAULT_ACTIVITY_ID = 'act:cm:form-submit';

	/**
	 * Nonce action used by the entry-detail Resend button.
	 */
	const RESEND_NONCE = 'alpha_ortto_resend';

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
	 * Wire up admin/AJAX hooks: the entry-detail meta box and the Resend
	 * AJAX endpoint.
	 */
	public function init() {
		parent::init();

		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'register_entry_meta_box' ), 10, 3 );
		add_action( 'wp_ajax_alpha_ortto_resend', array( $this, 'ajax_resend' ) );
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
						'name'       => 'api_key',
						'label'      => 'Ortto Private API Key',
						'type'       => 'text',
						'class'      => 'large',
						'input_type' => 'password',
						'encrypt'    => true,
						'required'   => true,
						'tooltip'    => 'In Ortto, go to Data sources -> your Custom API data source -> Configuration, and copy the Private API key.',
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
			array(
				'title'  => 'Salesforce ID Converter Webhook',
				'fields' => array(
					array(
						'name'  => 'sf_id_converter_info',
						'label' => 'Webhook URL',
						'type'  => 'html',
						'html'  => '<code>' . esc_html( rest_url( Alpha_Ortto_SF_ID_Converter::REST_NAMESPACE . Alpha_Ortto_SF_ID_Converter::REST_ROUTE ) ) . '</code>'
							. '<p class="description">Call this from an Ortto webhook (GET or POST) with an <code>id</code> parameter set to a 15 or 18 character Salesforce record ID, and an <code>X-Api-Key</code> header set to the secret below. Returns JSON: <code>{ "id_15": ..., "id_18": ... }</code>.</p>',
					),
					array(
						'name'       => 'sf_id_converter_secret',
						'label'      => 'Webhook Secret',
						'type'       => 'text',
						'class'      => 'large',
						'input_type' => 'password',
						'encrypt'    => true,
						'tooltip'    => 'A shared secret the caller must send as the X-Api-Key header. Leave blank to disable the endpoint. Generate a long random string -- treat it like a password. Also used by the Account Salesforce ID sync below.',
					),
				),
			),
			array(
				'title'  => 'Account Salesforce ID Sync',
				'fields' => array(
					array(
						'name'  => 'account_sf_id_info',
						'label' => 'Webhook URL',
						'type'  => 'html',
						'html'  => '<code>' . esc_html( rest_url( Alpha_Ortto_Account_SF_ID_Updater::REST_NAMESPACE . Alpha_Ortto_Account_SF_ID_Updater::REST_ROUTE ) ) . '</code>'
							. '<p class="description">Account journeys don\'t have a "dynamic" webhook action, so this can\'t write the result straight back onto the Account like a Person journey webhook can. Instead, add a (classic) Webhook action to an <strong>Account</strong> journey (not a Person journey -- this relies on the <code>account_id</code> key Ortto automatically includes for Account-journey webhooks): method POST, URL as above, header <code>X-Api-Key</code> set to the secret above, and exactly one payload field with key name <code>' . esc_html( Alpha_Ortto_Account_SF_ID_Updater::PAYLOAD_KEY ) . '</code> mapped to the Account\'s 15 character Salesforce ID field -- use that exact key name, not <code>id</code>, which collides with a key already reserved elsewhere in Ortto\'s webhook payload. This endpoint matches the Account by Ortto\'s own internal id (not the Salesforce ID field itself -- Ortto rejects merges that reference an Intercom-synced field at all, even to set its own unchanged value) and calls Ortto\'s Accounts API directly to write the 18 character result. The field id below must already exist on the Account object (Settings -> Customer data -> Fields) and be the actual Ortto field id (e.g. str:cm:...), not a payload key name. It\'s only ever written if it\'s currently empty on that Account -- an existing value is never overwritten.</p>',
					),
					array(
						'name'    => 'account_sf_id_18_field',
						'label'   => 'Account field: 18 char Salesforce ID',
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => 'The Ortto Account field id to write the converted 18 character Salesforce ID into, such as str:cm:sf_account_id_18.',
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
						'name'          => 'sendActivity',
						'label'         => 'Form Submit Activity',
						'type'          => 'toggle',
						'default_value' => 1,
						'tooltip'       => 'When enabled, also records a "Form Submit" activity against the contact in Ortto (a separate call to v1/activities/create), so the submission appears on their activity timeline and can trigger journeys. The activity id below must already exist in Ortto (CDP -> Activities) or the activity call will fail -- this never affects the contact sync itself, which is unaffected either way. Off by default for feeds that existed before this setting, since enabling it retroactively for every feed at once could hit a large number of accounts before the activity exists in Ortto.',
					),
					array(
						'name'          => 'activityId',
						'label'         => 'Activity ID',
						'type'          => 'text',
						'class'         => 'medium',
						'default_value' => self::DEFAULT_ACTIVITY_ID,
						'tooltip'       => 'The Ortto custom activity id to record (CDP -> Activities -> your activity -> the id shown there). Must already exist in Ortto.',
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
	 * Process a feed on submission: send the entry to Ortto and record the
	 * outcome against the entry so it can be reviewed (and resent) later.
	 *
	 * @param array $feed  The Feed Object currently being processed.
	 * @param array $entry The Entry Object currently being processed.
	 * @param array $form  The Form Object currently being processed.
	 *
	 * @return bool
	 */
	public function process_feed( $feed, $entry, $form ) {

		$result = $this->send_to_ortto( $feed, $entry, $form );

		$this->record_feed_status( $entry, $feed, $result );

		if ( ! $result['success'] ) {
			$this->add_feed_error( $result['message'], $feed, $entry, $form );
			return false;
		}

		if ( $this->feed_sends_activity( $feed ) ) {
			$activity_result = $this->send_activity_to_ortto( $feed, $entry, $form );

			$this->record_activity_status( $entry, $feed, $activity_result );

			// The activity is secondary to the contact sync above: log it as
			// a visible warning on the entry, but don't fail the feed over it.
			if ( ! $activity_result['success'] ) {
				$this->add_feed_error( 'Form Submit activity: ' . $activity_result['message'], $feed, $entry, $form );
			}
		}

		return true;
	}

	/**
	 * Resolve a feed's field mapping into the Ortto fields, location, and
	 * tags it produces for a given entry. Shared by the person merge and the
	 * Form Submit activity call so both target the same identity.
	 *
	 * @param array $feed  The Feed Object.
	 * @param array $entry The Entry Object.
	 * @param array $form  The Form Object.
	 *
	 * @return array {
	 *     @type array $fields   Ortto field id => value.
	 *     @type array $location Geolocation data, e.g. array( 'source_ip' => ... ).
	 *     @type array $tags     Tags pulled from mapped fields (not including feed-level static tags).
	 * }
	 */
	private function resolve_feed_mapping( $feed, $entry, $form ) {

		// Read the raw mapping rows rather than GFAddOn::get_generic_map_fields(),
		// which (when called without $form/$entry) returns a flattened,
		// already-"resolved" array keyed by Ortto field instead of the
		// key/custom_key/value rows this loop expects below.
		$mappings = rgar( $feed, 'meta' ) ? rgars( $feed, 'meta/fieldMap' ) : rgar( $feed, 'fieldMap' );
		if ( ! is_array( $mappings ) ) {
			$mappings = array();
		}

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

		return array(
			'fields'   => $fields,
			'location' => $location,
			'tags'     => $tags,
		);
	}

	/**
	 * Build the Ortto payload from the feed's field mapping and send it to
	 * Ortto's v1/person/merge endpoint.
	 *
	 * Shared by the on-submission processing and the manual resend so both
	 * paths behave identically.
	 *
	 * @param array $feed  The Feed Object.
	 * @param array $entry The Entry Object.
	 * @param array $form  The Form Object.
	 *
	 * @return array {
	 *     @type bool   $success Whether Ortto accepted the request.
	 *     @type int    $code    HTTP status code (0 if the request never left).
	 *     @type string $message Human-readable outcome (error detail on failure).
	 * }
	 */
	public function send_to_ortto( $feed, $entry, $form ) {

		$settings = $this->get_plugin_settings();
		$api_key  = rgar( $settings, 'api_key' );

		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'code'    => 0,
				'message' => 'Ortto API key is not configured. Go to Forms -> Settings -> Ortto to add it.',
			);
		}

		$mapped   = $this->resolve_feed_mapping( $feed, $entry, $form );
		$fields   = $mapped['fields'];
		$location = $mapped['location'];
		$tags     = $mapped['tags'];

		if ( empty( $fields ) ) {
			return array(
				'success' => false,
				'code'    => 0,
				'message' => 'No Ortto fields resolved to a value for this entry; nothing was sent.',
			);
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
			return array(
				'success' => false,
				'code'    => 0,
				'message' => 'Ortto request failed: ' . $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'success' => false,
				'code'    => $code,
				'message' => 'Ortto returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ),
			);
		}

		return array(
			'success' => true,
			'code'    => $code,
			'message' => 'Sent to Ortto (HTTP ' . $code . ').',
		);
	}

	/**
	 * Whether this feed is configured to send a "Form Submit" activity.
	 * Defaults to on only for feeds saved through the UI since this setting
	 * was introduced (see the "sendActivity" field's default_value); feeds
	 * saved before then have no value stored and default to off, so
	 * enabling this doesn't retroactively start firing activity calls for
	 * every existing feed at once.
	 *
	 * @param array $feed The Feed Object.
	 *
	 * @return bool
	 */
	private function feed_sends_activity( $feed ) {
		return (bool) rgar( $feed['meta'], 'sendActivity' );
	}

	/**
	 * Record a "Form Submit" activity against the same contact the merge
	 * call targeted, via Ortto's v1/activities/create endpoint.
	 *
	 * @param array $feed  The Feed Object.
	 * @param array $entry The Entry Object.
	 * @param array $form  The Form Object.
	 *
	 * @return array Same shape as send_to_ortto()'s return value.
	 */
	private function send_activity_to_ortto( $feed, $entry, $form ) {

		$settings = $this->get_plugin_settings();
		$api_key  = rgar( $settings, 'api_key' );

		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'code'    => 0,
				'message' => 'Ortto API key is not configured. Go to Forms -> Settings -> Ortto to add it.',
			);
		}

		// Reuse the same identity the merge call resolved, so the activity
		// attaches to the same contact.
		$mapped = $this->resolve_feed_mapping( $feed, $entry, $form );

		if ( empty( $mapped['fields'] ) ) {
			return array(
				'success' => false,
				'code'    => 0,
				'message' => 'No Ortto fields resolved to a value for this entry; activity not sent.',
			);
		}

		$activity_id = trim( rgar( $feed['meta'], 'activityId' ) );
		if ( '' === $activity_id ) {
			$activity_id = self::DEFAULT_ACTIVITY_ID;
		}

		$activity = array(
			'activity_id' => $activity_id,
			'attributes'  => array(
				'str:cm:form-name' => (string) rgar( $form, 'title' ),
				'int:cm:form-id'   => (int) rgar( $form, 'id' ),
				'str:cm:entry-id'  => (string) rgar( $entry, 'id' ),
			),
			'fields'      => $mapped['fields'],
		);

		if ( ! empty( $mapped['location'] ) ) {
			$activity['location'] = $mapped['location'];
		}

		$merge_by = rgar( $feed['meta'], 'mergeBy' );
		if ( empty( $merge_by ) ) {
			$merge_by = 'str::email';
		}

		$body = array(
			'activities' => array( $activity ),
			'merge_by'   => array( $merge_by ),
		);

		$region    = rgar( $settings, 'region' );
		$subdomain = $region ? $region . '.' : '';
		$url       = "https://api.{$subdomain}ap3api.com/v1/activities/create";

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
			return array(
				'success' => false,
				'code'    => 0,
				'message' => 'Ortto activity request failed: ' . $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'success' => false,
				'code'    => $code,
				'message' => 'Ortto activity returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ),
			);
		}

		return array(
			'success' => true,
			'code'    => $code,
			'message' => 'Activity sent to Ortto (HTTP ' . $code . ').',
		);
	}

	/**
	 * Store the outcome of a send against the entry, keyed by feed id, so the
	 * entry-detail meta box can show status and offer a resend.
	 *
	 * @param array $entry  The Entry Object.
	 * @param array $feed   The Feed Object.
	 * @param array $result Result array from send_to_ortto().
	 */
	private function record_feed_status( $entry, $feed, $result ) {
		$this->store_status( self::STATUS_META_KEY, $entry, $feed, $result );
	}

	/**
	 * Store the outcome of a "Form Submit" activity send against the entry,
	 * separately from the contact sync status recorded by
	 * record_feed_status().
	 *
	 * @param array $entry  The Entry Object.
	 * @param array $feed   The Feed Object.
	 * @param array $result Result array from send_activity_to_ortto().
	 */
	private function record_activity_status( $entry, $feed, $result ) {
		$this->store_status( self::ACTIVITY_STATUS_META_KEY, $entry, $feed, $result );
	}

	/**
	 * Shared implementation for record_feed_status() and
	 * record_activity_status(): stores a per-feed send outcome, keyed by
	 * feed id, under the given entry meta key.
	 *
	 * @param string $meta_key Entry meta key to store under.
	 * @param array  $entry    The Entry Object.
	 * @param array  $feed     The Feed Object.
	 * @param array  $result   Result array from a send_*() method.
	 */
	private function store_status( $meta_key, $entry, $feed, $result ) {

		$entry_id = rgar( $entry, 'id' );
		if ( empty( $entry_id ) ) {
			return;
		}

		$statuses = gform_get_meta( $entry_id, $meta_key );
		if ( ! is_array( $statuses ) ) {
			$statuses = array();
		}

		$statuses[ (string) $feed['id'] ] = array(
			'status'    => $result['success'] ? 'sent' : 'error',
			'code'      => isset( $result['code'] ) ? (int) $result['code'] : 0,
			'message'   => isset( $result['message'] ) ? $result['message'] : '',
			'timestamp' => time(),
			'user_id'   => get_current_user_id(),
		);

		gform_update_meta( $entry_id, $meta_key, $statuses, rgar( $entry, 'form_id' ) );
	}

	// # ENTRY DETAIL: STATUS + RESEND -----------------------------------------------------------

	/**
	 * Return the active Ortto feeds for a form.
	 *
	 * @param int $form_id Form id.
	 *
	 * @return array
	 */
	private function get_active_feeds_for_form( $form_id ) {
		$feeds = $this->get_feeds( $form_id );

		return array_filter(
			(array) $feeds,
			static function ( $feed ) {
				return ! empty( $feed['is_active'] );
			}
		);
	}

	/**
	 * Register an "Ortto" meta box on the entry detail page, in the side
	 * column beneath the Notifications box, showing each feed's send status
	 * and a Resend button.
	 *
	 * @param array $meta_boxes Registered meta boxes.
	 * @param array $entry      The current Entry Object.
	 * @param array $form       The current Form Object.
	 *
	 * @return array
	 */
	public function register_entry_meta_box( $meta_boxes, $entry, $form ) {

		if ( ! $this->current_user_can_any( $this->_capabilities_form_settings ) ) {
			return $meta_boxes;
		}

		if ( empty( $this->get_active_feeds_for_form( rgar( $form, 'id' ) ) ) ) {
			return $meta_boxes;
		}

		$meta_boxes['alpha_ortto'] = array(
			'title'         => 'Ortto',
			'callback'      => array( $this, 'render_entry_meta_box' ),
			'context'       => 'side',
			'priority'      => 'low',
			'callback_args' => array( 'form' => $form ),
		);

		return $meta_boxes;
	}

	/**
	 * Render the Ortto status/resend meta box.
	 *
	 * GFEntryDetail::lead_detail_page() calls do_meta_boxes() with
	 * array( 'form' => $form, 'entry' => $lead, 'mode' => $mode ) as the
	 * object, so $entry here is that wrapper, not the entry itself.
	 *
	 * @param array $entry   The do_meta_boxes() object wrapper (form/entry/mode).
	 * @param array $metabox The meta box definition; form is also under args/form.
	 */
	public function render_entry_meta_box( $entry, $metabox ) {

		$form     = rgars( $metabox, 'args/form' );
		$entry    = rgar( $entry, 'entry' );
		$entry_id = (int) rgar( $entry, 'id' );
		$feeds    = $this->get_active_feeds_for_form( rgar( $form, 'id' ) );

		$nonce = wp_create_nonce( self::RESEND_NONCE );

		echo '<div class="alpha-ortto-metabox">';

		foreach ( $feeds as $feed ) {
			$feed_id = (string) $feed['id'];
			$name    = rgars( $feed, 'meta/feedName' );
			if ( '' === $name ) {
				$name = 'Feed #' . $feed_id;
			}

			echo '<div class="alpha-ortto-feed" style="padding:8px 0;border-bottom:1px solid #f0f0f1;">';
			echo '<strong>' . esc_html( $name ) . '</strong><br />';
			echo '<span class="alpha-ortto-status" id="alpha-ortto-status-' . esc_attr( $feed_id ) . '">'
				. wp_kses_post( $this->render_feed_status_html( $feed, $entry_id ) )
				. '</span>';

			printf(
				'<p style="margin:8px 0 0;"><button type="button" class="button button-secondary alpha-ortto-resend" data-feed="%1$s" data-entry="%2$d">%3$s</button></p>',
				esc_attr( $feed_id ),
				esc_attr( $entry_id ),
				esc_html__( 'Resend to Ortto', 'alpha-ortto-integration' )
			);
			echo '</div>';
		}

		echo '</div>';

		$this->print_resend_script( $nonce );
	}

	/**
	 * Build the combined status HTML for one feed: the contact sync status,
	 * plus (if this feed sends one) the Form Submit activity status below
	 * it. Shared by the initial meta box render and the AJAX resend
	 * response so both look identical.
	 *
	 * @param array $feed     The Feed Object.
	 * @param int   $entry_id Entry id.
	 *
	 * @return string
	 */
	private function render_feed_status_html( $feed, $entry_id ) {

		$feed_id = (string) $feed['id'];

		$statuses = gform_get_meta( $entry_id, self::STATUS_META_KEY );
		$status   = is_array( $statuses ) ? rgar( $statuses, $feed_id ) : false;

		$html = $this->format_status_html( $status );

		if ( $this->feed_sends_activity( $feed ) ) {
			$activity_statuses = gform_get_meta( $entry_id, self::ACTIVITY_STATUS_META_KEY );
			$activity_status   = is_array( $activity_statuses ) ? rgar( $activity_statuses, $feed_id ) : false;

			$html .= '<div style="margin-top:6px;padding-top:6px;border-top:1px solid #f0f0f1;">'
				. '<span style="color:#646970;">Form Submit activity:</span> '
				. $this->format_status_html( $activity_status )
				. '</div>';
		}

		return $html;
	}

	/**
	 * Build the status line HTML for a feed's last recorded send.
	 *
	 * @param array|false $status Recorded status, or false if never sent.
	 *
	 * @return string
	 */
	private function format_status_html( $status ) {

		if ( empty( $status ) || ! is_array( $status ) ) {
			return '<span style="color:#646970;">Not yet sent.</span>';
		}

		$when = ! empty( $status['timestamp'] )
			? wp_date( 'M j, Y g:i a', (int) $status['timestamp'] )
			: '';

		if ( 'sent' === rgar( $status, 'status' ) ) {
			$html = '<span style="color:#008a20;">&#10003; Sent</span>';
			if ( $when ) {
				$html .= ' <span style="color:#646970;">on ' . esc_html( $when ) . '</span>';
			}
			return $html;
		}

		$html = '<span style="color:#d63638;">&#10007; Failed</span>';
		if ( $when ) {
			$html .= ' <span style="color:#646970;">on ' . esc_html( $when ) . '</span>';
		}
		$message = rgar( $status, 'message' );
		if ( $message ) {
			$html .= '<br /><span style="color:#646970;font-size:11px;">' . esc_html( $message ) . '</span>';
		}
		return $html;
	}

	/**
	 * Print the inline script that powers the Resend buttons.
	 *
	 * @param string $nonce Resend nonce.
	 */
	private function print_resend_script( $nonce ) {
		?>
		<script type="text/javascript">
		( function () {
			var boxes = document.querySelectorAll( '.alpha-ortto-resend' );
			boxes.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var feedId  = btn.getAttribute( 'data-feed' );
					var entryId = btn.getAttribute( 'data-entry' );
					var status  = document.getElementById( 'alpha-ortto-status-' + feedId );

					btn.disabled = true;
					var original = btn.textContent;
					btn.textContent = <?php echo wp_json_encode( __( 'Sending…', 'alpha-ortto-integration' ) ); ?>;

					var body = new URLSearchParams();
					body.append( 'action', 'alpha_ortto_resend' );
					body.append( 'nonce', <?php echo wp_json_encode( $nonce ); ?> );
					body.append( 'entry', entryId );
					body.append( 'feed', feedId );

					fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
						body: body.toString()
					} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.success && res.data && res.data.html ) {
							if ( status ) { status.innerHTML = res.data.html; }
						} else {
							var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Resend failed.';
							if ( status ) {
								status.innerHTML = '<span style="color:#d63638;">&#10007; ' + msg.replace( /</g, '&lt;' ) + '</span>';
							}
						}
					} )
					.catch( function () {
						if ( status ) {
							status.innerHTML = '<span style="color:#d63638;">&#10007; Request failed.</span>';
						}
					} )
					.finally( function () {
						btn.disabled = false;
						btn.textContent = original;
					} );
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * AJAX handler: manually resend a single feed to Ortto for one entry.
	 */
	public function ajax_resend() {

		if ( ! check_ajax_referer( self::RESEND_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed. Reload the page and try again.' ), 403 );
		}

		if ( ! $this->current_user_can_any( $this->_capabilities_form_settings ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to resend to Ortto.' ), 403 );
		}

		$entry_id = absint( rgpost( 'entry' ) );
		$feed_id  = absint( rgpost( 'feed' ) );

		if ( ! $entry_id || ! $feed_id ) {
			wp_send_json_error( array( 'message' => 'Missing entry or feed reference.' ), 400 );
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			wp_send_json_error( array( 'message' => 'Entry not found.' ), 404 );
		}

		$feed = $this->get_feed( $feed_id );
		if ( empty( $feed ) || (int) rgar( $feed, 'form_id' ) !== (int) rgar( $entry, 'form_id' ) ) {
			wp_send_json_error( array( 'message' => 'Feed not found for this entry.' ), 404 );
		}

		$form = GFAPI::get_form( rgar( $entry, 'form_id' ) );
		if ( empty( $form ) ) {
			wp_send_json_error( array( 'message' => 'Form not found.' ), 404 );
		}

		$result = $this->send_to_ortto( $feed, $entry, $form );

		$this->record_feed_status( $entry, $feed, $result );

		$feed_name = rgars( $feed, 'meta/feedName' );
		if ( '' === $feed_name ) {
			$feed_name = 'Feed #' . $feed_id;
		}
		$this->add_resend_note( $entry_id, $feed_name, $result );

		if ( $result['success'] && $this->feed_sends_activity( $feed ) ) {
			$activity_result = $this->send_activity_to_ortto( $feed, $entry, $form );
			$this->record_activity_status( $entry, $feed, $activity_result );
			$this->add_resend_note( $entry_id, $feed_name . ' (Form Submit activity)', $activity_result );
		}

		$html = $this->render_feed_status_html( $feed, $entry_id );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'html' => $html ) );
		}

		wp_send_json_error(
			array(
				'message' => $result['message'],
				'html'    => $html,
			)
		);
	}

	/**
	 * Record a timeline note on the entry documenting a manual resend.
	 *
	 * @param int    $entry_id  Entry id.
	 * @param string $feed_name Feed name.
	 * @param array  $result    Result array from send_to_ortto().
	 */
	private function add_resend_note( $entry_id, $feed_name, $result ) {

		if ( ! class_exists( 'GFFormsModel' ) || ! method_exists( 'GFFormsModel', 'add_note' ) ) {
			return;
		}

		$user      = wp_get_current_user();
		$user_id   = $user ? $user->ID : 0;
		$user_name = $user ? $user->display_name : 'System';

		$note = $result['success']
			? sprintf( 'Ortto: manually resent "%s" successfully (HTTP %d).', $feed_name, (int) $result['code'] )
			: sprintf( 'Ortto: manual resend of "%s" failed. %s', $feed_name, $result['message'] );

		GFFormsModel::add_note( $entry_id, $user_id, $user_name, $note, 'alpha-ortto' );
	}
}
