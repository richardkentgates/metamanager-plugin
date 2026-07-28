<?php
/**
 * Apt-server-based automatic updater for Metamanager.
 *
 * Hooks into the WordPress core plugin-update pipeline so Metamanager appears
 * in Dashboard → Updates exactly like a wordpress.org-hosted plugin.
 *
 * How it works:
 *   1. Every 12 hours (matching WP's own update check interval) this class
 *      fetches metadata.json from the apt server.
 *   2. If the remote version is newer than MM_VERSION it injects a
 *      plugin_information object into the core update transient.
 *   3. WordPress then offers the update in the normal UI and can install it
 *      with a single click — no manual download required.
 *   4. After WordPress downloads the zip, a filter renames the extracted
 *      folder to the correct slug (`metamanager`) so the plugin path stays
 *      stable.
 *
 * A "Check for Updates" action link is added to the Plugins list page so
 * admins can force an immediate check without waiting for the next cron cycle.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Updater
 */
class MM_Updater {

	/** URL of the apt server metadata.json. */
	private const METADATA_URL = 'http://apt.richardkentgates.com/metamanager/metadata.json';

	/** WordPress option / transient key used to cache the remote metadata. */
	private const TRANSIENT = 'mm_remote_metadata';

	/** How long to cache the remote response (seconds). Mirrors WP's 12-hour cycle. */
	private const CACHE_TTL = 43200;

	/** Basename of this plugin file, e.g. "metamanager/metamanager.php". */
	private string $plugin_basename;

	/**
	 * Boot the updater.
	 */
	public static function init(): void {
		$instance = new self();
		$instance->hooks();
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->plugin_basename = plugin_basename( MM_PLUGIN_FILE );
	}

	/**
	 * Register all WordPress hooks.
	 */
	private function hooks(): void {
		// Inject update data into the WP update transient.
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );

		// Provide plugin info for the "View version x.x.x details" modal.
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );

		// Rename the extracted zip folder to the correct plugin slug.
		add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );

		// Add "Check for Updates" link on the Plugins page.
		add_filter( 'plugin_action_links_' . $this->plugin_basename, [ $this, 'action_links' ] );

		// Handle the manual check-for-updates request.
		add_action( 'admin_init', [ $this, 'handle_manual_check' ] );

		// After any plugin is updated, prompt admins to restart the OS daemons.
		add_action( 'upgrader_process_complete', [ $this, 'on_plugin_updated' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Apt server metadata
	// -------------------------------------------------------------------------

	/**
	 * Fetch the latest metadata from the apt server, with caching.
	 *
	 * @param bool $force_refresh Bypass the transient cache when true.
	 * @return object|null  Decoded metadata object, or null on failure.
	 */
	private function get_metadata( bool $force_refresh = false ): ?object {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT );
			if ( false !== $cached ) {
				return $cached ?: null;
			}
		}

		$response = wp_remote_get( self::METADATA_URL, [
			'timeout'    => 10,
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; Metamanager/' . MM_VERSION,
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			// Cache an empty marker so we don't hammer the server on every page load.
			set_transient( self::TRANSIENT, '', self::CACHE_TTL );
			return null;
		}

		$metadata = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $metadata->version ) || empty( $metadata->download_url ) ) {
			set_transient( self::TRANSIENT, '', self::CACHE_TTL );
			return null;
		}

		set_transient( self::TRANSIENT, $metadata, self::CACHE_TTL );
		return $metadata;
	}

	// -------------------------------------------------------------------------
	// WordPress update pipeline hooks
	// -------------------------------------------------------------------------

	/**
	 * Inject Metamanager update data into the WP plugin-update transient.
	 *
	 * WordPress calls this filter every time it refreshes plugin update info.
	 * If a newer version is available on the apt server, we add an entry to
	 * $transient->response so the "Updates" badge appears in the admin menu.
	 *
	 * @param  \stdClass $transient The update_plugins site transient.
	 * @return object            (possibly modified) transient.
	 */
	public function inject_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$metadata = $this->get_metadata();
		if ( null === $metadata ) {
			return $transient;
		}

		$remote_version = $metadata->version;

		if ( version_compare( $remote_version, MM_VERSION, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) [
				'id'            => 'metamanager/apt-server',
				'slug'          => 'metamanager',
				'plugin'        => $this->plugin_basename,
				'new_version'   => $remote_version,
				'url'           => 'https://github.com/richardkentgates/metamanager',
				'package'       => $metadata->download_url,
				'icons'         => [],
				'banners'       => [],
				'banners_rtl'   => [],
				'tested'        => '',
				'requires_php'  => $metadata->requires->php ?? '8.0',
				'compatibility' => new stdClass(),
			];
		} else {
			// No update — ensure we are flagged as up-to-date so WP hides old notices.
			$transient->no_update[ $this->plugin_basename ] = (object) [
				'id'          => 'metamanager/apt-server',
				'slug'        => 'metamanager',
				'plugin'      => $this->plugin_basename,
				'new_version' => MM_VERSION,
				'url'         => 'https://github.com/richardkentgates/metamanager',
				'package'     => '',
			];
		}

		return $transient;
	}

	/**
	 * Provide plugin information for the "View version x.x.x details" thickbox.
	 *
	 * @param false|object $result  Existing result (false if unhandled).
	 * @param string       $action  Current API action.
	 * @param object       $args    API request arguments.
	 * @return false|object         Plugin info object, or false to let WP handle it.
	 */
	public function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( empty( $args->slug ) || 'metamanager' !== $args->slug ) {
			return $result;
		}

		$metadata = $this->get_metadata();
		if ( null === $metadata ) {
			return $result;
		}

		$remote_version = $metadata->version;
		$requires_php   = $metadata->requires->php ?? '8.0';
		$requires_wp    = $metadata->requires->wordpress ?? '6.0';

		return (object) [
			'name'              => 'Metamanager',
			'slug'              => 'metamanager',
			'version'           => $remote_version,
			'author'            => '<a href="https://github.com/richardkentgates">Richard Kent Gates</a>',
			'author_profile'    => 'https://github.com/richardkentgates',
			'homepage'          => 'https://metamanager.richardkentgates.com',
			'requires'          => $requires_wp,
			'requires_php'      => $requires_php,
			'download_link'     => $metadata->download_url,
			'trunk'             => $metadata->download_url,
			'last_updated'      => '',
			'sections'          => [
				'description' => 'Lossless image compression and standards-compliant EXIF/IPTC/XMP metadata embedding for WordPress, powered by OS-level daemons.',
				'changelog'   => $this->build_changelog_section(),
			],
			'banners'           => [],
			'icons'             => [],
		];
	}

	/**
	 * Fix the plugin folder name after WordPress extracts the zip.
	 *
	 * WordPress needs the folder to be "metamanager/" to match the installed slug,
	 * otherwise it treats the update as a new plugin.
	 *
	 * @param  string      $source        Extracted folder path.
	 * @param  string      $remote_source Temp folder containing the zip.
	 * @param  WP_Upgrader $upgrader      Upgrader instance.
	 * @param  array       $hook_extra    Extra context.
	 * @return string                     Corrected source path.
	 */
	public function fix_source_dir( string $source, string $remote_source, $upgrader, array $hook_extra ): string {
		global $wp_filesystem;

		// Only act on our own plugin.
		if ( empty( $hook_extra['plugin'] ) || $this->plugin_basename !== $hook_extra['plugin'] ) {
			return $source;
		}

		// If the folder already has the right name, nothing to do.
		$correct = trailingslashit( $remote_source ) . 'metamanager/';
		if ( $source === $correct ) {
			return $source;
		}

		// Rename the extracted folder.
		if ( $wp_filesystem->move( $source, $correct ) ) {
			return $correct;
		}

		// If rename failed, return original so WP can at least attempt the update.
		return $source;
	}

	// -------------------------------------------------------------------------
	// Manual "Check for Updates" link
	// -------------------------------------------------------------------------

	/**
	 * Add a "Check for Updates" action link on the Plugins page.
	 *
	 * @param  array $links Existing action links.
	 * @return array        Modified action links.
	 */
	public function action_links( array $links ): array {
		$check_url = wp_nonce_url(
			add_query_arg( [ 'mm_check_update' => '1' ], admin_url( 'plugins.php' ) ),
			'mm_check_update'
		);

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $check_url ),
			esc_html__( 'Check for Updates', 'metamanager' )
		);

		return $links;
	}

	/**
	 * Handle the manual update check request.
	 *
	 * Clears the cached metadata transient, forces a fresh apt server call,
	 * then redirects back to the Plugins page with a result notice.
	 */
	public function handle_manual_check(): void {
		if ( empty( $_GET['mm_check_update'] ) ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to update plugins.', 'metamanager' ) );
		}

		check_admin_referer( 'mm_check_update' );

		delete_transient( self::TRANSIENT );
		// Also clear WP's own plugin update transient so it re-evaluates immediately.
		delete_site_transient( 'update_plugins' );

		$metadata = $this->get_metadata( true );

		if ( $metadata ) {
			$remote_version = $metadata->version;
			if ( version_compare( $remote_version, MM_VERSION, '>' ) ) {
				$notice = urlencode( sprintf(
					/* translators: %s = new version number */
					__( 'Metamanager %s is available. Check the Updates page to install.', 'metamanager' ),
					$remote_version
				) );
				$type = 'updated';
			} else {
				$notice = urlencode( __( 'Metamanager is up to date.', 'metamanager' ) );
				$type   = 'updated';
			}
		} else {
			$notice = urlencode( __( 'Could not contact the update server to check for updates.', 'metamanager' ) );
			$type   = 'error';
		}

		wp_safe_redirect( add_query_arg(
			[
				'mm_notice'      => $notice,
				'mm_notice_type' => $type,
				'mm_check_update' => false,   // drop nonce params on redirect
				'_wpnonce'        => false,
			],
			admin_url( 'plugins.php' )
		) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Fires after the upgrader process completes. Triggers an automatic
	 * daemon package update when the plugin version changes, and sets a
	 * transient so the next admin page load shows a result notice.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance (unused).
	 * @param array        $options  Upgrader context: type, action, plugins list.
	 */
	public function on_plugin_updated( $upgrader, array $options ): void {
		if ( 'update' !== ( $options['action'] ?? '' ) ) {
			return;
		}
		if ( 'plugin' !== ( $options['type'] ?? '' ) ) {
			return;
		}
		$updated_plugins = $options['plugins'] ?? [];
		if ( ! in_array( $this->plugin_basename, (array) $updated_plugins, true ) ) {
			return;
		}

		// Trigger automatic daemon update.
		$daemon_result = MM_Daemon_Updater::handle_plugin_update();

		if ( $daemon_result['update_needed'] && $daemon_result['result']['success'] ) {
			set_transient( 'mm_daemon_restart_notice', '1', 7 * DAY_IN_SECONDS );
		}
	}

	/**
	 * Build a minimal changelog section from the CHANGELOG.md file in the
	 * plugin directory, stripping headings and converting to simple HTML.
	 * Falls back to an empty string if the file is not present.
	 */
	private function build_changelog_section(): string {
		$file = MM_PLUGIN_DIR . 'CHANGELOG.md';
		if ( ! file_exists( $file ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw  = file_get_contents( $file );
		$html = '';

		// Convert ## headings → <h4> and bullet lines → <li>.
		foreach ( explode( "\n", $raw ) as $line ) {
			$line = trim( $line );
			if ( str_starts_with( $line, '## ' ) ) {
				$html .= '<h4>' . esc_html( substr( $line, 3 ) ) . '</h4>';
			} elseif ( str_starts_with( $line, '- ' ) ) {
				$html .= '<li>' . esc_html( substr( $line, 2 ) ) . '</li>';
			}
		}

		return $html;
	}
}
