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
		update_option( MM_Sitemap::OPT_MEDIA, true );
		update_option( MM_Sitemap::OPT_VIDEO, false );

		$output = MM_Sitemap::append_robots_txt( "User-agent: *\nDisallow: /wp-admin/\n", true );

		$this->assertStringContainsString( 'Sitemap:', $output );
		$this->assertStringContainsString( 'sitemap-media.xml', $output );

		delete_option( MM_Sitemap::OPT_MEDIA );
		delete_option( MM_Sitemap::OPT_VIDEO );
	}

	public function test_append_robots_txt_no_change_when_private(): void {
		$original = "User-agent: *\nDisallow: /wp-admin/\n";
		$output   = MM_Sitemap::append_robots_txt( $original, false );

		$this->assertSame( $original, $output );
	}

	public function test_append_robots_txt_no_sitemap_when_disabled(): void {
		// Use delete_option to ensure get_option returns the default (true),
		// then explicitly set to a falsy value. '0' is more reliable than
		// boolean false in the WP test environment.
		delete_option( MM_Sitemap::OPT_MEDIA );
		delete_option( MM_Sitemap::OPT_VIDEO );
		update_option( MM_Sitemap::OPT_MEDIA, '0' );
		update_option( MM_Sitemap::OPT_VIDEO, '0' );

		$original = "User-agent: *\nDisallow: /wp-admin/\n";
		$output   = MM_Sitemap::append_robots_txt( $original, true );

		$this->assertStringNotContainsString( 'Sitemap:', $output );

		delete_option( MM_Sitemap::OPT_MEDIA );
		delete_option( MM_Sitemap::OPT_VIDEO );
	}

	// ------------------------------------------------------------------
	// ping_search_engines()
	// ------------------------------------------------------------------

	public function test_ping_search_engines_does_not_error(): void {
		update_option( MM_Sitemap::OPT_MEDIA, false );
		update_option( MM_Sitemap::OPT_VIDEO, false );

		MM_Sitemap::ping_search_engines();
		$this->assertTrue( true );

		delete_option( MM_Sitemap::OPT_MEDIA );
		delete_option( MM_Sitemap::OPT_VIDEO );
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
