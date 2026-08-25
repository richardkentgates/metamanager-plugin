<?php
/**
 * Unit tests for MM_Mod_Html_Sitemap — [mm_sitemap] shortcode.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Mod_Html_Sitemap_Unit extends WP_UnitTestCase {

	private MM_Site_Settings $settings;
	private MM_Mod_Html_Sitemap $module;

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		$this->settings = MM_Site_Settings::get_instance();
		$this->module   = new MM_Mod_Html_Sitemap( $this->settings );
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		remove_all_shortcodes();
		parent::tear_down();
	}

	private function empty_data(): array {
		return [
			'title'  => '',
			'meta'   => [],
			'links'  => [],
			'schema' => [],
		];
	}

	public function test_register_hooks_registers_shortcode_by_default(): void {
		$this->module->register_hooks();

		$this->assertTrue( shortcode_exists( 'mm_sitemap' ) );
	}

	public function test_register_hooks_skips_shortcode_when_disabled(): void {
		$this->settings->save_settings( [
			'sitemap' => [ 'html_sitemap' => [ 'enabled' => false ] ],
		] );

		$this->module->register_hooks();

		$this->assertFalse( shortcode_exists( 'mm_sitemap' ) );
	}

	public function test_render_outputs_wrapper_with_column_class(): void {
		$out = $this->module->render_shortcode( [] );

		$this->assertStringContainsString( 'gcm-html-sitemap', $out );
		$this->assertStringContainsString( 'gcm-html-sitemap--cols-1', $out );
		$this->assertStringEndsWith( '</div>', $out );
	}

	public function test_render_lists_published_posts(): void {
		$this->factory->post->create( [
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_title' => 'Visible Post',
		] );
		$this->factory->post->create( [
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Hidden Draft',
		] );

		$out = $this->module->render_shortcode( [ 'post_types' => 'post' ] );

		$this->assertStringContainsString( 'Visible Post', $out );
		$this->assertStringNotContainsString( 'Hidden Draft', $out );
	}

	public function test_render_honours_exclude_attribute(): void {
		$keep_id = (int) $this->factory->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Keep Page',
		] );
		$drop_id = (int) $this->factory->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Drop Page',
		] );

		$out = $this->module->render_shortcode( [
			'post_types' => 'page',
			'exclude'    => (string) $drop_id,
		] );

		$this->assertStringContainsString( 'Keep Page', $out );
		$this->assertStringNotContainsString( 'Drop Page', $out );
		$this->assertGreaterThan( 0, $keep_id );
	}

	public function test_render_includes_global_exclusions(): void {
		$this->settings->save_settings( [] ); // Ensure defaults loaded.

		$page_a = (int) $this->factory->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Global Keep',
		] );
		$page_b = (int) $this->factory->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Global Drop',
		] );

		// Re-instantiate so the module picks up saved settings for exclude_ids.
		$this->settings->save_settings( [
			'sitemap' => [ 'html_sitemap' => [ 'exclude_ids' => [ $page_b ] ] ],
		] );
		$this->module = new MM_Mod_Html_Sitemap( $this->settings );

		$out = $this->module->render_shortcode( [ 'post_types' => 'page' ] );

		$this->assertStringContainsString( 'Global Keep', $out );
		$this->assertStringNotContainsString( 'Global Drop', $out );
		$this->assertGreaterThan( 0, $page_a );
	}

	public function test_render_caps_columns_at_three(): void {
		$out = $this->module->render_shortcode( [ 'columns' => 9 ] );

		$this->assertStringContainsString( 'gcm-html-sitemap--cols-3', $out );
	}

	public function test_render_falls_back_to_menu_order_for_invalid_order(): void {
		$this->assertSame(
			$this->module->render_shortcode( [ 'order_by' => 'random' ] ),
			$this->module->render_shortcode( [ 'order_by' => 'menu_order' ] )
		);
	}

	public function test_render_shows_date_when_requested(): void {
		$this->factory->post->create( [
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Dated Post',
		] );

		$out = $this->module->render_shortcode( [
			'post_types' => 'post',
			'show_date'  => 'yes',
		] );

		$this->assertStringContainsString( 'Dated Post', $out );
		$this->assertStringContainsString( 'gcm-sitemap-date', $out );
	}

	public function test_populate_is_a_no_op(): void {
		$data    = $this->empty_data();
		$context = new MM_Page_Context();

		$this->module->populate( $data, $context, $this->settings );

		$this->assertSame( $this->empty_data(), $data );
	}
}
