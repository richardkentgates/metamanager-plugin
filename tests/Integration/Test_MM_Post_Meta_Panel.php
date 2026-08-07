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
		$this->panel   = new MM_Post_Meta_Panel( MM_Site_Settings::get_instance() );
		$this->post_id = $this->factory->post->create( [
			'post_title'  => 'Test Post',
			'post_author' => get_current_user_id(),
		] );
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// save_meta() — early returns
	// ------------------------------------------------------------------

	public function test_save_meta_skips_revision(): void {
		$rev_id = $this->factory->post->create( [
			'post_type'   => 'revision',
			'post_parent' => $this->post_id,
		] );

		$this->panel->save_meta( $rev_id, get_post( $rev_id ) );
		// Meta not set — get_post_meta returns '' for non-existent keys.
		$this->assertEmpty( get_post_meta( $rev_id, MM_META_KEY, true ) );
	}

	public function test_save_meta_skips_autosave(): void {
		$auto_id = $this->factory->post->create( [
			'post_title'  => 'Autosave',
			'post_type'   => 'revision',
			'post_parent' => $this->post_id,
			'post_status' => 'auto-draft',
		] );

		$this->panel->save_meta( $auto_id, get_post( $auto_id ) );
		$this->assertEmpty( get_post_meta( $auto_id, MM_META_KEY, true ) );
	}

	public function test_save_meta_skips_without_nonce(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = [ 'mm_meta_canonical' => 'https://example.com' ];

		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$this->assertEmpty( get_post_meta( $this->post_id, MM_META_KEY, true ) );

		unset( $_POST );
	}

	public function test_save_meta_skips_with_invalid_nonce(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = [
			'mm_meta_post_nonce' => 'invalid_nonce_value',
			'mm_meta_canonical'  => 'https://example.com',
		];

		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$this->assertEmpty( get_post_meta( $this->post_id, MM_META_KEY, true ) );

		unset( $_POST );
	}

	public function test_save_meta_skips_without_edit_capability(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$_POST = [
			'mm_meta_post_nonce' => wp_create_nonce( 'mm_meta_post_meta_save' ),
			'mm_meta_canonical'  => 'https://example.com',
		];

		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$this->assertEmpty( get_post_meta( $this->post_id, MM_META_KEY, true ) );

		unset( $_POST );
	}

	// ------------------------------------------------------------------
	// save_meta() — field sanitization
	// ------------------------------------------------------------------

	public function test_save_meta_escapes_url_fields(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = $this->base_post( [
			'mm_meta_canonical' => 'https://example.com/page with spaces/',
		] );

		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$raw  = get_post_meta( $this->post_id, MM_META_KEY, true );
		$meta = json_decode( $raw, true );

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'canonical', $meta );
		$this->assertStringNotContainsString( ' ', $meta['canonical'] );

		unset( $_POST );
	}

	public function test_save_meta_handles_exclude_sitemap_checkbox(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = $this->base_post( [ 'mm_meta_exclude_sitemap' => 1 ] );

		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$raw  = get_post_meta( $this->post_id, MM_META_KEY, true );
		$meta = json_decode( $raw, true );

		$this->assertIsArray( $meta );
		$this->assertTrue( $meta['exclude_sitemap'] );

		unset( $_POST );
	}

	// ------------------------------------------------------------------
	// save_meta() — tristate fields
	//
	// Tristate behavior: '' → null (default), '1' → true, '0' → false.
	// Null tristates ARE persisted in $clean because null passes the strip
	// loop ($v === '' || $v === 0 || $v === false). When no existing value,
	// null stays. When existing, null removes the override.
	// ------------------------------------------------------------------

	public function test_save_meta_tristate_noindex_true(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = $this->base_post( [ 'mm_meta_noindex' => '1' ] );
		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$meta = json_decode( get_post_meta( $this->post_id, MM_META_KEY, true ), true );

		$this->assertTrue( $meta['noindex'] );

		unset( $_POST );
	}

	public function test_save_meta_tristate_noindex_false(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = $this->base_post( [ 'mm_meta_noindex' => '0' ] );
		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$meta = json_decode( get_post_meta( $this->post_id, MM_META_KEY, true ), true );

		$this->assertFalse( $meta['noindex'] );

		unset( $_POST );
	}

	public function test_save_meta_tristate_noindex_default_stored_as_null(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = $this->base_post( [ 'mm_meta_noindex' => '' ] );
		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$meta = json_decode( get_post_meta( $this->post_id, MM_META_KEY, true ), true );

		// Null tristate IS persisted (no existing → stays as null).
		$this->assertArrayHasKey( 'noindex', $meta );
		$this->assertNull( $meta['noindex'] );

		unset( $_POST );
	}

	public function test_save_meta_tristate_clears_existing_override(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		// Set noindex to true first.
		$_POST = $this->base_post( [ 'mm_meta_noindex' => '1' ] );
		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$meta = json_decode( get_post_meta( $this->post_id, MM_META_KEY, true ), true );
		$this->assertTrue( $meta['noindex'] );

		// Clear to default (empty string → null).
		$_POST = $this->base_post( [ 'mm_meta_noindex' => '' ] );
		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$meta = json_decode( get_post_meta( $this->post_id, MM_META_KEY, true ), true );

		// Existing override removed.
		$this->assertArrayNotHasKey( 'noindex', $meta );

		unset( $_POST );
	}

	public function test_save_meta_all_tristates(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = $this->base_post( [
			'mm_meta_noindex'      => '1',
			'mm_meta_nofollow'     => '0',
			'mm_meta_noarchive'    => '',  // null (default)
			'mm_meta_nosnippet'    => '1',
			'mm_meta_noimageindex' => '0',
		] );
		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$meta = json_decode( get_post_meta( $this->post_id, MM_META_KEY, true ), true );

		$this->assertTrue( $meta['noindex'] );
		$this->assertFalse( $meta['nofollow'] );
		$this->assertNull( $meta['noarchive'] ); // null persisted as default
		$this->assertTrue( $meta['nosnippet'] );
		$this->assertFalse( $meta['noimageindex'] );

		unset( $_POST );
	}

	// ------------------------------------------------------------------
	// save_meta() — schema_fields sanitization
	// ------------------------------------------------------------------

	public function test_save_meta_sanitizes_schema_fields(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = $this->base_post( [
			'mm_meta_schema_fields' => [
				'name'        => '  <script>alert(1)</script>My Business  ',
				'description' => 'Safe text',
				''            => 'empty key should be stripped',
			],
		] );
		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$meta = json_decode( get_post_meta( $this->post_id, MM_META_KEY, true ), true );

		$this->assertArrayHasKey( 'schema_fields', $meta );
		$this->assertStringNotContainsString( '<script>', $meta['schema_fields']['name'] );
		$this->assertSame( 'My Business', $meta['schema_fields']['name'] );
		$this->assertSame( 'Safe text', $meta['schema_fields']['description'] );
		$this->assertArrayNotHasKey( '', $meta['schema_fields'] );

		unset( $_POST );
	}

	// ------------------------------------------------------------------
	// save_meta() — schema_type and breadcrumb_label
	// ------------------------------------------------------------------

	public function test_save_meta_stores_schema_type_and_breadcrumb(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = $this->base_post( [
			'mm_meta_schema_type'      => 'Article',
			'mm_meta_breadcrumb_label' => 'Home > Blog',
		] );
		$this->panel->save_meta( $this->post_id, get_post( $this->post_id ) );
		$meta = json_decode( get_post_meta( $this->post_id, MM_META_KEY, true ), true );

		$this->assertSame( 'article', $meta['schema_type'] );
		$this->assertSame( 'Home > Blog', $meta['breadcrumb_label'] );

		unset( $_POST );
	}

	// ------------------------------------------------------------------
	// add_meta_boxes() — hook registration
	// ------------------------------------------------------------------

	public function test_add_meta_boxes_registers_for_posts(): void {
		global $wp_meta_boxes;

		$this->panel->add_meta_boxes();

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
		$this->assertIsInt( has_action( 'admin_enqueue_scripts', [ $this->panel, 'enqueue_assets' ] ) );
	}

	// ------------------------------------------------------------------
	// enqueue_assets()
	// ------------------------------------------------------------------

	public function test_enqueue_assets_skips_on_wrong_hook(): void {
		$this->panel->enqueue_assets( 'edit-comments.php' );
		$this->assertFalse( wp_style_is( 'mm-meta-post-panel', 'enqueued' ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function base_post( array $overrides = [] ): array {
		return array_merge( [
			'mm_meta_post_nonce'       => wp_create_nonce( 'mm_meta_post_meta_save' ),
			'mm_meta_canonical'        => '',
			'mm_meta_schema_type'      => '',
			'mm_meta_breadcrumb_label' => '',
			'mm_meta_exclude_sitemap'  => 0,
			'mm_meta_noindex'          => '',
			'mm_meta_nofollow'         => '',
			'mm_meta_noarchive'        => '',
			'mm_meta_nosnippet'        => '',
			'mm_meta_noimageindex'     => '',
			'mm_meta_schema_fields'    => [],
		], $overrides );
	}
}
