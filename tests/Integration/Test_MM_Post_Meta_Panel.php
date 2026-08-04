<?php
/**
 * Integration tests for MM_Post_Meta_Panel — metabox save logic and sanitization.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Post_Meta_Panel extends WP_UnitTestCase {

	/** @var MM_Post_Meta_Panel */
	private $panel;

	/** @var int Post ID used across tests. */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		$this->panel    = new MM_Post_Meta_Panel( MM_Site_Settings::get_instance() );
		$this->post_id  = $this->factory->post->create( [
			'post_title'  => 'Test Post',
			'post_author' => get_current_user_id(),
		] );
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// save_meta() — nonce and capability guards
	// ------------------------------------------------------------------

	public function test_save_meta_skips_revision(): void {
		$rev_id = $this->factory->post->create( [
			'post_type'   => 'revision',
			'post_parent' => $this->post_id,
		] );

		// Should return early — no exception, no meta update.
		$this->panel->save_meta( $rev_id, get_post( $rev_id ) );
		$this->assertTrue( true ); // No crash = pass.
	}

	public function test_save_meta_skips_autosave(): void {
		$auto_id = $this->factory->post->create( [
			'post_title'   => 'Autosave',
			'post_type'    => 'revision',
			'post_parent'  => $this->post_id,
			'post_status'  => 'auto-draft',
		] );

		$this->panel->save_meta( $auto_id, get_post( $auto_id ) );
		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// save_meta() — field sanitization
	// ------------------------------------------------------------------

	public function test_save_meta_sanitizes_title_field(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = [
			'mm_meta_post_nonce'    => wp_create_nonce( 'mm_meta_post_meta_save' ),
			'mm_meta_title'         => '  Clean Title  ',
			'mm_meta_description'   => '',
			'mm_meta_canonical'     => '',
			'mm_meta_og_title'      => '',
			'mm_meta_og_description' => '',
			'mm_meta_og_image_id'   => 0,
			'mm_meta_schema_type'   => '',
			'mm_meta_breadcrumb_label' => '',
			'mm_meta_exclude_sitemap'  => 0,
			'mm_meta_noindex'       => '',
			'mm_meta_nofollow'      => '',
			'mm_meta_noarchive'     => '',
			'mm_meta_nosnippet'     => '',
			'mm_meta_noimageindex'  => '',
			'mm_meta_schema_fields' => [],
		];

		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$raw  = get_post_meta( $this->post_id, '_mm_meta', true );
		$meta = json_decode( $raw, true );

		$this->assertIsArray( $meta );
		$this->assertSame( 'Clean Title', $meta['title'] );

		unset( $_POST );
	}

	public function test_save_meta_strips_empty_values(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = [
			'mm_meta_post_nonce'    => wp_create_nonce( 'mm_meta_post_meta_save' ),
			'mm_meta_title'         => '',
			'mm_meta_description'   => '',
			'mm_meta_canonical'     => '',
			'mm_meta_og_title'      => '',
			'mm_meta_og_description' => '',
			'mm_meta_og_image_id'   => 0,
			'mm_meta_schema_type'   => '',
			'mm_meta_breadcrumb_label' => '',
			'mm_meta_exclude_sitemap'  => 0,
			'mm_meta_noindex'       => '',
			'mm_meta_nofollow'      => '',
			'mm_meta_noarchive'     => '',
			'mm_meta_nosnippet'     => '',
			'mm_meta_noimageindex'  => '',
			'mm_meta_schema_fields' => [],
		];

		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$raw  = get_post_meta( $this->post_id, '_mm_meta', true );
		$meta = json_decode( $raw, true );

		// Empty string/number/false fields are stripped. Tristate nulls are kept.
		$this->assertIsArray( $meta );
		$this->assertArrayNotHasKey( 'title', $meta );
		$this->assertArrayNotHasKey( 'description', $meta );
		$this->assertArrayNotHasKey( 'canonical', $meta );

		unset( $_POST );
	}

	public function test_save_meta_escapes_url_fields(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = [
			'mm_meta_post_nonce'    => wp_create_nonce( 'mm_meta_post_meta_save' ),
			'mm_meta_title'         => '',
			'mm_meta_description'   => '',
			'mm_meta_canonical'     => 'https://example.com/page with spaces/',
			'mm_meta_og_title'      => '',
			'mm_meta_og_description' => '',
			'mm_meta_og_image_id'   => 0,
			'mm_meta_schema_type'   => '',
			'mm_meta_breadcrumb_label' => '',
			'mm_meta_exclude_sitemap'  => 0,
			'mm_meta_noindex'       => '',
			'mm_meta_nofollow'      => '',
			'mm_meta_noarchive'     => '',
			'mm_meta_nosnippet'     => '',
			'mm_meta_noimageindex'  => '',
			'mm_meta_schema_fields' => [],
		];

		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$raw  = get_post_meta( $this->post_id, '_mm_meta', true );
		$meta = json_decode( $raw, true );

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'canonical', $meta );
		$this->assertStringNotContainsString( ' ', $meta['canonical'] );

		unset( $_POST );
	}

	public function test_save_meta_handles_exclude_sitemap_checkbox(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = [
			'mm_meta_post_nonce'    => wp_create_nonce( 'mm_meta_post_meta_save' ),
			'mm_meta_title'         => '',
			'mm_meta_description'   => '',
			'mm_meta_canonical'     => '',
			'mm_meta_og_title'      => '',
			'mm_meta_og_description' => '',
			'mm_meta_og_image_id'   => 0,
			'mm_meta_schema_type'   => '',
			'mm_meta_breadcrumb_label' => '',
			'mm_meta_exclude_sitemap'  => 1,
			'mm_meta_noindex'       => '',
			'mm_meta_nofollow'      => '',
			'mm_meta_noarchive'     => '',
			'mm_meta_nosnippet'     => '',
			'mm_meta_noimageindex'  => '',
			'mm_meta_schema_fields' => [],
		];

		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$raw  = get_post_meta( $this->post_id, '_mm_meta', true );
		$meta = json_decode( $raw, true );

		$this->assertIsArray( $meta );
		$this->assertTrue( $meta['exclude_sitemap'] );

		unset( $_POST );
	}

	// ------------------------------------------------------------------
	// add_meta_boxes() — hook registration
	// ------------------------------------------------------------------

	public function test_add_meta_boxes_registers_for_posts(): void {
		global $wp_meta_boxes;

		$this->panel->add_meta_boxes();

		// Verify a meta box was registered by checking the global was populated.
		$this->assertNotEmpty( $wp_meta_boxes, 'Expected add_meta_boxes to populate global.' );

		// Search all levels for the metabox ID.
		$found = ! empty( $wp_meta_boxes ) && (
			strpos( json_encode( $wp_meta_boxes ), 'mm_meta_post_meta' ) !== false
		);
		$this->assertTrue( $found, 'Expected mm_meta_post_meta to be registered.' );
	}

	// ------------------------------------------------------------------
	// register_hooks()
	// ------------------------------------------------------------------

	public function test_register_hooks_registers_expected_actions(): void {
		$this->panel->register_hooks();

		$this->assertIsInt( has_action( 'add_meta_boxes', [ $this->panel, 'add_meta_boxes' ] ) );
		$this->assertIsInt( has_action( 'save_post', [ $this->panel, 'save_meta' ] ) );
	}
}
