<?php
/**
 * MM_Mod_Schema — JSON-LD @graph builder.
 *
 * Emits a single <script type="application/ld+json"> block per page.
 * Nodes are added by this module and by MM_Mod_Local and MM_Mod_Author.
 * All nodes share a consistent @id convention: {site_url}#{fragment}.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mod schema.
 *
 * @package Metamanager
 */
class MM_Mod_Schema extends MM_Mod_Base {

	/**
	 * Schema types outside the CreativeWork family (verified against the
	 * schema.org validator: Place/Intangible lineages reject headline,
	 * datePublished, dateModified, isPartOf, breadcrumb, primaryImageOfPage).
	 * These render as content nodes with the Thing-level base (name +
	 * mainEntityOfPage) while the WebPage node carries the page fields.
	 */
	private const NON_CREATIVEWORK_TYPES = array(
		'TouristDestination',
		'Place',
		'Event',
		'Service',
		'TouristTrip',
		'Vehicle',
		'Product',
		'LocalBusiness',
		'Organization',
	);

	/**
	 * Populate.
	 *
	 * @param array            $data Data.
	 * @param MM_Page_Context  $context Context.
	 * @param MM_Site_Settings $settings Settings.
	 */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		// Media schema: scan content for actual media on singular pages.
		if ( $context->is_singular() ) {
			$this->add_media_schema( $data, $context );
		}

		// WebSite node (emitted on every page, referenced by other nodes).
		$this->add_website_node( $data, $settings );

		// WebPage / subtype node.
		$this->add_webpage_node( $data, $context, $settings );

		// BreadcrumbList.
		if ( $settings->get( 'schema.breadcrumbs', true ) ) {
			$this->add_breadcrumb_node( $data, $context, $settings );
		}

		// Content-type-specific nodes.
		if ( $context->is_singular() ) {
			$this->add_content_node( $data, $context, $settings );
		} elseif ( ( $context->is_tax() || $context->is_category() || $context->is_tag() || $context->is_post_type_archive() )
			&& $settings->get( 'schema.archive_itemlist', true ) ) {
			$this->add_itemlist_node( $data, $context );
		}

		// Archive media schema: scan archive pages for media when enabled.
		if ( ! $context->is_singular() && $settings->get( 'schema.archive_media', false ) ) {
			$this->add_archive_media_schema( $data, $context );
		}

		// Custom JSON-LD appended verbatim (power user escape hatch).
		$custom = trim( $settings->get( 'schema.custom_json_ld', '' ) );
		if ( $custom ) {
			$decoded = json_decode( $custom, true );
			if ( is_array( $decoded ) ) {
				$data['schema'][] = $decoded;
			}
		}
	}

	// -------------------------------------------------------------------------
	// WebSite
	// -------------------------------------------------------------------------

	/**
	 * Add website node.
	 *
	 * @param array            $data Data.
	 * @param MM_Site_Settings $settings Settings.
	 */
	private function add_website_node( array &$data, MM_Site_Settings $settings ): void {
		$node = array(
			'@type' => 'WebSite',
			'@id'   => $this->site_id( 'website' ),
			'url'   => $this->site_url(),
			'name'  => get_bloginfo( 'name' ),
		);
		if ( get_bloginfo( 'description' ) ) {
			$node['description'] = get_bloginfo( 'description' );
		}

		// SearchAction (Sitelinks search box) — disabled by default.
		// Deprecated by Google Nov 2024; still valid schema.org but ignored.
		if ( $settings->get( 'schema.website_searchaction', false ) ) {
			$node['potentialAction'] = array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			);
		}

		$this->add_node( $data, $node );

		// SiteNavigationElement: emit when nav menus exist.
		$this->add_navigation_node( $data );
	}

	// -------------------------------------------------------------------------
	// SiteNavigationElement
	// -------------------------------------------------------------------------

	/**
	 * Add navigation node.
	 *
	 * @param array $data Data.
	 */
	private function add_navigation_node( array &$data ): void {
		$menu = self::get_primary_menu();
		if ( ! $menu || empty( $menu['items'] ) ) {
			return;
		}

		// Only emit nav schema if the designated menu was actually rendered on this page.
		if ( ! in_array( $menu['id'], MM_Mod_Base::$rendered_menus, true ) ) {
			return;
		}

		$nav_items = array_map(
			function ( $item ) {
				return array(
					'@type'    => 'SiteNavigationElement',
					'name'     => $item['name'],
					'url'      => $item['url'],
					'position' => $item['position'],
				);
			},
			$menu['items']
		);

		$this->add_node(
			$data,
			array(
				'@type'   => 'SiteNavigationElement',
				'@id'     => $this->site_id( 'navigation' ),
				'name'    => $menu['name'],
				'hasPart' => $nav_items,
			)
		);

		// Link WebSite node to navigation.
		foreach ( $data['schema'] as &$node ) {
			if ( ( $node['@type'] ?? '' ) === 'WebSite' ) {
				$node['hasPart'] = array( '@id' => $this->site_id( 'navigation' ) );
				break;
			}
		}
	}

	// -------------------------------------------------------------------------
	// WebPage
	// -------------------------------------------------------------------------

	/**
	 * Add webpage node.
	 *
	 * @param array            $data Data.
	 * @param MM_Page_Context  $context Context.
	 * @param MM_Site_Settings $settings Settings.
	 */
	private function add_webpage_node( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		$url   = $this->current_url();
		$title = $data['title'] ?? get_the_title();

		$type = 'WebPage';
		if ( $context->is_singular() ) {
			$post = $context->get_post();
			if ( $post ) {
				// CPT posts use their schema type directly.
				if ( MM_Schema_Post_Types::is_schema_cpt( $post->post_type ) ) {
					$schema_type = MM_Schema_Post_Types::slug_to_type( $post->post_type );
					// Content types get WebPage as the container; the content node is separate.
					if ( in_array( $schema_type, array( 'BlogPosting', 'Article', 'HowTo', 'Product', 'Event', 'Service', 'TouristTrip', 'Vehicle', 'Course' ), true ) ) {
						$type = 'WebPage';
					} else {
						$type = $schema_type;
					}
				} else {
					// Page templates use their schema type directly.
					$template = MM_Schema_Post_Types::get_post_template( $post );
					if ( $template ) {
						$type = MM_Schema_Post_Types::template_to_type( $template ) ?? 'WebPage';
					} else {
						// Regular posts = BlogPosting, pages = WebPage.
						$type = ( 'post' === $post->post_type ) ? 'WebPage' : 'WebPage';
					}
				}
			}
		} elseif ( $context->is_front_page() ) {
			$type = 'WebPage';
		} elseif ( $context->is_author() ) {
			$type = 'ProfilePage';
		} elseif ( $context->is_search() ) {
			$type = 'SearchResultsPage';
		}

		// Non-CreativeWork types (TouristDestination, Event, Service, …) are
		// Places/Intangibles: the WebPage node stays the container and the
		// entity is emitted as a content node, so WebPage-only fields (dates,
		// breadcrumb, primaryImageOfPage) never land on the entity.
		$entity_type = null;
		if ( in_array( $type, self::NON_CREATIVEWORK_TYPES, true ) ) {
			$entity_type = $type;
			$type        = 'WebPage';
		}

		$node = array(
			'@type'    => $type,
			'@id'      => $url . '#webpage',
			'url'      => $url,
			'name'     => $title,
			'isPartOf' => array( '@id' => $this->site_id( 'website' ) ),
		);

		if ( $entity_type ) {
			$node['mainEntity'] = array( '@id' => $url . '#' . strtolower( $entity_type ) );
		}

		// ContactPage: reference the Organization node (business props live there, not on WebPage).
		if ( 'ContactPage' === $type && $context->is_singular() ) {
			$biz = $settings->all_business();
			if ( ! empty( $biz['name'] ) ) {
				$node['name'] = sanitize_text_field( $biz['name'] );
			}
			$node['about'] = array( '@id' => $this->site_id( 'organization' ) );
		}

		// AboutPage: reference the Organization node (business props live there, not on WebPage).
		if ( 'AboutPage' === $type && $context->is_singular() ) {
			$biz = $settings->all_business();
			if ( ! empty( $biz['name'] ) ) {
				$node['name'] = sanitize_text_field( $biz['name'] );
			}
			if ( ! empty( $biz['description'] ) ) {
				$node['description'] = sanitize_text_field( $biz['description'] );
			}
			$node['about'] = array( '@id' => $this->site_id( 'organization' ) );
		}

		// Description from meta.
		foreach ( $data['meta'] as $mt ) {
			if ( ( $mt['name'] ?? '' ) === 'description' ) {
				$node['description'] = $mt['content'];
				break;
			}
		}

		// CollectionPage: link to events in the collection.
		if ( 'CollectionPage' === $type && $context->is_singular() ) {
			$post = $context->get_post();
			if ( $post ) {
				$events = get_posts(
					array(
						'post_type'      => 'mm_event',
						'post_status'    => 'publish',
						'posts_per_page' => 50,
						'orderby'        => 'date',
						'order'          => 'DESC',
					)
				);
				if ( ! empty( $events ) ) {
					$node['hasPart'] = array_map(
						function ( $e ) {
							return array( '@id' => get_permalink( $e->ID ) . '#event' );
						},
						$events
					);
				}
			}
		}

		// Breadcrumb reference (added after breadcrumb node is built).
		if ( $settings->get( 'schema.breadcrumbs', true ) ) {
			$node['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
		}

		// Date signals (singular posts).
		if ( $context->is_singular() && $context->get_post() ) {
			$post                  = $context->get_post();
			$node['datePublished'] = get_the_date( 'c', $post );
			$node['dateModified']  = get_the_modified_date( 'c', $post );
		}

		// Primary image reference.
		$og_image = $this->get_og_image( $data );
		if ( $og_image ) {
			$node['primaryImageOfPage'] = array( '@id' => $url . '#primaryimage' );
			$image_node                 = array(
				'@type' => 'ImageObject',
				'@id'   => $url . '#primaryimage',
				'url'   => $og_image['url'],
			);
			// Only emit width/height as QuantitativeValue when valid positive integers.
			if ( ! empty( $og_image['width'] ) && is_numeric( $og_image['width'] ) && (int) $og_image['width'] > 0 ) {
				$image_node['width'] = array(
					'@type'    => 'QuantitativeValue',
					'value'    => (int) $og_image['width'],
					'unitCode' => 'PIX',
				);
			}
			if ( ! empty( $og_image['height'] ) && is_numeric( $og_image['height'] ) && (int) $og_image['height'] > 0 ) {
				$image_node['height'] = array(
					'@type'    => 'QuantitativeValue',
					'value'    => (int) $og_image['height'],
					'unitCode' => 'PIX',
				);
			}
			$this->add_node( $data, $image_node );
		}

		$this->add_node( $data, $node );
	}

	// -------------------------------------------------------------------------
	// BreadcrumbList
	// -------------------------------------------------------------------------

	/**
	 * Add breadcrumb node.
	 *
	 * @param array            $data Data.
	 * @param MM_Page_Context  $context Context.
	 * @param MM_Site_Settings $settings Settings.
	 */
	private function add_breadcrumb_node( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		$items = array();
		$pos   = 1;

		// Homepage always first.
		$items[] = $this->crumb( $pos++, get_bloginfo( 'name' ), home_url( '/' ) );

		if ( $context->is_singular() ) {
			$post = $context->get_post();
			if ( $post ) {
				// Parent pages hierarchy.
				$ancestors = array_reverse( get_post_ancestors( $post ) );
				foreach ( $ancestors as $ancestor_id ) {
					$items[] = $this->crumb( $pos++, get_the_title( $ancestor_id ), get_permalink( $ancestor_id ) );
				}
				// Primary category for posts.
				if ( 'post' === $post->post_type ) {
					$cats = get_the_category( $post->ID );
					if ( $cats ) {
						$cat_link = get_term_link( $cats[0] );
						if ( is_string( $cat_link ) ) {
							$items[] = $this->crumb( $pos++, $cats[0]->name, $cat_link );
						}
					}
				}
				// The post itself.
				$meta = $settings->get_post_meta( $post->ID );
				// Read breadcrumb label from CPT meta first, then legacy _mm_meta.
				$label = get_post_meta( $post->ID, 'mm_breadcrumb_label', true );
				if ( ! $label ) {
					$label = ! empty( $meta['breadcrumb_label'] ) ? $meta['breadcrumb_label'] : get_the_title( $post );
				}
				$items[] = $this->crumb( $pos++, $label, get_permalink( $post ) );
			}
		} elseif ( $context->is_tax() || $context->is_category() || $context->is_tag() ) {
			$term = $context->get_term();
			if ( $term ) {
				// Ancestor terms.
				$ancestors = get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' );
				foreach ( array_reverse( $ancestors ) as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, $term->taxonomy );
					if ( $ancestor && ! is_wp_error( $ancestor ) ) {
						$anc_link = get_term_link( $ancestor );
						if ( is_string( $anc_link ) ) {
							$items[] = $this->crumb( $pos++, $ancestor->name, $anc_link );
						}
					}
				}
				$term_link = get_term_link( $term );
				if ( is_string( $term_link ) ) {
					$items[] = $this->crumb( $pos++, $term->name, $term_link );
				}
			}
		} elseif ( $context->is_post_type_archive() ) {
			$pt   = $context->get_post_type();
			$name = get_post_type_object( $pt ) ? get_post_type_object( $pt )->labels->name : $pt;
			$url  = get_post_type_archive_link( $pt );
			if ( ! $url ) {
				$url = '';
			}
			$items[] = $this->crumb( $pos++, $name, $url );
		} elseif ( $context->is_author() ) {
			$author  = $context->get_author();
			$items[] = $this->crumb( $pos++, $author ? $author->display_name : 'Author', $author ? get_author_posts_url( $author->ID ) : '' );
		}

		if ( count( $items ) <= 1 ) {
			return; // No breadcrumb for homepage alone.
		}

		$url = $this->current_url();
		$this->add_node(
			$data,
			array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $url . '#breadcrumb',
				'itemListElement' => $items,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Content nodes (BlogPosting, Article, HowTo, Product, Event, Service, etc.)
	// -------------------------------------------------------------------------

	/**
	 * Add content node.
	 *
	 * @param array            $data Data.
	 * @param MM_Page_Context  $context Context.
	 * @param MM_Site_Settings $settings Settings.
	 */
	private function add_content_node( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		$post = $context->get_post();
		if ( ! $post ) {
			return;
		}

		// Determine schema type: CPT slug → schema type, or page template → schema type.
		$type = null;
		if ( MM_Schema_Post_Types::is_schema_cpt( $post->post_type ) ) {
			$type = MM_Schema_Post_Types::slug_to_type( $post->post_type );
		}
		if ( ! $type ) {
			$template = MM_Schema_Post_Types::get_post_template( $post );
			if ( $template ) {
				$type = MM_Schema_Post_Types::template_to_type( $template );
			}
		}
		if ( ! $type ) {
			// Regular posts = BlogPosting, pages = WebPage.
			$type = ( 'post' === $post->post_type ) ? 'BlogPosting' : 'WebPage';
		}

		// WebPage and its subtypes (AboutPage, ContactPage, Calendar) are already on the WebPage node; skip.
		if ( in_array( $type, array( 'WebPage', 'WebSite', 'AboutPage', 'ContactPage', 'CollectionPage' ), true ) ) {
			return;
		}

		$url = get_permalink( $post );

		$node = array(
			'@type' => $type,
			'@id'   => $url . '#' . strtolower( $type ),
			'url'   => $url,
		);

		if ( in_array( $type, self::NON_CREATIVEWORK_TYPES, true ) ) {
			// Place/Intangible, not CreativeWork: name instead of headline,
			// and no date/isPartOf fields (not in their vocabulary).
			$node['name']             = get_the_title( $post );
			$node['mainEntityOfPage'] = array( '@id' => $url . '#webpage' );
		} else {
			$node['headline']      = get_the_title( $post );
			$node['datePublished'] = get_the_date( 'c', $post );
			$node['dateModified']  = get_the_modified_date( 'c', $post );
			$node['isPartOf']      = array( '@id' => $url . '#webpage' );
		}

		// Description.
		foreach ( $data['meta'] as $mt ) {
			if ( ( $mt['name'] ?? '' ) === 'description' ) {
				$node['description'] = $mt['content'];
				break;
			}
		}

		// Image.
		$og_image = $this->get_og_image( $data );
		if ( $og_image ) {
			$node['image'] = array( '@id' => $url . '#primaryimage' );
		}

		// Author — link to Person node (added by Author module or built inline).
		if ( in_array( $type, array( 'BlogPosting', 'Article' ), true ) ) {
			$author = get_userdata( (int) $post->post_author );
			if ( $author && $settings->get( 'authors.person_schema', true ) ) {
				$node['author']    = array( '@id' => get_author_posts_url( $author->ID ) . '#person' );
				$node['publisher'] = array( '@id' => $this->site_id( 'organization' ) );
			}
		}

		// Merge per-post schema field overrides (Event dates, prices, addresses, etc.).
		// Read from CPT meta first, then legacy _mm_meta schema_fields.
		$schema_fields = array();
		if ( MM_Schema_Post_Types::is_schema_cpt( $post->post_type ) ) {
			$cpt_fields = get_post_meta( $post->ID, 'mm_schema_fields', true );
			if ( is_array( $cpt_fields ) ) {
				$schema_fields = $cpt_fields;
			}
		}
		if ( empty( $schema_fields ) ) {
			$post_meta     = $settings->get_post_meta( $post->ID );
			$schema_fields = $post_meta['schema_fields'] ?? array();
		}
		if ( 'Product' === $type && $this->is_woocommerce_active() ) {
			$wc_data = $this->get_woocommerce_product_data( $post->ID );
			if ( ! empty( $wc_data ) ) {
				$additions = MM_Schema_Types::build_node_additions( $schema_fields, $type );
				// WC data is base, manual overrides merge on top.
				$node = array_merge( $node, $wc_data, $additions );
			} else {
				// WC product not found — fall back to manual fields only.
				if ( ! empty( $schema_fields ) ) {
					$additions = MM_Schema_Types::build_node_additions( $schema_fields, $type );
					if ( ! empty( $additions ) ) {
						$node = array_merge( $node, $additions );
					}
				}
			}
		} elseif ( ! empty( $schema_fields ) ) {
			$additions = MM_Schema_Types::build_node_additions( $schema_fields, $type );
			if ( ! empty( $additions ) ) {
				$node = array_merge( $node, $additions );
			}
		}

		// Service: add areaServed and provider from business profile.
		if ( 'Service' === $type ) {
			$service_areas = $settings->get_business( 'service_areas', array() );
			if ( ! empty( $service_areas ) && is_array( $service_areas ) ) {
				$node['areaServed'] = count( $service_areas ) === 1 ? $service_areas[0] : array_values( $service_areas );
			}
			$node['provider'] = array( '@id' => $this->site_id( 'organization' ) );
		}

		// TouristTrip: merge vessel and destination relationships.
		if ( 'TouristTrip' === $type ) {
			// Vessel reference.
			$vessel_id = (int) get_post_meta( $post->ID, '_mm_vessel_id', true );
			if ( $vessel_id && get_post_status( $vessel_id ) === 'publish' ) {
				$vessel_url = get_permalink( $vessel_id );
				// Vessel reference: 'vehicle' is not a valid TouristTrip property; omit.
			}
			// Destination reference.
			$dest_id = (int) get_post_meta( $post->ID, '_mm_destination_id', true );
			if ( $dest_id && get_post_status( $dest_id ) === 'publish' ) {
				$dest_url          = get_permalink( $dest_id );
				$node['itinerary'] = array(
					'@type'           => 'ItemList',
					'itemListElement' => array(
						array(
							'@type'    => 'ListItem',
							'position' => 1,
							'item'     => array( '@id' => $dest_url . '#touristdestination' ),
						),
					),
				);
			}
		}

		$this->add_node( $data, $node );
	}

	// -------------------------------------------------------------------------
	// ItemList (taxonomy archives)
	// -------------------------------------------------------------------------

	/**
	 * Add itemlist node.
	 *
	 * @param array           $data Data.
	 * @param MM_Page_Context $context Context.
	 */
	private function add_itemlist_node( array &$data, MM_Page_Context $context ): void {
		global $wp_query;
		$posts = $wp_query->posts ?? array();
		if ( empty( $posts ) ) {
			return;
		}

		$items = array();
		$pos   = 1;
		foreach ( $posts as $post ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'url'      => get_permalink( $post ),
				'name'     => get_the_title( $post ),
			);
		}

		$name = '';
		$url  = '';
		if ( $context->is_post_type_archive() ) {
			$pt   = $context->get_post_type();
			$name = get_post_type_object( $pt ) ? get_post_type_object( $pt )->labels->name : $pt;
			$url  = get_post_type_archive_link( $pt );
			if ( ! $url ) {
				$url = '';
			}
		} else {
			$term = $context->get_term();
			if ( ! $term ) {
				return;
			}
			$name = $term->name;
			$url  = get_term_link( $term );
			$url  = is_string( $url ) ? $url : '';
		}

		$this->add_node(
			$data,
			array(
				'@type'           => 'ItemList',
				'@id'             => $url . '#itemlist',
				'name'            => $name,
				'itemListElement' => $items,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Handle crumb.
	 *
	 * @param int    $pos Pos.
	 * @param string $name Name.
	 * @param string $url URL.
	 * @return array Resulting array.
	 */
	private function crumb( int $pos, string $name, string $url ): array {
		return array(
			'@type'    => 'ListItem',
			'position' => $pos,
			'name'     => $name,
			'item'     => $url,
		);
	}

	/**
	 * Current URL.
	 *
	 * @return string Resulting string.
	 */
	private function current_url(): string {
		// Canonical URL for singular content: REQUEST_URI carries query
		// strings (?utm=...) that would make @ids unstable and dangle the
		// cross-references built from get_permalink().
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				return get_permalink( $post );
			}
		}
		return home_url( ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' ) );
	}

	/**
	 * Get OG image.
	 *
	 * @param array $data Data.
	 * @return array|null Return value.
	 */
	private function get_og_image( array $data ): ?array {
		foreach ( $data['meta'] as $mt ) {
			if ( ( $mt['property'] ?? '' ) === 'og:image' && $mt['content'] ) {
				$img = array(
					'url'    => $mt['content'],
					'width'  => 0,
					'height' => 0,
				);
				foreach ( $data['meta'] as $m2 ) {
					if ( ( $m2['property'] ?? '' ) === 'og:image:width' ) {
						$img['width'] = (int) $m2['content']; }
					if ( ( $m2['property'] ?? '' ) === 'og:image:height' ) {
						$img['height'] = (int) $m2['content']; }
				}
				return $img;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// WooCommerce integration
	// -------------------------------------------------------------------------

	/**
	 * Check if WooCommerce is active.
	 */
	private function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Pull normalized Product schema data from WooCommerce product meta.
	 *
	 * Returns an array suitable for merging into a JSON-LD Product node.
	 * Only includes fields that have actual values in WooCommerce.
	 *
	 * @param int $post_id Product post ID.
	 * @return array<string, mixed>
	 */
	private function get_woocommerce_product_data( int $post_id ): array {
		if ( ! $this->is_woocommerce_active() ) {
			return array();
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return array();
		}

		$out = array();

		// Price.
		$regular = $product->get_regular_price();
		$sale    = $product->get_sale_price();
		$price   = ( '' !== $sale && false !== $sale ) ? $sale : $regular;
		if ( '' !== $price && false !== $price ) {
			$out['offers'] = array(
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => get_woocommerce_currency(),
			);

			// Availability.
			$stock = $product->get_stock_status();
			$map   = array(
				'instock'     => 'InStock',
				'outofstock'  => 'OutOfStock',
				'onbackorder' => 'PreOrder',
			);
			if ( isset( $map[ $stock ] ) ) {
				$out['offers']['availability'] = 'https://schema.org/' . $map[ $stock ];
			}

			// SKU.
			$sku = $product->get_sku();
			if ( $sku ) {
				$out['offers']['sku'] = $sku;
			}
		}

		// Brand — from _brand meta or pa_brand attribute.
		$brand = $product->get_meta( '_brand' );
		if ( ! $brand ) {
			$attrs = $product->get_attributes();
			if ( isset( $attrs['pa_brand'] ) ) {
				$terms = $attrs['pa_brand']->get_terms();
				if ( $terms && ! is_wp_error( $terms ) ) {
					$brand = $terms[0]->name;
				}
			}
		}
		if ( $brand ) {
			$out['brand'] = array(
				'@type' => 'Brand',
				'name'  => $brand,
			);
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Media schema — content-based detection
	// -------------------------------------------------------------------------

	/**
	 * Scan post content for actual media elements and emit schema nodes.
	 *
	 * Only media that is present in the rendered HTML gets schema output.
	 * This prevents manipulation signals for media that doesn't exist on the page.
	 *
	 * @param array           $data Data.
	 * @param MM_Page_Context $context Context.
	 */
	private function add_media_schema( array &$data, MM_Page_Context $context ): void {
		$post = $context->get_post();
		if ( ! $post ) {
			return;
		}

		// Attachment pages: the media IS the page content.
		if ( 'attachment' === $post->post_type ) {
			$this->add_attachment_page_schema( $data, $post );
			return;
		}

		// Singular posts/pages: scan content for media elements.
		$media = MM_Media_Detector::scan_content( $post->post_content );

		foreach ( $media as $item ) {
			switch ( $item['type'] ) {
				case 'image':
					$this->add_image_schema( $data, $item, $post );
					break;
				case 'video':
					$this->add_video_schema( $data, $item, $post );
					break;
				case 'audio':
					$this->add_audio_schema( $data, $item, $post );
					break;
				case 'document':
					$this->add_document_schema( $data, $item );
					break;
			}
		}

		// Featured image: add schema if present on page and not already added from content.
		$this->add_featured_image_schema( $data, $post, $media );
	}

	/**
	 * Emit ImageObject schema for featured image if present on page.
	 *
	 * @param array    $data Data.
	 * @param \WP_Post $post Post object.
	 * @param array    $content_media Content media.
	 */
	private function add_featured_image_schema( array &$data, \WP_Post $post, array $content_media ): void {
		$thumb_id = (int) get_post_thumbnail_id( $post->ID );
		if ( ! $thumb_id ) {
			return;
		}

		$thumb_url = wp_get_attachment_url( $thumb_id );
		if ( ! $thumb_url ) {
			return;
		}

		// Check if this image was already added from content scanning.
		foreach ( $content_media as $item ) {
			if ( isset( $item['attachment_id'] ) && $item['attachment_id'] === $thumb_id ) {
				return; // Already added.
			}
			if ( isset( $item['url'] ) && $item['url'] === $thumb_url ) {
				return; // Already added.
			}
		}

		// Add featured image schema.
		$src  = wp_get_attachment_image_src( $thumb_id, 'full' );
		$item = array(
			'type'          => 'image',
			'url'           => $thumb_url,
			'attachment_id' => $thumb_id,
			'width'         => $src ? $src[1] : 0,
			'height'        => $src ? $src[2] : 0,
			'alt'           => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
			'caption'       => trim( get_post( $thumb_id )->post_excerpt ),
		);

		$this->add_image_schema( $data, $item, $post );
	}

	/**
	 * Emit ImageObject schema for featured images on archive pages.
	 *
	 * @param array           $data Data.
	 * @param MM_Page_Context $context Context.
	 */
	private function add_archive_media_schema( array &$data, MM_Page_Context $context ): void {
		global $wp_query;

		if ( ! $wp_query->have_posts() ) {
			return;
		}

		$added_ids = array();

		foreach ( $wp_query->posts as $query_post ) {
			$post = get_post( $query_post );

			if ( ! $post ) {
				continue;
			}

			$thumb_id = (int) get_post_thumbnail_id( $post->ID );
			if ( ! $thumb_id || in_array( $thumb_id, $added_ids, true ) ) {
				continue;
			}

			$thumb_url = wp_get_attachment_url( $thumb_id );
			if ( ! $thumb_url ) {
				continue;
			}

			$src  = wp_get_attachment_image_src( $thumb_id, 'full' );
			$item = array(
				'type'          => 'image',
				'url'           => $thumb_url,
				'attachment_id' => $thumb_id,
				'width'         => $src ? $src[1] : 0,
				'height'        => $src ? $src[2] : 0,
				'alt'           => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
				'caption'       => trim( get_post( $thumb_id )->post_excerpt ),
			);

			$this->add_image_schema( $data, $item, $post );
			$added_ids[] = $thumb_id;
		}
	}

	/**
	 * Emit schema for an attachment page (the media IS the page).
	 *
	 * @param array    $data Data.
	 * @param \WP_Post $post Post object.
	 */
	private function add_attachment_page_schema( array &$data, \WP_Post $post ): void {
		$mime = (string) get_post_mime_type( $post->ID );
		$url  = wp_get_attachment_url( $post->ID );
		if ( ! $url ) {
			return;
		}

		if ( MM_Metadata::is_video_mime( $mime ) ) {
			$this->add_video_schema(
				$data,
				array(
					'type'          => 'video',
					'url'           => $url,
					'attachment_id' => $post->ID,
					'duration'      => (int) get_post_meta( $post->ID, MM_Metadata::META_DURATION, true ),
				),
				$post
			);
		} elseif ( MM_Metadata::is_audio_mime( $mime ) ) {
			$this->add_audio_schema(
				$data,
				array(
					'type'          => 'audio',
					'url'           => $url,
					'attachment_id' => $post->ID,
					'duration'      => (int) get_post_meta( $post->ID, MM_Metadata::META_DURATION, true ),
				),
				$post
			);
		} elseif ( MM_Metadata::is_pdf_mime( $mime ) ) {
			$this->add_document_schema(
				$data,
				array(
					'type'          => 'document',
					'url'           => $url,
					'attachment_id' => $post->ID,
				)
			);
		} elseif ( wp_attachment_is_image( $post->ID ) ) {
			$src = wp_get_attachment_image_src( $post->ID, 'full' );
			if ( $src ) {
				$this->add_image_schema(
					$data,
					array(
						'type'          => 'image',
						'url'           => $src[0],
						'attachment_id' => $post->ID,
						'width'         => $src[1],
						'height'        => $src[2],
						'alt'           => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
						'caption'       => trim( $post->post_excerpt ),
					),
					$post
				);
			}
		}
	}

	/**
	 * Emit an ImageObject schema node for a detected image.
	 *
	 * @param array    $data Data.
	 * @param array    $item Item.
	 * @param \WP_Post $post Post object.
	 */
	private function add_image_schema( array &$data, array $item, \WP_Post $post ): void {
		$meta = static fn( string $key ): string => (string) get_post_meta( $item['attachment_id'], $key, true );

		// Check if media is protected (S-10): use watermarked preview for schema.
		$is_protected = 'yes' === get_post_meta( $item['attachment_id'], '_mm_media_protected', true );
		$schema_url   = $item['url'];
		if ( $is_protected ) {
			$preview_url = get_post_meta( $item['attachment_id'], '_mm_media_watermarked_url', true );
			if ( $preview_url ) {
				$schema_url = $preview_url;
			}
		}

		$node = array(
			'@type'      => 'ImageObject',
			'@id'        => $item['url'] . '#image',
			'url'        => $schema_url,
			'contentUrl' => $schema_url,
		);

		if ( $item['width'] && is_numeric( $item['width'] ) && (int) $item['width'] > 0 ) {
			$node['width'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => (int) $item['width'],
				'unitCode' => 'PIX',
			);
		}
		if ( $item['height'] && is_numeric( $item['height'] ) && (int) $item['height'] > 0 ) {
			$node['height'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => (int) $item['height'],
				'unitCode' => 'PIX',
			);
		}

		// Enrich from attachment metadata.
		if ( $item['attachment_id'] ) {
			$headline = $meta( MM_Metadata::META_HEADLINE );
			$name     = '' !== $headline ? $headline : trim( $post->post_title );
			if ( '' !== $name ) {
				$node['name'] = $name;
			}

			if ( '' !== $item['alt'] ) {
				$node['alternativeHeadline'] = $item['alt'];
			}
			if ( '' !== $item['caption'] ) {
				$node['caption'] = $item['caption'];
			}

			// Attribution.
			$creator = $meta( MM_Metadata::META_CREATOR );
			if ( '' !== $creator ) {
				$node['creator'] = array(
					'@type' => 'Person',
					'name'  => $creator,
				);
			}
			$copyright = $meta( MM_Metadata::META_COPYRIGHT );
			if ( '' !== $copyright ) {
				$node['copyrightNotice'] = $copyright;
			}
			$owner = $meta( MM_Metadata::META_OWNER );
			if ( '' !== $owner ) {
				$node['copyrightHolder'] = array(
					'@type' => 'Organization',
					'name'  => $owner,
				);
			}

			// Classification.
			$keywords = $meta( MM_Metadata::META_KEYWORDS );
			if ( '' !== $keywords ) {
				$kw = array_values( array_filter( array_map( 'trim', explode( ';', $keywords ) ) ) );
				if ( $kw ) {
					$node['keywords'] = $kw;
				}
			}

			// Date.
			$date = $meta( MM_Metadata::META_DATE );
			if ( '' !== $date ) {
				$node['dateCreated'] = $date;
			}

			// Location.
			$this->add_location_to_node( $node, $item['attachment_id'] );

			// Thumbnail (medium size if different from full).
			$thumb = wp_get_attachment_image_src( $item['attachment_id'], 'medium' );
			if ( $thumb && $thumb[0] !== $item['url'] ) {
				$node['thumbnail'] = array(
					'@type' => 'ImageObject',
					'url'   => $thumb[0],
				);
			}
		} else {
			// Minimal schema from HTML only.
			$node['name'] = $item['alt'] ? $item['alt'] : basename( wp_parse_url( $item['url'], PHP_URL_PATH ) );
		}

		$this->add_node( $data, $node );
	}

	/**
	 * Emit a VideoObject schema node for a detected video.
	 *
	 * @param array    $data Data.
	 * @param array    $item Item.
	 * @param \WP_Post $post Post object.
	 */
	private function add_video_schema( array &$data, array $item, \WP_Post $post ): void {
		$meta = static fn( string $key ): string => (string) get_post_meta( $item['attachment_id'], $key, true );

		// Check if media is protected (S-10): use watermarked preview for schema.
		$is_protected = 'yes' === get_post_meta( $item['attachment_id'], '_mm_media_protected', true );
		$schema_url   = $item['url'];
		if ( $is_protected ) {
			$preview_url = get_post_meta( $item['attachment_id'], '_mm_media_watermarked_url', true );
			if ( $preview_url ) {
				$schema_url = $preview_url;
			}
		}

		$node = array(
			'@type'      => 'VideoObject',
			'@id'        => $item['url'] . '#video',
			'url'        => $schema_url,
			'contentUrl' => $schema_url,
			'uploadDate' => gmdate( 'Y-m-d', strtotime( $post->post_date_gmt ) ),
		);

		if ( $item['attachment_id'] ) {
			$headline = $meta( MM_Metadata::META_HEADLINE );
			$name     = '' !== $headline ? $headline : trim( $post->post_title );
			if ( '' !== $name ) {
				$node['name'] = $name;
			}

			$description = trim( $post->post_excerpt );
			if ( '' !== $description ) {
				$node['description'] = $description;
			}

			// Duration (ISO 8601).
			if ( $item['duration'] > 0 ) {
				$node['duration'] = $this->seconds_to_iso_duration( $item['duration'] );
			}

			// Thumbnail.
			$thumb_id  = (int) get_post_thumbnail_id( $item['attachment_id'] );
			$thumb_src = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'medium' ) : false;
			if ( $thumb_src ) {
				$node['thumbnailUrl'] = $thumb_src[0];
			}

			// Keywords.
			$keywords = $meta( MM_Metadata::META_KEYWORDS );
			if ( '' !== $keywords ) {
				$kw = array_values( array_filter( array_map( 'trim', explode( ';', $keywords ) ) ) );
				if ( $kw ) {
					$node['keywords'] = implode( ', ', array_slice( $kw, 0, 32 ) );
				}
			}
		} else {
			$node['name'] = basename( wp_parse_url( $item['url'], PHP_URL_PATH ) );
		}

		$this->add_node( $data, $node );
	}

	/**
	 * Emit an AudioObject schema node for a detected audio file.
	 *
	 * @param array    $data Data.
	 * @param array    $item Item.
	 * @param \WP_Post $post Post object.
	 */
	private function add_audio_schema( array &$data, array $item, \WP_Post $post ): void {
		$meta = static fn( string $key ): string => (string) get_post_meta( $item['attachment_id'], $key, true );

		$mime = $item['attachment_id']
			? (string) get_post_mime_type( $item['attachment_id'] )
			: 'audio/mpeg';

		// Check if media is protected (S-10): use watermarked preview for schema.
		$is_protected = 'yes' === get_post_meta( $item['attachment_id'], '_mm_media_protected', true );
		$schema_url   = $item['url'];
		if ( $is_protected ) {
			$preview_url = get_post_meta( $item['attachment_id'], '_mm_media_watermarked_url', true );
			if ( $preview_url ) {
				$schema_url = $preview_url;
			}
		}

		$node = array(
			'@type'          => 'AudioObject',
			'@id'            => $item['url'] . '#audio',
			'url'            => $schema_url,
			'contentUrl'     => $schema_url,
			'encodingFormat' => $mime,
		);

		if ( $item['attachment_id'] ) {
			$headline = $meta( MM_Metadata::META_HEADLINE );
			$name     = '' !== $headline ? $headline : trim( $post->post_title );
			if ( '' !== $name ) {
				$node['name'] = $name;
			}

			$description = trim( $post->post_excerpt );
			if ( '' !== $description ) {
				$node['description'] = $description;
			}

			if ( $item['duration'] > 0 ) {
				$node['duration'] = $this->seconds_to_iso_duration( $item['duration'] );
			}

			$copyright = $meta( MM_Metadata::META_COPYRIGHT );
			if ( '' !== $copyright ) {
				$node['copyrightNotice'] = $copyright;
			}
		} else {
			$node['name'] = basename( wp_parse_url( $item['url'], PHP_URL_PATH ) );
		}

		$this->add_node( $data, $node );
	}

	/**
	 * Emit a DigitalDocument schema node for a detected PDF.
	 *
	 * @param array $data Data.
	 * @param array $item Item.
	 */
	private function add_document_schema( array &$data, array $item ): void {
		// Check if media is protected (S-10): use watermarked preview for schema.
		$is_protected = 'yes' === get_post_meta( $item['attachment_id'] ?? 0, '_mm_media_protected', true );
		$schema_url   = $item['url'];
		if ( $is_protected ) {
			$preview_url = get_post_meta( $item['attachment_id'], '_mm_media_watermarked_url', true );
			if ( $preview_url ) {
				$schema_url = $preview_url;
			}
		}

		$node = array(
			'@type'          => 'DigitalDocument',
			'@id'            => $item['url'] . '#document',
			'url'            => $schema_url,
			'contentUrl'     => $schema_url,
			'encodingFormat' => 'application/pdf',
		);

		if ( $item['attachment_id'] ) {
			$att = get_post( $item['attachment_id'] );
			if ( $att ) {
				$headline = (string) get_post_meta( $item['attachment_id'], MM_Metadata::META_HEADLINE, true );
				$name     = '' !== $headline ? $headline : trim( $att->post_title );
				if ( '' !== $name ) {
					$node['name'] = $name;
				}
				$description = trim( $att->post_excerpt );
				if ( '' !== $description ) {
					$node['description'] = $description;
				}
			}
		} else {
			$node['name'] = basename( wp_parse_url( $item['url'], PHP_URL_PATH ) );
		}

		$this->add_node( $data, $node );
	}

	// -------------------------------------------------------------------------
	// Media schema helpers
	// -------------------------------------------------------------------------

	/**
	 * Add location to node.
	 *
	 * @param array $node Node.
	 * @param int   $attachment_id Attachment ID.
	 */
	private function add_location_to_node( array &$node, int $attachment_id ): void {
		$lat     = (string) get_post_meta( $attachment_id, MM_Metadata::META_GPS_LAT, true );
		$lon     = (string) get_post_meta( $attachment_id, MM_Metadata::META_GPS_LON, true );
		$alt_m   = (string) get_post_meta( $attachment_id, MM_Metadata::META_GPS_ALT, true );
		$city    = (string) get_post_meta( $attachment_id, MM_Metadata::META_CITY, true );
		$state   = (string) get_post_meta( $attachment_id, MM_Metadata::META_STATE, true );
		$country = (string) get_post_meta( $attachment_id, MM_Metadata::META_COUNTRY, true );

		$loc_parts = array_filter( array( $city, $state, $country ) );

		if ( '' !== $lat && '' !== $lon ) {
			$geo = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lon,
			);
			if ( '' !== $alt_m ) {
				$geo['elevation'] = (float) $alt_m;
			}
			$place = array(
				'@type' => 'Place',
				'geo'   => $geo,
			);
			if ( $loc_parts ) {
				$place['name'] = implode( ', ', $loc_parts );
			}
			$node['locationCreated'] = $place;
			$node['contentLocation'] = $place;
		} elseif ( $loc_parts ) {
			$place                   = array(
				'@type' => 'Place',
				'name'  => implode( ', ', $loc_parts ),
			);
			$node['locationCreated'] = $place;
			$node['contentLocation'] = $place;
		}
	}

	/**
	 * Seconds to iso duration.
	 *
	 * @param int $seconds Seconds.
	 * @return string Resulting string.
	 */
	private function seconds_to_iso_duration( int $seconds ): string {
		$h = (int) floor( $seconds / 3600 );
		$m = (int) floor( ( $seconds % 3600 ) / 60 );
		$s = $seconds % 60;
		$d = 'PT';
		if ( $h ) {
			$d .= $h . 'H'; }
		if ( $m ) {
			$d .= $m . 'M'; }
		if ( $s || ( ! $h && ! $m ) ) {
			$d .= $s . 'S'; }
		return $d;
	}
}
