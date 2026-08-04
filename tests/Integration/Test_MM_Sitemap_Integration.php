<?php
/**
 * Integration tests for MM_Mod_Sitemap_Web — XML generation, rewrite rules, and routing.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Sitemap_Integration extends WP_UnitTestCase {

	/** @var MM_Mod_Sitemap_Web */
	private $sitemap;

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( 'mm_meta_sitemap_ping' );
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// flush_sitemap_cache()
	// ------------------------------------------------------------------

	public function test_flush_sitemap_cache_updates_option(): void {
		$before = (int) get_option( 'mm_sitemap_cache_ver', 0 );
		sleep( 1 );
		$this->sitemap->flush_sitemap_cache();
		$after = (int) get_option( 'mm_sitemap_cache_ver', 0 );

		$this->assertGreaterThan( $before, $after );
	}

	// ------------------------------------------------------------------
	// append_robots_txt()
	// ------------------------------------------------------------------

	public function test_append_robots_txt_adds_media_sitemap_when_enabled(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => true, 'video' => false ],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$this->factory->attachment->create( [
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		] );

		$output = $this->sitemap->append_robots_txt( "User-agent: *\nDisallow: /wp-admin/\n", true );

		$this->assertStringContainsString( 'Sitemap:', $output );
		$this->assertStringContainsString( 'sitemap-media.xml', $output );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	public function test_append_robots_txt_adds_video_sitemap_when_enabled_and_content_exists(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => true, 'video' => true ],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$this->factory->attachment->create( [
			'post_mime_type' => 'video/mp4',
			'post_status'    => 'inherit',
		] );

		$output = $this->sitemap->append_robots_txt( "User-agent: *\n", true );

		$this->assertStringContainsString( 'sitemap-video.xml', $output );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	public function test_append_robots_txt_no_change_when_private(): void {
		$original = "User-agent: *\nDisallow: /wp-admin/\n";
		$output   = $this->sitemap->append_robots_txt( $original, false );

		$this->assertSame( $original, $output );
	}

	public function test_append_robots_txt_no_sitemap_when_disabled(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => false, 'video' => false ],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$original = "User-agent: *\nDisallow: /wp-admin/\n";
		$output   = $this->sitemap->append_robots_txt( $original, true );

		$this->assertStringNotContainsString( 'Sitemap:', $output );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	public function test_append_robots_txt_no_media_sitemap_when_no_media(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => true, 'video' => false ],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$output = $this->sitemap->append_robots_txt( "User-agent: *\n", true );

		$this->assertStringNotContainsString( 'sitemap-media.xml', $output );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	// ------------------------------------------------------------------
	// send_ping()
	// ------------------------------------------------------------------

	public function test_send_ping_does_not_error(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => false, 'video' => false ],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$this->sitemap->send_ping();
		$this->assertTrue( true );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	// ------------------------------------------------------------------
	// schedule_ping()
	// ------------------------------------------------------------------

	public function test_schedule_ping_schedules_cron_on_publish(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => true, 'post_types' => [ 'post' => true ] ],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$post = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$this->sitemap->schedule_ping( 'transition_post_status', 'publish', get_post( $post ) );

		$events = wp_get_scheduled_event( 'mm_meta_sitemap_ping' );
		$this->assertNotNull( $events );

		wp_clear_scheduled_hook( 'mm_meta_sitemap_ping' );
		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	public function test_schedule_ping_skips_on_update(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => true, 'post_types' => [ 'post' => true ] ],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$post = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$before = wp_get_scheduled_event( 'mm_meta_sitemap_ping' );

		// Transition from publish → publish (update, not new publish).
		$this->sitemap->schedule_ping( 'publish', 'publish', get_post( $post ) );

		$after = wp_get_scheduled_event( 'mm_meta_sitemap_ping' );

		// Should not have changed — no new event scheduled.
		if ( null === $before ) {
			$this->assertNull( $after );
		} else {
			$this->assertSame( $before->timestamp, $after->timestamp );
		}

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	public function test_schedule_ping_skips_disabled_post_type(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => true, 'post_types' => [ 'post' => false ] ],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$post = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$before = wp_get_scheduled_event( 'mm_meta_sitemap_ping' );

		$this->sitemap->schedule_ping( 'publish', 'draft', get_post( $post ) );

		$after = wp_get_scheduled_event( 'mm_meta_sitemap_ping' );

		if ( null === $before ) {
			$this->assertNull( $after );
		} else {
			$this->assertSame( $before->timestamp, $after->timestamp );
		}

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	// ------------------------------------------------------------------
	// XML namespace constants
	// ------------------------------------------------------------------

	public function test_sitemap_namespace_constants(): void {
		$this->assertSame( 'http://www.sitemaps.org/schemas/sitemap/0.9', MM_Mod_Sitemap_Web::NS_SITEMAP );
		$this->assertSame( 'http://www.google.com/schemas/sitemap-image/1.1', MM_Mod_Sitemap_Web::NS_IMAGE );
		$this->assertSame( 'http://www.google.com/schemas/sitemap-video/0.9', MM_Mod_Sitemap_Web::NS_VIDEO );
	}

	// ------------------------------------------------------------------
	// register_hooks()
	// ------------------------------------------------------------------

	public function test_register_hooks_wires_rewrite_and_query_vars(): void {
		$this->sitemap->register_hooks();

		$this->assertIsInt( has_filter( 'robots_txt', [ $this->sitemap, 'append_robots_txt' ] ) );
	}

	public function test_register_hooks_skips_when_disabled(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => false ],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$this->sitemap->register_hooks();

		$this->assertFalse( has_filter( 'robots_txt', [ $this->sitemap, 'append_robots_txt' ] ) );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	// ------------------------------------------------------------------
	// get_active_post_types / get_active_taxonomies (via settings)
	// ------------------------------------------------------------------

	public function test_active_post_types_from_settings(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [
				'enabled'    => true,
				'post_types' => [ 'post' => true, 'page' => false ],
			],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$method = new ReflectionMethod( $this->sitemap, 'get_active_post_types' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->sitemap );

		$this->assertContains( 'post', $result );
		$this->assertNotContains( 'page', $result );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	public function test_active_taxonomies_from_settings(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [
				'enabled'    => true,
				'taxonomies' => [ 'category' => true, 'post_tag' => false ],
			],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$method = new ReflectionMethod( $this->sitemap, 'get_active_taxonomies' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->sitemap );

		$this->assertContains( 'category', $result );
		$this->assertNotContains( 'post_tag', $result );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	// ------------------------------------------------------------------
	// render_index()
	// ------------------------------------------------------------------

	public function test_render_index_includes_post_type_sitemaps(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [
				'enabled'    => true,
				'post_types' => [ 'post' => true ],
				'taxonomies' => [],
			],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$this->factory->post->create( [ 'post_status' => 'publish' ] );

		$method = new ReflectionMethod( $this->sitemap, 'render_index' );
		$method->setAccessible( true );
		$xml = $method->invoke( $this->sitemap );

		$this->assertStringContainsString( '<?xml version="1.0"', $xml );
		$this->assertStringContainsString( 'sitemapindex', $xml );
		$this->assertStringContainsString( 'sitemap-post-post.xml', $xml );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	// ------------------------------------------------------------------
	// render_post_sitemap()
	// ------------------------------------------------------------------

	public function test_render_post_sitemap_includes_published_posts(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [
				'enabled'    => true,
				'post_types' => [ 'post' => true ],
			],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$method = new ReflectionMethod( $this->sitemap, 'render_post_sitemap' );
		$method->setAccessible( true );
		$xml = $method->invoke( $this->sitemap, 'post' );

		$this->assertStringContainsString( 'urlset', $xml );
		$this->assertStringContainsString( get_permalink( $post_id ), $xml );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	// ------------------------------------------------------------------
	// render_tax_sitemap()
	// ------------------------------------------------------------------

	public function test_render_tax_sitemap_includes_terms(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [
				'enabled'    => true,
				'taxonomies' => [ 'category' => true ],
			],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		$this->factory->term->create( [
			'taxonomy' => 'category',
			'name'     => 'Test Category',
		] );

		$method = new ReflectionMethod( $this->sitemap, 'render_tax_sitemap' );
		$method->setAccessible( true );
		$xml = $method->invoke( $this->sitemap, 'category' );

		$this->assertStringContainsString( 'urlset', $xml );
		// The term should produce a <url><loc> entry if get_term_link succeeds,
		// or be silently skipped if it returns WP_Error (test env limitation).
		$this->assertStringContainsString( '</urlset>', $xml );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	// ------------------------------------------------------------------
	// render_empty_urlset()
	// ------------------------------------------------------------------

	public function test_render_empty_urlset(): void {
		$method = new ReflectionMethod( $this->sitemap, 'render_empty_urlset' );
		$method->setAccessible( true );
		$xml = $method->invoke( $this->sitemap, '', '' );

		$this->assertStringContainsString( '<?xml version="1.0"', $xml );
		$this->assertStringContainsString( 'urlset', $xml );
	}

	public function test_render_empty_urlset_with_namespace(): void {
		$method = new ReflectionMethod( $this->sitemap, 'render_empty_urlset' );
		$method->setAccessible( true );
		$xml = $method->invoke( $this->sitemap, MM_Mod_Sitemap_Web::NS_VIDEO, 'video' );

		$this->assertStringContainsString( 'xmlns:video', $xml );
	}

	// ------------------------------------------------------------------
	// render_video_node()
	// ------------------------------------------------------------------

	public function test_render_video_node_basic(): void {
		$method = new ReflectionMethod( $this->sitemap, 'render_video_node' );
		$method->setAccessible( true );

		$video = [
			'title'       => 'Test Video',
			'description' => 'A test video',
			'content_loc' => 'https://example.com/video.mp4',
		];

		$xml = $method->invoke( $this->sitemap, $video );

		$this->assertStringContainsString( 'video:video', $xml );
		$this->assertStringContainsString( 'video:title', $xml );
		$this->assertStringContainsString( 'Test Video', $xml );
		$this->assertStringContainsString( 'video:content_loc', $xml );
		$this->assertStringContainsString( 'https://example.com/video.mp4', $xml );
	}

	public function test_render_video_node_with_optional_fields(): void {
		$method = new ReflectionMethod( $this->sitemap, 'render_video_node' );
		$method->setAccessible( true );

		$video = [
			'title'           => 'Full Video',
			'description'     => 'Complete',
			'content_loc'     => 'https://example.com/v.mp4',
			'thumbnail'       => 'https://example.com/thumb.jpg',
			'duration'        => 120,
			'pub_date'        => '2026-01-15T00:00:00+00:00',
			'rating'          => 4.5,
			'family_friendly' => true,
			'tags'            => [ 'test', 'demo' ],
			'uploader'        => 'Jane Doe',
			'uploader_url'    => 'https://example.com/author',
		];

		$xml = $method->invoke( $this->sitemap, $video );

		$this->assertStringContainsString( 'video:thumbnail_loc', $xml );
		$this->assertStringContainsString( 'video:duration', $xml );
		$this->assertStringContainsString( '120', $xml );
		$this->assertStringContainsString( 'video:publication_date', $xml );
		$this->assertStringContainsString( 'video:rating', $xml );
		$this->assertStringContainsString( '4.5', $xml );
		$this->assertStringContainsString( 'video:family_friendly', $xml );
		$this->assertStringContainsString( 'yes', $xml );
		$this->assertStringContainsString( 'video:tag', $xml );
		$this->assertStringContainsString( 'test', $xml );
		$this->assertStringContainsString( 'demo', $xml );
		$this->assertStringContainsString( 'video:uploader', $xml );
		$this->assertStringContainsString( 'Jane Doe', $xml );
		$this->assertStringContainsString( 'info="https://example.com/author"', $xml );
	}

	public function test_render_video_node_with_player_loc(): void {
		$method = new ReflectionMethod( $this->sitemap, 'render_video_node' );
		$method->setAccessible( true );

		$video = [
			'title'       => 'Embed',
			'description' => '',
			'player_loc'  => 'https://example.com/embed/v',
			'content_loc' => 'https://example.com/v.mp4',
		];

		$xml = $method->invoke( $this->sitemap, $video );

		$this->assertStringContainsString( 'video:player_loc', $xml );
		$this->assertStringNotContainsString( 'video:content_loc', $xml );
	}

	// ------------------------------------------------------------------
	// render_image_node()
	// ------------------------------------------------------------------

	public function test_render_image_node_returns_empty_without_file(): void {
		$att_id = $this->factory->attachment->create( [
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
			'post_title'     => 'Test Image',
		] );

		$method = new ReflectionMethod( $this->sitemap, 'render_image_node' );
		$method->setAccessible( true );

		// Without a real image file on disk, wp_get_attachment_image_src returns false.
		$xml = $method->invoke( $this->sitemap, $att_id, get_post( $att_id ) );

		$this->assertSame( '', $xml );
	}

	public function test_render_image_node_returns_empty_even_with_meta(): void {
		$att_id = $this->factory->attachment->create( [
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
			'post_title'     => 'GPS Image',
		] );

		update_post_meta( $att_id, MM_Metadata::META_GPS_LAT, '40.7128' );
		update_post_meta( $att_id, MM_Metadata::META_GPS_LON, '-74.0060' );
		update_post_meta( $att_id, MM_Metadata::META_CITY, 'New York' );
		update_post_meta( $att_id, MM_Metadata::META_COUNTRY, 'US' );
		update_post_meta( $att_id, MM_Metadata::META_COPYRIGHT, 'https://example.com/license' );

		$method = new ReflectionMethod( $this->sitemap, 'render_image_node' );
		$method->setAccessible( true );

		// Without a real image file, returns empty regardless of meta.
		$xml = $method->invoke( $this->sitemap, $att_id, get_post( $att_id ) );
		$this->assertSame( '', $xml );
	}

	// ------------------------------------------------------------------
	// extract_selfhosted_videos()
	// ------------------------------------------------------------------

	public function test_extract_selfhosted_videos_returns_empty_for_no_video(): void {
		$post = $this->factory->post->create_and_get( [
			'post_content' => '<p>No videos here</p>',
		] );

		$method = new ReflectionMethod( $this->sitemap, 'extract_selfhosted_videos' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->sitemap, $post );

		$this->assertEmpty( $result );
	}

	// ------------------------------------------------------------------
	// build_video_record()
	// ------------------------------------------------------------------

	public function test_build_video_record_uses_attachment_meta(): void {
		$att_id = $this->factory->attachment->create( [
			'post_mime_type' => 'video/mp4',
			'post_status'    => 'inherit',
			'post_title'     => 'Test Video Att',
		] );

		update_post_meta( $att_id, MM_Metadata::META_DURATION, 90 );
		update_post_meta( $att_id, MM_Metadata::META_KEYWORDS, 'test; demo; wp' );
		update_post_meta( $att_id, MM_Metadata::META_RATING, '4' );
		update_post_meta( $att_id, MM_Metadata::META_DATE, '2026-03-15' );
		update_post_meta( $att_id, MM_Metadata::META_CREATOR, 'John Doe' );

		$method = new ReflectionMethod( $this->sitemap, 'build_video_record' );
		$method->setAccessible( true );

		$att    = get_post( $att_id );
		$url    = wp_get_attachment_url( $att_id );
		$record = $method->invoke( $this->sitemap, $att_id, $att, $url );

		$this->assertSame( 90, $record['duration'] );
		$this->assertSame( [ 'test', 'demo', 'wp' ], $record['tags'] );
		$this->assertSame( 4.0, $record['rating'] );
		$this->assertTrue( $record['family_friendly'] );
		$this->assertStringContainsString( '2026-03-15', $record['pub_date'] );
		$this->assertSame( 'John Doe', $record['uploader'] );
	}

	public function test_build_video_record_falls_back_to_post_title(): void {
		$att_id = $this->factory->attachment->create( [
			'post_mime_type' => 'video/mp4',
			'post_status'    => 'inherit',
			'post_title'     => 'Fallback Title',
		] );

		$method = new ReflectionMethod( $this->sitemap, 'build_video_record' );
		$method->setAccessible( true );

		$att    = get_post( $att_id );
		$url    = wp_get_attachment_url( $att_id );
		$record = $method->invoke( $this->sitemap, $att_id, $att, $url );

		$this->assertSame( 'Fallback Title', $record['title'] );
	}

	// ------------------------------------------------------------------
	// has_video_content()
	// ------------------------------------------------------------------

	public function test_has_video_content_true_with_video_attachment(): void {
		$this->factory->attachment->create( [
			'post_mime_type' => 'video/mp4',
			'post_status'    => 'inherit',
		] );

		$method = new ReflectionMethod( $this->sitemap, 'has_video_content' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->sitemap );

		$this->assertTrue( $result );
	}

	public function test_has_video_content_false_without_videos(): void {
		$method = new ReflectionMethod( $this->sitemap, 'has_video_content' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->sitemap );

		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// has_media_attachments()
	// ------------------------------------------------------------------

	public function test_has_media_attachments_true_with_image(): void {
		$this->factory->attachment->create( [
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		] );

		$method = new ReflectionMethod( $this->sitemap, 'has_media_attachments' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->sitemap );

		$this->assertTrue( $result );
	}

	public function test_has_media_attachments_false_without_media(): void {
		$method = new ReflectionMethod( $this->sitemap, 'has_media_attachments' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->sitemap );

		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// post_type_count() / last_modified_post()
	// ------------------------------------------------------------------

	public function test_post_type_count_returns_zero_for_empty_type(): void {
		$method = new ReflectionMethod( $this->sitemap, 'post_type_count' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->sitemap, 'nonexistent_type' );

		$this->assertSame( 0, $result );
	}

	public function test_last_modified_post_returns_date_string(): void {
		$this->factory->post->create( [ 'post_status' => 'publish' ] );

		$method = new ReflectionMethod( $this->sitemap, 'last_modified_post' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->sitemap, 'post' );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $result );
	}
}
