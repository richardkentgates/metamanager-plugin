<?php
/**
 * MM_Mod_Sitemap_Web — XML sitemap engine.
 *
 * Generates:
 *   /sitemap.xml              — index listing all sub-sitemaps
 *   /sitemap-post-{type}.xml  — per post-type sitemap with image extension
 *   /sitemap-tax-{taxonomy}.xml — per taxonomy sitemap
 *   /sitemap-video.xml        — Google Video Sitemap (self-hosted + video attachments)
 *   /sitemap-media.xml        — media attachment pages with image:image and video:video
 *
 * On post publish, pings Google and Bing (async via cron, once per event).
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Sitemap_Web extends MM_Mod_Base {

	/** XML namespaces. */
	const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	const NS_IMAGE   = 'http://www.google.com/schemas/sitemap-image/1.1';
	const NS_VIDEO   = 'http://www.google.com/schemas/sitemap-video/0.9';

	/** Nothing to add to HTML head. */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {}

	public function register_hooks(): void {
		if ( ! $this->settings->get( 'sitemap.enabled', true ) ) {
			return;
		}

		// Disable WordPress 5.5+ built-in sitemap.
		add_filter( 'wp_sitemaps_enabled', '__return_false' );

		// Register rewrite rules and query var.
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve' ] );

		// Append Sitemap: directives to WordPress-generated robots.txt.
		add_filter( 'robots_txt', [ $this, 'append_robots_txt' ], 10, 2 );

		// Ping on publish (async).
		add_action( 'transition_post_status', [ $this, 'schedule_ping' ], 10, 3 );
		add_action( 'mm_meta_sitemap_ping', [ $this, 'send_ping' ] );

		// Bust the sitemap cache whenever content or taxonomy changes.
		foreach ( [ 'save_post', 'deleted_post', 'add_attachment', 'delete_attachment' ] as $hook ) {
			add_action( $hook, [ $this, 'flush_sitemap_cache' ] );
		}
		foreach ( [ 'created_term', 'edited_term', 'delete_term' ] as $hook ) {
			add_action( $hook, [ $this, 'flush_sitemap_cache' ] );
		}
	}

	// -------------------------------------------------------------------------
	// Rewrite rules
	// -------------------------------------------------------------------------

	public function add_rewrite_rules(): void {
		global $wp_rewrite;

		// Remove WordPress core sitemap rewrite rules that intercept wp-sitemap.xml.
		unset( $wp_rewrite->extra_rules_top['^wp-sitemap\.xml$'] );

		add_rewrite_rule( '^wp-sitemap\.xml/?$', 'index.php?mm_meta_sitemap=index', 'top' );
		add_rewrite_rule( '^sitemap\.xml/?$', 'index.php?mm_meta_sitemap=index', 'top' );
		add_rewrite_rule( '^sitemap-post-([a-z0-9_-]+)\.xml/?$', 'index.php?mm_meta_sitemap=post&mm_meta_sitemap_type=$matches[1]', 'top' );
		add_rewrite_rule( '^sitemap-tax-([a-z0-9_-]+)\.xml/?$', 'index.php?mm_meta_sitemap=tax&mm_meta_sitemap_type=$matches[1]', 'top' );
		add_rewrite_rule( '^sitemap-video\.xml/?$', 'index.php?mm_meta_sitemap=video', 'top' );
		add_rewrite_rule( '^sitemap-media\.xml/?$', 'index.php?mm_meta_sitemap=media', 'top' );
	}

	public function add_query_var( array $vars ): array {
		$vars[] = 'mm_meta_sitemap';
		$vars[] = 'mm_meta_sitemap_type';
		return $vars;
	}

	// -------------------------------------------------------------------------
	// Routing
	// -------------------------------------------------------------------------

	public function maybe_serve(): void {
		$type = get_query_var( 'mm_meta_sitemap' );
		if ( ! $type ) {
			return;
		}

		$sub = sanitize_key( get_query_var( 'mm_meta_sitemap_type' ) );

		// Bail if the requested post type or taxonomy is disabled in settings.
		if ( 'post' === $type && $sub && ! in_array( $sub, $this->get_active_post_types(), true ) ) {
			return;
		}
		if ( 'tax' === $type && $sub && ! in_array( $sub, $this->get_active_taxonomies(), true ) ) {
			return;
		}

		$cache_key = 'mm_sm_' . $this->get_cache_version() . '_' . $type . ( $sub ? "_$sub" : '' );

		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		switch ( $type ) {
			case 'index':
				$output = $this->render_index();
				break;
			case 'post':
				$output = $this->render_post_sitemap( $sub );
				break;
			case 'tax':
				$output = $this->render_tax_sitemap( $sub );
				break;
			case 'video':
				$output = $this->render_video_sitemap();
				break;
			case 'media':
				$output = $this->render_media_sitemap();
				break;
			default:
				$output = '';
		}

		set_transient( $cache_key, $output, HOUR_IN_SECONDS );
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/** Returns a version stamp used to scope all sitemap cache keys. */
	private function get_cache_version(): int {
		return (int) get_option( 'mm_sitemap_cache_ver', 0 );
	}

	/** Bust all sitemap transients cheaply by incrementing the version stamp. */
	public function flush_sitemap_cache(): void {
		update_option( 'mm_sitemap_cache_ver', time(), false );
	}

	// -------------------------------------------------------------------------
	// Index sitemap
	// -------------------------------------------------------------------------

	private function render_index(): string {
		$s        = $this->settings;
		$sitemaps = [];

		foreach ( $this->get_active_post_types() as $pt ) {
			$count = $this->post_type_count( $pt );
			if ( $count > 0 ) {
				$sitemaps[] = [
					'loc'     => home_url( '/sitemap-post-' . $pt . '.xml' ),
					'lastmod' => $this->last_modified_post( $pt ),
				];
			}
		}

		foreach ( $this->get_active_taxonomies() as $taxonomy ) {
			$count = wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true ] );
			if ( ! is_wp_error( $count ) && $count > 0 ) {
				$sitemaps[] = [
					'loc'     => home_url( '/sitemap-tax-' . $taxonomy . '.xml' ),
					'lastmod' => gmdate( 'Y-m-d' ),
				];
			}
		}

		// Video sitemap.
		if ( $this->has_video_content() ) {
			$sitemaps[] = [
				'loc'     => home_url( '/sitemap-video.xml' ),
				'lastmod' => gmdate( 'Y-m-d' ),
			];
		}

		// Media sitemap.
		if ( $this->has_media_attachments() ) {
			$sitemaps[] = [
				'loc'     => home_url( '/sitemap-media.xml' ),
				'lastmod' => gmdate( 'Y-m-d' ),
			];
		}

		$xml  = $this->xml_header();
		$xml .= '<sitemapindex xmlns="' . self::NS_SITEMAP . '">' . "\n";
		foreach ( $sitemaps as $sm ) {
			$xml .= "  <sitemap>\n";
			$xml .= '    <loc>' . esc_url( $sm['loc'] ) . "</loc>\n";
			if ( $sm['lastmod'] ) {
				$xml .= '    <lastmod>' . esc_html( $sm['lastmod'] ) . "</lastmod>\n";
			}
			$xml .= "  </sitemap>\n";
		}
		$xml .= '</sitemapindex>';
		return $xml;
	}

	// -------------------------------------------------------------------------
	// Post-type sitemap
	// -------------------------------------------------------------------------

	private function render_post_sitemap( string $pt ): string {
		$s    = $this->settings;
		$args = [
			'post_type'      => $pt,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $s->get( 'sitemap.records_per_file', 1000 ),
			'no_found_rows'  => true,
			'fields'         => 'ids',
		];

		if ( $s->get( 'sitemap.exclude_password_protected', true ) ) {
			$args['has_password'] = false;
		}

		// Exclude noindexed posts.
		if ( $s->get( 'sitemap.exclude_noindexed', true ) ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				[
					'key'     => MM_META_KEY,
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => MM_META_KEY,
					'value'   => '"noindex":true',
					'compare' => 'NOT LIKE',
				],
			];
		}

		$ids = get_posts( $args );

		$xml  = $this->xml_header();
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . "\"\n";

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$xml .= "  <url>\n";
			$xml .= '    <loc>' . esc_url( get_permalink( $post ) ) . "</loc>\n";
			$xml .= '    <lastmod>' . esc_html( get_the_modified_date( 'Y-m-d', $post ) ) . "</lastmod>\n";
			$xml .= '    <changefreq>monthly</changefreq>' . "\n";

			$xml .= "  </url>\n";
		}

		$xml .= '</urlset>';
		return $xml;
	}

	// -------------------------------------------------------------------------
	// Taxonomy sitemap
	// -------------------------------------------------------------------------

	private function render_tax_sitemap( string $taxonomy ): string {
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		] );

		$xml  = $this->xml_header();
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . "\">\n";

		if ( ! is_wp_error( $terms ) && $terms ) {
			foreach ( $terms as $term ) {
				// Skip noindexed terms.
				if ( $this->settings->get( 'sitemap.exclude_noindexed', true ) ) {
					$meta = $this->settings->get_term_meta( $term->term_id );
					if ( ! empty( $meta['noindex'] ) ) {
						continue;
					}
				}
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$xml .= "  <url>\n";
					$xml .= '    <loc>' . esc_url( $link ) . "</loc>\n";
					$xml .= '    <changefreq>monthly</changefreq>' . "\n";
					$xml .= "  </url>\n";
				}
			}
		}

		$xml .= '</urlset>';
		return $xml;
	}

	// -------------------------------------------------------------------------
	// Video sitemap (/sitemap-video.xml)
	// -------------------------------------------------------------------------

	/**
	 * Build the video sitemap XML.
	 *
	 * Sources:
	 *   1. Published posts containing self-hosted <video> tags (local URLs).
	 *   2. Video attachment pages with Metamanager metadata.
	 */
	private function render_video_sitemap(): string {
		$entries = [];

		// 1. Self-hosted video tags in published posts.
		$posts = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		foreach ( $posts as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$videos = $this->extract_selfhosted_videos( $post );
			if ( $videos ) {
				$permalink = get_permalink( $post );
				if ( $permalink ) {
					$entries[ $permalink ] = $videos;
				}
			}
		}

		// 2. Video attachment pages.
		$attachments = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'post_mime_type' => 'video',
			'fields'         => 'all',
		] );

		foreach ( $attachments as $att ) {
			$permalink = get_attachment_link( $att->ID );
			$url       = wp_get_attachment_url( $att->ID );
			if ( $permalink && $url ) {
				$entries[ $permalink ] = [ $this->build_video_record( $att->ID, $att, $url ) ];
			}
		}

		if ( ! $entries ) {
			return $this->render_empty_urlset( self::NS_VIDEO, 'video' );
		}

		$xml  = $this->xml_header();
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . '" xmlns:video="' . self::NS_VIDEO . "\">\n";

		foreach ( $entries as $loc => $videos ) {
			$xml .= "  <url>\n";
			$xml .= '    <loc>' . esc_xml( $loc ) . "</loc>\n";
			foreach ( $videos as $video ) {
				$xml .= $this->render_video_node( $video );
			}
			$xml .= "  </url>\n";
		}

		$xml .= '</urlset>';
		return $xml;
	}

	/**
	 * Extract self-hosted video records from a post's content.
	 */
	private function extract_selfhosted_videos( \WP_Post $post ): array {
		$media = MM_Media_Detector::scan_content( $post->post_content );
		$records = [];

		foreach ( $media as $item ) {
			if ( 'video' !== $item['type'] ) {
				continue;
			}
			// Skip external embeds (YouTube/Vimeo) — only self-hosted.
			if ( 0 === $item['attachment_id'] && str_starts_with( $item['url'], 'http' ) ) {
				continue;
			}
			$records[] = [
				'thumbnail'      => '',
				'title'          => get_the_title( $post ),
				'description'    => wp_strip_all_tags( $post->post_excerpt ),
				'player_loc'     => '',
				'content_loc'    => $item['url'],
				'duration'       => $item['duration'] ?? null,
				'pub_date'       => null,
				'rating'         => null,
				'family_friendly'=> null,
				'tags'           => [],
				'uploader'       => null,
				'uploader_url'   => null,
			];
		}

		return $records;
	}

	/**
	 * Build a fully-enriched video record for a video attachment.
	 */
	private function build_video_record( int $id, \WP_Post $att, string $url ): array {
		$meta = static fn( string $key ): string => (string) get_post_meta( $id, $key, true );

		$thumbnail = '';
		$thumb_id  = (int) get_post_thumbnail_id( $id );
		if ( $thumb_id ) {
			$thumb_src = wp_get_attachment_image_src( $thumb_id, 'medium' );
			if ( $thumb_src ) {
				$thumbnail = $thumb_src[0];
			}
		}

		$title       = $meta( MM_Metadata::META_HEADLINE ) ?: trim( $att->post_title ) ?: basename( $url );
		$description = wp_strip_all_tags( $att->post_excerpt ?: '' );

		$record = [
			'thumbnail'      => $thumbnail,
			'title'          => $title,
			'description'    => $description,
			'player_loc'     => '',
			'content_loc'    => $url,
			'duration'       => null,
			'pub_date'       => null,
			'rating'         => null,
			'family_friendly'=> null,
			'tags'           => [],
			'uploader'       => null,
			'uploader_url'   => null,
		];

		$duration = $meta( MM_Metadata::META_DURATION );
		if ( '' !== $duration && (int) $duration > 0 ) {
			$record['duration'] = (int) $duration;
		}

		$keywords = $meta( MM_Metadata::META_KEYWORDS );
		if ( '' !== $keywords ) {
			$kw = array_values( array_filter( array_map( 'trim', explode( ';', $keywords ) ) ) );
			if ( $kw ) {
				$record['tags'] = array_slice( $kw, 0, 32 );
			}
		}

		$rating = $meta( MM_Metadata::META_RATING );
		if ( '' !== $rating ) {
			$float_rating              = round( (float) $rating, 1 );
			$record['rating']          = $float_rating;
			$record['family_friendly'] = $float_rating <= 4.0;
		}

		$date_created = $meta( MM_Metadata::META_DATE );
		if ( '' !== $date_created ) {
			$record['pub_date'] = $date_created . 'T00:00:00+00:00';
		}

		$creator = $meta( MM_Metadata::META_CREATOR );
		if ( '' !== $creator ) {
			$record['uploader']     = $creator;
			$record['uploader_url'] = get_author_posts_url( (int) $att->post_author );
		}

		return $record;
	}

	/**
	 * Render a <video:video> extension node from a video record array.
	 */
	private function render_video_node( array $v ): string {
		$xml = "    <video:video>\n";

		if ( ! empty( $v['thumbnail'] ) ) {
			$xml .= '      <video:thumbnail_loc>' . esc_xml( $v['thumbnail'] ) . "</video:thumbnail_loc>\n";
		}

		$xml .= '      <video:title>' . esc_xml( $v['title'] ?? '' ) . "</video:title>\n";
		$xml .= '      <video:description>' . esc_xml( $v['description'] ?? '' ) . "</video:description>\n";

		if ( ! empty( $v['player_loc'] ) ) {
			$xml .= '      <video:player_loc>' . esc_xml( $v['player_loc'] ) . "</video:player_loc>\n";
		} elseif ( ! empty( $v['content_loc'] ) ) {
			$xml .= '      <video:content_loc>' . esc_xml( $v['content_loc'] ) . "</video:content_loc>\n";
		}

		if ( null !== ( $v['duration'] ?? null ) && (int) $v['duration'] > 0 ) {
			$xml .= '      <video:duration>' . (int) $v['duration'] . "</video:duration>\n";
		}

		if ( ! empty( $v['pub_date'] ) ) {
			$xml .= '      <video:publication_date>' . esc_xml( $v['pub_date'] ) . "</video:publication_date>\n";
		}

		if ( null !== ( $v['rating'] ?? null ) ) {
			$xml .= '      <video:rating>' . number_format( (float) $v['rating'], 1 ) . "</video:rating>\n";
		}

		if ( null !== ( $v['family_friendly'] ?? null ) ) {
			$xml .= '      <video:family_friendly>' . ( $v['family_friendly'] ? 'yes' : 'no' ) . "</video:family_friendly>\n";
		}

		foreach ( (array) ( $v['tags'] ?? [] ) as $tag ) {
			if ( '' !== $tag ) {
				$xml .= '      <video:tag>' . esc_xml( (string) $tag ) . "</video:tag>\n";
			}
		}

		if ( ! empty( $v['uploader'] ) ) {
			$info_attr = ! empty( $v['uploader_url'] )
				? ' info="' . esc_attr( $v['uploader_url'] ) . '"'
				: '';
			$xml .= '      <video:uploader' . $info_attr . '>' . esc_xml( $v['uploader'] ) . "</video:uploader>\n";
		}

		$xml .= "    </video:video>\n";
		return $xml;
	}

	// -------------------------------------------------------------------------
	// Media sitemap (/sitemap-media.xml)
	// -------------------------------------------------------------------------

	/**
	 * Build the media attachment sitemap XML.
	 *
	 * Lists all attachment pages for supported media types with
	 * <image:image> or <video:video> extension nodes where applicable.
	 */
	private function render_media_sitemap(): string {
		$mime_types = array_merge(
			[ 'image' ],
			MM_Metadata::VIDEO_MIME_TYPES,
			MM_Metadata::AUDIO_MIME_TYPES,
			MM_Metadata::PDF_MIME_TYPES
		);

		$attachments = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'post_mime_type' => implode( ',', $mime_types ),
			'fields'         => 'all',
		] );

		if ( ! $attachments ) {
			return $this->render_empty_urlset( '', '' );
		}

		$has_images = false;
		$has_videos = false;
		foreach ( $attachments as $att ) {
			$mime = (string) get_post_mime_type( $att->ID );
			if ( wp_attachment_is_image( $att->ID ) ) {
				$has_images = true;
			}
			if ( MM_Metadata::is_video_mime( $mime ) ) {
				$has_videos = true;
			}
			if ( $has_images && $has_videos ) {
				break;
			}
		}

		$xml  = $this->xml_header();
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . '"';
		if ( $has_images ) {
			$xml .= ' xmlns:image="' . self::NS_IMAGE . '"';
		}
		if ( $has_videos ) {
			$xml .= ' xmlns:video="' . self::NS_VIDEO . '"';
		}
		$xml .= ">\n";

		foreach ( $attachments as $att ) {
			$mime = (string) get_post_mime_type( $att->ID );

			// PDFs: use the raw attachment URL (Google indexes documents directly).
			$loc = MM_Metadata::is_pdf_mime( $mime )
				? wp_get_attachment_url( $att->ID )
				: get_attachment_link( $att->ID );

			if ( ! $loc ) {
				continue;
			}

			$xml .= "  <url>\n";
			$xml .= '    <loc>' . esc_xml( $loc ) . "</loc>\n";

			if ( wp_attachment_is_image( $att->ID ) ) {
				$xml .= $this->render_image_node( $att->ID, $att );
			} elseif ( MM_Metadata::is_video_mime( $mime ) ) {
				$url = wp_get_attachment_url( $att->ID );
				if ( $url ) {
					$xml .= $this->render_video_node(
						$this->build_video_record( $att->ID, $att, $url )
					);
				}
			}

			$xml .= "  </url>\n";
		}

		$xml .= '</urlset>';
		return $xml;
	}

	/**
	 * Render an <image:image> extension node for an image attachment.
	 */
	private function render_image_node( int $id, \WP_Post $post ): string {
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src ) {
			return '';
		}
		[ $url ] = $src;

		$meta = static fn( string $key ): string => (string) get_post_meta( $id, $key, true );

		$xml  = "    <image:image>\n";
		$xml .= '      <image:loc>' . esc_xml( $url ) . "</image:loc>\n";

		$headline = $meta( MM_Metadata::META_HEADLINE );
		$title    = '' !== $headline ? $headline : trim( $post->post_title );
		if ( '' !== $title ) {
			$xml .= '      <image:title>' . esc_xml( $title ) . "</image:title>\n";
		}

		$caption = trim( $post->post_excerpt );
		if ( '' !== $caption ) {
			$xml .= '      <image:caption>' . esc_xml( $caption ) . "</image:caption>\n";
		}

		$copyright = $meta( MM_Metadata::META_COPYRIGHT );
		if ( str_starts_with( $copyright, 'http' ) ) {
			$xml .= '      <image:license>' . esc_xml( $copyright ) . "</image:license>\n";
		}

		$lat   = $meta( MM_Metadata::META_GPS_LAT );
		$lon   = $meta( MM_Metadata::META_GPS_LON );
		if ( '' !== $lat && '' !== $lon ) {
			$city    = $meta( MM_Metadata::META_CITY );
			$country = $meta( MM_Metadata::META_COUNTRY );
			if ( '' !== $city && '' !== $country ) {
				$geo_label = "{$city}, {$country}";
			} elseif ( '' !== $city ) {
				$geo_label = $city;
			} elseif ( '' !== $country ) {
				$geo_label = $country;
			} else {
				$geo_label = round( (float) $lat, 4 ) . ',' . round( (float) $lon, 4 );
			}
			$xml .= '      <image:geo_location>' . esc_xml( $geo_label ) . "</image:geo_location>\n";
		}

		$xml .= "    </image:image>\n";
		return $xml;
	}

	// -------------------------------------------------------------------------
	// Robots.txt
	// -------------------------------------------------------------------------

	/**
	 * Append Sitemap: directives to the WordPress-generated robots.txt.
	 */
	public function append_robots_txt( string $output, bool $public ): string {
		if ( ! $public ) {
			return $output;
		}

		$s = $this->settings;
		if ( $s->get( 'sitemap.enabled', true ) && $this->has_media_attachments() ) {
			$output .= "\nSitemap: " . home_url( '/sitemap-media.xml' );
		}
		if ( $s->get( 'sitemap.video', true ) && $this->has_video_content() ) {
			$output .= "\nSitemap: " . home_url( '/sitemap-video.xml' );
		}
		return $output;
	}

	// -------------------------------------------------------------------------
	// Content presence checks
	// -------------------------------------------------------------------------

	private function has_video_content(): bool {
		global $wpdb;

		// Check for video attachments.
		$video = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type LIKE %s",
			'video/%'
		) );
		if ( $video > 0 ) {
			return true;
		}

		// Check for posts with self-hosted video tags.
		$posts = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'fields'         => 'ids',
		] );
		foreach ( $posts as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && $this->extract_selfhosted_videos( $post ) ) {
				return true;
			}
		}

		return false;
	}

	private function has_media_attachments(): bool {
		$mime_types = array_merge(
			[ 'image' ],
			MM_Metadata::VIDEO_MIME_TYPES,
			MM_Metadata::AUDIO_MIME_TYPES,
			MM_Metadata::PDF_MIME_TYPES
		);

		$count = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'post_mime_type' => implode( ',', $mime_types ),
			'fields'         => 'ids',
		] );

		return ! empty( $count );
	}

	// -------------------------------------------------------------------------
	// Publish ping
	// -------------------------------------------------------------------------

	public function schedule_ping( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		$pt = $this->get_active_post_types();
		if ( ! in_array( $post->post_type, $pt, true ) ) {
			return;
		}
		if ( ! wp_next_scheduled( 'mm_meta_sitemap_ping' ) ) {
			wp_schedule_single_event( time() + 10, 'mm_meta_sitemap_ping' );
		}
	}

	public function send_ping(): void {
		$urls = [ home_url( '/sitemap.xml' ) ];
		if ( $this->settings->get( 'sitemap.video', true ) ) {
			$urls[] = home_url( '/sitemap-video.xml' );
		}
		if ( $this->settings->get( 'sitemap.enabled', true ) ) {
			$urls[] = home_url( '/sitemap-media.xml' );
		}

		foreach ( $urls as $sitemap_url ) {
			$encoded = rawurlencode( $sitemap_url );
			if ( $this->settings->get( 'sitemap.ping_google', true ) ) {
				wp_remote_get( 'https://www.google.com/ping?sitemap=' . $encoded, [
					'timeout'   => 5,
					'blocking'  => false,
					'sslverify' => true,
				] );
			}
			if ( $this->settings->get( 'sitemap.ping_bing', true ) ) {
				wp_remote_get( 'https://www.bing.com/ping?sitemap=' . $encoded, [
					'timeout'   => 5,
					'blocking'  => false,
					'sslverify' => true,
				] );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function get_active_post_types(): array {
		$configured = $this->settings->get( 'sitemap.post_types', [] );
		return array_keys( array_filter( $configured ) );
	}

	private function get_active_taxonomies(): array {
		$configured = $this->settings->get( 'sitemap.taxonomies', [] );
		return array_keys( array_filter( $configured ) );
	}

	private function post_type_count( string $pt ): int {
		$counts = wp_count_posts( $pt );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	private function last_modified_post( string $pt ): string {
		global $wpdb;
		$date = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$pt
		) );
		return $date ? gmdate( 'Y-m-d', strtotime( $date ) ) : gmdate( 'Y-m-d' );
	}

	private function xml_header(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	}

	/**
	 * Render an empty <urlset> document.
	 */
	private function render_empty_urlset( string $ns_uri, string $ns_prefix ): string {
		$xml  = $this->xml_header();
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . '"';
		if ( '' !== $ns_uri && '' !== $ns_prefix ) {
			$xml .= ' xmlns:' . $ns_prefix . '="' . $ns_uri . '"';
		}
		$xml .= "></urlset>\n";
		return $xml;
	}
}
