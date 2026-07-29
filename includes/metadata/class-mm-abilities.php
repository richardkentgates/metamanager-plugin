<?php
/**
 * MM_Abilities — registers Metamanager capabilities with the WordPress
 * Abilities API so they are discoverable by the MCP Adapter and AI agents.
 *
 * Requires WordPress 6.9+ (Abilities API). Gracefully degrades if unavailable.
 */

defined( 'ABSPATH' ) || exit;

class MM_Abilities {

	public function register_hooks(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // Abilities API not available (pre-6.9).
		}

		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	public function register_abilities(): void {
		$this->register_get_post_meta();
		$this->register_update_post_meta();
		$this->register_get_schema();
		$this->register_get_business_profile();
		$this->register_get_navigation();
		$this->register_get_term_meta();
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
			'callback'    => [ $this, 'ability_get_post_meta' ],
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
			'callback'    => [ $this, 'ability_update_post_meta' ],
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
			'callback'    => [ $this, 'ability_get_schema' ],
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
			'callback'    => [ $this, 'ability_get_business_profile' ],
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
			'callback'    => [ $this, 'ability_get_navigation' ],
			'meta'        => [ 'mcp.public' => true ],
		] );
	}

	/**
	 * @param array{} $input
	 * @return array|WP_Error
	 */
	public function ability_get_navigation( array $input ) {
		$primary = get_posts( [
			'post_type'   => 'nav_menu',
			'meta_key'    => '_mm_nav_menu_primary',
			'meta_value'  => '1',
			'numberposts' => 1,
		] );

		if ( empty( $primary ) ) {
			return [ 'menu_name' => '', 'items' => [] ];
		}

		$menu_term_id = $primary[0]->ID;
		$menu_items   = wp_get_nav_menu_items( $menu_term_id );
		$menu_name    = get_the_title( $menu_term_id ) ?: 'Navigation';

		$items = [];
		$position = 1;
		if ( is_array( $menu_items ) ) {
			foreach ( $menu_items as $item ) {
				if ( $item->url && $item->title ) {
					$items[] = [
						'name'     => $item->title,
						'url'      => $item->url,
						'position' => $position++,
					];
				}
			}
		}

		return [ 'menu_name' => $menu_name, 'items' => $items ];
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
			'callback'    => [ $this, 'ability_get_term_meta' ],
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
