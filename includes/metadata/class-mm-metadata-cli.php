<?php
/**
 * MM_Metadata_CLI — WP-CLI command group.
 *
 * Usage: wp metamanager metadata <subcommand> [options]
 *
 * Subcommands:
 *   export          Dump current settings as JSON to stdout
 *   import          Import settings from a JSON file or stdin
 *   reset           Reset all settings to defaults
 *   backfill-links  Extract links from all existing published posts
 *   check-links     Run a full broken-link scan immediately
 *   ping            Ping Google + Bing with the sitemap URL
 *   flush-rewrites  Flush WordPress rewrite rules
 *   schema-test     Fetch a URL and print the JSON-LD found in <head>
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class MM_Metadata_CLI extends \WP_CLI_Command {

	// -------------------------------------------------------------------------
	// export
	// -------------------------------------------------------------------------

	/**
	 * Export current plugin settings as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: json (default), pretty.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager export
	 *   wp metamanager export --format=pretty
	 *
	 * @when after_wp_load
	 */
	public function export( array $args, array $assoc_args ): void {
		$settings  = get_option( MM_META_OPT_SETTINGS, [] );
		$business  = get_option( MM_META_OPT_BUSINESS, [] );
		$format    = $assoc_args['format'] ?? 'json';
		$flags     = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

		if ( 'pretty' === $format ) {
			$flags |= JSON_PRETTY_PRINT;
		}

		WP_CLI::line( wp_json_encode( compact( 'settings', 'business' ), $flags ) );
	}

	// -------------------------------------------------------------------------
	// import
	// -------------------------------------------------------------------------

	/**
	 * Import plugin settings from a JSON file or stdin.
	 *
	 * Reads a JSON file previously created by `wp metamanager export` and
	 * applies the settings and/or business profile data. Missing keys are
	 * filled from factory defaults so the imported data is always complete.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Path to a JSON file. Omit to read from stdin.
	 *
	 * [--dry-run]
	 * : Show what would be imported without making changes.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager import backup.json
	 *   wp metamanager import backup.json --dry-run
	 *   cat backup.json | wp metamanager import
	 *
	 * @when after_wp_load
	 */
	public function import( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$file    = $args[0] ?? '';

		// Read JSON from file or stdin.
		if ( '' !== $file ) {
			$file = realpath( $file );
			if ( ! $file || ! is_readable( $file ) ) {
				WP_CLI::error( "Cannot read file: {$args[0]}" );
			}
			$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			$json = '';
			while ( !feof( STDIN ) ) {
				$json .= fread( STDIN, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_operations_read
			}
		}

		$data = json_decode( trim( $json ), true );
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'Invalid JSON — expected an object with "settings" and/or "business" keys.' );
		}

		$has_settings = isset( $data['settings'] ) && is_array( $data['settings'] );
		$has_business = isset( $data['business'] ) && is_array( $data['business'] );

		if ( ! $has_settings && ! $has_business ) {
			WP_CLI::error( 'JSON must contain a "settings" and/or "business" key.' );
		}

		// Deep-merge with defaults so imported data is always complete.
		$settings = $has_settings
			? $this->deep_merge( MM_Site_Settings::settings_defaults(), $data['settings'] )
			: null;
		$business = $has_business
			? $this->deep_merge( MM_Site_Settings::business_defaults(), $data['business'] )
			: null;

		// Show diff.
		if ( $has_settings ) {
			$old = get_option( MM_META_OPT_SETTINGS, [] );
			$this->show_settings_diff( $old, $settings );
		}
		if ( $has_business ) {
			$old = get_option( MM_META_OPT_BUSINESS, [] );
			$this->show_settings_diff( $old, $business, 'business' );
		}

		if ( $dry_run ) {
			WP_CLI::warning( 'Dry run — no changes written.' );
			return;
		}

		// Write.
		if ( $has_settings ) {
			update_option( MM_META_OPT_SETTINGS, $settings, false );
		}
		if ( $has_business ) {
			update_option( MM_META_OPT_BUSINESS, $business, false );
		}

		$parts = [];
		if ( $has_settings ) { $parts[] = 'settings'; }
		if ( $has_business ) { $parts[] = 'business profile'; }
		WP_CLI::success( 'Imported ' . implode( ' + ', $parts ) . '.' );
	}

	// -------------------------------------------------------------------------
	// reset
	// -------------------------------------------------------------------------

	/**
	 * Reset all plugin settings to factory defaults.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager reset
	 *
	 * @when after_wp_load
	 */
	public function reset( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'This will overwrite all Metamanager settings. Continue?' );
		update_option( MM_META_OPT_SETTINGS, MM_Site_Settings::settings_defaults() );
		update_option( MM_META_OPT_BUSINESS, MM_Site_Settings::business_defaults() );
		WP_CLI::success( 'Settings reset to defaults.' );
	}

	// -------------------------------------------------------------------------
	// check-links
	// -------------------------------------------------------------------------

	/**
	 * Run a full broken-link scan immediately (bypasses the cron schedule).
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Check ALL queued links, not just the next batch.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager check-links
	 *   wp metamanager check-links --all
	 *
	 * @when after_wp_load
	 */
	public function check_links( array $args, array $assoc_args ): void {
		$settings = MM_Site_Settings::get_instance();
		$checker  = new MM_Mod_Links( $settings );
		$check_all = isset( $assoc_args['all'] );

		if ( $check_all ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_ignored = 0', MM_Mod_Links::table_name() ) );
			$batch = (int) $settings->get( 'links.batch_size', 50 );
			$runs  = (int) ceil( $total / max( 1, $batch ) );

			WP_CLI::log( "Running {$runs} batch(es) for {$total} link(s)..." );
			for ( $i = 0; $i < $runs; $i++ ) {
				$checker->run_batch_check();
				WP_CLI::log( "Batch " . ( $i + 1 ) . "/{$runs} done." );
			}
		} else {
			$checker->run_batch_check();
			WP_CLI::log( "One batch checked." );
		}

		// Report broken count.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$broken = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_broken = 1 AND is_ignored = 0', MM_Mod_Links::table_name() ) );
		WP_CLI::success( "Done. Broken links: {$broken}." );
	}

	// -------------------------------------------------------------------------
	// ping
	// -------------------------------------------------------------------------

	/**
	 * Immediately ping Google and Bing with the sitemap URL.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager ping
	 *
	 * @when after_wp_load
	 */
	public function ping( array $args, array $assoc_args ): void {
		$settings = MM_Site_Settings::get_instance();
		$sitemap  = new MM_Mod_Sitemap_Web( $settings );
		$sitemap->send_ping();
		WP_CLI::success( 'Ping sent to search engines.' );
	}

	// -------------------------------------------------------------------------
	// backfill-links
	// -------------------------------------------------------------------------

	/**
	 * Scan all existing published posts and populate the link table.
	 *
	 * Posts that were saved after the plugin was installed (and therefore
	 * already indexed via the save_post hook) are skipped automatically.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager backfill-links
	 *
	 * @when after_wp_load
	 */
	public function backfill_links( array $args, array $assoc_args ): void {
		$settings = MM_Site_Settings::get_instance();
		$mod      = new MM_Mod_Links( $settings );
		$batch    = 50;
		$offset   = 0;
		$progress = null;
		$new_count     = 0;
		$skipped_count = 0;

		do {
			$result = $mod->backfill_posts( $offset, $batch );

			if ( null === $progress && $result['total'] > 0 ) {
				$progress = \WP_CLI\Utils\make_progress_bar(
					"Scanning {$result['total']} post(s)",
					$result['total']
				);
			}

			if ( $progress ) {
				$progress->tick( $result['scanned'] );
			}

			$new_count     += $result['scanned'] - $result['skipped'];
			$skipped_count += $result['skipped'];
			$offset         = $result['new_offset'];

		} while ( ! $result['done'] && $result['scanned'] > 0 );

		if ( $progress ) {
			$progress->finish();
		}

		WP_CLI::success( "Done. Extracted links from {$new_count} new post(s). {$skipped_count} already-indexed post(s) skipped." );
	}

	// -------------------------------------------------------------------------
	// flush-rewrites
	// -------------------------------------------------------------------------

	/**
	 * Flush WordPress rewrite rules.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager flush-rewrites
	 *
	 * @when after_wp_load
	 */
	public function flush_rewrites( array $args, array $assoc_args ): void {
		flush_rewrite_rules( true );
		WP_CLI::success( 'Rewrite rules flushed.' );
	}

	// -------------------------------------------------------------------------
	// schema-test
	// -------------------------------------------------------------------------

	/**
	 * Fetch a site URL and print any JSON-LD schema found in the page.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The page URL to test. Must be on this WordPress site.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager schema-test https://example.com/about/
	 *
	 * @when after_wp_load
	 */
	public function schema_test( array $args, array $assoc_args ): void {
		$url = $args[0] ?? '';
		if ( ! $url ) {
			WP_CLI::error( 'Please provide a URL.' );
		}

		// Validate URL belongs to this site.
		if ( strpos( $url, home_url() ) !== 0 ) {
			WP_CLI::error( 'URL must start with ' . home_url() );
		}

		$response = wp_remote_get( $url, [
			'timeout'   => 15,
			'sslverify' => false,
			'user-agent' => 'GCM-SEO-CLI/1.0',
		] );

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( 'Request failed: ' . $response->get_error_message() );
		}

		$body    = wp_remote_retrieve_body( $response );
		$code    = wp_remote_retrieve_response_code( $response );
		WP_CLI::log( "HTTP {$code}" );

		// Extract all <script type="application/ld+json"> blocks.
		if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $body, $matches ) ) {
			foreach ( $matches[1] as $i => $json ) {
				WP_CLI::log( "\n── JSON-LD block " . ( $i + 1 ) . " ──" );
				$decoded = json_decode( trim( $json ), true );
				WP_CLI::line( wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			}
		} else {
			WP_CLI::warning( 'No JSON-LD blocks found in page.' );
		}

		// Also show meta robots.
		if ( preg_match( '/<meta\s+name=["\']robots["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $body, $m ) ) {
			WP_CLI::log( "\nmeta robots: " . $m[1] );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Recursively merge $override into $base. Arrays are merged by key;
	 * scalar values in $override replace $base values.
	 */
	private function deep_merge( array $base, array $override ): array {
		$result = $base;
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $result[ $key ] ) && is_array( $result[ $key ] ) ) {
				$result[ $key ] = $this->deep_merge( $result[ $key ], $value );
			} else {
				$result[ $key ] = $value;
			}
		}
		return $result;
	}

	/**
	 * Show a flat diff between old and new settings arrays.
	 */
	private function show_settings_diff( array $old, array $new, string $label = 'settings' ): void {
		$flat_old = $this->flatten_array( $old );
		$flat_new = $this->flatten_array( $new );
		$all_keys = array_unique( array_merge( array_keys( $flat_old ), array_keys( $flat_new ) ) );
		sort( $all_keys );

		$changes = 0;
		foreach ( $all_keys as $key ) {
			$old_val = $flat_old[ $key ] ?? '(missing)';
			$new_val = $flat_new[ $key ] ?? '(missing)';
			if ( $old_val === $new_val ) {
				continue;
			}
			++$changes;
			WP_CLI::log( "  {$label}: {$key}" );
			WP_CLI::log( "    - " . wp_json_encode( $old_val ) );
			WP_CLI::log( "    + " . wp_json_encode( $new_val ) );
		}

		if ( 0 === $changes ) {
			WP_CLI::log( "  {$label}: no changes" );
		} else {
			WP_CLI::log( "  {$label}: {$changes} change(s)" );
		}
	}

	/**
	 * Flatten a nested array to dot-notation keys.
	 *
	 * @return array<string, mixed>
	 */
	private function flatten_array( array $array, string $prefix = '' ): array {
		$result = [];
		foreach ( $array as $key => $value ) {
			$new_key = '' === $prefix ? $key : "{$prefix}.{$key}";
			if ( is_array( $value ) && ! empty( $value ) ) {
				$result += $this->flatten_array( $value, $new_key );
			} else {
				$result[ $new_key ] = $value;
			}
		}
		return $result;
	}
}

\WP_CLI::add_command( 'metamanager metadata', 'MM_Metadata_CLI' );
