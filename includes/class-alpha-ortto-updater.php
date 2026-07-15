<?php
/**
 * GitHub-based plugin updater for Alpha Ortto Integration.
 *
 * Lets WordPress see new releases published on GitHub as regular plugin
 * updates (Dashboard -> Updates, and the Plugins screen). Modelled on the
 * Alpha theme's updater, but adapted for the plugin update transient and
 * with a few hardening changes:
 *
 *   - Uses the WordPress HTTP API (wp_remote_get) instead of raw cURL, so
 *     TLS verification stays on and requests respect site HTTP settings.
 *   - Caches the GitHub API response in a transient to avoid hammering the
 *     API (and hitting the 60/hr unauthenticated rate limit).
 *   - Prefers a release asset zip (correct folder name) but falls back to
 *     the source zipball, renaming the extracted folder to the plugin slug.
 *   - Optional auth token (for private repos or higher rate limits) read
 *     from the `alpha_ortto_updates_key` option.
 *
 * @package Alpha_Ortto_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Alpha_Ortto_Updater {

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Plugin basename, e.g. "alpha-ortto-integration/alpha-ortto-integration.php".
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Plugin folder slug, e.g. "alpha-ortto-integration".
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Currently installed version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * GitHub owner / organisation.
	 *
	 * @var string
	 */
	private $username;

	/**
	 * GitHub repository name.
	 *
	 * @var string
	 */
	private $repository;

	/**
	 * Optional GitHub auth token (for private repos / rate limits).
	 *
	 * @var string|null
	 */
	private $authorize_token;

	/**
	 * Cached "latest release" object from the GitHub API.
	 *
	 * @var object|null
	 */
	private $github_response;

	/**
	 * How long (seconds) to cache the GitHub API response.
	 *
	 * @var int
	 */
	private $cache_ttl = HOUR_IN_SECONDS * 6;

	/**
	 * @param string $file Absolute path to the main plugin file (__FILE__).
	 */
	public function __construct( $file ) {
		$this->file = $file;
	}

	public function set_username( $username ) {
		$this->username = $username;
	}

	public function set_repository( $repository ) {
		$this->repository = $repository;
	}

	public function authorize( $token ) {
		$this->authorize_token = $token;
	}

	/**
	 * Register all the WordPress hooks the updater needs.
	 */
	public function initialize() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data    = get_plugin_data( $this->file, false, false );
		$this->basename = plugin_basename( $this->file );
		$this->slug     = dirname( $this->basename );
		$this->version  = $plugin_data['Version'];

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'modify_transient' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_popup' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 2 );

		// Clear the cached release when this plugin is (re)activated.
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 2 );
	}

	/**
	 * Fetch (and cache) the latest release from the GitHub API.
	 *
	 * @return object|false Release object, or false on failure.
	 */
	private function get_repository_info() {
		if ( ! is_null( $this->github_response ) ) {
			return $this->github_response;
		}

		$cache_key   = 'alpha_ortto_gh_release';
		$force_check = ! empty( $_GET['force-check'] );

		if ( ! $force_check ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				$this->github_response = $cached;
				return $this->github_response;
			}
		}

		$request_uri = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $this->username ),
			rawurlencode( $this->repository )
		);

		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => $this->username . '-' . $this->slug,
			),
		);

		if ( $this->authorize_token ) {
			$args['headers']['Authorization'] = 'token ' . $this->authorize_token;
		}

		$response = wp_remote_get( $request_uri, $args );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly so we don't retry on every page load.
			set_transient( $cache_key, false, MINUTE_IN_SECONDS * 15 );
			$this->github_response = false;
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! is_object( $data ) || empty( $data->tag_name ) ) {
			set_transient( $cache_key, false, MINUTE_IN_SECONDS * 15 );
			$this->github_response = false;
			return false;
		}

		set_transient( $cache_key, $data, $this->cache_ttl );
		$this->github_response = $data;

		return $data;
	}

	/**
	 * Normalise a git tag (e.g. "v1.2.0") to a plain version ("1.2.0").
	 *
	 * @param string $tag Tag name.
	 * @return string
	 */
	private function normalize_version( $tag ) {
		return ltrim( trim( (string) $tag ), 'vV' );
	}

	/**
	 * Work out which URL WordPress should download for the update. Prefers a
	 * release asset (a purpose-built zip with the correct folder name), and
	 * falls back to the auto-generated source zipball.
	 *
	 * @param object $release GitHub release object.
	 * @return string
	 */
	private function get_download_url( $release ) {
		if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( isset( $asset->name ) && preg_match( '/\.zip$/i', $asset->name ) ) {
					return $asset->browser_download_url;
				}
			}
		}

		return isset( $release->zipball_url ) ? $release->zipball_url : '';
	}

	/**
	 * Inject our update into the plugins update transient when GitHub has a
	 * newer version than what's installed.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function modify_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		// Only proceed after WordPress has done its own version check.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_repository_info();
		if ( ! $release ) {
			return $transient;
		}

		$new_version = $this->normalize_version( $release->tag_name );
		$package     = $this->get_download_url( $release );

		if ( '' === $package ) {
			return $transient;
		}

		$out_of_date = version_compare( $new_version, $this->version, 'gt' );

		$update = (object) array(
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $new_version,
			'url'         => sprintf( 'https://github.com/%s/%s', $this->username, $this->repository ),
			'package'     => $package,
			'tested'      => get_bloginfo( 'version' ),
		);

		if ( $out_of_date ) {
			$transient->response[ $this->basename ] = $update;
		} else {
			// Report "no update" so the Plugins screen shows current state.
			$transient->no_update[ $this->basename ] = $update;
		}

		return $transient;
	}

	/**
	 * Provide the "View version details" popup content on the Plugins screen.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object
	 */
	public function plugin_popup( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_repository_info();
		if ( ! $release ) {
			return $result;
		}

		$plugin_data = get_plugin_data( $this->file, false, false );

		return (object) array(
			'name'          => $plugin_data['Name'],
			'slug'          => $this->slug,
			'version'       => $this->normalize_version( $release->tag_name ),
			'author'        => $plugin_data['Author'],
			'homepage'      => $plugin_data['PluginURI'],
			'requires'      => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
			'requires_php'  => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => isset( $release->published_at ) ? $release->published_at : '',
			'download_link' => $this->get_download_url( $release ),
			'sections'      => array(
				'description' => $plugin_data['Description'],
				'changelog'   => isset( $release->body ) ? nl2br( esc_html( $release->body ) ) : '',
			),
		);
	}

	/**
	 * Add the auth token to the download request for private repos.
	 *
	 * @param bool   $reply   Whether to bail without returning the package.
	 * @param string $package The package file name or URL.
	 * @return bool
	 */
	public function pre_download( $reply, $package ) {
		if ( $this->authorize_token && false !== strpos( (string) $package, 'github.com' ) ) {
			add_filter(
				'http_request_args',
				function ( $args, $url ) use ( $package ) {
					if ( $url === $package ) {
						$args['headers']['Authorization'] = 'token ' . $this->authorize_token;
					}
					return $args;
				},
				10,
				2
			);
		}

		return $reply;
	}

	/**
	 * When installing from a GitHub zipball, the extracted folder is named
	 * "owner-repo-hash". Rename it to the plugin slug so WordPress updates the
	 * correct plugin. Release-asset zips already use the right name, so this
	 * is a no-op for them.
	 *
	 * @param string      $source        File source location.
	 * @param string      $remote_source Remote file source location.
	 * @param WP_Upgrader $upgrader      WP_Upgrader instance.
	 * @param array       $hook_extra    Extra arguments passed to hooked filters.
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;

		if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Clear the cached release after an update runs, so the next check is fresh.
	 *
	 * @param WP_Upgrader $upgrader WP_Upgrader instance.
	 * @param array       $data     Update data.
	 */
	public function flush_cache( $upgrader, $data ) {
		if ( isset( $data['type'] ) && 'plugin' === $data['type'] ) {
			delete_transient( 'alpha_ortto_gh_release' );
		}
	}
}
