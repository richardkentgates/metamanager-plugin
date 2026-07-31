<?php
/**
 * MM_Sitemap — XML media sitemap generator.
 *
 * Serves two dedicated sitemap endpoints:
 *
 *   /sitemap-video.xml  — public posts that embed YouTube/Vimeo/self-hosted video,
 *                         plus video attachment pages, rendered as a Google Video
 *                         Sitemap with full spec support including Metamanager-sourced
 *                         duration, keywords, rating, publication date, and uploader.
 *
 *   /sitemap-media.xml  — all media attachment pages (image, video, audio, PDF) with
 *                         image extension nodes (<image:image>) for images and video
 *                         extension nodes (<video:video>) for video attachments.
 *
 * Settings are exposed as a new "Sitemaps" section on the existing
 * Media → MM Settings admin page.
 *
 * Ported from gcm-seo-core (commit ed2021e, removed 2026-03-14) with enhancements
 * made possible by Metamanager's per-attachment metadata storage.
 *
 * @package Metamanager
 * @since   1.6.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Sitemap
 */
class MM_Sitemap {

	// -----------------------------------------------------------------------
	// XML namespace constants
	// -----------------------------------------------------------------------

	const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	const NS_IMAGE   = 'http://www.google.com/schemas/sitemap-image/1.1';
	const NS_VIDEO   = 'http://www.google.com/schemas/sitemap-video/0.9';

	// -----------------------------------------------------------------------
	// Settings keys (read from MM_Site_Settings via mm_meta_settings)
	// -----------------------------------------------------------------------

	// Legacy standalone option constants removed — all sitemap settings are
	// now stored in mm_meta_settings['sitemap'] and read via MM_Site_Settings.

	// Transient keys and TTL for server-side XML caching.
	const CACHE_KEY_MEDIA = 'mm_sitemap_cache_media';
	const CACHE_KEY_VIDEO = 'mm_sitemap_cache_video';
	const CACHE_TTL       = HOUR_IN_SECONDS;

	// -----------------------------------------------------------------------
	// Boot
	// -----------------------------------------------------------------------

	/**
	 * Register hooks. Called from the main plugin file on 'plugins_loaded'.
	 */
	public static function init(): void {
		add_action( 'init',                                    [ __CLASS__, 'add_rewrite_rules' ] );
		add_filter( 'query_vars',                              [ __CLASS__, 'register_query_vars' ] );
		add_action( 'template_redirect',                       [ __CLASS__, 'maybe_serve_sitemap' ] );
		// Append Sitemap: directives to WordPress-generated robots.txt.
		add_filter( 'robots_txt',                              [ __CLASS__, 'append_robots_txt' ], 10, 2 );
		// Flush cached XML whenever media or post content changes.
		add_action( 'save_post',         [ __CLASS__, 'flush_sitemap_cache' ] );
		add_action( 'add_attachment',    [ __CLASS__, 'flush_sitemap_cache' ] );
		add_action( 'delete_attachment', [ __CLASS__, 'flush_sitemap_cache' ] );
		// Ping search engines when a post is published.
		add_action( 'publish_post',      [ __CLASS__, 'ping_search_engines' ] );
	}

	// -----------------------------------------------------------------------
	// Rewrite rules
	// -----------------------------------------------------------------------

	/**
	 * Register custom rewrite rules so WordPress recognises the sitemap URLs.
	 * Must be followed by flush_rewrite_rules() (called on plugin activation).
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule( '^sitemap-video\.xml$', 'index.php?mm_sitemap=video', 'top' );
		add_rewrite_rule( '^sitemap-media\.xml$', 'index.php?mm_sitemap=media', 'top' );
	}

	/**
	 * Expose the mm_sitemap query variable to WordPress.
	 *
	 * @param  string[] $vars  Existing registered query variables.
	 * @return string[]        Updated list.
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = 'mm_sitemap';
		return $vars;
	}

	// -----------------------------------------------------------------------
	// Dispatcher
	// -----------------------------------------------------------------------

	/**
	 * Intercept sitemap requests and serve the XML response.
	 * Calls exit() after output to prevent the WordPress theme from rendering.
	 */
	public static function maybe_serve_sitemap(): void {
		$type = (string) get_query_var( 'mm_sitemap', '' );
		if ( '' === $type ) {
			return;
		}

		$settings = MM_Site_Settings::get_instance();

		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );

		switch ( $type ) {
			case 'video':
				$cached = get_transient( self::CACHE_KEY_VIDEO );
				if ( false === $cached ) {
					$cached = $settings->get( 'sitemap.video', true )
						? self::render_video_sitemap()
						: self::render_empty_urlset( self::NS_VIDEO, 'video' );
					set_transient( self::CACHE_KEY_VIDEO, $cached, self::CACHE_TTL );
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $cached;
				break;

			case 'media':
				$cached = get_transient( self::CACHE_KEY_MEDIA );
				if ( false === $cached ) {
					$cached = $settings->get( 'sitemap.enabled', true )
						? self::render_media_sitemap()
						: self::render_empty_urlset( '', '' );
					set_transient( self::CACHE_KEY_MEDIA, $cached, self::CACHE_TTL );
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $cached;
				break;
		}

		exit;
	}

	// -----------------------------------------------------------------------
	// /sitemap-video.xml
	// -----------------------------------------------------------------------

	/**
	 * Build the complete video sitemap XML string.
	 *
	 * Sources:
	 *   1. Published posts whose content contains YouTube/Vimeo embeds or
	 *      self-hosted <video> tags (toggled by individual settings).
	 *   2. Video attachment pages — enriched with Metamanager metadata.
	 *
	 * @return string  Complete XML document.
	 */
	private static function render_video_sitemap(): string {
		$settings    = MM_Site_Settings::get_instance();
		$self_enabled = (bool) $settings->get( 'sitemap.video_selfhosted', true );

		$entries = []; // keyed by page permalink to deduplicate

		// --- 1. Self-hosted <video> tags in published posts ---
		if ( $self_enabled ) {
			$posts = get_posts( [
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'all',
			] );

			foreach ( $posts as $post ) {
				$permalink = get_permalink( $post );
				if ( ! $permalink ) {
					continue;
				}

				$videos = self::extract_selfhosted_videos( $post );

				if ( $videos ) {
					$entries[ $permalink ] = $videos;
				}
			}
		}

		// --- 2. Video attachment pages ---
		$attachments = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'post_mime_type' => 'video',
			'fields'         => 'all',
		] );

		foreach ( $attachments as $att ) {
			$permalink = get_attachment_link( $att->ID );
			if ( ! $permalink ) {
				continue;
			}
			$url = wp_get_attachment_url( $att->ID );
			if ( ! $url ) {
				continue;
			}
			$entries[ $permalink ] = [ self::build_attachment_video_record( $att->ID, $att, $url ) ];
		}

		if ( ! $entries ) {
			return self::render_empty_urlset( self::NS_VIDEO, 'video' );
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . '" xmlns:video="' . self::NS_VIDEO . "\">\n";

		foreach ( $entries as $loc => $videos ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_xml( $loc ) . "</loc>\n";
			foreach ( $videos as $video ) {
				$xml .= self::render_video_node( $video );
			}
			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>';
		return $xml;
	}

	// -----------------------------------------------------------------------
	// Video extraction helpers
	// -----------------------------------------------------------------------

	/**
	 * Extract self-hosted video records from a post's content.
	 * Only local URLs (same origin) are included.
	 *
	 * @param  WP_Post $post  The post to scan.
	 * @return array[]        Video record arrays.
	 */
	private static function extract_selfhosted_videos( WP_Post $post ): array {
		preg_match_all(
			'/<video[^>]*>.*?<source[^>]+src=["\']([^"\']+\.(?:mp4|webm|ogg))["\'][^>]*/is',
			$post->post_content,
			$matches
		);

		$records = [];
		foreach ( array_unique( $matches[1] ) as $src ) {
			if ( ! self::is_local_url( $src ) ) {
				continue;
			}
			$records[] = [
				'thumbnail'   => '',
				'title'       => get_the_title( $post ),
				'description' => wp_strip_all_tags( $post->post_excerpt ),
				'player_loc'  => '',
				'content_loc' => $src,
				'duration'    => null,
				'pub_date'    => null,
				'rating'      => null,
				'tags'        => [],
				'uploader'    => null,
				'uploader_url'=> null,
			];
		}

		return $records;
	}

	/**
	 * Build a fully-enriched video record for a video attachment, using all
	 * available Metamanager metadata.
	 *
	 * @param  int      $id   Attachment post ID.
	 * @param  WP_Post  $att  Attachment post object.
	 * @param  string   $url  Direct file URL.
	 * @return array          Video record array.
	 */
	private static function build_attachment_video_record( int $id, WP_Post $att, string $url ): array {
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

		// Duration from ffprobe (integer seconds, stored by the meta daemon).
		$duration = $meta( MM_Metadata::META_DURATION );
		if ( '' !== $duration && (int) $duration > 0 ) {
			$record['duration'] = (int) $duration;
		}

		// Keywords → <video:tag> elements (Google spec: max 32 per video).
		$keywords = $meta( MM_Metadata::META_KEYWORDS );
		if ( '' !== $keywords ) {
			$kw = array_values( array_filter( array_map( 'trim', explode( ';', $keywords ) ) ) );
			if ( $kw ) {
				$record['tags'] = array_slice( $kw, 0, 32 );
			}
		}

		// Rating (0–5 stars stored as integer, spec accepts 0.0–5.0 float).
		$rating = $meta( MM_Metadata::META_RATING );
		if ( '' !== $rating ) {
			$float_rating              = round( (float) $rating, 1 );
			$record['rating']          = $float_rating;
			$record['family_friendly'] = $float_rating <= 4.0;
		}

		// Publication date (ISO 8601).
		$date_created = $meta( MM_Metadata::META_DATE );
		if ( '' !== $date_created ) {
			$record['pub_date'] = $date_created . 'T00:00:00+00:00';
		}

		// Uploader (mm_creator) with author profile as info URL.
		$creator = $meta( MM_Metadata::META_CREATOR );
		if ( '' !== $creator ) {
			$record['uploader']      = $creator;
			$record['uploader_url']  = get_author_posts_url( (int) $att->post_author );
		}

		return $record;
	}

	/**
	 * Return true if the URL belongs to this site (same origin or a relative path).
	 *
	 * @param  string $url  URL to check.
	 * @return bool
	 */
	private static function is_local_url( string $url ): bool {
		return str_starts_with( $url, home_url() )
			|| ( ! str_starts_with( $url, 'http' ) && ! str_starts_with( $url, '//' ) );
	}

	// -----------------------------------------------------------------------
	// /sitemap-media.xml
	// -----------------------------------------------------------------------

	/**
	 * Build the attachment-pages sitemap XML string.
	 *
	 * Lists all attachment pages for supported media types
	 * (image/*, video/*, audio/*, application/pdf) with
	 * <image:image> or <video:video> extension nodes where applicable.
	 *
	 * @return string  Complete XML document.
	 */
	private static function render_media_sitemap(): string {
		$mime_types = array_merge(
			[ 'image' ], // WordPress shorthand expands to all image/* MIME types.
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
			return self::render_empty_urlset( '', '' );
		}

		// Determine which namespaces are actually needed.
		$settings      = MM_Site_Settings::get_instance();
		$include_images = (bool) $settings->get( 'sitemap.images', true );
		$has_images     = false;
		$has_videos     = false;
		foreach ( $attachments as $att ) {
			$mime = (string) get_post_mime_type( $att->ID );
			if ( $include_images && wp_attachment_is_image( $att->ID ) ) {
				$has_images = true;
			}
			if ( MM_Metadata::is_video_mime( $mime ) ) {
				$has_videos = true;
			}
			if ( $has_images && $has_videos ) {
				break;
			}
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
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

			// PDFs: Google indexes documents directly by file URL, so use the
			// raw attachment URL rather than the WordPress attachment page.
			$loc = MM_Metadata::is_pdf_mime( $mime )
				? wp_get_attachment_url( $att->ID )
				: get_attachment_link( $att->ID );

			if ( ! $loc ) {
				continue;
			}

			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_xml( $loc ) . "</loc>\n";

			if ( $include_images && $has_images && wp_attachment_is_image( $att->ID ) ) {
				$xml .= self::render_image_node( $att->ID, $att );
			} elseif ( $has_videos && MM_Metadata::is_video_mime( $mime ) ) {
				$url = wp_get_attachment_url( $att->ID );
				if ( $url ) {
					$xml .= self::render_video_node(
						self::build_attachment_video_record( $att->ID, $att, $url )
					);
				}
			}

			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>';
		return $xml;
	}

	// -----------------------------------------------------------------------
	// XML node renderers
	// -----------------------------------------------------------------------

	/**
	 * Render a <image:image> extension node for a single image attachment.
	 *
	 * Populated fields:
	 *   image:loc           — Full-size attachment URL.
	 *   image:title         — mm_headline, falling back to post_title.
	 *   image:caption       — post_excerpt (the WordPress caption field).
	 *   image:license       — mm_copyright when it starts with "http".
	 *   image:geo_location  — GPS "City, Country" label, or decimal coordinates.
	 *
	 * @param  int      $id    Attachment post ID.
	 * @param  WP_Post  $post  Attachment post object.
	 * @return string          XML fragment.
	 */
	private static function render_image_node( int $id, WP_Post $post ): string {
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src ) {
			return '';
		}
		[ $url ] = $src;

		$meta = static fn( string $key ): string => (string) get_post_meta( $id, $key, true );

		$xml = "\t\t<image:image>\n";
		$xml .= "\t\t\t<image:loc>" . esc_xml( $url ) . "</image:loc>\n";

		// Title: mm_headline preferred; post_title as fallback.
		$headline = $meta( MM_Metadata::META_HEADLINE );
		$title    = '' !== $headline ? $headline : trim( $post->post_title );
		if ( '' !== $title ) {
			$xml .= "\t\t\t<image:title>" . esc_xml( $title ) . "</image:title>\n";
		}

		// Caption.
		$caption = trim( $post->post_excerpt );
		if ( '' !== $caption ) {
			$xml .= "\t\t\t<image:caption>" . esc_xml( $caption ) . "</image:caption>\n";
		}

		// License: only when mm_copyright is a URL.
		$copyright = $meta( MM_Metadata::META_COPYRIGHT );
		if ( str_starts_with( $copyright, 'http' ) ) {
			$xml .= "\t\t\t<image:license>" . esc_xml( $copyright ) . "</image:license>\n";
		}

		// Geo location: build a human-readable label from GPS or IPTC city/country.
		$lat = $meta( MM_Metadata::META_GPS_LAT );
		$lon = $meta( MM_Metadata::META_GPS_LON );
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
			$xml .= "\t\t\t<image:geo_location>" . esc_xml( $geo_label ) . "</image:geo_location>\n";
		}

		$xml .= "\t\t</image:image>\n";
		return $xml;
	}

	/**
	 * Render a <video:video> extension node from a normalised video record array.
	 *
	 * Expected array keys: thumbnail, title, description, player_loc,
	 * content_loc, duration, pub_date, rating, family_friendly (bool|null),
	 * tags (array), uploader, uploader_url. All keys are optional;
	 * missing/null values are omitted.
	 *
	 * Per Google's spec, player_loc and content_loc are mutually exclusive;
	 * player_loc takes precedence when both are set.
	 *
	 * @param  array $v  Video record.
	 * @return string    XML fragment.
	 */
	private static function render_video_node( array $v ): string {
		$xml = "\t\t<video:video>\n";

		if ( ! empty( $v['thumbnail'] ) ) {
			$xml .= "\t\t\t<video:thumbnail_loc>" . esc_xml( $v['thumbnail'] ) . "</video:thumbnail_loc>\n";
		}

		$xml .= "\t\t\t<video:title>" . esc_xml( $v['title'] ?? '' ) . "</video:title>\n";
		$xml .= "\t\t\t<video:description>" . esc_xml( $v['description'] ?? '' ) . "</video:description>\n";

		// player_loc and content_loc are mutually exclusive.
		if ( ! empty( $v['player_loc'] ) ) {
			$xml .= "\t\t\t<video:player_loc>" . esc_xml( $v['player_loc'] ) . "</video:player_loc>\n";
		} elseif ( ! empty( $v['content_loc'] ) ) {
			$xml .= "\t\t\t<video:content_loc>" . esc_xml( $v['content_loc'] ) . "</video:content_loc>\n";
		}

		if ( null !== ( $v['duration'] ?? null ) && (int) $v['duration'] > 0 ) {
			$xml .= "\t\t\t<video:duration>" . (int) $v['duration'] . "</video:duration>\n";
		}

		if ( ! empty( $v['pub_date'] ) ) {
			$xml .= "\t\t\t<video:publication_date>" . esc_xml( $v['pub_date'] ) . "</video:publication_date>\n";
		}

		if ( null !== ( $v['rating'] ?? null ) ) {
			$xml .= "\t\t\t<video:rating>" . number_format( (float) $v['rating'], 1 ) . "</video:rating>\n";
		}

		if ( null !== ( $v['family_friendly'] ?? null ) ) {
			$xml .= "\t\t\t<video:family_friendly>" . ( $v['family_friendly'] ? 'yes' : 'no' ) . "</video:family_friendly>\n";
		}

		foreach ( (array) ( $v['tags'] ?? [] ) as $tag ) {
			if ( '' !== $tag ) {
				$xml .= "\t\t\t<video:tag>" . esc_xml( (string) $tag ) . "</video:tag>\n";
			}
		}

		if ( ! empty( $v['uploader'] ) ) {
			$info_attr = ! empty( $v['uploader_url'] )
				? ' info="' . esc_attr( $v['uploader_url'] ) . '"'
				: '';
			$xml .= "\t\t\t<video:uploader{$info_attr}>" . esc_xml( $v['uploader'] ) . "</video:uploader>\n";
		}

		$xml .= "\t\t</video:video>\n";
		return $xml;
	}

	/**
	 * Return a well-formed empty <urlset> document for when content or
	 * the feature is disabled.
	 *
	 * @param  string $ns_uri    Optional extra namespace URI (e.g. NS_VIDEO).
	 * @param  string $ns_prefix Namespace prefix to pair with $ns_uri.
	 * @return string            XML document.
	 */
	private static function render_empty_urlset( string $ns_uri, string $ns_prefix ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . '"';
		if ( '' !== $ns_uri && '' !== $ns_prefix ) {
			$xml .= ' xmlns:' . $ns_prefix . '="' . $ns_uri . '"';
		}
		$xml .= "></urlset>\n";
		return $xml;
	}

	// -------------------------------------------------------------------------
	// Cache management helpers
	// -------------------------------------------------------------------------

	/**
	 * Delete cached sitemap XML (called on any content change).
	 */
	public static function flush_sitemap_cache(): void {
		delete_transient( self::CACHE_KEY_MEDIA );
		delete_transient( self::CACHE_KEY_VIDEO );
	}

	/**
	 * Append Sitemap: directives to the WordPress-generated robots.txt.
	 *
	 * @param string $output  Current robots.txt content.
	 * @param bool   $public  Whether search engines are allowed (site is public).
	 * @return string
	 */
	public static function append_robots_txt( string $output, bool $public ): string {
		if ( ! $public ) {
			return $output;
		}
		$settings = MM_Site_Settings::get_instance();
		if ( $settings->get( 'sitemap.enabled', true ) ) {
			$output .= "\nSitemap: " . home_url( '/sitemap-media.xml' );
		}
		if ( $settings->get( 'sitemap.video', true ) ) {
			$output .= "\nSitemap: " . home_url( '/sitemap-video.xml' );
		}
		return $output;
	}

	/**
	 * Ping Google and Bing with the active sitemaps after a post is published.
	 * Requests are non-blocking so they never slow down the publish action.
	 */
	public static function ping_search_engines(): void {
		$settings    = MM_Site_Settings::get_instance();
		$sitemap_urls = [];
		if ( $settings->get( 'sitemap.enabled', true ) ) {
			$sitemap_urls[] = home_url( '/sitemap-media.xml' );
		}
		if ( $settings->get( 'sitemap.video', true ) ) {
			$sitemap_urls[] = home_url( '/sitemap-video.xml' );
		}
		foreach ( $sitemap_urls as $sitemap_url ) {
			$encoded = rawurlencode( $sitemap_url );
			wp_remote_get(
				'https://www.google.com/ping?sitemap=' . $encoded,
				[ 'blocking' => false, 'timeout' => 5 ]
			);
			wp_remote_get(
				'https://www.bing.com/ping?sitemap=' . $encoded,
				[ 'blocking' => false, 'timeout' => 5 ]
			);
		}
	}
}
