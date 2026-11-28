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
	 *   wp metamanager metadata export
	 *   wp metamanager metadata export --format=pretty
	 *
	 * @when after_wp_load
	 */
	public function export( array $args, array $assoc_args ): void {
		$settings = get_option( MM_META_OPT_SETTINGS, array() );
		$business = get_option( MM_META_OPT_BUSINESS, array() );
		$format   = $assoc_args['format'] ?? 'json';
		$flags    = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

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
	 * Reads a JSON file previously created by `wp metamanager metadata export` and
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
	 *   wp metamanager metadata import backup.json
	 *   wp metamanager metadata import backup.json --dry-run
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
			while ( ! feof( STDIN ) ) {
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
			$old = get_option( MM_META_OPT_SETTINGS, array() );
			$this->show_settings_diff( $old, $settings );
		}
		if ( $has_business ) {
			$old = get_option( MM_META_OPT_BUSINESS, array() );
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

		$parts = array();
		if ( $has_settings ) {
			$parts[] = 'settings'; }
		if ( $has_business ) {
			$parts[] = 'business profile'; }
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
	 *   wp metamanager metadata reset
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
	 *   wp metamanager metadata check-links
	 *   wp metamanager metadata check-links --all
	 *
	 * @when after_wp_load
	 */
	public function check_links( array $args, array $assoc_args ): void {
		$settings  = MM_Site_Settings::get_instance();
		$checker   = new MM_Mod_Links( $settings );
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
				WP_CLI::log( 'Batch ' . ( $i + 1 ) . "/{$runs} done." );
			}
		} else {
			$checker->run_batch_check();
			WP_CLI::log( 'One batch checked.' );
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
	 *   wp metamanager metadata ping
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
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Re-extract links from posts already in the link table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata backfill-links
	 *   wp metamanager metadata backfill-links --force
	 *
	 * @when after_wp_load
	 */
	public function backfill_links( array $args, array $assoc_args ): void {
		$settings      = MM_Site_Settings::get_instance();
		$mod           = new MM_Mod_Links( $settings );
		$batch         = 50;
		$offset        = 0;
		$progress      = null;
		$new_count     = 0;
		$skipped_count = 0;
		$force = isset( $assoc_args['force'] );

		do {
			$result = $mod->backfill_posts( $offset, $batch, $force );

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
	 *   wp metamanager metadata flush-rewrites
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
	 *   wp metamanager metadata schema-test https://example.com/about/
	 *
	 * @when after_wp_load
	 */
	public function schema_test( array $args, array $assoc_args ): void {
		$url = $args[0] ?? '';
		if ( ! $url ) {
			WP_CLI::error( 'Please provide a URL.' );
		}

		// Validate URL belongs to this site.
		if ( ! str_starts_with( $url, home_url() ) ) {
			WP_CLI::error( 'URL must start with ' . home_url() );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'GCM-SEO-CLI/1.0',
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( 'Request failed: ' . $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );
		WP_CLI::log( "HTTP {$code}" );

		// Extract all <script type="application/ld+json"> blocks.
		if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $body, $matches ) ) {
			foreach ( $matches[1] as $i => $json ) {
				WP_CLI::log( "\n── JSON-LD block " . ( $i + 1 ) . ' ──' );
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
	 * Numeric lists are merged by key (not replaced).
	 */
	private function deep_merge( array $base, array $override ): array {
		return MM_Site_Settings::deep_merge( $base, $override, true );
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
			WP_CLI::log( '    - ' . wp_json_encode( $old_val ) );
			WP_CLI::log( '    + ' . wp_json_encode( $new_val ) );
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
		$result = array();
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

	// -------------------------------------------------------------------------
	// business — business profile settings (goes through save_business)
	// -------------------------------------------------------------------------

	/**
	 * Get or set business profile settings.
	 *
	 * Goes through MM_Site_Settings::save_business() for proper validation
	 * and geocoding. Single source of truth for business data.
	 *
	 * ## OPTIONS
	 *
	 * <get|set>
	 * : Subcommand: get or set.
	 *
	 * [--name=<text>]
	 * : Business name.
	 *
	 * [--type=<text>]
	 * : Business type (e.g. LocalBusiness, ProfessionalService).
	 *
	 * [--description=<text>]
	 * : Business description.
	 *
	 * [--street=<text>]
	 * : Street address.
	 *
	 * [--city=<text>]
	 * : City.
	 *
	 * [--state=<text>]
	 * : State or province.
	 *
	 * [--zip=<text>]
	 * : Postal code.
	 *
	 * [--country=<text>]
	 * : Country code (default US).
	 *
	 * [--phone=<text>]
	 * : Phone number.
	 *
	 * [--email=<text>]
	 * : Email address.
	 *
	 * [--location-type=<brick_and_mortar|service_area|none>]
	 * : Location type: brick_and_mortar or service_area.
	 *
	 * [--service-areas=<semicolon-separated>]
	 * : Semicolon-separated service areas (e.g. "Destin, FL; Fort Walton Beach, FL").
	 *
	 * [--lat=<number>]
	 * : Latitude (auto-geocoded from address if blank).
	 *
	 * [--lng=<number>]
	 * : Longitude (auto-geocoded from address if blank).
	 *
	 * [--show-phone=<0|1>]
	 * : Display phone button on contact page.
	 *
	 * [--show-sms=<0|1>]
	 * : Display SMS button on contact page.
	 *
	 * [--show-email=<0|1>]
	 * : Display email button on contact page.
	 *
	 * [--show-vcard=<0|1>]
	 * : Display vCard download button.
	 *
	 * [--show-json=<0|1>]
	 * : Display JSON download button.
	 *
	 * [--show-csv=<0|1>]
	 * : Display CSV download button.
	 *
	 * [--booking-link=<url>]
	 * : External booking URL (FareHarbor, Vagaro, etc.).
	 *
	 * [--booking-label=<text>]
	 * : Booking button label.
	 *
	 * [--booking-new-tab=<0|1>]
	 * : Open booking link in new tab.
	 *
	 * [--price-range=<text>]
	 * : Price range (e.g. $$ or 50-150).
	 *
	 * [--founding-date=<date>]
	 * : Founding date.
	 *
	 * [--employees=<number>]
	 * : Number of employees.
	 *
	 * [--google-maps-key=<key>]
	 * : Google Maps API key (for geocoding).
	 *
	 * [--contact-form-enabled=<0|1>]
	 * : Toggle contact form (1 or 0).
	 *
	 * [--contact-form-subject-prefix=<text>]
	 * : Subject prefix for contact form emails.
	 *
	 * [--contact-form-submit-text=<text>]
	 * : Submit button text.
	 *
	 * [--contact-form-tos-page=<id>]
	 * : Terms of Service page ID (for the agreement checkbox link).
	 *
	 * [--contact-form-recaptcha-site=<key>]
	 * : reCAPTCHA site key (v2 invisible).
	 *
	 * [--contact-form-recaptcha-secret=<key>]
	 * : reCAPTCHA secret key (v2 invisible).
	 *
	 * [--booking-link=<url>]
	 * : External booking URL.
	 *
	 * [--booking-label=<text>]
	 * : Booking button label.
	 *
	 * [--format=<format>]
	 * : For get: output format (table or json).
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata business get
	 *   wp metamanager metadata business set --street="91 Ready Ave Ste D" --city="Fort Walton Beach" --state=FL --zip=32548
	 *   wp metamanager metadata business set --location-type=service_area --service-areas="Destin, FL; Fort Walton Beach, FL"
	 *
	 * @when after_wp_load
	 */
	public function business( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? 'get';
		switch ( $sub ) {
			case 'get':
				$this->business_get( $assoc_args );
				break;
			case 'set':
				$this->business_set( $assoc_args );
				break;
			default:
				WP_CLI::error( "Unknown subcommand: {$sub}. Valid: get, set" );
		}
	}

	/**
	 * Display current business profile.
	 */
	private function business_get( array $assoc_args ): void {
		$settings = MM_Site_Settings::get_instance();
		$biz      = $settings->all_business();
		$format   = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $biz, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$rows = [];
		foreach ( $biz as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}
			$rows[] = [ 'Key' => $key, 'Value' => (string) $value ];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'Key', 'Value' ] );
	}

	/**
	 * Update business profile fields through the settings layer.
	 */
	private function business_set( array $assoc_args ): void {
		$settings = MM_Site_Settings::get_instance();
		$biz      = $settings->all_business();

		$field_map = [
			'name'           => [ 'name' ],
			'type'           => [ 'type' ],
			'description'    => [ 'description' ],
			'street'         => [ 'address', 'street' ],
			'city'           => [ 'address', 'city' ],
			'state'          => [ 'address', 'state' ],
			'zip'            => [ 'address', 'zip' ],
			'country'        => [ 'address', 'country' ],
			'phone'          => [ 'phone' ],
			'email'          => [ 'email' ],
			'location-type'  => [ 'location_type' ],
			'lat'            => [ 'lat' ],
			'lng'            => [ 'lng' ],
			'show-phone'     => [ 'show_phone' ],
			'show-sms'       => [ 'show_sms' ],
			'show-email'     => [ 'show_email' ],
			'show-vcard'     => [ 'show_vcard' ],
			'show-json'      => [ 'show_json' ],
			'show-csv'       => [ 'show_csv' ],
			'booking-link'   => [ 'booking_link' ],
			'booking-label'  => [ 'booking_link_label' ],
			'booking-new-tab' => [ 'booking_link_new_tab' ],
			'price-range'    => [ 'price_range' ],
			'founding-date'  => [ 'founding_date' ],
			'employees'      => [ 'number_of_employees' ],
			'google-maps-key' => [ 'google_maps_api_key' ],
			'contact-form-enabled'         => [ 'contact_form_enabled' ],
			'contact-form-subject-prefix'  => [ 'contact_form_subject_prefix' ],
			'contact-form-submit-text'     => [ 'contact_form_submit_text' ],
			'contact-form-tos-page'        => [ 'contact_form_tos_page_id' ],
			'contact-form-recaptcha-site'  => [ 'contact_form_recaptcha_site' ],
			'contact-form-recaptcha-secret' => [ 'contact_form_recaptcha_secret' ],
		];

		$changes = 0;
		foreach ( $field_map as $cli_key => $path ) {
			if ( ! isset( $assoc_args[ $cli_key ] ) ) {
				continue;
			}
			$value = (string) $assoc_args[ $cli_key ];
			$ref   = & $biz;
			$last  = array_pop( $path );
			foreach ( $path as $k ) {
				if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) {
					$ref[ $k ] = [];
				}
				$ref = & $ref[ $k ];
			}
			$ref[ $last ] = $value;
			++$changes;
		}

		if ( isset( $assoc_args['service-areas'] ) ) {
			$areas = array_filter( array_map( 'trim', explode( ';', (string) $assoc_args['service-areas'] ) ) );
			$biz['service_areas'] = array_values( $areas );
			++$changes;
		}

		if ( 0 === $changes ) {
			WP_CLI::error( 'No fields specified. Use --help to see available fields.' );
		}

		$settings->save_business( $biz );
		WP_CLI::success( sprintf( 'Business profile updated (%d fields).', $changes ) );
	}

	// -------------------------------------------------------------------------
	// titles — title and description templates
	// -------------------------------------------------------------------------

	/**
	 * Get or set title and description templates.
	 *
	 * Page/post descriptions derive from excerpts. These settings control
	 * the homepage title/description and global title templates.
	 *
	 * ## OPTIONS
	 *
	 * <get|set>
	 * : Subcommand: get or set.
	 *
	 * [--home-title=<template>]
	 * : Homepage title template. Supports %%sitetitle%%, %%sep%%, %%tagline%%.
	 *
	 * [--home-description=<text>]
	 * : Homepage meta description.
	 *
	 * [--separator=<char>]
	 * : Title separator character (e.g. |, -, ·).
	 *
	 * [--search-title=<template>]
	 * : Search results title template. Supports %%search_query%%, %%sep%%, %%sitetitle%%.
	 *
	 * [--404-title=<template>]
	 * : 404 page title template.
	 *
	 * [--format=<format>]
	 * : For get: output format (table or json).
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata titles get
	 *   wp metamanager metadata titles set --home-description="Payroll and HR services in Destin, Florida"
	 *   wp metamanager metadata titles set --separator="-"
	 *
	 * @when after_wp_load
	 */
	public function titles( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? 'get';
		switch ( $sub ) {
			case 'get':
				$this->titles_get( $assoc_args );
				break;
			case 'set':
				$this->titles_set( $assoc_args );
				break;
			default:
				WP_CLI::error( "Unknown subcommand: {$sub}. Valid: get, set" );
		}
	}

	/**
	 * Display current title/description settings.
	 */
	private function titles_get( array $assoc_args ): void {
		$settings = MM_Site_Settings::get_instance();
		$format   = (string) ( $assoc_args['format'] ?? 'table' );
		$keys     = [ 'separator', 'home_title', 'home_description', 'search_title', '404_title', 'date_archive_noindex', 'search_noindex', 'paginate_append' ];

		if ( 'json' === $format ) {
			$out = [];
			foreach ( $keys as $k ) {
				$out[ $k ] = $settings->get( "titles.{$k}", '' );
			}
			WP_CLI::line( wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$rows = [];
		foreach ( $keys as $k ) {
			$rows[] = [ 'Key' => $k, 'Value' => (string) $settings->get( "titles.{$k}", '' ) ];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'Key', 'Value' ] );
	}

	/**
	 * Update title/description settings.
	 */
	private function titles_set( array $assoc_args ): void {
		$settings = MM_Site_Settings::get_instance();
		$titles   = [];
		foreach ( [ 'separator', 'home_title', 'home_description', 'search_title', '404_title' ] as $k ) {
			$titles[ $k ] = (string) $settings->get( "titles.{$k}", '' );
		}

		$field_map = [
			'home-title'        => 'home_title',
			'home-description'  => 'home_description',
			'separator'         => 'separator',
			'search-title'      => 'search_title',
			'404-title'         => '404_title',
		];

		$changes = 0;
		foreach ( $field_map as $cli_key => $path ) {
			if ( ! isset( $assoc_args[ $cli_key ] ) ) {
				continue;
			}
			$titles[ $path ] = (string) $assoc_args[ $cli_key ];
			++$changes;
		}

		if ( 0 === $changes ) {
			WP_CLI::error( 'No fields specified. Use --help to see available fields.' );
		}

		// Merge into existing settings, preserving all other sections.
		$raw  = get_option( MM_META_OPT_SETTINGS, [] );
		$full = is_array( $raw ) ? $raw : [];
		// Ensure defaults are present for all sections.
		$full = MM_Site_Settings::deep_merge( MM_Site_Settings::settings_defaults(), $full );
		$full['titles'] = array_merge( $full['titles'] ?? [], $titles );
		$settings->save_settings( $full );
		WP_CLI::success( sprintf( 'Title settings updated (%d fields).', $changes ) );
	}

	// -------------------------------------------------------------------------
	// pref — plugin preferences (mm_* standalone options)
	// -------------------------------------------------------------------------

	/**
	 * Get or set Metamanager preference options.
	 *
	 * ## OPTIONS
	 *
	 * <list|get|set>
	 * : Subcommand: list, get, or set.
	 *
	 * [<key>]
	 * : For get/set: option key (e.g. mm_compress_level).
	 *
	 * [<value>]
	 * : For set: new value.
	 *
	 * [--format=<format>]
	 * : For list: output format (table or json).
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata pref list
	 *   wp metamanager metadata pref get mm_compress_level
	 *   wp metamanager metadata pref set mm_compress_level 3
	 *
	 * @when after_wp_load
	 */
	public function pref( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? 'list';
		switch ( $sub ) {
			case 'list':
				$this->pref_list( array_slice( $args, 1 ), $assoc_args );
				break;
			case 'get':
				$this->pref_get( array_slice( $args, 1 ), $assoc_args );
				break;
			case 'set':
				$this->pref_set( array_slice( $args, 1 ), $assoc_args );
				break;
			default:
				WP_CLI::error( "Unknown subcommand: {$sub}. Valid: list, get, set" );
		}
	}

	/**
	 * List all Metamanager preference options and their current values.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments (format).
	 */
	private function pref_list( array $args, array $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$prefs  = $this->get_pref_map();

		$rows = [];
		foreach ( $prefs as $label => $key ) {
			$rows[] = [ 'Option' => $key, 'Label' => $label, 'Value' => get_option( $key, '' ) ];
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'Option', 'Label', 'Value' ] );
		}
	}

	/**
	 * Get a single preference value.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Option key (e.g. mm_compress_level, mm_notify_email).
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata pref get mm_compress_level
	 *

	 * @when after_wp_load
	 */
		private function pref_get( array $args, array $assoc_args ): void {
		$key = $args[0] ?? '';
		if ( ! $key ) {
			WP_CLI::error( 'Usage: wp metamanager metadata pref get <key>' );
		}
		$prefs = $this->get_pref_map();
		if ( ! in_array( $key, $prefs, true ) ) {
			WP_CLI::error( "Unknown preference key: {$key}. Valid: " . implode( ', ', $prefs ) );
		}
		$value = get_option( $key, '' );
		WP_CLI::line( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
	}

	/**
	 * Set a preference value.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Option key (e.g. mm_compress_level, mm_notify_email).
	 *
	 * <value>
	 * : New value.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata pref set mm_compress_level 3
	 *   wp metamanager metadata pref set mm_notify_email ops@example.com
	 *

	 * @when after_wp_load
	 */
		private function pref_set( array $args, array $assoc_args ): void {
		$key   = $args[0] ?? '';
		$value = $args[1] ?? '';
		if ( ! $key ) {
			WP_CLI::error( 'Usage: wp metamanager metadata pref set <key> <value>' );
		}
		$prefs = $this->get_pref_map();
		if ( ! in_array( $key, $prefs, true ) ) {
			WP_CLI::error( "Unknown preference key: {$key}. Valid: " . implode( ', ', $prefs ) );
		}
		update_option( $key, $value );
		WP_CLI::success( "{$key} set to {$value}" );
	}

	/**
	 * Canonical map of preference labels to option keys.
	 *
	 * @return array<string, string>
	 */
	private function get_pref_map(): array {
		return [
			'Compress level'              => 'mm_compress_level',
			'Max revisions'               => 'mm_max_revisions',
			'Failure notifications'       => 'mm_notify_enabled',
			'Notification email'          => 'mm_notify_email',
			'REST API disabled'           => 'mm_api_disabled',
			'REST API allowed IPs'        => 'mm_api_allowed_ips',
			'Upload receipt CC'           => 'mm_upload_notify_extra_email',
			'Auto provenance'             => 'mm_auto_provenance',
			'Delete data on uninstall'    => 'mm_delete_data_on_uninstall',
			'Contact card style'          => 'mm_meta_contact_style',
		];
	}

	// -------------------------------------------------------------------------
	// links — link maintenance
	// -------------------------------------------------------------------------

	/**
	 * Manage tracked links (list, ignore, recheck, purge).
	 *
	 * ## OPTIONS
	 *
	 * <list|set-ignored|recheck|purge>
	 * : Subcommand.
	 *
	 * [<id>]
	 * : For set-ignored/recheck: link row ID.
	 *
	 * [<state>]
	 * : For set-ignored: "ignore" or "unignore".
	 *
	 * [--status=<status>]
	 * : For list: filter (broken, ok, ignored, all).
	 *
	 * [--yes]
	 * : For purge: confirm.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata links list --status=broken
	 *   wp metamanager metadata links set-ignored 5 ignore
	 *   wp metamanager metadata links recheck 5
	 *   wp metamanager metadata links purge --yes
	 *
	 * @when after_wp_load
	 */
	public function links( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? 'list';
		switch ( $sub ) {
			case 'list':
				$this->links_list( array_slice( $args, 1 ), $assoc_args );
				break;
			case 'set-ignored':
				$this->links_set_ignored( array_slice( $args, 1 ), $assoc_args );
				break;
			case 'recheck':
				$this->links_recheck( array_slice( $args, 1 ), $assoc_args );
				break;
			case 'purge':
				$this->links_purge( array_slice( $args, 1 ), $assoc_args );
				break;
			default:
				WP_CLI::error( "Unknown subcommand: {$sub}. Valid: list, set-ignored, recheck, purge" );
		}
	}

	/**
	 * List tracked links with status filter.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments (status, per_page, paged).
	 */
	private function links_list( array $args, array $assoc_args ): void {
		global $wpdb;
		$table = MM_Mod_Links::table_name();

		$status   = (string) ( $assoc_args['status'] ?? 'all' );
		$per_page = (int) ( $assoc_args['per_page'] ?? 20 );
		$paged    = (int) ( $assoc_args['paged'] ?? 1 );
		$offset   = ( max( 1, $paged ) - 1 ) * $per_page;

		$conditions = [];
		if ( 'broken' === $status ) {
			$conditions[] = 'is_broken = 1';
		} elseif ( 'ok' === $status ) {
			$conditions[] = 'is_broken = 0 AND is_ignored = 0';
		} elseif ( 'ignored' === $status ) {
			$conditions[] = 'is_ignored = 1';
		}
		$where = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT {$per_page} OFFSET {$offset}" );
		// phpcs:enable

		if ( empty( $rows ) ) {
			WP_CLI::line( 'No links found.' );
			return;
		}

		$items = [];
		foreach ( $rows as $row ) {
			$status_label = $row->is_ignored ? 'ignored' : ( $row->is_broken ? 'broken' : 'ok' );
			$items[] = [
				'ID'      => $row->id,
				'URL'     => $row->url,
				'Status'  => $status_label,
				'Code'    => $row->http_code ?: '',
				'Checked' => $row->last_checked ?? '',
			];
		}
		\WP_CLI\Utils\format_items( 'table', $items, [ 'ID', 'URL', 'Status', 'Code', 'Checked' ] );
		WP_CLI::line( sprintf( 'Showing %d of %d links.', count( $items ), $total ) );
	}

	/**
	 * Ignore or unignore a tracked link.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Link row ID.
	 *
	 * <state>
	 * : "ignore" or "unignore".
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata links set-ignored 5 ignore
	 *   wp metamanager metadata links set-ignored 5 unignore
	 *

	 * @when after_wp_load
	 */
		private function links_set_ignored( array $args, array $assoc_args ): void {
		global $wpdb;
		$table = MM_Mod_Links::table_name();

		$id    = (int) ( $args[0] ?? 0 );
		$state = (string) ( $args[1] ?? '' );
		if ( ! $id || ! in_array( $state, [ 'ignore', 'unignore' ], true ) ) {
			WP_CLI::error( 'Usage: wp metamanager metadata links set-ignored <id> <ignore|unignore>' );
		}

		$new_ignored = 'ignore' === $state ? 1 : 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update( $table, [ 'is_ignored' => $new_ignored ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
		if ( false === $updated ) {
			WP_CLI::error( "Failed to update link {$id}." );
		}
		WP_CLI::success( "Link {$id} is_ignored set to {$new_ignored}." );
	}

	/**
	 * Re-check one tracked link by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Link row ID.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata links recheck 5
	 *

	 * @when after_wp_load
	 */
		private function links_recheck( array $args, array $assoc_args ): void {
		global $wpdb;
		$table = MM_Mod_Links::table_name();

		$id = (int) ( $args[0] ?? 0 );
		if ( ! $id ) {
			WP_CLI::error( 'Usage: wp metamanager metadata links recheck <id>' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ) );
		if ( ! $row ) {
			WP_CLI::error( "Link {$id} not found." );
		}

		$response = wp_remote_head( $row->url, [ 'timeout' => 15, 'sslverify' => true ] );
		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, [ 'is_broken' => 1, 'last_checked' => current_time( 'mysql' ) ], [ 'id' => $id ], [ '%d', '%s' ], [ '%d' ] );
			WP_CLI::warning( "Link {$id} check failed: " . $response->get_error_message() );
			return;
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$is_broken = ( $code >= 200 && $code < 400 ) ? 0 : 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, [ 'http_code' => $code, 'is_broken' => $is_broken, 'last_checked' => current_time( 'mysql' ) ], [ 'id' => $id ], [ '%d', '%d', '%s' ], [ '%d' ] );
		$label = $is_broken ? 'broken' : 'ok';
		WP_CLI::success( "Link {$id}: HTTP {$code} → {$label}" );
	}

	/**
	 * Purge (truncate) the entire link table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata links purge --yes
	 *

	 * @when after_wp_load
	 */
		private function links_purge( array $args, array $assoc_args ): void {
		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes' ) ) {
			WP_CLI::error( 'Add --yes to confirm purging the link table.' );
		}
		global $wpdb;
		$table = MM_Mod_Links::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
		WP_CLI::success( 'Link table purged.' );
	}

	// -------------------------------------------------------------------------
	// receipts — upload receipt failure handling
	// -------------------------------------------------------------------------

	/**
	 * Manage failed upload-receipt notifications (list, retry, dismiss).
	 *
	 * ## OPTIONS
	 *
	 * <list|retry|dismiss>
	 * : Subcommand.
	 *
	 * [<key>]
	 * : For retry/dismiss: failed notification key.
	 *
	 * [--format=<format>]
	 * : For list: output format (table or json).
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata receipts list
	 *   wp metamanager metadata receipts retry 3
	 *   wp metamanager metadata receipts dismiss 3
	 *
	 * @when after_wp_load
	 */
	public function receipts( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? 'list';
		switch ( $sub ) {
			case 'list':
				$this->receipts_list( array_slice( $args, 1 ), $assoc_args );
				break;
			case 'retry':
				$this->receipts_retry( array_slice( $args, 1 ), $assoc_args );
				break;
			case 'dismiss':
				$this->receipts_dismiss( array_slice( $args, 1 ), $assoc_args );
				break;
			default:
				WP_CLI::error( "Unknown subcommand: {$sub}. Valid: list, retry, dismiss" );
		}
	}

	/**
	 * Retry a failed upload-receipt email by key.
	 *
	 * @param array $args       Positional arguments (key).
	 * @param array $assoc_args Named arguments (unused).
	 */
	private function receipts_retry( array $args, array $assoc_args ): void {
		$key = (string) ( $args[0] ?? '' );
		if ( ! $key ) {
			WP_CLI::error( 'Usage: wp metamanager metadata receipts retry <key>' );
		}

		$failures = MM_Upload_Notify::get_failed_notices();
		if ( ! isset( $failures[ $key ] ) ) {
			WP_CLI::error( "Failed notification '{$key}' not found." );
		}

		$entry = $failures[ $key ];
		$sent  = MM_Upload_Notify::send_mail(
			(string) ( $entry['to'] ?? '' ),
			(string) ( $entry['subject'] ?? '' ),
			(string) ( $entry['body'] ?? '' )
		);

		if ( $sent ) {
			unset( $failures[ $key ] );
			update_option( MM_Upload_Notify::FAILED_OPTION, $failures, false );
			WP_CLI::success( "Notification '{$key}' resent." );
		} else {
			$failures[ $key ]['retry_count'] = ( (int) ( $failures[ $key ]['retry_count'] ?? 0 ) ) + 1;
			update_option( MM_Upload_Notify::FAILED_OPTION, $failures, false );
			WP_CLI::error( "Email still could not be delivered. Check your site's email configuration." );
		}
	}

	/**
	 * Dismiss a failed upload-receipt notification.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Failed notification key.
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata receipts dismiss 3
	 *

	 * @when after_wp_load
	 */
		private function receipts_dismiss( array $args, array $assoc_args ): void {
		$key = (string) ( $args[0] ?? '' );
		if ( ! $key ) {
			WP_CLI::error( 'Usage: wp metamanager metadata receipts dismiss <key>' );
		}

		$failures = MM_Upload_Notify::get_failed_notices();
		if ( ! isset( $failures[ $key ] ) ) {
			WP_CLI::error( "Failed notification '{$key}' not found." );
		}

		unset( $failures[ $key ] );
		update_option( MM_Upload_Notify::FAILED_OPTION, $failures, false );
		WP_CLI::success( "Notification '{$key}' dismissed." );
	}

	/**
	 * List failed upload-receipt notifications.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table or json (default table).
	 *
	 * ## EXAMPLES
	 *
	 *   wp metamanager metadata receipts list
	 *

	 * @when after_wp_load
	 */
		private function receipts_list( array $args, array $assoc_args ): void {
		$failures = MM_Upload_Notify::get_failed_notices();
		if ( empty( $failures ) ) {
			WP_CLI::line( 'No failed notifications.' );
			return;
		}

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$rows   = [];
		foreach ( $failures as $key => $entry ) {
			$rows[] = [
				'Key'      => $key,
				'To'       => (string) ( $entry['to'] ?? '' ),
				'Subject'  => (string) ( $entry['subject'] ?? '' ),
				'Retries'  => (int) ( $entry['retry_count'] ?? 0 ),
			];
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'Key', 'To', 'Subject', 'Retries' ] );
		}
	}
}

\WP_CLI::add_command( 'metamanager metadata', 'MM_Metadata_CLI' );
