<?php
/**
 * Unit tests for MM_Mod_Robots — robots.txt generation.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Mod_Robots_Unit extends WP_UnitTestCase {

	private MM_Site_Settings $settings;
	private MM_Mod_Robots $module;

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		$this->settings = MM_Site_Settings::get_instance();
		$this->module   = new MM_Mod_Robots( $this->settings );
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		remove_all_filters( 'robots_txt' );
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

	public function test_generate_starts_with_user_agent(): void {
		$output = $this->module->generate( '', true );

		$this->assertStringStartsWith( "User-agent: *\n", $output );
	}

	public function test_generate_includes_disallow_paths(): void {
		$this->settings->save_settings( [
			'robots' => [ 'disallow' => [ '/wp-admin/', '/secret/' ] ],
		] );

		$output = $this->module->generate( '', true );

		$this->assertStringContainsString( 'Disallow: /wp-admin/', $output );
		$this->assertStringContainsString( 'Disallow: /secret/', $output );
	}

	public function test_generate_skips_blank_disallow_paths(): void {
		$this->settings->save_settings( [
			'robots' => [ 'disallow' => [ '', '   ', '/keep/' ] ],
		] );

		$lines = explode( "\n", $this->module->generate( '', true ) );

		$this->assertNotContains( 'Disallow: ', $lines );
		$this->assertContains( 'Disallow: /keep/', $lines );
	}

	public function test_generate_includes_allow_paths(): void {
		$this->settings->save_settings( [
			'robots' => [ 'allow' => [ '/wp-admin/admin-ajax.php' ] ],
		] );

		$output = $this->module->generate( '', true );

		$this->assertStringContainsString( 'Allow: /wp-admin/admin-ajax.php', $output );
	}

	public function test_generate_includes_numeric_crawl_delay(): void {
		$this->settings->save_settings( [
			'robots' => [ 'crawl_delay' => '10' ],
		] );

		$this->assertStringContainsString( 'Crawl-delay: 10', $this->module->generate( '', true ) );
	}

	public function test_generate_skips_non_numeric_crawl_delay(): void {
		$this->settings->save_settings( [
			'robots' => [ 'crawl_delay' => 'soon' ],
		] );

		$this->assertStringNotContainsString( 'Crawl-delay:', $this->module->generate( '', true ) );
	}

	public function test_generate_appends_custom_directives_line_by_line(): void {
		$this->settings->save_settings( [
			'robots' => [ 'custom' => "User-agent: BadBot\nDisallow: /\n\n" ],
		] );

		$output = $this->module->generate( '', true );

		$this->assertStringContainsString( "User-agent: BadBot\nDisallow: /", $output );
	}

	public function test_generate_includes_sitemap_when_enabled(): void {
		$output = $this->module->generate( '', true );

		$this->assertStringContainsString( 'Sitemap: ' . home_url( '/sitemap.xml' ), $output );
	}

	public function test_generate_omits_sitemap_when_disabled(): void {
		$this->settings->save_settings( [
			'sitemap' => [ 'enabled' => false ],
		] );

		$output = $this->module->generate( '', true );

		$this->assertStringNotContainsString( 'Sitemap:', $output );
	}

	public function test_register_hooks_adds_filter_when_enabled(): void {
		$this->module->register_hooks();

		$this->assertNotFalse( has_filter( 'robots_txt', [ $this->module, 'generate' ] ) );
	}

	public function test_register_hooks_skips_filter_when_disabled(): void {
		$this->settings->save_settings( [
			'robots' => [ 'enabled' => false ],
		] );

		$this->module->register_hooks();

		$this->assertFalse( has_filter( 'robots_txt', [ $this->module, 'generate' ] ) );
	}

	public function test_populate_is_a_no_op(): void {
		$data    = $this->empty_data();
		$context = new MM_Page_Context();

		$this->module->populate( $data, $context, $this->settings );

		$this->assertSame( $this->empty_data(), $data );
	}
}
