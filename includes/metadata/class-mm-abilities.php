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
			return;
		}

		// Two-phase API: categories register on their own action, and an
		// ability whose category is not yet registered is silently
		// rejected ("register the category first").
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
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
		wp_register_ability_category(
			'site',
			array(
				'label'       => __( 'Site', 'metamanager' ),
				'description' => 'Site content, SEO, and server status reports.',
			)
		);
	}

	public function register_abilities(): void {
		$this->register_get_post_meta();
		$this->register_update_post_meta();
		$this->register_get_schema();
		$this->register_get_business_profile();
		$this->register_get_navigation();
		$this->register_get_term_meta();
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
		wp_register_ability(
			'metamanager/get-daemon-status',
			array(
				'label'               => __( 'Get Daemon Status', 'metamanager' ),
				'description'         => 'Read the MetaManager daemon status: daemon running state and PIDs, queue depths, tool availability, WP-Cron history.',
				'category'            => 'site',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ts'             => array( 'type' => 'string' ),
						'plugin_version' => array( 'type' => 'string' ),
						'daemon_version' => array( 'type' => array( 'string', 'null' ) ),
						'daemons'        => array( 'type' => 'object' ),
						'queues'         => array( 'type' => 'object' ),
						'tools'          => array( 'type' => 'object' ),
						'cron'           => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( $this, 'ability_get_daemon_status' ),
				'permission_callback' => array( $this, 'can_use' ),
				'meta'                => array(
					'mcp.public' => true,
					'tier'       => 'read',
				),
			)
		);
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
		wp_register_ability(
			'metamanager/get-post-meta',
			array(
				'label'               => 'Get Post Metadata',
				'description'         => 'Read SEO metadata (title, description, OG tags, schema type) for a WordPress post or page.',
				'category'            => 'site',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The post ID to read metadata for.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array( 'type' => 'integer' ),
						'title'            => array(
							'type'        => 'string',
							'description' => 'SEO title override.',
						),
						'description'      => array(
							'type'        => 'string',
							'description' => 'Meta description override.',
						),
						'canonical'        => array(
							'type'        => 'string',
							'description' => 'Canonical URL override.',
						),
						'noindex'          => array(
							'type'        => array( 'string', 'null' ),
							'description' => 'robots noindex: "yes", "no", or null (default).',
						),
						'nofollow'         => array( 'type' => array( 'string', 'null' ) ),
						'og_title'         => array(
							'type'        => 'string',
							'description' => 'Open Graph title override.',
						),
						'og_description'   => array( 'type' => 'string' ),
						'og_image_url'     => array(
							'type'        => 'string',
							'description' => 'Open Graph image URL.',
						),
						'schema_type'      => array(
							'type'        => 'string',
							'description' => 'Schema.org type (e.g. BlogPosting, Product).',
						),
						'breadcrumb_label' => array( 'type' => 'string' ),
						'schema_fields'    => array(
							'type'        => 'object',
							'description' => 'Per-type schema field overrides.',
						),
					),
				),
				'execute_callback'    => array( $this, 'ability_get_post_meta' ),
				'permission_callback' => array( $this, 'can_use' ),
				'meta'                => array(
					'mcp.public' => true,
					'tier'       => 'read',
				),
			)
		);
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

		return array_merge( array( 'post_id' => $post_id ), $meta );
	}

	// -------------------------------------------------------------------------
	// metamanager/update-post-meta
	// -------------------------------------------------------------------------

	private function register_update_post_meta(): void {
		wp_register_ability(
			'metamanager/update-post-meta',
			array(
				'label'               => 'Update Post Metadata',
				'description'         => 'Write SEO metadata fields for a WordPress post or page. Only provided fields are updated; omitted fields remain unchanged.',
				'category'            => 'site',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array(
							'type'        => 'integer',
							'description' => 'The post ID to update.',
						),
						'title'            => array(
							'type'        => 'string',
							'description' => 'SEO title override.',
						),
						'description'      => array(
							'type'        => 'string',
							'description' => 'Meta description override.',
						),
						'canonical'        => array(
							'type'        => 'string',
							'description' => 'Canonical URL.',
						),
						'noindex'          => array(
							'type' => 'string',
							'enum' => array( 'yes', 'no' ),
						),
						'nofollow'         => array(
							'type' => 'string',
							'enum' => array( 'yes', 'no' ),
						),
						'og_title'         => array( 'type' => 'string' ),
						'og_description'   => array( 'type' => 'string' ),
						'og_image_url'     => array(
							'type'        => 'string',
							'description' => 'OG image URL or attachment ID.',
						),
						'schema_type'      => array(
							'type'        => 'string',
							'description' => 'Schema.org type.',
						),
						'breadcrumb_label' => array( 'type' => 'string' ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'post_id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'ability_update_post_meta' ),
				'permission_callback' => array( $this, 'can_edit_meta' ),
				'meta'                => array( 'mcp.public' => true ),
			)
		);
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
		$updatable = array(
			'title',
			'description',
			'canonical',
			'noindex',
			'nofollow',
			'noarchive',
			'nosnippet',
			'noimageindex',
			'og_title',
			'og_description',
			'og_image_id',
			'og_image_url',
			'schema_type',
			'breadcrumb_label',
			'schema_fields',
		);

		foreach ( $updatable as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$existing[ $field ] = $input[ $field ];
			}
		}

		$settings->save_post_meta( $post_id, $existing );

		return array(
			'success' => true,
			'post_id' => $post_id,
		);
	}

	// -------------------------------------------------------------------------
	// metamanager/get-schema
	// -------------------------------------------------------------------------

	private function register_get_schema(): void {
		wp_register_ability(
			'metamanager/get-schema',
			array(
				'label'               => 'Get Schema Output',
				'description'         => 'Fetch the complete JSON-LD schema output for a given URL. Returns the full @graph.',
				'category'            => 'site',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array(
							'type'        => 'string',
							'description' => 'The URL to fetch schema for. Must be on this site.',
						),
					),
					'required'   => array( 'url' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'url'    => array( 'type' => 'string' ),
						'schema' => array(
							'type'        => 'object',
							'description' => 'The JSON-LD output with @context and @graph.',
						),
					),
				),
				'execute_callback'    => array( $this, 'ability_get_schema' ),
				'permission_callback' => array( $this, 'can_use' ),
				'meta'                => array(
					'mcp.public' => true,
					'tier'       => 'read',
				),
			)
		);
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

		$host      = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host !== $site_host ) {
			return new WP_Error( 'external_url', 'URL must belong to this site.' );
		}

		// Temporarily switch context to the requested URL.
		$context  = new MM_Page_Context();
		$settings = MM_Site_Settings::get_instance();
		$emitter  = new MM_Head_Emitter( $context, $settings );

		// Parse the URL path to set up the context.
		$path                   = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
		$_SERVER['REQUEST_URI'] = $path;

		// Force a fresh context parse.
		$context = new MM_Page_Context();
		$data    = array(
			'title'  => '',
			'meta'   => array(),
			'links'  => array(),
			'schema' => array(),
		);

		// Populate all modules.
		( new MM_Mod_Head_Meta( $settings ) )->populate( $data, $context, $settings );
		( new MM_Mod_Social( $settings ) )->populate( $data, $context, $settings );
		( new MM_Mod_Schema( $settings ) )->populate( $data, $context, $settings );
		( new MM_Mod_Local( $settings ) )->populate( $data, $context, $settings );
		( new MM_Mod_Author( $settings ) )->populate( $data, $context, $settings );

		return array(
			'url'    => $url,
			'schema' => array(
				'@context' => 'https://schema.org',
				'@graph'   => $data['schema'],
			),
		);
	}

	// -------------------------------------------------------------------------
	// metamanager/get-business-profile
	// -------------------------------------------------------------------------

	private function register_get_business_profile(): void {
		wp_register_ability(
			'metamanager/get-business-profile',
			array(
				'label'               => 'Get Business Profile',
				'description'         => 'Retrieve the site-wide LocalBusiness or Organization entity including name, address, phone, hours, and social profiles.',
				'category'            => 'site',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'type'        => array(
							'type'        => 'string',
							'description' => 'Organization or LocalBusiness.',
						),
						'name'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'telephone'   => array( 'type' => 'string' ),
						'email'       => array( 'type' => 'string' ),
						'address'     => array( 'type' => 'object' ),
						'geo'         => array( 'type' => 'object' ),
						'hours'       => array( 'type' => 'array' ),
						'price_range' => array( 'type' => 'string' ),
						'same_as'     => array(
							'type'        => 'array',
							'description' => 'Social profile URLs.',
						),
					),
				),
				'execute_callback'    => array( $this, 'ability_get_business_profile' ),
				'permission_callback' => array( $this, 'can_use' ),
				'meta'                => array(
					'mcp.public' => true,
					'tier'       => 'read',
				),
			)
		);
	}

	/**
	 * @param array{} $input
	 * @return array|WP_Error
	 */
	public function ability_get_business_profile( array $input ) {
		$settings = MM_Site_Settings::get_instance();
		$entity   = $settings->get( 'schema.knowledge_entity', 'LocalBusiness' );
		$local    = $settings->all_business();
		$social   = $settings->get( 'social', array() );

		$profile = array(
			'type'        => $entity,
			'name'        => $local['name'] ?? get_bloginfo( 'name' ),
			'description' => $local['description'] ?? get_bloginfo( 'description' ),
			'telephone'   => $local['phone'] ?? '',
			'email'       => $local['email'] ?? '',
			'address'     => array(
				'street'  => $local['street'] ?? '',
				'city'    => $local['city'] ?? '',
				'region'  => $local['region'] ?? '',
				'postal'  => $local['postal'] ?? '',
				'country' => $local['country'] ?? '',
			),
			'geo'         => array(
				'latitude'  => $local['latitude'] ?? '',
				'longitude' => $local['longitude'] ?? '',
			),
			'hours'       => array_values( (array) ( $local['hours'] ?? array() ) ),
			'price_range' => $local['price_range'] ?? '',
			'same_as'     => array_values( (array) ( $social['accounts'] ?? array() ) ),
		);

		// Remove empty values.
		return array_filter(
			$profile,
			function ( $v ) {
				return is_array( $v ) || $v !== '';
			}
		);
	}

	// -------------------------------------------------------------------------
	// metamanager/get-navigation
	// -------------------------------------------------------------------------

	private function register_get_navigation(): void {
		wp_register_ability(
			'metamanager/get-navigation',
			array(
				'label'               => 'Get Navigation Menu',
				'description'         => 'Retrieve the primary navigation menu items designated for schema. Returns the menu name and ordered items with URLs.',
				'category'            => 'site',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'menu_name' => array( 'type' => 'string' ),
						'items'     => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'     => array( 'type' => 'string' ),
									'url'      => array( 'type' => 'string' ),
									'position' => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'ability_get_navigation' ),
				'permission_callback' => array( $this, 'can_use' ),
				'meta'                => array(
					'mcp.public' => true,
					'tier'       => 'read',
				),
			)
		);
	}

	/**
	 * @param array{} $input
	 * @return array|WP_Error
	 */
	public function ability_get_navigation( array $input ) {
		$menu = MM_Mod_Base::get_primary_menu();
		if ( ! $menu ) {
			return array(
				'menu_name' => '',
				'items'     => array(),
			);
		}

		return array(
			'menu_name' => $menu['name'],
			'items'     => $menu['items'],
		);
	}

	// -------------------------------------------------------------------------
	// metamanager/get-term-meta
	// -------------------------------------------------------------------------

	private function register_get_term_meta(): void {
		wp_register_ability(
			'metamanager/get-term-meta',
			array(
				'label'               => 'Get Term Metadata',
				'description'         => 'Read SEO metadata (title, description, OG tags) for a taxonomy term (category, tag, etc.).',
				'category'            => 'site',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'term_id'  => array(
							'type'        => 'integer',
							'description' => 'The term ID.',
						),
						'taxonomy' => array(
							'type'        => 'string',
							'description' => 'The taxonomy slug (e.g. "category", "post_tag").',
						),
					),
					'required'   => array( 'term_id', 'taxonomy' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'term_id'        => array( 'type' => 'integer' ),
						'taxonomy'       => array( 'type' => 'string' ),
						'title'          => array( 'type' => 'string' ),
						'description'    => array( 'type' => 'string' ),
						'og_title'       => array( 'type' => 'string' ),
						'og_description' => array( 'type' => 'string' ),
						'og_image_url'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'ability_get_term_meta' ),
				'permission_callback' => array( $this, 'can_use' ),
				'meta'                => array(
					'mcp.public' => true,
					'tier'       => 'read',
				),
			)
		);
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

		return array_merge(
			array(
				'term_id'  => $term_id,
				'taxonomy' => $taxonomy,
			),
			$meta
		);
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
