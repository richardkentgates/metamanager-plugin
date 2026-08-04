<?php
/**
 * Integration tests for MM_Metadata_Admin — settings registration, sanitization, and AJAX tools.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Metadata_Admin extends WP_UnitTestCase {

	/** @var MM_Metadata_Admin */
	private $admin;

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		$this->admin = new MM_Metadata_Admin( MM_Site_Settings::get_instance() );
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// sanitize_business()
	// ------------------------------------------------------------------

	public function test_sanitize_business_returns_defaults_for_non_array(): void {
		$result = $this->admin->sanitize_business( 'not an array' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
	}

	public function test_sanitize_business_passes_through_array(): void {
		$input = [
			'name'    => 'Test Business',
			'phone'   => '555-1234',
			'email'   => 'test@example.com',
			'address' => [
				'street'  => '123 Main St',
				'city'    => 'Springfield',
				'state'   => 'IL',
				'zip'     => '62701',
				'country' => 'US',
			],
		];

		$result = $this->admin->sanitize_business( $input );

		$this->assertIsArray( $result );
		$this->assertSame( 'Test Business', $result['name'] );
		$this->assertSame( '555-1234', $result['phone'] );
		$this->assertSame( 'test@example.com', $result['email'] );
	}

	public function test_sanitize_business_strips_unknown_keys(): void {
		$input = [
			'name'         => 'Test',
			'unknown_key'  => 'should be removed',
			'address'      => [],
		];

		$result = $this->admin->sanitize_business( $input );

		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayNotHasKey( 'unknown_key', $result );
	}

	// ------------------------------------------------------------------
	// sanitize_contact_style()
	// ------------------------------------------------------------------

	public function test_sanitize_contact_style_returns_defaults_for_non_array(): void {
		$result = $this->admin->sanitize_contact_style( null );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	public function test_sanitize_contact_style_passes_through_array(): void {
		$defaults = MM_Mod_Business_Contact::style_defaults();
		$input    = $defaults;

		$result = $this->admin->sanitize_contact_style( $input );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	// ------------------------------------------------------------------
	// get_page_url()
	// ------------------------------------------------------------------

	public function test_get_page_url_returns_admin_url(): void {
		$url = $this->admin->get_page_url( 'metamanager-settings' );

		$this->assertStringContainsString( 'admin.php?page=metamanager-settings', $url );
	}

	// ------------------------------------------------------------------
	// register_hooks()
	// ------------------------------------------------------------------

	public function test_register_hooks_registers_expected_actions(): void {
		$this->admin->register_hooks();

		$this->assertIsInt( has_action( 'admin_menu', [ $this->admin, 'register_menu' ] ) );
		$this->assertIsInt( has_action( 'admin_init', [ $this->admin, 'register_settings' ] ) );
		$this->assertIsInt( has_action( 'wp_ajax_mm_meta_tools_action', [ $this->admin, 'ajax_tools_action' ] ) );
	}

	// ------------------------------------------------------------------
	// register_settings() — option groups exist
	// ------------------------------------------------------------------

	public function test_register_settings_registers_groups(): void {
		global $wp_registered_settings;

		$this->admin->register_settings();

		// Verify at least one metamanager-related setting is registered.
		$found = false;
		foreach ( $wp_registered_settings as $name => $args ) {
			if ( str_contains( $name, 'mm_meta' ) || str_contains( $name, 'mm_' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Expected at least one mm_meta setting to be registered.' );
	}
}
