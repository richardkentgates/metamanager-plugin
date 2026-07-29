<?php
/**
 * Integration tests for MM_Mod_Discovery — llms.txt and API catalog generation.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Mod_Discovery_Integration extends WP_UnitTestCase {

	private MM_Site_Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = MM_Site_Settings::get_instance();
		MM_Site_Settings::reset_instance();
		delete_transient( 'mm_llms' );
		delete_transient( 'mm_llms_full' );
		delete_transient( 'mm_api_catalog' );
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		delete_transient( 'mm_llms' );
		delete_transient( 'mm_llms_full' );
		delete_transient( 'mm_api_catalog' );
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// Discovery settings defaults
	// ------------------------------------------------------------------

	public function test_discovery_settings_exist(): void {
		$llms = $this->settings->get( 'discovery.llms_txt_enabled', null );
		$this->assertNotNull( $llms );
		$this->assertTrue( $llms );
	}

	public function test_mcp_server_setting_exists(): void {
		$mcp = $this->settings->get( 'discovery.mcp_server', null );
		$this->assertNotNull( $mcp );
		$this->assertTrue( $mcp );
	}

	// ------------------------------------------------------------------
	// Cache management
	// ------------------------------------------------------------------

	public function test_clear_cache_deletes_all_transients(): void {
		set_transient( 'mm_llms', 'test', 60 );
		set_transient( 'mm_llms_full', 'test', 60 );
		set_transient( 'mm_api_catalog', 'test', 60 );

		$module = new MM_Mod_Discovery( $this->settings );
		$module->clear_cache();

		$this->assertFalse( get_transient( 'mm_llms' ) );
		$this->assertFalse( get_transient( 'mm_llms_full' ) );
		$this->assertFalse( get_transient( 'mm_api_catalog' ) );
	}

	// ------------------------------------------------------------------
	// Class instantiation
	// ------------------------------------------------------------------

	public function test_class_can_be_instantiated(): void {
		$module = new MM_Mod_Discovery( $this->settings );
		$this->assertInstanceOf( MM_Mod_Discovery::class, $module );
	}

	public function test_class_has_register_hooks_method(): void {
		$module = new MM_Mod_Discovery( $this->settings );
		$this->assertTrue( method_exists( $module, 'register_hooks' ) );
	}
}
