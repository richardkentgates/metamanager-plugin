<?php
/**
 * Unit tests for MM_Mod_Media_Display — featured image citation.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Mod_Media_Display_Unit extends WP_UnitTestCase {

	private MM_Site_Settings $settings;
	private MM_Mod_Media_Display $module;

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		$this->settings = MM_Site_Settings::get_instance();
		$this->module   = new MM_Mod_Media_Display();
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		remove_all_filters( 'wp_get_attachment_image' );
		parent::tear_down();
	}

	public function test_register_hooks_adds_filter_when_citation_enabled(): void {
		$this->settings->save_settings( [
			'media' => [ 'featured_image_citation' => true ],
		] );

		$this->module->register_hooks();

		$this->assertNotFalse( has_filter( 'wp_get_attachment_image', [ $this->module, 'filter_featured_image_citation' ] ) );
	}

	public function test_register_hooks_skips_filter_by_default(): void {
		$this->module->register_hooks();

		$this->assertFalse( has_filter( 'wp_get_attachment_image', [ $this->module, 'filter_featured_image_citation' ] ) );
	}

	public function test_filter_returns_image_unchanged_for_non_thumbnail_attachment(): void {
		$image = '<img src="/foo.jpg" alt="">';

		$result = $this->module->filter_featured_image_citation( $image, 99999, 'full', [], 0 );

		$this->assertSame( $image, $result );
	}

	public function test_filter_appends_citation_with_all_fields(): void {
		$post_id = (int) $this->factory->post->create();
		$att_id  = (int) $this->factory->attachment->create_object(
			'test.jpg',
			$post_id,
			[ 'post_mime_type' => 'image/jpeg' ]
		);
		set_post_thumbnail( $post_id, $att_id );

		update_post_meta( $att_id, MM_Metadata::META_CREATOR, 'Jane Photographer' );
		update_post_meta( $att_id, MM_Metadata::META_COPYRIGHT, '2026 Jane Co' );
		update_post_meta( $att_id, MM_Metadata::META_DATE, '2026-01-15' );

		$result = $this->module->filter_featured_image_citation( '<img src="/test.jpg">', $att_id, 'full', [], $post_id );

		$this->assertStringContainsString( '<figcaption class="mm-image-citation">', $result );
		$this->assertStringContainsString( 'Jane Photographer', $result );
		$this->assertStringContainsString( '2026 Jane Co', $result );
		$this->assertStringContainsString( '2026-01-15', $result );
		$this->assertStringContainsString( ' | ', $result );
	}

	public function test_filter_falls_back_to_owner_with_copyright_symbol(): void {
		$post_id = (int) $this->factory->post->create();
		$att_id  = (int) $this->factory->attachment->create_object(
			'owner.jpg',
			$post_id,
			[ 'post_mime_type' => 'image/jpeg' ]
		);
		set_post_thumbnail( $post_id, $att_id );

		update_post_meta( $att_id, MM_Metadata::META_OWNER, 'Acme Corp' );

		$result = $this->module->filter_featured_image_citation( '<img>', $att_id, 'full', [], $post_id );

		$this->assertStringContainsString( '&copy; Acme Corp', $result );
	}

	public function test_filter_returns_unchanged_when_no_citation_meta(): void {
		$post_id = (int) $this->factory->post->create();
		$att_id  = (int) $this->factory->attachment->create_object(
			'bare.jpg',
			$post_id,
			[ 'post_mime_type' => 'image/jpeg' ]
		);
		set_post_thumbnail( $post_id, $att_id );

		$image  = '<img src="/bare.jpg">';
		$result = $this->module->filter_featured_image_citation( $image, $att_id, 'full', [], $post_id );

		$this->assertSame( $image, $result );
	}
}
