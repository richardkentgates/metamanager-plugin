<?php
/**
 * Integration tests for MM_Sitemap — XML generation and rewrite rules.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Sitemap_Integration extends WP_UnitTestCase {

	// ------------------------------------------------------------------
	// flush_sitemap_cache()
	// ------------------------------------------------------------------

	public function test_flush_sitemap_cache_deletes_transients(): void {
		set_transient( 'mm_sitemap_cache_media', '<xml/>', HOUR_IN_SECONDS );
		set_transient( 'mm_sitemap_cache_video', '<xml/>', HOUR_IN_SECONDS );

		MM_Sitemap::flush_sitemap_cache();

		$this->assertFalse( get_transient( 'mm_sitemap_cache_media' ) );
		$this->assertFalse( get_transient( 'mm_sitemap_cache_video' ) );
	}

	// ------------------------------------------------------------------
	// append_robots_txt()
	// ------------------------------------------------------------------

	public function test_append_robots_txt_adds_sitemap_when_media_enabled(): void {
		MM_Site_Settings::reset_instance();
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => true, 'video' => false ],
		] );
		MM_Site_Settings::reset_instance();

		$output = MM_Sitemap::append_robots_txt( "User-agent: *\nDisallow: /wp-admin/\n", true );

		$this->assertStringContainsString( 'Sitemap:', $output );
		$this->assertStringContainsString( 'sitemap-media.xml', $output );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	public function test_append_robots_txt_no_change_when_private(): void {
		$original = "User-agent: *\nDisallow: /wp-admin/\n";
		$output   = MM_Sitemap::append_robots_txt( $original, false );

		$this->assertSame( $original, $output );
	}

	public function test_append_robots_txt_no_sitemap_when_disabled(): void {
		MM_Site_Settings::reset_instance();
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => false, 'video' => false ],
		] );
		MM_Site_Settings::reset_instance();

		$original = "User-agent: *\nDisallow: /wp-admin/\n";
		$output   = MM_Sitemap::append_robots_txt( $original, true );

		$this->assertStringNotContainsString( 'Sitemap:', $output );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	// ------------------------------------------------------------------
	// ping_search_engines()
	// ------------------------------------------------------------------

	public function test_ping_search_engines_does_not_error(): void {
		MM_Site_Settings::reset_instance();
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => false, 'video' => false ],
		] );
		MM_Site_Settings::reset_instance();

		MM_Sitemap::ping_search_engines();
		$this->assertTrue( true );

		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	// ------------------------------------------------------------------
	// XML namespace constants
	// ------------------------------------------------------------------

	public function test_sitemap_namespace_constants(): void {
		$this->assertSame( 'http://www.sitemaps.org/schemas/sitemap/0.9', MM_Sitemap::NS_SITEMAP );
		$this->assertSame( 'http://www.google.com/schemas/sitemap-image/1.1', MM_Sitemap::NS_IMAGE );
		$this->assertSame( 'http://www.google.com/schemas/sitemap-video/0.9', MM_Sitemap::NS_VIDEO );
	}

	// ------------------------------------------------------------------
	// Cache key constants
	// ------------------------------------------------------------------

	public function test_cache_key_constants(): void {
		$this->assertSame( 'mm_sitemap_cache_media', MM_Sitemap::CACHE_KEY_MEDIA );
		$this->assertSame( 'mm_sitemap_cache_video', MM_Sitemap::CACHE_KEY_VIDEO );
		$this->assertSame( HOUR_IN_SECONDS, MM_Sitemap::CACHE_TTL );
	}
}
