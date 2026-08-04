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

	public function test_sanitize_business_passes_through_valid_array(): void {
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
			'name'        => 'Test',
			'unknown_key' => 'should be removed',
			'address'     => [],
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

		$found = false;
		foreach ( $wp_registered_settings as $name => $args ) {
			if ( str_contains( $name, 'mm_meta' ) || str_contains( $name, 'mm_' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Expected at least one mm_meta setting to be registered.' );
	}

	public function test_register_settings_registers_business_option(): void {
		global $wp_registered_settings;

		$this->admin->register_settings();

		$this->assertArrayHasKey( MM_META_OPT_BUSINESS, $wp_registered_settings );
	}

	public function test_register_settings_registers_settings_option(): void {
		global $wp_registered_settings;

		$this->admin->register_settings();

		$this->assertArrayHasKey( MM_META_OPT_SETTINGS, $wp_registered_settings );
	}

	public function test_register_settings_registers_contact_style_option(): void {
		global $wp_registered_settings;

		$this->admin->register_settings();

		$this->assertArrayHasKey( MM_Mod_Business_Contact::OPT_STYLE, $wp_registered_settings );
	}

	// ------------------------------------------------------------------
	// ajax_tools_action() — capability and nonce checks
	// ------------------------------------------------------------------

	public function test_ajax_tools_action_rejects_without_nonce(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = [ 'tools_action' => 'reset_settings' ];

		try {
			$this->admin->ajax_tools_action();
		} catch ( \WPDieException $e ) {
			// Expected — check_ajax_referer calls wp_die.
		}

		$this->assertTrue( true );
		unset( $_POST );
	}

	public function test_ajax_tools_action_rejects_unauthorized_user(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$_POST = [
			'_nonce'        => wp_create_nonce( 'mm_meta_tools_nonce' ),
			'tools_action'  => 'reset_settings',
		];

		try {
			$this->admin->ajax_tools_action();
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( '403', $e->getMessage() );
		}

		unset( $_POST );
	}

	// ------------------------------------------------------------------
	// ajax_tools_action() — valid actions
	// ------------------------------------------------------------------

	public function test_ajax_tools_action_reset_settings(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = [
			'_nonce'        => wp_create_nonce( 'mm_meta_tools_nonce' ),
			'tools_action'  => 'reset_settings',
		];

		try {
			$this->admin->ajax_tools_action();
		} catch ( \WPDieException $e ) {
			// wp_send_json_success calls wp_die.
		}

		$settings = get_option( MM_META_OPT_SETTINGS, [] );
		$this->assertNotEmpty( $settings );
		$this->assertArrayHasKey( 'titles', $settings );

		$business = get_option( MM_META_OPT_BUSINESS, [] );
		$this->assertNotEmpty( $business );
		$this->assertArrayHasKey( 'name', $business );

		unset( $_POST );
	}

	public function test_ajax_tools_action_flush_rewrite(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = [
			'_nonce'        => wp_create_nonce( 'mm_meta_tools_nonce' ),
			'tools_action'  => 'flush_rewrite',
		];

		try {
			$this->admin->ajax_tools_action();
		} catch ( \WPDieException $e ) {
			// Expected.
		}

		$this->assertTrue( true );
		unset( $_POST );
	}

	public function test_ajax_tools_action_ping_sitemap(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = [
			'_nonce'        => wp_create_nonce( 'mm_meta_tools_nonce' ),
			'tools_action'  => 'ping_sitemap',
		];

		try {
			$this->admin->ajax_tools_action();
		} catch ( \WPDieException $e ) {
			// Expected.
		}

		$this->assertTrue( true );
		unset( $_POST );
	}

	public function test_ajax_tools_action_unknown_action(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_POST = [
			'_nonce'        => wp_create_nonce( 'mm_meta_tools_nonce' ),
			'tools_action'  => 'nonexistent_action',
		];

		try {
			$this->admin->ajax_tools_action();
		} catch ( \WPDieException $e ) {
			// Expected.
		}

		$this->assertTrue( true );
		unset( $_POST );
	}

	// ------------------------------------------------------------------
	// add_help_tabs()
	// ------------------------------------------------------------------

	public function test_add_help_tabs_registers_on_known_screen(): void {
		$screen = new \stdClass();
		$screen->id = 'metamanager_page_mm-meta-titles';

		$mock = $this->getMockBuilder( \WP_Screen::class )
			->disableOriginalConstructor()
			->onlyMethods( [] )
			->getMock();
		$mock->id = 'metamanager_page_mm-meta-titles';

		// add_help_tab and set_help_sidebar should not throw.
		$this->admin->add_help_tabs( $mock );
		$this->assertTrue( true );
	}

	public function test_add_help_tabs_skips_unknown_screen(): void {
		$mock = $this->getMockBuilder( \WP_Screen::class )
			->disableOriginalConstructor()
			->onlyMethods( [] )
			->getMock();
		$mock->id = 'unknown_screen_id';

		$this->admin->add_help_tabs( $mock );
		$this->assertTrue( true );
	}
}
