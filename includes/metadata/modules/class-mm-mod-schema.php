<?php
/**
 * MM_Mod_Schema — JSON-LD @graph builder.
 *
 * Emits a single <script type="application/ld+json"> block per page.
 * Nodes are added by this module and by MM_Mod_Local and MM_Mod_Author.
 * All nodes share a consistent @id convention: {site_url}#{fragment}.
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Schema extends MM_Mod_Base {

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
		} elseif ( ( $context->is_tax() || $context->is_category() || $context->is_tag() )
			&& $settings->get( 'schema.archive_itemlist', true ) ) {
			$this->add_itemlist_node( $data, $context );
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

	private function add_website_node( array &$data, MM_Site_Settings $settings ): void {
		$node = [
			'@type'  => 'WebSite',
			'@id'    => $this->site_id( 'website' ),
			'url'    => $this->site_url(),
			'name'   => get_bloginfo( 'name' ),
		];
		if ( get_bloginfo( 'description' ) ) {
			$node['description'] = get_bloginfo( 'description' );
		}

		// SearchAction (Sitelinks search box) — disabled by default.
		// Deprecated by Google Nov 2024; still valid schema.org but ignored.
		if ( $settings->get( 'schema.website_searchaction', false ) ) {
			$node['potentialAction'] = [
				'@type'       => 'SearchAction',
				'target'      => [
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				],
				'query-input' => 'required name=search_term_string',
			];
		}

		$this->add_node( $data, $node );

		// SiteNavigationElement: emit when nav menus exist.
		$this->add_navigation_node( $data );
	}

	// -------------------------------------------------------------------------
	// SiteNavigationElement
	// -------------------------------------------------------------------------

	private function add_navigation_node( array &$data ): void {
		$menu = self::get_primary_menu();
		if ( ! $menu || empty( $menu['items'] ) ) {
			return;
		}

		$nav_items = array_map( function ( $item ) {
			return [
				'@type'    => 'SiteNavigationElement',
				'name'     => $item['name'],
				'url'      => $item['url'],
				'position' => $item['position'],
			];
		}, $menu['items'] );

		$this->add_node( $data, [
			'@type'   => 'SiteNavigationElement',
			'@id'     => $this->site_id( 'navigation' ),
			'name'    => $menu['name'],
			'hasPart' => $nav_items,
		] );

		// Link WebSite node to navigation.
		foreach ( $data['schema'] as &$node ) {
			if ( ( $node['@type'] ?? '' ) === 'WebSite' ) {
				$node['hasPart'] = [ '@id' => $this->site_id( 'navigation' ) ];
				break;
			}
		}
	}

	// -------------------------------------------------------------------------
	// WebPage
	// -------------------------------------------------------------------------

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
					if ( in_array( $schema_type, [ 'BlogPosting', 'Article', 'HowTo', 'Product', 'Event', 'Service' ], true ) ) {
						$type = 'WebPage';
					} else {
						$type = $schema_type;
					}
				} else {
					$meta = $settings->get_post_meta( $post->ID );
					$default_type = $settings->get( "schema.post_type_types.{$post->post_type}", 'WebPage' );
					$type = ! empty( $meta['schema_type'] ) ? $meta['schema_type'] : $default_type;
					// Map content types to their WebPage counterpart.
					if ( in_array( $type, [ 'BlogPosting', 'Article', 'HowTo', 'Product', 'Event', 'Service' ], true ) ) {
						$type = 'WebPage';
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

		$node = [
			'@type'       => $type,
			'@id'         => $url . '#webpage',
			'url'         => $url,
			'name'        => $title,
			'isPartOf'    => [ '@id' => $this->site_id( 'website' ) ],
		];

		// ContactPage: add business info data from settings.
		if ( 'ContactPage' === $type && $context->is_singular() ) {
			$biz  = $settings->all_business();
			if ( ! empty( $biz['name'] ) ) {
				$node['name'] = sanitize_text_field( $biz['name'] );
			}
			if ( ! empty( $biz['phone'] ) ) {
				$node['telephone'] = sanitize_text_field( $biz['phone'] );
			}
			if ( ! empty( $biz['email'] ) ) {
				$node['email'] = sanitize_email( $biz['email'] );
			}
			$addr = MM_Mod_Base::postal_address_node( $biz['address'] ?? [] );
			if ( $addr ) {
				$node['address'] = $addr;
			}
			$geo_lat = isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : null;
			$geo_lng = isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : null;
			if ( null !== $geo_lat && null !== $geo_lng ) {
				$node['geo'] = [
					'@type'     => 'GeoCoordinates',
					'latitude'  => $geo_lat,
					'longitude' => $geo_lng,
				];
			}
			// ContactPoint nodes.
			$contact_points = [];
			if ( ! empty( $biz['phone'] ) ) {
				$contact_points[] = [
					'@type'       => 'ContactPoint',
					'telephone'   => sanitize_text_field( $biz['phone'] ),
					'contactType' => 'customer service',
				];
			}
			if ( ! empty( $biz['email'] ) ) {
				$contact_points[] = [
					'@type'       => 'ContactPoint',
					'email'       => sanitize_email( $biz['email'] ),
					'contactType' => 'customer service',
				];
			}
			if ( $contact_points ) {
				$node['contactPoint'] = ( 1 === count( $contact_points ) ) ? $contact_points[0] : $contact_points;
			}
		}

		// AboutPage: add business info data from settings.
		if ( 'AboutPage' === $type && $context->is_singular() ) {
			$biz  = $settings->all_business();
			if ( ! empty( $biz['name'] ) ) {
				$node['name'] = sanitize_text_field( $biz['name'] );
			}
			if ( ! empty( $biz['description'] ) ) {
				$node['description'] = sanitize_text_field( $biz['description'] );
			}
			if ( ! empty( $biz['founding_date'] ) ) {
				$node['foundingDate'] = sanitize_text_field( $biz['founding_date'] );
			}
			if ( ! empty( $biz['number_of_employees'] ) ) {
				$node['numberOfEmployees'] = sanitize_text_field( $biz['number_of_employees'] );
			}
			$addr = MM_Mod_Base::postal_address_node( $biz['address'] ?? [] );
			if ( $addr ) {
				$node['address'] = $addr;
			}
			$geo_lat = isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : null;
			$geo_lng = isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : null;
			if ( null !== $geo_lat && null !== $geo_lng ) {
				$node['geo'] = [
					'@type'     => 'GeoCoordinates',
					'latitude'  => $geo_lat,
					'longitude' => $geo_lng,
				];
			}
			if ( ! empty( $biz['phone'] ) ) {
				$node['telephone'] = sanitize_text_field( $biz['phone'] );
			}
			if ( ! empty( $biz['email'] ) ) {
				$node['email'] = sanitize_email( $biz['email'] );
			}
			// ContactPoint nodes.
			$contact_points = [];
			if ( ! empty( $biz['phone'] ) ) {
				$contact_points[] = [
					'@type'       => 'ContactPoint',
					'telephone'   => sanitize_text_field( $biz['phone'] ),
					'contactType' => 'customer service',
				];
			}
			if ( ! empty( $biz['email'] ) ) {
				$contact_points[] = [
					'@type'       => 'ContactPoint',
					'email'       => sanitize_email( $biz['email'] ),
					'contactType' => 'customer service',
				];
			}
			if ( $contact_points ) {
				$node['contactPoint'] = ( 1 === count( $contact_points ) ) ? $contact_points[0] : $contact_points;
			}
		}

		// Description from meta.
		foreach ( $data['meta'] as $mt ) {
			if ( ( $mt['name'] ?? '' ) === 'description' ) {
				$node['description'] = $mt['content'];
				break;
			}
		}

		// Breadcrumb reference (added after breadcrumb node is built).
		if ( $settings->get( 'schema.breadcrumbs', true ) ) {
			$node['breadcrumb'] = [ '@id' => $url . '#breadcrumb' ];
		}

		// Date signals (singular posts).
		if ( $context->is_singular() && $context->get_post() ) {
			$post = $context->get_post();
			$node['datePublished'] = get_the_date( 'c', $post );
			$node['dateModified']  = get_the_modified_date( 'c', $post );
		}

		// Primary image reference.
		$og_image = $this->get_og_image( $data );
		if ( $og_image ) {
			$node['primaryImageOfPage'] = [ '@id' => $url . '#primaryimage' ];
			$this->add_node( $data, [
				'@type'  => 'ImageObject',
				'@id'    => $url . '#primaryimage',
				'url'    => $og_image['url'],
				'width'  => $og_image['width'],
				'height' => $og_image['height'],
			] );
		}

		$this->add_node( $data, $node );
	}

	// -------------------------------------------------------------------------
	// BreadcrumbList
	// -------------------------------------------------------------------------

	private function add_breadcrumb_node( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		$items = [];
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
			$meta  = $settings->get_post_meta( $post->ID );
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
		} elseif ( $context->is_author() ) {
			$author  = $context->get_author();
			$items[] = $this->crumb( $pos++, $author ? $author->display_name : 'Author', $author ? get_author_posts_url( $author->ID ) : '' );
		}

		if ( count( $items ) <= 1 ) {
			return; // No breadcrumb for homepage alone.
		}

		$url = $this->current_url();
		$this->add_node( $data, [
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $items,
		] );
	}

	// -------------------------------------------------------------------------
	// Content nodes (BlogPosting, Article, HowTo, Product, Event, Service, etc.)
	// -------------------------------------------------------------------------

	private function add_content_node( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		$post = $context->get_post();
		if ( ! $post ) {
			return;
		}

		// Determine schema type: CPT slug → schema type, or legacy _mm_meta schema_type.
		$type = null;
		if ( MM_Schema_Post_Types::is_schema_cpt( $post->post_type ) ) {
			$type = MM_Schema_Post_Types::slug_to_type( $post->post_type );
		}
		if ( ! $type ) {
			$meta         = $settings->get_post_meta( $post->ID );
			$default_type = $settings->get( "schema.post_type_types.{$post->post_type}", 'WebPage' );
			$type         = ! empty( $meta['schema_type'] ) ? $meta['schema_type'] : $default_type;
		}

		// WebPage itself is already added above; skip if type resolves to WebPage.
		if ( in_array( $type, [ 'WebPage', 'WebSite' ], true ) ) {
			return;
		}

		$url = get_permalink( $post );

		$node = [
			'@type'         => $type,
			'@id'           => $url . '#' . strtolower( $type ),
			'headline'      => get_the_title( $post ),
			'url'           => $url,
			'datePublished' => get_the_date( 'c', $post ),
			'dateModified'  => get_the_modified_date( 'c', $post ),
			'isPartOf'      => [ '@id' => $url . '#webpage' ],
		];

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
			$node['image'] = [ '@id' => $url . '#primaryimage' ];
		}

		// Author — link to Person node (added by Author module or built inline).
		if ( in_array( $type, [ 'BlogPosting', 'Article' ], true ) ) {
			$author = get_userdata( (int) $post->post_author );
			if ( $author && $settings->get( 'authors.person_schema', true ) ) {
				$node['author']    = [ '@id' => get_author_posts_url( $author->ID ) . '#person' ];
				$node['publisher'] = [ '@id' => $this->site_id( 'organization' ) ];
			}
		}

		// Merge per-post schema field overrides (Event dates, prices, addresses, etc.).
		// Read from CPT meta first, then legacy _mm_meta schema_fields.
		$schema_fields = [];
		if ( MM_Schema_Post_Types::is_schema_cpt( $post->post_type ) ) {
			$cpt_fields = get_post_meta( $post->ID, 'mm_schema_fields', true );
			if ( is_array( $cpt_fields ) ) {
				$schema_fields = $cpt_fields;
			}
		}
		if ( empty( $schema_fields ) ) {
			$schema_fields = $meta['schema_fields'] ?? [];
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

		$this->add_node( $data, $node );
	}

	// -------------------------------------------------------------------------
	// ItemList (taxonomy archives)
	// -------------------------------------------------------------------------

	private function add_itemlist_node( array &$data, MM_Page_Context $context ): void {
		$term = $context->get_term();
		if ( ! $term ) {
			return;
		}

		global $wp_query;
		$posts = $wp_query->posts ?? [];
		if ( empty( $posts ) ) {
			return;
		}

		$items = [];
		$pos   = 1;
		foreach ( $posts as $post ) {
			$items[] = [
				'@type'    => 'ListItem',
				'position' => $pos++,
				'url'      => get_permalink( $post ),
				'name'     => get_the_title( $post ),
			];
		}

		$url = get_term_link( $term );
		$this->add_node( $data, [
			'@type'           => 'ItemList',
			'@id'             => ( is_string( $url ) ? $url : '' ) . '#itemlist',
			'name'            => $term->name,
			'itemListElement' => $items,
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function crumb( int $pos, string $name, string $url ): array {
		return [
			'@type'    => 'ListItem',
			'position' => $pos,
			'name'     => $name,
			'item'     => $url,
		];
	}

	private function current_url(): string {
		return home_url( ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' ) );
	}

	private function get_og_image( array $data ): ?array {
		foreach ( $data['meta'] as $mt ) {
			if ( ( $mt['property'] ?? '' ) === 'og:image' && $mt['content'] ) {
				$img = [ 'url' => $mt['content'], 'width' => 0, 'height' => 0 ];
				foreach ( $data['meta'] as $m2 ) {
					if ( ( $m2['property'] ?? '' ) === 'og:image:width' )  { $img['width']  = (int) $m2['content']; }
					if ( ( $m2['property'] ?? '' ) === 'og:image:height' ) { $img['height'] = (int) $m2['content']; }
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
			return [];
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return [];
		}

		$out = [];

		// Price.
		$regular = $product->get_regular_price();
		$sale    = $product->get_sale_price();
		$price   = ( '' !== $sale && false !== $sale ) ? $sale : $regular;
		if ( '' !== $price && false !== $price ) {
			$out['offers'] = [
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => get_woocommerce_currency(),
			];

			// Availability.
			$stock = $product->get_stock_status();
			$map   = [
				'instock'     => 'InStock',
				'outofstock'  => 'OutOfStock',
				'onbackorder' => 'PreOrder',
			];
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
			$out['brand'] = [ '@type' => 'Brand', 'name' => $brand ];
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
	}

	/**
	 * Emit schema for an attachment page (the media IS the page).
	 */
	private function add_attachment_page_schema( array &$data, \WP_Post $post ): void {
		$mime = (string) get_post_mime_type( $post->ID );
		$url  = wp_get_attachment_url( $post->ID );
		if ( ! $url ) {
			return;
		}

		if ( MM_Metadata::is_video_mime( $mime ) ) {
			$this->add_video_schema( $data, [
				'type'          => 'video',
				'url'           => $url,
				'attachment_id' => $post->ID,
				'duration'      => (int) get_post_meta( $post->ID, MM_Metadata::META_DURATION, true ),
			], $post );
		} elseif ( MM_Metadata::is_audio_mime( $mime ) ) {
			$this->add_audio_schema( $data, [
				'type'          => 'audio',
				'url'           => $url,
				'attachment_id' => $post->ID,
				'duration'      => (int) get_post_meta( $post->ID, MM_Metadata::META_DURATION, true ),
			], $post );
		} elseif ( MM_Metadata::is_pdf_mime( $mime ) ) {
			$this->add_document_schema( $data, [
				'type'          => 'document',
				'url'           => $url,
				'attachment_id' => $post->ID,
			] );
		} elseif ( wp_attachment_is_image( $post->ID ) ) {
			$src = wp_get_attachment_image_src( $post->ID, 'full' );
			if ( $src ) {
				$this->add_image_schema( $data, [
					'type'          => 'image',
					'url'           => $src[0],
					'attachment_id' => $post->ID,
					'width'         => $src[1],
					'height'        => $src[2],
					'alt'           => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
					'caption'       => trim( $post->post_excerpt ),
				], $post );
			}
		}
	}

	/**
	 * Emit an ImageObject schema node for a detected image.
	 */
	private function add_image_schema( array &$data, array $item, \WP_Post $post ): void {
		$meta = static fn( string $key ): string => (string) get_post_meta( $item['attachment_id'], $key, true );

		$node = [
			'@type'      => 'ImageObject',
			'@id'        => $item['url'] . '#image',
			'url'        => $item['url'],
			'contentUrl' => $item['url'],
		];

		if ( $item['width'] ) {
			$node['width'] = $item['width'];
		}
		if ( $item['height'] ) {
			$node['height'] = $item['height'];
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
				$node['creator'] = [ '@type' => 'Person', 'name' => $creator ];
			}
			$copyright = $meta( MM_Metadata::META_COPYRIGHT );
			if ( '' !== $copyright ) {
				$node['copyrightNotice'] = $copyright;
			}
			$owner = $meta( MM_Metadata::META_OWNER );
			if ( '' !== $owner ) {
				$node['copyrightHolder'] = [ '@type' => 'Organization', 'name' => $owner ];
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
				$node['thumbnail'] = [ '@type' => 'ImageObject', 'url' => $thumb[0] ];
			}
		} else {
			// Minimal schema from HTML only.
			$node['name'] = $item['alt'] ?: basename( wp_parse_url( $item['url'], PHP_URL_PATH ) );
		}

		$this->add_node( $data, $node );
	}

	/**
	 * Emit a VideoObject schema node for a detected video.
	 */
	private function add_video_schema( array &$data, array $item, \WP_Post $post ): void {
		$meta = static fn( string $key ): string => (string) get_post_meta( $item['attachment_id'], $key, true );

		$node = [
			'@type'       => 'VideoObject',
			'@id'         => $item['url'] . '#video',
			'url'         => $item['url'],
			'contentUrl'  => $item['url'],
			'uploadDate'  => gmdate( 'Y-m-d', strtotime( $post->post_date_gmt ) ),
		];

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
	 */
	private function add_audio_schema( array &$data, array $item, \WP_Post $post ): void {
		$meta = static fn( string $key ): string => (string) get_post_meta( $item['attachment_id'], $key, true );

		$mime = $item['attachment_id']
			? (string) get_post_mime_type( $item['attachment_id'] )
			: 'audio/mpeg';

		$node = [
			'@type'          => 'AudioObject',
			'@id'            => $item['url'] . '#audio',
			'url'            => $item['url'],
			'contentUrl'     => $item['url'],
			'encodingFormat' => $mime,
		];

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
	 */
	private function add_document_schema( array &$data, array $item ): void {
		$node = [
			'@type'          => 'DigitalDocument',
			'@id'            => $item['url'] . '#document',
			'url'            => $item['url'],
			'contentUrl'     => $item['url'],
			'encodingFormat' => 'application/pdf',
		];

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

	private function add_location_to_node( array &$node, int $attachment_id ): void {
		$lat   = (string) get_post_meta( $attachment_id, MM_Metadata::META_GPS_LAT, true );
		$lon   = (string) get_post_meta( $attachment_id, MM_Metadata::META_GPS_LON, true );
		$alt_m = (string) get_post_meta( $attachment_id, MM_Metadata::META_GPS_ALT, true );
		$city  = (string) get_post_meta( $attachment_id, MM_Metadata::META_CITY, true );
		$state = (string) get_post_meta( $attachment_id, MM_Metadata::META_STATE, true );
		$country = (string) get_post_meta( $attachment_id, MM_Metadata::META_COUNTRY, true );

		$loc_parts = array_filter( [ $city, $state, $country ] );

		if ( '' !== $lat && '' !== $lon ) {
			$geo = [
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lon,
			];
			if ( '' !== $alt_m ) {
				$geo['elevation'] = (float) $alt_m;
			}
			$place = [ '@type' => 'Place', 'geo' => $geo ];
			if ( $loc_parts ) {
				$place['name'] = implode( ', ', $loc_parts );
			}
			$node['locationCreated'] = $place;
			$node['contentLocation'] = $place;
		} elseif ( $loc_parts ) {
			$place = [ '@type' => 'Place', 'name' => implode( ', ', $loc_parts ) ];
			$node['locationCreated'] = $place;
			$node['contentLocation'] = $place;
		}
	}

	private function seconds_to_iso_duration( int $seconds ): string {
		$h = (int) floor( $seconds / 3600 );
		$m = (int) floor( ( $seconds % 3600 ) / 60 );
		$s = $seconds % 60;
		$d = 'PT';
		if ( $h ) { $d .= $h . 'H'; }
		if ( $m ) { $d .= $m . 'M'; }
		if ( $s || ( ! $h && ! $m ) ) { $d .= $s . 'S'; }
		return $d;
	}
}
