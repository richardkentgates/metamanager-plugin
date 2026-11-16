<?php
/**
 * MM_Site_Settings — thin wrapper around two wp_options keys.
 *
 * All reads are from a deep-merged in-memory copy (defaults + saved).
 * Dot-notation keys: e.g. get('titles.separator'), get('social.og_locale').
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site settings.
 *
 * @package Metamanager
 */
class MM_Site_Settings {

	/**
	 * Instance.
	 *
	 * @var ?self
	 */
	private static ?self $instance = null;
	/**
	 * Settings data.
	 *
	 * @var ?array
	 */
	private ?array $settings_data = null;
	/**
	 * Business data.
	 *
	 * @var ?array
	 */
	private ?array $business_data = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Get instance.
	 *
	 * @return self Return value.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			add_filter( 'pre_update_option_' . MM_META_OPT_SETTINGS, array( self::class, 'intercept_save' ), 10, 3 );
		}
		return self::$instance;
	}

	/**
	 * Clear the in-memory singleton and force a fresh load on next access.
	 * For test isolation only — do not call in production code.
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}

	// -------------------------------------------------------------------------
	// Public getters
	// -------------------------------------------------------------------------

	/**
	 * Get a setting value using dot-notation.
	 *
	 * @param string $key     Dot-separated path, e.g. 'titles.separator'.
	 * @param mixed  $default Returned when the key does not exist.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ) {
		$this->load();
		return self::dot_get( $this->settings_data, $key, $default );
	}

	/**
	 * Get a business profile value using dot-notation.
	 *
	 * @param string $key Key.
	 * @param mixed  $default Default value.
	 */
	public function get_business( string $key = '', mixed $default = null ) {
		$this->load();
		if ( '' === $key ) {
			return $this->business_data;
		}
		return self::dot_get( $this->business_data, $key, $default );
	}

	/**
	 * Return the full (merged) settings array.
	 */
	public function all(): array {
		$this->load();
		return $this->settings_data;
	}

	/**
	 * Return the full (merged) business array.
	 */
	public function all_business(): array {
		$this->load();
		return $this->business_data;
	}

	// -------------------------------------------------------------------------
	// Entity meta helpers
	// -------------------------------------------------------------------------

	/**
	 * Return decoded _mm_meta postmeta for a post, or [].
	 *
	 * @param int $post_id Post ID.
	 */
	public function get_post_meta( int $post_id ): array {
		$raw = get_post_meta( $post_id, MM_META_KEY, true );
		return $this->decode_meta( $raw );
	}

	/**
	 * Return decoded _mm_meta termmeta for a term, or [].
	 *
	 * @param int $term_id Term ID.
	 */
	public function get_term_meta( int $term_id ): array {
		$raw = get_term_meta( $term_id, MM_META_KEY, true );
		return $this->decode_meta( $raw );
	}

	/**
	 * Return decoded _mm_meta usermeta for a user, or [].
	 *
	 * @param int $user_id User ID.
	 */
	public function get_user_meta( int $user_id ): array {
		$raw = get_user_meta( $user_id, MM_META_KEY, true );
		return $this->decode_meta( $raw );
	}

	/**
	 * Save entity meta (post/term/user) — always stores clean JSON.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data Data.
	 */
	public function save_post_meta( int $post_id, array $data ): void {
		update_post_meta( $post_id, MM_META_KEY, wp_json_encode( $data ) );
	}

	/**
	 * Save term meta.
	 *
	 * @param int   $term_id Term ID.
	 * @param array $data Data.
	 */
	public function save_term_meta( int $term_id, array $data ): void {
		update_term_meta( $term_id, MM_META_KEY, wp_json_encode( $data ) );
	}

	/**
	 * Save user meta.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data Data.
	 */
	public function save_user_meta( int $user_id, array $data ): void {
		update_user_meta( $user_id, MM_META_KEY, wp_json_encode( $data ) );
	}

	// -------------------------------------------------------------------------
	// Save settings
	// -------------------------------------------------------------------------

	/**
	 * Persist settings to the database and bust the in-memory cache.
	 *
	 * @param array $data Data.
	 */
	public function save_settings( array $data ): void {
		update_option( MM_META_OPT_SETTINGS, $data, false );
		$this->settings_data = null; // Bust instance cache.
	}

	/**
	 * Save business.
	 *
	 * @param array $data Data.
	 */
	public function save_business( array $data ): void {
		// Auto-derive coordinates via Google Maps Geocoding API when:
		// - API key is set
		// - Address has a street
		// - Coordinates are missing OR address changed.
		$old            = get_option( MM_META_OPT_BUSINESS, array() );
		$key            = sanitize_text_field( $data['google_maps_api_key'] ?? '' );
		$has_addr       = ! empty( $data['address']['street'] ?? '' );
		$coords_missing = empty( $data['lat'] ) || empty( $data['lng'] );
		$addr_changed   = ( $old['address']['street'] ?? '' ) !== ( $data['address']['street'] ?? '' )
			|| ( $old['address']['city'] ?? '' ) !== ( $data['address']['city'] ?? '' )
			|| ( $old['address']['state'] ?? '' ) !== ( $data['address']['state'] ?? '' )
			|| ( $old['address']['zip'] ?? '' ) !== ( $data['address']['zip'] ?? '' );

		if ( $has_addr && ( $coords_missing || $addr_changed ) ) {
			$geo = false;
			// Try Google first (if key is set), then Nominatim fallback.
			if ( $key ) {
				$geo = self::geocode_with_key( $key, $data['address'] );
			}
			if ( ! $geo ) {
				$geo = self::geocode_nominatim( $data['address'] );
			}
			if ( $geo ) {
				$data['lat'] = (string) $geo['lat'];
				$data['lng'] = (string) $geo['lng'];
				do_action( 'mm_geocode_success', $geo, $data['address'] );
			} else {
				do_action( 'mm_geocode_failed', $data['address'] );
			}
		}

		update_option( MM_META_OPT_BUSINESS, $data, false );
		$this->business_data = null;
		do_action( 'mm_business_profile_saved', $data );
	}

	/**
	 * Derive coordinates from address using Google Maps Geocoding API.
	 *
	 * @param string $key  Google Maps API key.
	 * @param array  $addr Address array with street/city/state/zip/country.
	 * @return array|false ['lat' => float, 'lng' => float] or false.
	 */
	public static function geocode_with_key( string $key, array $addr ): array|false {
		$parts = array_filter(
			array(
				$addr['street'] ?? '',
				$addr['city'] ?? '',
				$addr['state'] ?? '',
				$addr['zip'] ?? '',
				$addr['country'] ?? 'US',
			)
		);
		if ( empty( $parts ) ) {
			return false;
		}
		$address = implode( ', ', $parts );
		$url     = add_query_arg(
			array(
				'address' => rawurlencode( $address ),
				'key'     => $key,
			),
			'https://maps.googleapis.com/maps/api/geocode/json'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Referer' => home_url( '/' ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['results'][0]['geometry']['location'] ) ) {
			return false;
		}
		$loc = $body['results'][0]['geometry']['location'];
		return array(
			'lat' => (float) $loc['lat'],
			'lng' => (float) $loc['lng'],
		);
	}

	/**
	 * Fallback geocoding using OpenStreetMap Nominatim (no API key required).
	 *
	 * @param array $addr Address array.
	 * @return array|false ['lat' => float, 'lng' => float] or false.
	 */
	public static function geocode_nominatim( array $addr ): array|false {
		$parts = array_filter(
			array(
				$addr['street'] ?? '',
				$addr['city'] ?? '',
				$addr['state'] ?? '',
				$addr['zip'] ?? '',
				$addr['country'] ?? 'US',
			)
		);
		if ( empty( $parts ) ) {
			return false;
		}
		$address  = implode( ', ', $parts );
		$url      = 'https://nominatim.openstreetmap.org/search?q=' . rawurlencode( $address ) . '&format=json&limit=1';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'User-Agent' => 'MetaManager/1.0 (WordPress plugin)' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body[0]['lat'] ) || empty( $body[0]['lon'] ) ) {
			return false;
		}
		return array(
			'lat' => (float) $body[0]['lat'],
			'lng' => (float) $body[0]['lon'],
		);
	}

	// -------------------------------------------------------------------------
	// options.php save interception
	// -------------------------------------------------------------------------

	/**
	 * Intercept options.php save to merge the submitted section into the full option.
	 *
	 * Options.php calls update_option() which first runs sanitize_option(). When
	 * multiple sections register sanitize_callbacks on the same shared option,
	 * they chain in the sanitize_option filter — each callback reloads from DB
	 * via get_option(), destroying the POST data by the time the matching
	 * section's callback fires. So the sanitize callbacks cannot be used for
	 * partial saves on a shared option.
	 *
	 * This pre_update_option filter handles it instead. It fires after
	 * sanitize_option() with the (now stale from DB) value, but detects partial
	 * saves by checking for a single known section key, then loads the POST
	 * data from the $old_value comparison to produce the correct merged result.
	 *
	 * NOTE: Because the sanitize callbacks were removed from register_setting()
	 * for the shared option, the value here IS the raw POST data (unmodified
	 * by sanitize_option). This is the correct behavior.
	 *
	 * @param mixed $value Value.
	 * @param mixed $old_value Old value.
	 * @param mixed $option Option.
	 */
	public static function intercept_save( $value, $old_value, $option ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		// Unwrap: options.php passes wp_unslash($_POST[$option]) which has
		// the option name as the top-level key (e.g. ['mm_meta_settings' => ['titles' => [...]]]).
		// WordPress core does NOT strip this before the pre_update_option filter.
		if ( isset( $value[ $option ] ) && is_array( $value[ $option ] ) ) {
			$value = $value[ $option ];
		}

		$defaults       = self::settings_defaults();
		$known_sections = array_keys( $defaults );
		$value_keys     = array_keys( $value );

		// Only intercept partial saves (single section from options.php form).
		if ( count( $value_keys ) !== 1 || ! in_array( $value_keys[0], $known_sections, true ) ) {
			return $value;
		}

		$section   = $value_keys[0];
		$submitted = $value[ $section ];

		if ( ! is_array( $submitted ) ) {
			return $value;
		}

		// Use the old value (current full option from the database).
		$current = is_array( $old_value ) ? $old_value : $defaults;

		// Sanitize the submitted section against defaults (casts types, fills missing keys).
		$section_defaults = $defaults[ $section ];
		$sanitized        = self::deep_sanitize_section( $submitted, $section_defaults );

		// Merge the sanitized section into the full option.
		$current[ $section ] = $sanitized;

		return $current;
	}

	/**
	 * Recursively sanitize $input against $defaults.
	 * Known keys: type-cast and fill missing from defaults.
	 * Unknown keys in nested associative arrays: preserved and recursively
	 * sanitized using the first default value as a structural template.
	 * Missing bool keys (unchecked checkboxes) default to false.
	 *
	 * @param array $input Raw input.
	 * @param array $defaults Defaults.
	 */
	public static function deep_sanitize_section( array $input, array $defaults ): array {
		$out = array();
		foreach ( $defaults as $key => $default_val ) {
			if ( ! array_key_exists( $key, $input ) ) {
				$out[ $key ] = is_bool( $default_val ) ? false : $default_val;
				continue;
			}

			$val = $input[ $key ];

			if ( is_array( $default_val ) && is_array( $val ) ) {
				$is_list = empty( $default_val ) || ( array_keys( $default_val ) === range( 0, count( $default_val ) - 1 ) );
				if ( $is_list ) {
					// Detect if items are arrays (repeater rows with nested structure).
					$items_are_arrays = ! empty( $val ) && is_array( reset( $val ) );
					if ( $items_are_arrays ) {
						// Sanitize each row as an associative array, preserving structure.
						$out[ $key ] = array_values(
							array_map(
								function ( $item ) {
									if ( ! is_array( $item ) ) {
										return sanitize_text_field( (string) $item );
									}
									$clean = array();
									foreach ( $item as $ik => $iv ) {
										if ( is_array( $iv ) ) {
											$clean[ $ik ] = array_map(
												function ( $v ) {
													return sanitize_text_field( (string) $v );
												},
												$iv
											);
										} elseif ( is_bool( $iv ) ) {
											$clean[ $ik ] = (bool) $iv;
										} elseif ( is_int( $iv ) ) {
											$clean[ $ik ] = (int) $iv;
										} else {
											$clean[ $ik ] = sanitize_text_field( (string) $iv );
										}
									}
									return $clean;
								},
								$val
							)
						);
					} else {
						$first_default = reset( $default_val );
						$out[ $key ]   = array_values(
							array_map(
								function ( $item ) use ( $first_default ) {
									if ( is_array( $item ) && is_array( $first_default ) ) {
										return self::deep_sanitize_section( $item, $first_default );
									}
									return sanitize_text_field( (string) $item );
								},
								$val
							)
						);
					}
				} else {
					$out[ $key ] = self::deep_sanitize_section( $val, $default_val );
				}
			} elseif ( is_bool( $default_val ) ) {
				$out[ $key ] = (bool) $val;
			} elseif ( is_int( $default_val ) ) {
				$out[ $key ] = (int) $val;
			} else {
				$str = (string) $val;
				if ( str_contains( $key, 'custom' ) || str_contains( $key, 'json' ) ) {
					$out[ $key ] = sanitize_textarea_field( $str );
				} elseif ( str_contains( $key, 'url' ) || str_contains( $key, '_image' ) ) {
					$out[ $key ] = esc_url_raw( $str );
				} else {
					$out[ $key ] = sanitize_text_field( $str );
				}
			}
		}

		// Preserve unknown keys in nested associative arrays.
		// This handles dynamically-generated fields (custom post types,
		// custom taxonomies, repeater rows) that are in the form but
		// not in the static defaults.
		$extra_keys = array_diff_key( $input, $defaults );
		foreach ( $extra_keys as $key => $val ) {
			if ( is_array( $val ) ) {
				// Find a template from defaults to determine structure.
				$template = self::find_array_template( $defaults );
				if ( $template ) {
					$out[ $key ] = self::deep_sanitize_section( $val, $template );
				} else {
					// No template — recursively sanitize with empty defaults.
					$out[ $key ] = self::deep_sanitize_section( $val, array() );
				}
			} else {
				$str         = (string) $val;
				$out[ $key ] = sanitize_text_field( $str );
			}
		}

		return $out;
	}

	/**
	 * Find an array-typed value in $defaults to use as a sanitization template.
	 *
	 * @param array $defaults Defaults to search.
	 * @return array|null First array-typed value found, or null.
	 */
	private static function find_array_template( array $defaults ): ?array {
		foreach ( $defaults as $val ) {
			if ( is_array( $val ) && ! empty( $val ) ) {
				return $val;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Template variable resolver
	// -------------------------------------------------------------------------

	/**
	 * Replace %%variable%% tokens in a template string.
	 *
	 * @param string        $tpl     The template string.
	 * @param \WP_Post|null $post    Optional post object for post-specific vars.
	 * @param \WP_Term|null $term    Optional term object.
	 * @param \WP_User|null $author  Optional author.
	 * @param int           $page    Current page number (for pagination).
	 * @return string
	 */
	public function resolve( string $tpl, ?\WP_Post $post = null, ?\WP_Term $term = null, ?\WP_User $author = null, int $page = 0 ): string {
		$this->load();

		$replacements = array(
			'%%sitetitle%%'     => esc_html( get_bloginfo( 'name' ) ),
			'%%tagline%%'       => esc_html( get_bloginfo( 'description' ) ),
			'%%sep%%'           => esc_html( $this->settings_data['titles']['separator'] ?? '|' ),
			'%%current_year%%'  => (string) gmdate( 'Y' ),
			'%%current_month%%' => esc_html( gmdate( 'F' ) ),
		);

		if ( $post ) {
			$replacements['%%post_title%%']      = esc_html( get_the_title( $post ) );
			$replacements['%%post_excerpt%%']    = esc_html( $this->get_excerpt( $post ) );
			$replacements['%%post_type_label%%'] = esc_html( $this->get_post_type_label( $post->post_type ) );

			// Primary category.
			$cats                         = get_the_category( $post->ID );
			$replacements['%%category%%'] = $cats ? esc_html( $cats[0]->name ) : '';
		}

		if ( $term ) {
			$replacements['%%term_title%%']       = esc_html( $term->name );
			$replacements['%%term_description%%'] = esc_html( $term->description );
			$replacements['%%post_type_label%%']  = esc_html( $this->get_post_type_label_for_tax( $term->taxonomy ) );
		}

		if ( $author ) {
			$replacements['%%author_name%%'] = esc_html( $author->display_name );
			$replacements['%%author_bio%%']  = esc_html( $author->description );
		}

		if ( is_search() ) {
			$replacements['%%search_query%%'] = esc_html( get_search_query() );
		}

		if ( $page > 1 ) {
			$replacements['%%page%%'] = (string) $page;
		} else {
			// Remove page tokens completely when not paginated.
			$tpl = preg_replace( '/\s*%%page%%\s*/', '', $tpl );
		}

		// Replace all tokens.
		$result = str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$tpl
		);

		// Strip any remaining unresolved tokens.
		return trim( preg_replace( '/%%[a-z_]+%%/', '', $result ) );
	}

	// -------------------------------------------------------------------------
	// Defaults
	// -------------------------------------------------------------------------

	/**
	 * Settings defaults.
	 *
	 * @return array Resulting array.
	 */
	public static function settings_defaults(): array {
		return array(
			'titles'    => array(
				'separator'                  => '|',
				'home_title'                 => '%%sitetitle%% %%sep%% %%tagline%%',
				'home_description'           => '',
				'author_archive_title'       => 'Articles by %%author_name%% %%sep%% %%sitetitle%%',
				'author_archive_description' => '%%author_bio%%',
				'search_title'               => 'Search Results for %%search_query%% %%sep%% %%sitetitle%%',
				'404_title'                  => 'Page Not Found %%sep%% %%sitetitle%%',
				'paginate_append'            => true,
				'date_archive_noindex'       => true,
				'search_noindex'             => true,
				'post_types'                 => array(
					'post' => array(
						'single_title'       => '%%post_title%% %%sep%% %%sitetitle%%',
						'archive_title'      => 'Blog %%sep%% %%sitetitle%%',
						'description_source' => 'excerpt',
						'noindex'            => false,
						'noindex_archive'    => false,
					),
					'page' => array(
						'single_title'       => '%%post_title%% %%sep%% %%sitetitle%%',
						'description_source' => 'excerpt',
						'noindex'            => false,
					),
				),
				'taxonomies'                 => array(
					'category' => array(
						'archive_title'      => '%%term_title%% %%sep%% %%sitetitle%%',
						'description_source' => 'term_description',
						'noindex'            => false,
					),
					'post_tag' => array(
						'archive_title'      => '%%term_title%% %%sep%% %%sitetitle%%',
						'description_source' => 'term_description',
						'noindex'            => false,
					),
				),
			),

			'social'    => array(
				'og_enabled'          => true,
				'twitter_enabled'     => true,
				'og_default_image'    => '',
				'og_default_image_id' => 0,
				'og_locale'           => 'en_US',
				'fb_app_id'           => '',
				'twitter_site'        => '',
				'twitter_card_type'   => 'summary_large_image',
				'accounts'            => array(
					'facebook'  => '',
					'instagram' => '',
					'pinterest' => '',
					'youtube'   => '',
					'linkedin'  => '',
					'twitter'   => '',
					'bluesky'   => '',
				),
				'pinterest_verify'    => '',
			),

			'schema'    => array(
				'knowledge_entity'     => 'LocalBusiness',
				'website_searchaction' => true,
				'breadcrumbs'          => true,
				'archive_itemlist'     => true,
				'archive_media'        => false,
				'custom_json_ld'       => '',
				'archive_layouts'      => array(
					'mm_service'      => 'menu',
					'mm_event'        => 'menu',
					'mm_trip'         => 'menu',
					'mm_destination'  => 'menu',
					'mm_vessel'       => 'menu',
					'mm_course'       => 'menu',
					'mm_how_to'       => 'menu',
					'mm_faq_page'     => 'menu',
					'mm_business'     => 'menu',
					'mm_organization' => 'menu',
				),
			),

			'media'     => array(
				'featured_image_citation' => false,
			),

			'sitemap'   => array(
				'enabled'                    => true,
				'post_types'                 => array(
					'post' => true,
					'page' => true,
				),
				'taxonomies'                 => array( 'category' => true ),
				'images'                     => true,
				'video'                      => true,
				'video_selfhosted'           => true,
				'exclude_password_protected' => true,
				'exclude_noindexed'          => true,
				'records_per_file'           => 1000,
				'ping_google'                => true,
				'ping_bing'                  => true,
				'html_sitemap'               => array(
					'enabled'            => true,
					'post_types'         => array( 'page', 'post' ),
					'taxonomies'         => array(),
					'columns'            => 1,
					'order_by'           => 'menu_order',
					'exclude_ids'        => array(),
					'flat_limit'         => 500,
					'hierarchical_limit' => 500,
				),
			),

			'robots'    => array(
				'enabled'     => true,
				'disallow'    => array( '/wp-admin/', '/wp-login.php' ),
				'allow'       => array( '/wp-admin/admin-ajax.php' ),
				'crawl_delay' => '',
				'custom'      => '',
			),

			'authors'   => array(
				'enabled'               => true,
				'noindex_default'       => false,
				'title_template'        => 'Articles by %%author_name%% %%sep%% %%sitetitle%%',
				'description_template'  => '%%author_bio%%',
				'person_schema'         => true,
				'profile_social_fields' => true,
			),

			'links'     => array(
				'enabled'        => true,
				'cron_frequency' => 'twicedaily',
				'timeout'        => 10,
				'batch_size'     => 50,
				'check_external' => true,
				'ignore_domains' => array(),
				'email_alerts'   => false,
				'email_address'  => '',
			),

			'hygiene'   => array(
				'remove_generator'       => true,
				'remove_oembed_links'    => true,
				'remove_shortlink'       => true,
				'remove_wlw_manifest'    => true,
				'remove_rsd_link'        => true,
				'remove_pingback_header' => true,
				'remove_x_powered_by'    => true,
				'remove_wp_dns_prefetch' => true,
				'remove_hentry'          => true,
			),

			'feed'      => array(
				'cleanup_enabled'          => true,
				'remove_generator'         => true,
				'remove_comments_elements' => true,
				'use_excerpt'              => false,
				'feed_title'               => '',
				'feed_copyright'           => '',
			),

			'discovery' => array(
				'llms_txt_enabled' => true,
				'mcp_server'       => true,
			),

			'listing'   => array(
				'btn_text'              => 'Learn more',
				'btn_bg'                => '#0073aa',
				'btn_text_color'        => '#ffffff',
				'btn_radius'            => '4px',
				'btn_padding'           => '10px 16px',
				'btn_font_size'         => '14px',
				'btn_font_weight'       => '600',
				'btn_border_thickness'  => '0px',
				'btn_border_color'      => '#0073aa',
				'btn_box_shadow'        => false,
				'btn_box_shadow_color'  => 'rgba(0,0,0,0.3)',
				'btn_box_shadow_x'      => '0px',
				'btn_box_shadow_y'      => '2px',
				'btn_box_shadow_blur'   => '4px',
				'btn_box_shadow_spread' => '0px',
				'btn_hover_enabled'     => true,
				'btn_hover_bg'          => '',
				'btn_hover_text'        => '',
				'btn_display'           => 'inline-block',
			),

			'templates' => array(
				'noindex_about'    => false,
				'noindex_contact'  => false,
				'noindex_calendar' => false,
			),

		);
	}

	/**
	 * Business defaults.
	 *
	 * @return array Resulting array.
	 */
	public static function business_defaults(): array {
		return array(
			'name'                 => '',
			'type'                 => 'LocalBusiness',
			'logo_id'              => 0,
			'logo_url'             => '',
			'phone'                => '',
			'email'                => '',
			'description'          => '',
			'founding_date'        => '',
			'number_of_employees'  => '',
			'address'              => array(
				'street'  => '',
				'city'    => '',
				'state'   => '',
				'zip'     => '',
				'country' => 'US',
			),
			'lat'                  => '',
			'lng'                  => '',
			'location_type'        => 'brick_and_mortar',  // 'brick_and_mortar' | 'service_area'
			'google_maps_api_key'  => '',
			'show_sms'             => true,
			'show_phone'           => true,
			'show_email'           => true,
			'show_vcard'           => true,
			'show_json'            => true,
			'show_csv'             => true,
			'booking_link'         => '',
			'booking_link_label'   => 'Book Now',
			'booking_link_new_tab' => true,
			'map_links'            => array(
				'google' => true,
				'bing'   => true,
				'apple'  => true,
				'geo'    => true,
			),
			'price_range'          => '',
			'payment_accepted'     => array(),
			'hours'                => array(),
			'service_areas'        => array(),
			'accounts'             => array(
				'facebook'  => '',
				'instagram' => '',
				'linkedin'  => '',
				'youtube'   => '',
				'twitter'   => '',
				'bluesky'   => '',
				'pinterest' => '',
			),
		);
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Load.
	 */
	private function load(): void {
		if ( null === $this->settings_data ) {
			$saved               = get_option( MM_META_OPT_SETTINGS, array() );
			$this->settings_data = self::deep_merge( self::settings_defaults(), is_array( $saved ) ? $saved : array() );
		}
		if ( null === $this->business_data ) {
			$saved               = get_option( MM_META_OPT_BUSINESS, array() );
			$this->business_data = self::deep_merge( self::business_defaults(), is_array( $saved ) ? $saved : array() );
		}
	}

	/**
	 * Deep merge.
	 *
	 * @param array $base Base.
	 * @param array $override Override.
	 * @param bool  $merge_lists Merge lists.
	 * @return array Resulting array.
	 */
	public static function deep_merge( array $base, array $override, bool $merge_lists = false ): array {
		foreach ( $override as $key => $value ) {
			if ( isset( $base[ $key ] ) && is_array( $base[ $key ] ) && is_array( $value ) ) {
				$is_list      = array_is_list( $base[ $key ] ) && array_is_list( $value );
				$base[ $key ] = ( $is_list && ! $merge_lists )
					? $value
					: self::deep_merge( $base[ $key ], $value, $merge_lists );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * Dot get.
	 *
	 * @param array  $data Data.
	 * @param string $key Key.
	 * @param mixed  $default Default value.
	 * @return mixed Return value.
	 */
	private static function dot_get( array $data, string $key, mixed $default ): mixed {
		$keys    = explode( '.', $key );
		$current = $data;
		foreach ( $keys as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return $default;
			}
			$current = $current[ $segment ];
		}
		return $current;
	}

	/**
	 * Decode meta.
	 *
	 * @param mixed $raw Raw.
	 * @return array Resulting array.
	 */
	private function decode_meta( mixed $raw ): array {
		if ( ! $raw ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Get excerpt.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string Resulting string.
	 */
	private function get_excerpt( \WP_Post $post ): string {
		return $post->post_excerpt ? wp_strip_all_tags( $post->post_excerpt ) : '';
	}

	/**
	 * Get post type label.
	 *
	 * @param string $pt Pt.
	 * @return string Resulting string.
	 */
	private function get_post_type_label( string $pt ): string {
		$obj = get_post_type_object( $pt );
		return $obj ? $obj->labels->singular_name : $pt;
	}

	/**
	 * Get post type label for tax.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string Resulting string.
	 */
	private function get_post_type_label_for_tax( string $taxonomy ): string {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || empty( $tax->object_type ) ) {
			return '';
		}
		return $this->get_post_type_label( $tax->object_type[0] );
	}
}
