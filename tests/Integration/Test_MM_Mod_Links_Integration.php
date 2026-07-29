<?php
/**
 * Integration tests for MM_Mod_Links — link extraction and checking.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Mod_Links_Integration extends WP_UnitTestCase {

	private MM_Site_Settings $settings;
	private MM_Mod_Links $links;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( class_exists( 'MM_Mod_Links' ) ) {
			MM_Mod_Links::create_table();
		}
	}

	public function set_up(): void {
		parent::set_up();
		$this->settings = MM_Site_Settings::get_instance();
		$this->links    = new MM_Mod_Links( $this->settings );
	}

	// ------------------------------------------------------------------
	// table_name()
	// ------------------------------------------------------------------

	public function test_table_name_returns_prefixed_name(): void {
		$name = MM_Mod_Links::table_name();
		$this->assertStringStartsWith( $GLOBALS['wpdb']->prefix, $name );
		$this->assertStringContainsString( 'mm_meta_links', $name );
	}

	// ------------------------------------------------------------------
	// parse_links() via extract_from_post()
	// ------------------------------------------------------------------

	public function test_extract_from_post_finds_links(): void {
		$post_id = $this->factory->post->create( [
			'post_content' => '<p>Visit <a href="https://example.com">Example</a> and <a href="https://wordpress.org">WordPress</a>.</p>',
			'post_status'  => 'publish',
		] );

		$this->links->extract_from_post( $post_id, get_post( $post_id ) );

		global $wpdb;
		$table  = MM_Mod_Links::table_name();
		$count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id ) );

		$this->assertSame( 2, $count );
	}

	public function test_extract_from_post_skips_mailto_links(): void {
		$post_id = $this->factory->post->create( [
			'post_content' => '<p>Email <a href="mailto:test@example.com">us</a>.</p>',
			'post_status'  => 'publish',
		] );

		$this->links->extract_from_post( $post_id, get_post( $post_id ) );

		global $wpdb;
		$table = MM_Mod_Links::table_name();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id ) );

		$this->assertSame( 0, $count );
	}

	public function test_extract_from_post_skips_javascript_links(): void {
		$post_id = $this->factory->post->create( [
			'post_content' => '<p><a href="javascript:void(0)">Click</a>.</p>',
			'post_status'  => 'publish',
		] );

		$this->links->extract_from_post( $post_id, get_post( $post_id ) );

		global $wpdb;
		$table = MM_Mod_Links::table_name();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id ) );

		$this->assertSame( 0, $count );
	}

	public function test_extract_from_post_skips_draft_posts(): void {
		$post_id = $this->factory->post->create( [
			'post_content' => '<p><a href="https://example.com">Link</a></p>',
			'post_status'  => 'draft',
		] );

		$this->links->extract_from_post( $post_id, get_post( $post_id ) );

		global $wpdb;
		$table = MM_Mod_Links::table_name();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id ) );

		$this->assertSame( 0, $count );
	}

	// ------------------------------------------------------------------
	// purge_for_post()
	// ------------------------------------------------------------------

	public function test_purge_for_post_removes_all_links(): void {
		$post_id = $this->factory->post->create( [
			'post_content' => '<p><a href="https://example.com">Link</a></p>',
			'post_status'  => 'publish',
		] );

		$this->links->extract_from_post( $post_id, get_post( $post_id ) );

		global $wpdb;
		$table = MM_Mod_Links::table_name();

		$count_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id ) );
		$this->assertGreaterThan( 0, $count_before );

		$this->links->purge_for_post( $post_id );

		$count_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id ) );
		$this->assertSame( 0, $count_after );
	}

	// ------------------------------------------------------------------
	// backfill_posts()
	// ------------------------------------------------------------------

	public function test_backfill_posts_returns_structured_result(): void {
		$this->factory->post->create( [
			'post_content' => '<p><a href="https://example.com">Link</a></p>',
			'post_status'  => 'publish',
		] );

		$result = $this->links->backfill_posts( 0, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'scanned', $result );
		$this->assertArrayHasKey( 'skipped', $result );
		$this->assertArrayHasKey( 'new_offset', $result );
		$this->assertArrayHasKey( 'done', $result );
	}

	public function test_backfill_posts_done_flag(): void {
		$result = $this->links->backfill_posts( 1000, 10 );
		$this->assertTrue( $result['done'] );
	}
}
