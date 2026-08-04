<?php
/**
 * Integration tests for MM_Mod_Sitemap_Web — XML generation and rewrite rules.
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
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// flush_sitemap_cache()
	// ------------------------------------------------------------------

	public function test_flush_sitemap_cache_updates_option(): void {
		$before = (int) get_option( 'mm_sitemap_cache_ver', 0 );
		// Wait ensures time() advances.
		sleep( 1 );
		$this->sitemap->flush_sitemap_cache();
		$after = (int) get_option( 'mm_sitemap_cache_ver', 0 );

		$this->assertGreaterThan( $before, $after );
	}

	// ------------------------------------------------------------------
	// append_robots_txt()
	// ------------------------------------------------------------------

	public function test_append_robots_txt_adds_sitemap_when_media_enabled(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'sitemap' => [ 'enabled' => true, 'video' => false ],
		] );
		MM_Site_Settings::reset_instance();
		$this->sitemap = new MM_Mod_Sitemap_Web( MM_Site_Settings::get_instance() );

		// Create an image attachment so has_media_attachments() returns true.
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

	// ------------------------------------------------------------------
	// send_ping() — just verify it doesn't error
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
	// XML namespace constants
	// ------------------------------------------------------------------

	public function test_sitemap_namespace_constants(): void {
		$this->assertSame( 'http://www.sitemaps.org/schemas/sitemap/0.9', MM_Mod_Sitemap_Web::NS_SITEMAP );
		$this->assertSame( 'http://www.google.com/schemas/sitemap-image/1.1', MM_Mod_Sitemap_Web::NS_IMAGE );
		$this->assertSame( 'http://www.google.com/schemas/sitemap-video/0.9', MM_Mod_Sitemap_Web::NS_VIDEO );
	}
}
