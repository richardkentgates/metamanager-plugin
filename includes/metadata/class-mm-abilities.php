<?php
/**
 * MM_Abilities — registers Metamanager capabilities with the WordPress
 * Abilities API so they are discoverable by the MCP Adapter and AI agents.
 *
 * Requires WordPress 6.9+ (Abilities API). Gracefully degrades if unavailable.
 */

defined( 'ABSPATH' ) || exit;

class MM_Abilities {

	/**
	 * Default permission: SEO/meta tools are for editorial users.
	 *
	 * @return bool|WP_Error
	 */
	public function can_use() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return new WP_Error(
			'mm_cannot_use_ability',
			__( 'You are not allowed to use this ability.', 'metamanager' )
		);
	}

	/**
	 * Permission for the post-meta write: edit rights on the target post.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error
	 */
	public function can_edit_meta( array $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}
		return new WP_Error(
			'mm_cannot_edit_meta',
			__( 'You are not allowed to edit metadata for this post.', 'metamanager' )
		);
	}

	public function register_hooks(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // Abilities API not available (pre-6.9).
		}

		// Two-phase API: categories register on their own action, and an
		// ability whose category is not yet registered is silently
		// rejected ("register the category first").
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register the "site" category before any ability claims it.
	 */
	public function register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		$registry = WP_Ability_Categories_Registry::get_instance();
		if ( $registry && method_exists( $registry, 'is_registered' ) && $registry->is_registered( 'site' ) ) {
			return;
		}
		wp_register_ability_category( 'site', array(
			'label'       => __( 'Site', 'metamanager' ),
			'description' => 'Site content, SEO, and server status reports.',
		) );
	}

	public function register_abilities(): void {
		$this->register_get_post_meta();
		$this->register_update_post_meta();
		$this->register_get_schema();
		$this->register_get_business_profile();
		$this->register_get_navigation();
		$this->register_get_term_meta();
		$this->register_report_abilities();
	}

	// -------------------------------------------------------------------------
	// reports/* — read-only status reports (AI phase A1).
	//
	// Registered here because MetaManager is the plugin every site carries
	// and hosts the chat surface. Each report reads exactly the file its
	// WordPress dashboard widget reads (source of truth: the AGENTS.md
	// status paths) — no parallel data paths. Read-only: no approval
	// cards, no writes, nothing that can call gcm or shell. Missing or
	// corrupt data is an explicit error — absence of evidence is never
	// reported as a state.
	// -------------------------------------------------------------------------

	/**
	 * Report file locations, overridable for tests (mirrors the
	 * GCM_STATUS_JSON seam the GCM dashboard uses).
	 *
	 * @param string $which gcm_status|security_scan|daemon|mail.
	 * @return string
	 */
	public static function report_path( string $which ): string {
		$job_root = defined( 'MM_JOB_ROOT' ) ? MM_JOB_ROOT : WP_CONTENT_DIR . '/metamanager-jobs';
		$paths    = array(
			'gcm_status'    => '/etc/gcm/status.json',
			'security_scan' => '/etc/gcm/security_scan.json',
			'daemon'        => $job_root . '/metamanager-status.json',
			'mail'          => WP_CONTENT_DIR . '/mxroute-status.json',
		);
		if ( ! isset( $paths[ $which ] ) ) {
			return '';
		}
		return (string) apply_filters( 'mm_report_path', $paths[ $which ], $which );
	}

	/**
	 * Read and decode a report. No fallbacks: an unreadable or invalid
	 * report is an error, never empty data.
	 *
	 * @param string $which gcm_status|security_scan|daemon|mail.
	 * @return array|WP_Error
	 */
	public static function read_report( string $which ) {
		$path = self::report_path( $which );
		if ( '' === $path || ! is_readable( $path ) ) {
			return new WP_Error(
				'report_unavailable',
				sprintf( 'The %s report is not readable (%s).', $where = $which, $path )
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw  = file_get_contents( $path );
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'report_unreadable',
				sprintf( 'The %s report at %s is not valid JSON.', $which, $path )
			);
		}
		return $data;
	}

	private function register_report_abilities(): void {
		$read_only_input = array(
			'type'       => 'object',
			'properties' => new stdClass(),
		);

		wp_register_ability( 'reports/get-status', array(
			'label'         => __( 'Get Server Status', 'metamanager' ),
			'description'   => 'Read the GCM server status report: services, versions, busy gates, backup state, security posture, source availability — the same report the WordPress dashboard reads.',
			'category'      => 'site',
			'input_schema'  => $read_only_input,
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'gcm_version'       => array( 'type' => 'string' ),
					'gcm_branch'        => array( 'type' => [ 'string', 'null' ] ),
					'wp_version'        => array( 'type' => [ 'string', 'null' ] ),
					'ts'                => array( 'type' => 'integer' ),
					'busy'              => array( 'type' => 'object', 'description' => 'Gate busy flags: backup, maint, update, scan, restore.' ),
					'backup'            => array( 'type' => 'object', 'description' => 'date, size, archive_valid, sql_ok, meta.' ),
					'os_update'         => array( 'type' => [ 'object', 'null' ] ),
					'wordpress_updates' => array( 'type' => 'object' ),
					'ssl'               => array( 'type' => 'object' ),
					'site_integrity'    => array( 'type' => 'object' ),
					'sources'           => array( 'type' => 'object', 'description' => 'apt + WordPress feed availability.' ),
					'domain'            => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => array( $this, 'ability_get_status' ),
			'permission_callback' => array( $this, 'can_use' ),
			'meta'                => array( 'mcp.public' => true ),
		) );

		wp_register_ability( 'reports/get-os-update', array(
			'label'         => __( 'Get OS Update Report', 'metamanager' ),
			'description'   => 'Read the OS update block of the server status report (last run, result, packages upgraded, reboot required).',
			'category'      => 'site',
			'input_schema'  => $read_only_input,
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ts'                => array( 'type' => [ 'integer', 'null' ] ),
					'result'            => array( 'type' => [ 'string', 'null' ] ),
					'packages_upgraded' => array( 'type' => [ 'integer', 'null' ] ),
					'reboot_required'   => array( 'type' => [ 'boolean', 'null' ] ),
				),
			),
			'execute_callback'    => array( $this, 'ability_get_os_update' ),
			'permission_callback' => array( $this, 'can_use' ),
			'meta'                => array( 'mcp.public' => true ),
		) );

		wp_register_ability( 'reports/get-security-scan', array(
			'label'         => __( 'Get Security Scan Report', 'metamanager' ),
			'description'   => 'Read the last webroot security scan report (timestamp, clean/ok, findings).',
			'category'      => 'site',
			'input_schema'  => $read_only_input,
			'output_schema' => array( 'type' => 'object' ),
			'execute_callback'    => array( $this, 'ability_get_security_scan' ),
			'permission_callback' => array( $this, 'can_use' ),
			'meta'                => array( 'mcp.public' => true ),
		) );

		wp_register_ability( 'reports/get-daemon-status', array(
			'label'         => __( 'Get Daemon Status', 'metamanager' ),
			'description'   => 'Read the MetaManager daemon status report: daemon running state and PIDs, queue depths, tool availability, WP-Cron history.',
			'category'      => 'site',
			'input_schema'  => $read_only_input,
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ts'             => array( 'type' => 'string' ),
					'plugin_version' => array( 'type' => 'string' ),
					'daemon_version' => array( 'type' => [ 'string', 'null' ] ),
					'daemons'        => array( 'type' => 'object', 'description' => 'compress/meta: running + pid.' ),
					'queues'         => array( 'type' => 'object', 'description' => 'compress, meta, completed, failed.' ),
					'tools'          => array( 'type' => 'object' ),
					'cron'           => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => array( $this, 'ability_get_daemon_status' ),
			'permission_callback' => array( $this, 'can_use' ),
			'meta'                => array( 'mcp.public' => true ),
		) );

		wp_register_ability( 'reports/get-mail-status', array(
			'label'         => __( 'Get Mail Status', 'metamanager' ),
			'description'   => 'Read the MXRoute Mailer status report: queue counts (pending/sent/failed), cron health, and WP-Cron history.',
			'category'      => 'site',
			'input_schema'  => $read_only_input,
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'pending'    => array( 'type' => 'integer' ),
					'sent'       => array( 'type' => 'integer' ),
					'failed'     => array( 'type' => 'integer' ),
					'cron'       => array( 'type' => 'object', 'description' => 'last_run, next_scheduled, interval.' ),
					'history'    => array( 'type' => 'object' ),
					'version'    => array( 'type' => 'string' ),
					'updated_at' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( $this, 'ability_get_mail_status' ),
			'permission_callback' => array( $this, 'can_use' ),
			'meta'                => array( 'mcp.public' => true ),
		) );
	}

	/**
	 * @return array|WP_Error
	 */
	public function ability_get_status( array $input = array() ) {
		return self::read_report( 'gcm_status' );
	}

	/**
	 * @return array|WP_Error
	 */
	public function ability_get_os_update( array $input = array() ) {
		$data = self::read_report( 'gcm_status' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! array_key_exists( 'os_update', $data ) ) {
			return new WP_Error(
				'report_missing_field',
				'The status report has no os_update block.'
			);
		}
		return array( 'os_update' => $data['os_update'] );
	}

	/**
	 * @return array|WP_Error
	 */
	public function ability_get_security_scan( array $input = array() ) {
		return self::read_report( 'security_scan' );
	}

	/**
	 * @return array|WP_Error
	 */
	public function ability_get_daemon_status( array $input = array() ) {
		return self::read_report( 'daemon' );
	}

	/**
	 * @return array|WP_Error
	 */
	public function ability_get_mail_status( array $input = array() ) {
		return self::read_report( 'mail' );
	}

	// -------------------------------------------------------------------------
	// metamanager/get-post-meta
	// -------------------------------------------------------------------------

	private function register_get_post_meta(): void {
		wp_register_ability( 'metamanager/get-post-meta', [
			'label'       => 'Get Post Metadata',
			'description' => 'Read SEO metadata (title, description, OG tags, schema type) for a WordPress post or page.',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'The post ID to read metadata for.',
					],
				],
				'required' => [ 'post_id' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'post_id'    => [ 'type' => 'integer' ],
					'title'      => [ 'type' => 'string', 'description' => 'SEO title override.' ],
					'description' => [ 'type' => 'string', 'description' => 'Meta description override.' ],
					'canonical'  => [ 'type' => 'string', 'description' => 'Canonical URL override.' ],
					'noindex'    => [ 'type' => [ 'string', 'null' ], 'description' => 'robots noindex: "yes", "no", or null (default).' ],
					'nofollow'   => [ 'type' => [ 'string', 'null' ] ],
					'og_title'   => [ 'type' => 'string', 'description' => 'Open Graph title override.' ],
					'og_description' => [ 'type' => 'string' ],
					'og_image_url'   => [ 'type' => 'string', 'description' => 'Open Graph image URL.' ],
					'schema_type'    => [ 'type' => 'string', 'description' => 'Schema.org type (e.g. BlogPosting, Product).' ],
					'breadcrumb_label' => [ 'type' => 'string' ],
					'schema_fields' => [ 'type' => 'object', 'description' => 'Per-type schema field overrides.' ],
				],
			],
			'execute_callback'    => [ $this, 'ability_get_post_meta' ],
			'permission_callback' => [ $this, 'can_use' ],
			'meta'        => [ 'mcp.public' => true ],
		] );
	}

	/**
	 * @param array{post_id: int} $input
	 * @return array|WP_Error
	 */
	public function ability_get_post_meta( array $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'invalid_post', 'Post not found.' );
		}

		$settings = MM_Site_Settings::get_instance();
		$meta     = $settings->get_post_meta( $post_id );

		return array_merge( [ 'post_id' => $post_id ], $meta );
	}

	// -------------------------------------------------------------------------
	// metamanager/update-post-meta
	// -------------------------------------------------------------------------

	private function register_update_post_meta(): void {
		wp_register_ability( 'metamanager/update-post-meta', [
			'label'       => 'Update Post Metadata',
			'description' => 'Write SEO metadata fields for a WordPress post or page. Only provided fields are updated; omitted fields remain unchanged.',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [
					'post_id'      => [ 'type' => 'integer', 'description' => 'The post ID to update.' ],
					'title'        => [ 'type' => 'string', 'description' => 'SEO title override.' ],
					'description'  => [ 'type' => 'string', 'description' => 'Meta description override.' ],
					'canonical'    => [ 'type' => 'string', 'description' => 'Canonical URL.' ],
					'noindex'      => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
					'nofollow'     => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
					'og_title'     => [ 'type' => 'string' ],
					'og_description' => [ 'type' => 'string' ],
					'og_image_url' => [ 'type' => 'string', 'description' => 'OG image URL or attachment ID.' ],
					'schema_type'  => [ 'type' => 'string', 'description' => 'Schema.org type.' ],
					'breadcrumb_label' => [ 'type' => 'string' ],
				],
				'required' => [ 'post_id' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success' => [ 'type' => 'boolean' ],
					'post_id' => [ 'type' => 'integer' ],
				],
			],
			'execute_callback'    => [ $this, 'ability_update_post_meta' ],
			'permission_callback' => [ $this, 'can_edit_meta' ],
			'meta'        => [ 'mcp.public' => true ],
		] );
	}

	/**
	 * @param array{post_id: int, ...} $input
	 * @return array|WP_Error
	 */
	public function ability_update_post_meta( array $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'invalid_post', 'Post not found.' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to edit this post.' );
		}

		$settings = MM_Site_Settings::get_instance();
		$existing = $settings->get_post_meta( $post_id );

		// Merge only provided fields.
		$updatable = [ 'title', 'description', 'canonical', 'noindex', 'nofollow',
			'noarchive', 'nosnippet', 'noimageindex', 'og_title', 'og_description',
			'og_image_id', 'og_image_url', 'schema_type', 'breadcrumb_label', 'schema_fields' ];

		foreach ( $updatable as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$existing[ $field ] = $input[ $field ];
			}
		}

		$settings->save_post_meta( $post_id, $existing );

		return [ 'success' => true, 'post_id' => $post_id ];
	}

	// -------------------------------------------------------------------------
	// metamanager/get-schema
	// -------------------------------------------------------------------------

	private function register_get_schema(): void {
		wp_register_ability( 'metamanager/get-schema', [
			'label'       => 'Get Schema Output',
			'description' => 'Fetch the complete JSON-LD schema output for a given URL. Returns the full @graph.',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [
					'url' => [
						'type'        => 'string',
						'description' => 'The URL to fetch schema for. Must be on this site.',
					],
				],
				'required' => [ 'url' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'url'    => [ 'type' => 'string' ],
					'schema' => [ 'type' => 'object', 'description' => 'The JSON-LD output with @context and @graph.' ],
				],
			],
			'execute_callback'    => [ $this, 'ability_get_schema' ],
			'permission_callback' => [ $this, 'can_use' ],
			'meta'        => [ 'mcp.public' => true ],
		] );
	}

	/**
	 * @param array{url: string} $input
	 * @return array|WP_Error
	 */
	public function ability_get_schema( array $input ) {
		$url = esc_url_raw( $input['url'] ?? '' );
		if ( ! $url || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			return new WP_Error( 'invalid_url', 'A valid URL on this site is required.' );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host !== $site_host ) {
			return new WP_Error( 'external_url', 'URL must belong to this site.' );
		}

		// Temporarily switch context to the requested URL.
		$context = new MM_Page_Context();
		$settings = MM_Site_Settings::get_instance();
		$emitter  = new MM_Head_Emitter( $context, $settings );

		// Parse the URL path to set up the context.
		$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
		$_SERVER['REQUEST_URI'] = $path;

		// Force a fresh context parse.
		$context = new MM_Page_Context();
		$data    = [ 'title' => '', 'meta' => [], 'links' => [], 'schema' => [] ];

		// Populate all modules.
		( new MM_Mod_Head_Meta( $settings ) )->populate( $data, $context, $settings );
		( new MM_Mod_Social( $settings ) )->populate( $data, $context, $settings );
		( new MM_Mod_Schema( $settings ) )->populate( $data, $context, $settings );
		( new MM_Mod_Local( $settings ) )->populate( $data, $context, $settings );
		( new MM_Mod_Author( $settings ) )->populate( $data, $context, $settings );

		return [
			'url'    => $url,
			'schema' => [
				'@context' => 'https://schema.org',
				'@graph'   => $data['schema'],
			],
		];
	}

	// -------------------------------------------------------------------------
	// metamanager/get-business-profile
	// -------------------------------------------------------------------------

	private function register_get_business_profile(): void {
		wp_register_ability( 'metamanager/get-business-profile', [
			'label'       => 'Get Business Profile',
			'description' => 'Retrieve the site-wide LocalBusiness or Organization entity including name, address, phone, hours, and social profiles.',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'type'         => [ 'type' => 'string', 'description' => 'Organization or LocalBusiness.' ],
					'name'         => [ 'type' => 'string' ],
					'description'  => [ 'type' => 'string' ],
					'telephone'    => [ 'type' => 'string' ],
					'email'        => [ 'type' => 'string' ],
					'address'      => [ 'type' => 'object' ],
					'geo'          => [ 'type' => 'object' ],
					'hours'        => [ 'type' => 'array' ],
					'price_range'  => [ 'type' => 'string' ],
					'same_as'      => [ 'type' => 'array', 'description' => 'Social profile URLs.' ],
				],
			],
			'execute_callback'    => [ $this, 'ability_get_business_profile' ],
			'permission_callback' => [ $this, 'can_use' ],
			'meta'        => [ 'mcp.public' => true ],
		] );
	}

	/**
	 * @param array{} $input
	 * @return array|WP_Error
	 */
	public function ability_get_business_profile( array $input ) {
		$settings = MM_Site_Settings::get_instance();
		$entity   = $settings->get( 'schema.knowledge_entity', 'LocalBusiness' );
		$local    = $settings->all_business();
		$social   = $settings->get( 'social', [] );

		$profile = [
			'type'        => $entity,
			'name'        => $local['name'] ?? get_bloginfo( 'name' ),
			'description' => $local['description'] ?? get_bloginfo( 'description' ),
			'telephone'   => $local['phone'] ?? '',
			'email'       => $local['email'] ?? '',
			'address'     => [
				'street'  => $local['street'] ?? '',
				'city'    => $local['city'] ?? '',
				'region'  => $local['region'] ?? '',
				'postal'  => $local['postal'] ?? '',
				'country' => $local['country'] ?? '',
			],
			'geo' => [
				'latitude'  => $local['latitude'] ?? '',
				'longitude' => $local['longitude'] ?? '',
			],
			'hours'       => $local['hours'] ?? [],
			'price_range' => $local['price_range'] ?? '',
			'same_as'     => $social['accounts'] ?? [],
		];

		// Remove empty values.
		return array_filter( $profile, function ( $v ) {
			return is_array( $v ) || $v !== '';
		} );
	}

	// -------------------------------------------------------------------------
	// metamanager/get-navigation
	// -------------------------------------------------------------------------

	private function register_get_navigation(): void {
		wp_register_ability( 'metamanager/get-navigation', [
			'label'       => 'Get Navigation Menu',
			'description' => 'Retrieve the primary navigation menu items designated for schema. Returns the menu name and ordered items with URLs.',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'menu_name' => [ 'type' => 'string' ],
					'items'     => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'name'     => [ 'type' => 'string' ],
								'url'      => [ 'type' => 'string' ],
								'position' => [ 'type' => 'integer' ],
							],
						],
					],
				],
			],
			'execute_callback'    => [ $this, 'ability_get_navigation' ],
			'permission_callback' => [ $this, 'can_use' ],
			'meta'        => [ 'mcp.public' => true ],
		] );
	}

	/**
	 * @param array{} $input
	 * @return array|WP_Error
	 */
	public function ability_get_navigation( array $input ) {
		$menu = MM_Mod_Base::get_primary_menu();
		if ( ! $menu ) {
			return [ 'menu_name' => '', 'items' => [] ];
		}

		return [ 'menu_name' => $menu['name'], 'items' => $menu['items'] ];
	}

	// -------------------------------------------------------------------------
	// metamanager/get-term-meta
	// -------------------------------------------------------------------------

	private function register_get_term_meta(): void {
		wp_register_ability( 'metamanager/get-term-meta', [
			'label'       => 'Get Term Metadata',
			'description' => 'Read SEO metadata (title, description, OG tags) for a taxonomy term (category, tag, etc.).',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [
					'term_id'  => [ 'type' => 'integer', 'description' => 'The term ID.' ],
					'taxonomy' => [ 'type' => 'string', 'description' => 'The taxonomy slug (e.g. "category", "post_tag").' ],
				],
				'required' => [ 'term_id', 'taxonomy' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'term_id'     => [ 'type' => 'integer' ],
					'taxonomy'    => [ 'type' => 'string' ],
					'title'       => [ 'type' => 'string' ],
					'description' => [ 'type' => 'string' ],
					'og_title'    => [ 'type' => 'string' ],
					'og_description' => [ 'type' => 'string' ],
					'og_image_url' => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => [ $this, 'ability_get_term_meta' ],
			'permission_callback' => [ $this, 'can_use' ],
			'meta'        => [ 'mcp.public' => true ],
		] );
	}

	/**
	 * @param array{term_id: int, taxonomy: string} $input
	 * @return array|WP_Error
	 */
	public function ability_get_term_meta( array $input ) {
		$term_id  = absint( $input['term_id'] ?? 0 );
		$taxonomy = sanitize_text_field( $input['taxonomy'] ?? '' );

		if ( ! $term_id || ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'invalid_term', 'Term or taxonomy not found.' );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'invalid_term', 'Term not found.' );
		}

		$settings = MM_Site_Settings::get_instance();
		$meta     = $settings->get_term_meta( $term_id );

		return array_merge( [
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
		], $meta );
	}
}
