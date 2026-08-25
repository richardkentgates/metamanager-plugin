<?php
/**
 * Unit tests for MM_Mod_Hygiene — WordPress head cleanup.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Mod_Hygiene_Unit extends WP_UnitTestCase {

	private MM_Site_Settings $settings;
	private MM_Mod_Hygiene $module;

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		$this->settings = MM_Site_Settings::get_instance();
		$this->module   = new MM_Mod_Hygiene( $this->settings );
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		remove_all_filters( 'wp_headers' );
		remove_all_filters( 'wp_resource_hints' );
		remove_all_filters( 'the_generator' );
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

	public function test_remove_pingback_header_unsets_pingback_only(): void {
		$headers = [
			'X-Pingback'  => 'https://example.com/xmlrpc.php',
			'Content-Type' => 'text/html',
		];

		$result = $this->module->remove_pingback_header( $headers );

		$this->assertArrayNotHasKey( 'X-Pingback', $result );
		$this->assertArrayHasKey( 'Content-Type', $result );
	}

	public function test_remove_x_powered_by_unsets_header_only(): void {
		$headers = [
			'X-Powered-By' => 'PHP/8.4',
			'X-Custom'     => 'keep-me',
		];

		$result = $this->module->remove_x_powered_by( $headers );

		$this->assertArrayNotHasKey( 'X-Powered-By', $result );
		$this->assertArrayHasKey( 'X-Custom', $result );
	}

	public function test_remove_dns_prefetch_only_clears_prefetch_hints(): void {
		$urls = [ 'https://fonts.googleapis.com', 'https://example.com' ];

		$this->assertSame( [], $this->module->remove_dns_prefetch_only( $urls, 'dns-prefetch' ) );
	}

	public function test_remove_dns_prefetch_only_preserves_other_relations(): void {
		$urls = [ 'https://example.com' ];

		$this->assertSame( $urls, $this->module->remove_dns_prefetch_only( $urls, 'preconnect' ) );
		$this->assertSame( $urls, $this->module->remove_dns_prefetch_only( $urls, 'prerender' ) );
	}

	public function test_register_hooks_adds_wp_headers_filter_by_default(): void {
		$this->module->register_hooks();

		$this->assertNotFalse( has_filter( 'wp_headers', [ $this->module, 'remove_pingback_header' ] ) );
		$this->assertNotFalse( has_filter( 'wp_headers', [ $this->module, 'remove_x_powered_by' ] ) );
		$this->assertNotFalse( has_filter( 'wp_resource_hints', [ $this->module, 'remove_dns_prefetch_only' ] ) );
	}

	public function test_register_hooks_respects_disabled_settings(): void {
		$this->settings->save_settings( [
			'hygiene' => [
				'remove_pingback_header'   => false,
				'remove_x_powered_by'      => false,
				'remove_wp_dns_prefetch'   => false,
				'remove_generator'         => false,
			],
		] );

		$this->module->register_hooks();

		$this->assertFalse( has_filter( 'wp_headers', [ $this->module, 'remove_pingback_header' ] ) );
		$this->assertFalse( has_filter( 'wp_headers', [ $this->module, 'remove_x_powered_by' ] ) );
		$this->assertFalse( has_filter( 'wp_resource_hints', [ $this->module, 'remove_dns_prefetch_only' ] ) );
	}

	public function test_populate_is_a_no_op(): void {
		$data    = $this->empty_data();
		$context = new MM_Page_Context();

		$this->module->populate( $data, $context, $this->settings );

		$this->assertSame( $this->empty_data(), $data );
	}
}
