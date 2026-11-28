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

	public function can_manage_site() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'mm_cannot_manage_site',
			__( 'You are not allowed to manage site settings.', 'metamanager' )
		);
	}

	public function register_hooks(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
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
		$this->register_update_business_profile();
		$this->register_get_settings();
		$this->register_update_settings();
		$this->register_get_navigation();
		$this->register_get_term_meta();
		$this->register_update_term_meta();
		$this->register_get_jobs();
		$this->register_manage_jobs();
		$this->register_geocode_images();
		$this->register_report_abilities();
		// content/* and comments/* abilities moved to GCM Coworker (gcm repo).
		// General WordPress operations belong in the mu-plugin that's on
		// every site, not in an optional plugin. MetaManager owns only
		// metamanager/* and metamanager/get-daemon-status.
	}

	// -------------------------------------------------------------------------
	// MetaManager daemon status — reads metamanager-status.json only.
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
	 * @param string $which Only 'daemon' (MetaManager's own status file).
	 * @return string
	 */
	public static function report_path( string $which ): string {
		$job_root = defined( 'MM_JOB_ROOT' ) ? MM_JOB_ROOT : WP_CONTENT_DIR . '/metamanager-jobs';
		$paths    = array(
			'daemon' => $job_root . '/metamanager-status.json',
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
	 * @param string $which Only 'daemon' (MetaManager's own status file).
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

	/**
	 * Write on one post: edit rights on that post (the can_edit_meta
	 * pattern, generalized).
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error
	 */
	public function can_edit_post( array $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}
		return new WP_Error(
			'mxroute_cannot_edit_post',
			__( 'You are not allowed to edit this post.', 'metamanager' )
		);
	}

	/**
	 * Publishing: edit rights on the post plus the publish capability.
	 */
	public function can_publish_post( array $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id > 0 && current_user_can( 'edit_post', $post_id )
			&& current_user_can( 'publish_posts' ) ) {
			return true;
		}
		return new WP_Error(
			'mxroute_cannot_publish',
			__( 'You are not allowed to publish this post.', 'metamanager' )
		);
	}

	/**
	 * Destructive: delete rights on the post.
	 */
	public function can_trash_post( array $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id > 0 && current_user_can( 'delete_post', $post_id ) ) {
			return true;
		}
		return new WP_Error(
			'mxroute_cannot_trash',
			__( 'You are not allowed to trash this post.', 'metamanager' )
		);
	}

	public function can_upload() {
		if ( current_user_can( 'upload_files' ) ) {
			return true;
		}
		return new WP_Error(
			'mxroute_cannot_upload',
			__( 'You are not allowed to upload media.', 'metamanager' )
		);
	}

	public function can_moderate() {
		if ( current_user_can( 'moderate_comments' ) ) {
			return true;
		}
		return new WP_Error(
			'mxroute_cannot_moderate',
			__( 'You are not allowed to moderate comments.', 'metamanager' )
		);
	}

	private function register_report_abilities(): void {
		// MetaManager manages metadata (post meta, term meta, schema).
		// GCM server status lives in the GCM Coworker (gcm/*).
		// MXRoute mail status lives in mxroute/queue-status.
		// Only MetaManager's own daemon status is reported here.
		wp_register_ability( 'metamanager/get-daemon-status', array(
			'label'         => __( 'Get Daemon Status', 'metamanager' ),
			'description'   => 'Read the MetaManager daemon status: daemon running state and PIDs, queue depths, tool availability, WP-Cron history.',
			'category'      => 'site',
			'input_schema'  => array( 'type' => 'object', 'properties' => new stdClass() ),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ts'             => array( 'type' => 'string' ),
					'plugin_version' => array( 'type' => 'string' ),
					'daemon_version' => array( 'type' => [ 'string', 'null' ] ),
					'daemons'        => array( 'type' => 'object' ),
					'queues'         => array( 'type' => 'object' ),
					'tools'          => array( 'type' => 'object' ),
					'cron'           => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => array( $this, 'ability_get_daemon_status' ),
			'permission_callback' => array( $this, 'can_use' ),
			'meta'                => array( 'mcp.public' => true, 'tier' => 'read' ),
		) );
	}

	/**
	 * @return array|WP_Error
	 */
	public function ability_get_daemon_status( array $input = array() ) {
		return self::read_report( 'daemon' );
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
			'meta'        => [ 'mcp.public' => true, 'tier' => 'read' ],
		] );
	}

	/**
	 * @param array{post_id: int} $input
	 * @return array|WP_Error
	 */
	public function ability_get_post_meta( array $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'invalid_post', __( 'Post not found.', 'metamanager' ) );
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
					'noarchive'    => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
					'nosnippet'    => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
					'noimageindex' => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
					'og_title'     => [ 'type' => 'string' ],
					'og_description' => [ 'type' => 'string' ],
					'og_image_id'  => [ 'type' => 'integer', 'description' => 'OG image attachment ID.' ],
					'og_image_url' => [ 'type' => 'string', 'description' => 'OG image URL or attachment ID.' ],
					'schema_type'  => [ 'type' => 'string', 'description' => 'Schema.org type.' ],
					'schema_fields' => [ 'type' => 'object', 'description' => 'Schema field overrides.' ],
					'breadcrumb_label' => [ 'type' => 'string' ],
					'exclude_sitemap' => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
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
			return new WP_Error( 'invalid_post', __( 'Post not found.', 'metamanager' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'metamanager' ) );
		}

		$settings = MM_Site_Settings::get_instance();
		$existing = $settings->get_post_meta( $post_id );

		// Merge only provided fields.
		$updatable = [ 'title', 'description', 'canonical', 'noindex', 'nofollow',
			'noarchive', 'nosnippet', 'noimageindex', 'og_title', 'og_description',
			'og_image_id', 'og_image_url', 'schema_type', 'breadcrumb_label', 'schema_fields',
			'exclude_sitemap' ];

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
			'meta'        => [ 'mcp.public' => true, 'tier' => 'read' ],
		] );
	}

	/**
	 * @param array{url: string} $input
	 * @return array|WP_Error
	 */
	public function ability_get_schema( array $input ) {
		$url = esc_url_raw( $input['url'] ?? '' );
		if ( ! $url || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			return new WP_Error( 'invalid_url', __( 'A valid URL on this site is required.', 'metamanager' ) );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host !== $site_host ) {
			return new WP_Error( 'external_url', __( 'URL must belong to this site.', 'metamanager' ) );
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
			'meta'        => [ 'mcp.public' => true, 'tier' => 'read' ],
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
		$addr     = $local['address'] ?? [];

		$profile = [
			'type'        => $entity,
			'name'        => $local['name'] ?? get_bloginfo( 'name' ),
			'description' => $local['description'] ?? get_bloginfo( 'description' ),
			'telephone'   => $local['phone'] ?? '',
			'email'       => $local['email'] ?? '',
			'address'     => [
				'street'  => $addr['street'] ?? '',
				'city'    => $addr['city'] ?? '',
				'region'  => $addr['state'] ?? '',
				'postal'  => $addr['zip'] ?? '',
				'country' => $addr['country'] ?? '',
			],
			'geo' => [
				'latitude'  => $local['lat'] ?? '',
				'longitude' => $local['lng'] ?? '',
			],
			'location_type' => $local['location_type'] ?? '',
			'service_areas' => array_values( (array) ( $local['service_areas'] ?? [] ) ),
			'hours'       => array_values( (array) ( $local['hours'] ?? [] ) ),
			'price_range' => $local['price_range'] ?? '',
			'same_as'     => array_values( array_filter( array_map( 'esc_url_raw', array_merge(
				array_filter( (array) ( $social['accounts'] ?? [] ) ),
				array_filter( (array) ( $local['accounts'] ?? [] ) )
			) ) ) ),
		];

		// Remove empty values.
		return array_filter( $profile, function ( $v ) {
			return is_array( $v ) || $v !== '';
		} );
	}

	// -------------------------------------------------------------------------
	// metamanager/update-business-profile
	// -------------------------------------------------------------------------

	private function register_update_business_profile(): void {
		wp_register_ability( 'metamanager/update-business-profile', [
			'label'       => 'Update Business Profile',
			'description' => 'Update business profile fields: name, type, description, address, phone, email, location_type, service_areas, geo coordinates. Goes through save_business() for validation and geocoding.',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [
					'name'          => [ 'type' => 'string' ],
					'type'          => [ 'type' => 'string' ],
					'description'   => [ 'type' => 'string' ],
					'phone'         => [ 'type' => 'string' ],
					'email'         => [ 'type' => 'string' ],
					'address'       => [
						'type'       => 'object',
						'properties' => [
							'street'  => [ 'type' => 'string' ],
							'city'    => [ 'type' => 'string' ],
							'state'   => [ 'type' => 'string' ],
							'zip'     => [ 'type' => 'string' ],
							'country' => [ 'type' => 'string' ],
						],
					],
					'location_type' => [ 'type' => 'string', 'enum' => [ 'brick_and_mortar', 'service_area', 'none' ] ],
					'service_areas' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'lat'           => [ 'type' => 'string' ],
					'lng'           => [ 'type' => 'string' ],
					'show_phone'    => [ 'type' => 'boolean' ],
					'show_sms'      => [ 'type' => 'boolean' ],
					'show_email'    => [ 'type' => 'boolean' ],
					'show_vcard'    => [ 'type' => 'boolean' ],
					'show_json'     => [ 'type' => 'boolean' ],
					'show_csv'      => [ 'type' => 'boolean' ],
					'booking_link'  => [ 'type' => 'string' ],
					'booking_link_label' => [ 'type' => 'string' ],
					'booking_link_new_tab' => [ 'type' => 'boolean' ],
					'price_range'   => [ 'type' => 'string' ],
					'founding_date' => [ 'type' => 'string' ],
					'number_of_employees' => [ 'type' => 'string' ],
					'google_maps_api_key' => [ 'type' => 'string' ],
					'payment_accepted' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'accounts'      => [
						'type'       => 'object',
						'properties' => [
							'facebook'  => [ 'type' => 'string' ],
							'instagram' => [ 'type' => 'string' ],
							'linkedin'  => [ 'type' => 'string' ],
							'youtube'   => [ 'type' => 'string' ],
							'twitter'   => [ 'type' => 'string' ],
							'bluesky'   => [ 'type' => 'string' ],
							'pinterest' => [ 'type' => 'string' ],
						],
					],
					'contact_form_enabled' => [ 'type' => 'boolean' ],
					'contact_form_subject_prefix' => [ 'type' => 'string' ],
					'contact_form_submit_text' => [ 'type' => 'string' ],
					'contact_form_tos_page_id' => [ 'type' => 'integer' ],
					'contact_form_recaptcha_site' => [ 'type' => 'string' ],
					'contact_form_recaptcha_secret' => [ 'type' => 'string' ],
				],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success' => [ 'type' => 'boolean' ],
					'updated' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
			'permission_callback' => [ $this, 'can_manage_site' ],
			'execute_callback'    => [ $this, 'ability_update_business_profile' ],
		] );
	}

	public function ability_update_business_profile( array $input ) {
		$settings = MM_Site_Settings::get_instance();
		$biz      = $settings->all_business();
		$updated  = [];

		$field_map = [
			'name'          => 'name',
			'type'          => 'type',
			'description'   => 'description',
			'phone'         => 'phone',
			'email'         => 'email',
			'location_type' => 'location_type',
			'lat'           => 'lat',
			'lng'           => 'lng',
			'show_phone'    => 'show_phone',
			'show_sms'      => 'show_sms',
			'show_email'    => 'show_email',
			'show_vcard'    => 'show_vcard',
			'show_json'     => 'show_json',
			'show_csv'      => 'show_csv',
			'booking_link'  => 'booking_link',
			'booking_link_label' => 'booking_link_label',
			'booking_link_new_tab' => 'booking_link_new_tab',
			'price_range'   => 'price_range',
			'founding_date' => 'founding_date',
			'number_of_employees' => 'number_of_employees',
			'google_maps_api_key' => 'google_maps_api_key',
			'contact_form_enabled'          => 'contact_form_enabled',
			'contact_form_subject_prefix'   => 'contact_form_subject_prefix',
			'contact_form_submit_text'      => 'contact_form_submit_text',
			'contact_form_tos_page_id'      => 'contact_form_tos_page_id',
			'contact_form_recaptcha_site'   => 'contact_form_recaptcha_site',
			'contact_form_recaptcha_secret' => 'contact_form_recaptcha_secret',
		];
		foreach ( $field_map as $key => $path ) {
			if ( isset( $input[ $key ] ) ) {
				$biz[ $path ] = (string) $input[ $key ];
				$updated[]    = $key;
			}
		}

		if ( isset( $input['address'] ) && is_array( $input['address'] ) ) {
			foreach ( [ 'street', 'city', 'state', 'zip', 'country' ] as $k ) {
				if ( isset( $input['address'][ $k ] ) ) {
					$biz['address'][ $k ] = (string) $input['address'][ $k ];
					$updated[]            = "address.{$k}";
				}
			}
		}

		if ( isset( $input['service_areas'] ) && is_array( $input['service_areas'] ) ) {
			$biz['service_areas'] = array_values( array_filter( array_map( 'strval', $input['service_areas'] ) ) );
			$updated[]            = 'service_areas';
		}

		if ( isset( $input['payment_accepted'] ) && is_array( $input['payment_accepted'] ) ) {
			$biz['payment_accepted'] = array_values( array_filter( array_map( 'strval', $input['payment_accepted'] ) ) );
			$updated[]               = 'payment_accepted';
		}

		if ( isset( $input['accounts'] ) && is_array( $input['accounts'] ) ) {
			foreach ( [ 'facebook', 'instagram', 'linkedin', 'youtube', 'twitter', 'bluesky', 'pinterest' ] as $platform ) {
				if ( isset( $input['accounts'][ $platform ] ) ) {
					$biz['accounts'][ $platform ] = esc_url_raw( (string) $input['accounts'][ $platform ] );
					$updated[]                    = "accounts.{$platform}";
				}
			}
		}

		if ( empty( $updated ) ) {
			return [ 'success' => false, 'updated' => [] ];
		}

		$settings->save_business( $biz );
		return [ 'success' => true, 'updated' => $updated ];
	}

	// -------------------------------------------------------------------------
	// metamanager/get-settings
	// -------------------------------------------------------------------------

	private function register_get_settings(): void {
		wp_register_ability( 'metamanager/get-settings', [
			'label'       => 'Get Site Settings',
			'description' => 'Read MetaManager settings sections: titles, social, schema, sitemap, robots, authors, hygiene, feed, links. Provide a section name or omit for all.',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [
					'section' => [
						'type'        => 'string',
						'description' => 'Section to read: titles, social, schema, sitemap, robots, authors, hygiene, feed, links, discovery, media, templates, listing.',
					],
				],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'settings' => [ 'type' => 'object' ],
				],
			],
			'permission_callback' => [ $this, 'can_manage_site' ],
			'execute_callback'    => [ $this, 'ability_get_settings' ],
		] );
	}

	public function ability_get_settings( array $input ) {
		$settings = MM_Site_Settings::get_instance();
		$section  = $input['section'] ?? '';

		if ( '' !== $section ) {
			return [ 'settings' => [ $section => $settings->get( $section, [] ) ] ];
		}

		$sections = [ 'titles', 'social', 'schema', 'sitemap', 'robots', 'authors', 'hygiene', 'feed', 'links', 'discovery', 'media', 'templates', 'listing' ];
		$out = [];
		foreach ( $sections as $s ) {
			$out[ $s ] = $settings->get( $s, [] );
		}
		return [ 'settings' => $out ];
	}

	// -------------------------------------------------------------------------
	// metamanager/update-settings
	// -------------------------------------------------------------------------

	private function register_update_settings(): void {
		wp_register_ability( 'metamanager/update-settings', [
			'label'       => 'Update Site Settings',
			'description' => 'Update MetaManager settings sections: titles, social, schema, sitemap, robots, authors, hygiene, feed, links. Merges provided keys into existing settings.',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [
					'section' => [
						'type'        => 'string',
						'description' => 'Section to update: titles, social, schema, sitemap, robots, authors, hygiene, feed, links, discovery, media, templates, listing.',
					],
					'values' => [
						'type'        => 'object',
						'description' => 'Key-value pairs to set within the section. Merged into existing values.',
					],
				],
				'required' => [ 'section', 'values' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success' => [ 'type' => 'boolean' ],
					'updated' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
			'permission_callback' => [ $this, 'can_manage_site' ],
			'execute_callback'    => [ $this, 'ability_update_settings' ],
		] );
	}

	public function ability_update_settings( array $input ) {
		$section = $input['section'] ?? '';
		$values  = $input['values'] ?? [];

		$valid_sections = [ 'titles', 'social', 'schema', 'sitemap', 'robots', 'authors', 'hygiene', 'feed', 'links', 'discovery', 'media', 'templates', 'listing' ];
		if ( ! in_array( $section, $valid_sections, true ) ) {
			return new WP_Error( 'invalid_section', 'Invalid section. Valid: ' . implode( ', ', $valid_sections ) );
		}
		if ( ! is_array( $values ) || empty( $values ) ) {
			return new WP_Error( 'invalid_values', 'Values must be a non-empty object.' );
		}

		$settings = MM_Site_Settings::get_instance();
		$current  = $settings->get( $section, [] );
		if ( ! is_array( $current ) ) {
			$current = [];
		}
		$current = array_merge( $current, $values );

		// Merge into full settings and save.
		$raw  = get_option( MM_META_OPT_SETTINGS, [] );
		$full = is_array( $raw ) ? $raw : [];
		$full = MM_Site_Settings::deep_merge( MM_Site_Settings::settings_defaults(), $full );
		$full[ $section ] = $current;
		$settings->save_settings( $full );

		return [ 'success' => true, 'updated' => array_keys( $values ) ];
	}

	// -------------------------------------------------------------------------
	// metamanager/get-navigation
	// -------------------------------------------------------------------------

	private function register_get_navigation(): void {
		wp_register_ability( 'metamanager/get-navigation', [
			'label'       => 'Get Navigation Menu',
			'description' => 'Retrieve the primary navigation menu items from the theme\'s primary menu location. Returns the menu name and ordered items with URLs.',
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
			'meta'        => [ 'mcp.public' => true, 'tier' => 'read' ],
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
			'meta'        => [ 'mcp.public' => true, 'tier' => 'read' ],
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
			return new WP_Error( 'invalid_term', __( 'Term or taxonomy not found.', 'metamanager' ) );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'invalid_term', __( 'Term not found.', 'metamanager' ) );
		}

		$settings = MM_Site_Settings::get_instance();
		$meta     = $settings->get_term_meta( $term_id );

		return array_merge( [
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
		], $meta );
	}

	// -------------------------------------------------------------------------
	// metamanager/update-term-meta
	// -------------------------------------------------------------------------

	private function register_update_term_meta(): void {
		wp_register_ability( 'metamanager/update-term-meta', [
			'label'       => 'Update Term Metadata',
			'description' => 'Write SEO metadata for a taxonomy term (category, tag, etc.). Only provided fields are updated.',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [
					'term_id'     => [ 'type' => 'integer' ],
					'taxonomy'    => [ 'type' => 'string' ],
					'og_title'    => [ 'type' => 'string' ],
					'og_description' => [ 'type' => 'string' ],
					'og_image_id' => [ 'type' => 'integer' ],
					'noindex'     => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
					'nofollow'    => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
					'breadcrumb_label' => [ 'type' => 'string' ],
					'exclude_sitemap' => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
				],
				'required' => [ 'term_id', 'taxonomy' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success' => [ 'type' => 'boolean' ],
					'term_id' => [ 'type' => 'integer' ],
				],
			],
			'permission_callback' => [ $this, 'can_manage_site' ],
			'execute_callback'    => [ $this, 'ability_update_term_meta' ],
		] );
	}

	public function ability_update_term_meta( array $input ) {
		$term_id  = absint( $input['term_id'] ?? 0 );
		$taxonomy = sanitize_key( $input['taxonomy'] ?? '' );
		if ( ! $term_id || ! get_term( $term_id, $taxonomy ) ) {
			return new WP_Error( 'invalid_term', 'Term not found.' );
		}

		$settings = MM_Site_Settings::get_instance();
		$existing = $settings->get_term_meta( $term_id );
		$updatable = [ 'og_title', 'og_description', 'og_image_id', 'noindex', 'nofollow', 'breadcrumb_label', 'exclude_sitemap' ];

		$updated = [];
		foreach ( $updatable as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$existing[ $field ] = $input[ $field ];
				$updated[]          = $field;
			}
		}
		if ( empty( $updated ) ) {
			return [ 'success' => false, 'term_id' => $term_id ];
		}
		$settings->save_term_meta( $term_id, $existing );
		return [ 'success' => true, 'term_id' => $term_id ];
	}

	// -------------------------------------------------------------------------
	// metamanager/get-jobs
	// -------------------------------------------------------------------------

	private function register_get_jobs(): void {
		wp_register_ability( 'metamanager/get-jobs', [
			'label'       => 'Get Processing Jobs',
			'description' => 'List media processing jobs (compression, metadata, import) with search and status filters.',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [
					'search'   => [ 'type' => 'string', 'description' => 'Search by image name or job type.' ],
					'status'   => [ 'type' => 'string', 'enum' => [ 'pending', 'completed', 'failed', 'not_pending' ] ],
					'per_page' => [ 'type' => 'integer', 'default' => 20 ],
					'paged'    => [ 'type' => 'integer', 'default' => 1 ],
				],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'jobs'  => [ 'type' => 'array' ],
					'total' => [ 'type' => 'integer' ],
				],
			],
			'permission_callback' => [ $this, 'can_manage_site' ],
			'execute_callback'    => [ $this, 'ability_get_jobs' ],
		] );
	}

	public function ability_get_jobs( array $input ) {
		$result = MM_DB::get_jobs( [
			'search'   => (string) ( $input['search'] ?? '' ),
			'status'   => (string) ( $input['status'] ?? '' ),
			'per_page' => (int) ( $input['per_page'] ?? 20 ),
			'paged'    => (int) ( $input['paged'] ?? 1 ),
		] );
		return [
			'jobs'  => array_map( fn( $j ) => (array) $j, $result['jobs'] ),
			'total' => $result['total'],
		];
	}

	// -------------------------------------------------------------------------
	// metamanager/manage-jobs
	// -------------------------------------------------------------------------

	private function register_manage_jobs(): void {
		wp_register_ability( 'metamanager/manage-jobs', [
			'label'       => 'Manage Processing Jobs',
			'description' => 'Requeue failed jobs, trigger library scan, queue compression or metadata embed for attachments.',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [
					'action'      => [
						'type'        => 'string',
						'enum'        => [ 'requeue', 'scan', 'compress', 'embed', 'set-licensing' ],
						'description' => 'Action to perform.',
					],
					'job_id'      => [ 'type' => 'integer', 'description' => 'For requeue: the job ID.' ],
					'attachment_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'For compress/embed/set-licensing: attachment IDs.' ],
					'license'     => [ 'type' => 'string', 'description' => 'For set-licensing: license URL.' ],
					'credit'      => [ 'type' => 'string', 'description' => 'For set-licensing: credit text.' ],
					'acquire'     => [ 'type' => 'string', 'description' => 'For set-licensing: acquire license URL.' ],
					'creator'     => [ 'type' => 'string', 'description' => 'For set-licensing: creator / photographer name.' ],
					'copyright'   => [ 'type' => 'string', 'description' => 'For set-licensing: copyright notice.' ],
					'subtype'     => [ 'type' => 'string', 'enum' => [ 'provenance', 'copyright', 'geocode', 'edit', 'import' ], 'description' => 'For embed: job subtype label. Default: auto-detected.' ],
				],
				'required' => [ 'action' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success' => [ 'type' => 'boolean' ],
					'message' => [ 'type' => 'string' ],
					'count'   => [ 'type' => 'integer' ],
				],
			],
			'permission_callback' => [ $this, 'can_manage_site' ],
			'execute_callback'    => [ $this, 'ability_manage_jobs' ],
		] );
	}

	public function ability_manage_jobs( array $input ) {
		$action = $input['action'] ?? '';

		switch ( $action ) {
			case 'requeue':
				$job_id = absint( $input['job_id'] ?? 0 );
				if ( ! $job_id ) {
					return new WP_Error( 'missing_job_id', 'job_id is required for requeue.' );
				}
				$job = MM_DB::get_job( $job_id );
				if ( ! $job ) {
					return new WP_Error( 'not_found', "Job {$job_id} not found." );
				}
				if ( 'failed' !== $job->status ) {
					return new WP_Error( 'not_failed', "Job {$job_id} is not failed (status: {$job->status})." );
				}
				MM_Job_Queue::requeue( $job_id );
				return [ 'success' => true, 'message' => "Job {$job_id} requeued.", 'count' => 1 ];

			case 'scan':
				$ids = get_posts( [
					'post_type'      => 'attachment',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => [
						'relation' => 'OR',
						[ 'key' => '_mm_imported', 'compare' => 'NOT EXISTS' ],
						[ 'key' => '_mm_imported', 'value' => '1', 'compare' => '!=' ],
					],
				] );
				$result = MM_Media_Service::scan( $ids, 'ability_scan' );
				return [ 'success' => true, 'message' => "Library scan queued for {$result['count']} attachments.", 'count' => $result['count'] ];

			case 'compress':
				$ids = array_filter( array_map( 'absint', (array) ( $input['attachment_ids'] ?? [] ) ) );
				if ( empty( $ids ) ) {
					return new WP_Error( 'missing_ids', 'attachment_ids is required for compress.' );
				}
				$result = MM_Media_Service::compress( $ids, 'ability_compress' );
				return [ 'success' => true, 'message' => "Compression queued for {$result['count']} attachments.", 'count' => $result['count'] ];

			case 'embed':
				$ids = array_filter( array_map( 'absint', (array) ( $input['attachment_ids'] ?? [] ) ) );
				if ( empty( $ids ) ) {
					return new WP_Error( 'missing_ids', 'attachment_ids is required for embed.' );
				}
				$subtype = sanitize_key( $input['subtype'] ?? '' );
				$result = MM_Media_Service::embed_metadata( $ids, 'ability_embed', [], $subtype );
				return [ 'success' => true, 'message' => "Metadata embed queued for {$result['count']} attachments.", 'count' => $result['count'] ];

			case 'set-licensing':
				$ids = array_filter( array_map( 'absint', (array) ( $input['attachment_ids'] ?? [] ) ) );
				if ( empty( $ids ) ) {
					return new WP_Error( 'missing_ids', 'attachment_ids is required for set-licensing.' );
				}
				$result = MM_Media_Service::set_licensing( $ids, [
					'creator'   => (string) ( $input['creator'] ?? '' ),
					'credit'    => (string) ( $input['credit'] ?? '' ),
					'copyright' => (string) ( $input['copyright'] ?? '' ),
					'license'   => (string) ( $input['license'] ?? '' ),
					'acquire'   => (string) ( $input['acquire'] ?? '' ),
				], 'ability_licensing' );
				if ( ! empty( $result['errors'] ) ) {
					return new WP_Error( 'licensing_error', implode( ' ', $result['errors'] ) );
				}
				return [ 'success' => true, 'message' => "Attribution applied to {$result['count']} images. Metadata sync jobs queued.", 'count' => $result['count'] ];

			default:
				return new WP_Error( 'invalid_action', 'Valid actions: requeue, scan, compress, embed, set-licensing' );
		}
	}

	// -------------------------------------------------------------------------
	// metamanager/geocode-images
	// -------------------------------------------------------------------------

	private function register_geocode_images(): void {
		wp_register_ability( 'metamanager/geocode-images', [
			'label'       => 'Geocode Image Addresses',
			'description' => 'Reverse-geocode GPS coordinates to address fields (city, state, country) on images. Only populates empty fields — never overwrites existing values. Requires Google Maps API key in business profile.',
			'category'    => 'site',
			'input_schema'  => [
				'type'       => 'object',
				'properties' => [
					'attachment_ids' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'integer' ],
						'description' => 'Attachment IDs to geocode. Omit for all images.',
					],
				],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success' => [ 'type' => 'boolean' ],
					'message' => [ 'type' => 'string' ],
					'count'   => [ 'type' => 'integer' ],
				],
			],
			'permission_callback' => [ $this, 'can_manage_site' ],
			'execute_callback'    => [ $this, 'ability_geocode_images' ],
		] );
	}

	public function ability_geocode_images( array $input ) {
		$ids = $input['attachment_ids'] ?? [];
		if ( empty( $ids ) ) {
			$ids = get_posts( [
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] );
		}

		$result = MM_Media_Service::geocode( array_map( 'absint', (array) $ids ), 'ability_geocode' );
		if ( ! empty( $result['errors'] ) ) {
			return new WP_Error( 'geocode_error', implode( ' ', $result['errors'] ) );
		}
		return [ 'success' => true, 'message' => "Geocoded {$result['count']} images ({$result['skipped']} skipped).", 'count' => $result['count'] ];
	}

	// -------------------------------------------------------------------------
	// content/* and comments/* — AI phase A3. Native codex wrappers: the
	// AI gets exactly the powers wp_insert_post / wp_update_post /
	// wp_trash_post / media_handle_sideload / wp_set_post_terms /
	// wp_update_comment give a developer — nothing improvised. Reads are
	// free; writes are gated per target; trash/restore are marked
	// destructive for the chat's typed-confirmation tier.
	// -------------------------------------------------------------------------


}
