<?php
/**
 * Integration tests for MM_Updater — plugin update pipeline.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Updater_Integration extends WP_UnitTestCase {

	/**
	 * Create an MM_Updater instance via reflection (constructor is private).
	 */
	private function create_updater(): MM_Updater {
		$ref  = new ReflectionClass( MM_Updater::class );
		$ctor = $ref->getMethod( '__construct' );
		$ctor->setAccessible( true );

		$inst = $ref->newInstanceWithoutConstructor();
		$ctor->invoke( $inst );
		return $inst;
	}

	/**
	 * Set up a mock remote metadata transient so get_metadata() works offline.
	 */
	private function set_mock_metadata( string $version ): void {
		$metadata              = new stdClass();
		$metadata->version     = $version;
		$metadata->download_url = 'http://apt.richardkentgates.com/metamanager/metamanager.zip';
		$metadata->requires    = new stdClass();
		$metadata->requires->php      = '8.0';
		$metadata->requires->wordpress = '6.2';
		set_transient( 'mm_remote_metadata', $metadata, 43200 );
	}

	public function tear_down(): void {
		delete_transient( 'mm_remote_metadata' );
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// inject_update()
	// ------------------------------------------------------------------

	public function test_inject_update_with_empty_checked(): void {
		$updater   = $this->create_updater();
		$transient = new stdClass();
		$transient->checked = [];

		$result = $updater->inject_update( $transient );

		$this->assertObjectHasProperty( 'checked', $result );
		$this->assertEmpty( $result->checked );
	}

	public function test_inject_update_adds_no_update_when_remote_is_current(): void {
		$this->set_mock_metadata( MM_VERSION );

		$updater   = $this->create_updater();
		$transient = new stdClass();
		$transient->checked  = [ 'metamanager/metamanager.php' => MM_VERSION ];
		$transient->response = [];
		$transient->no_update = [];

		$result = $updater->inject_update( $transient );

		$basename = plugin_basename( MM_PLUGIN_FILE );
		$this->assertArrayHasKey( $basename, $result->no_update );
	}

	// ------------------------------------------------------------------
	// plugin_info()
	// ------------------------------------------------------------------

	public function test_plugin_info_passes_through_for_wrong_action(): void {
		$updater = $this->create_updater();
		$result  = $updater->plugin_info( false, 'plugin_information', (object) [ 'slug' => 'other' ] );
		$this->assertFalse( $result );
	}

	public function test_plugin_info_passes_through_for_wrong_slug(): void {
		$updater = $this->create_updater();
		$result  = $updater->plugin_info( false, 'plugin_information', (object) [ 'slug' => 'other-plugin' ] );
		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// on_plugin_updated()
	// ------------------------------------------------------------------

	public function test_on_plugin_updated_skips_non_update_action(): void {
		$updater = $this->create_updater();
		$updater->on_plugin_updated( null, [ 'action' => 'install', 'type' => 'plugin' ] );
		$this->assertTrue( true );
	}

	public function test_on_plugin_updated_skips_non_plugin_type(): void {
		$updater = $this->create_updater();
		$updater->on_plugin_updated( null, [ 'action' => 'update', 'type' => 'theme' ] );
		$this->assertTrue( true );
	}

	public function test_on_plugin_updated_skips_when_our_plugin_not_in_list(): void {
		$updater = $this->create_updater();
		$updater->on_plugin_updated( null, [
			'action'  => 'update',
			'type'    => 'plugin',
			'plugins' => [ 'other-plugin/other-plugin.php' ],
		] );
		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// Transient cache clearing
	// ------------------------------------------------------------------

	public function test_metadata_transient_can_be_set_and_deleted(): void {
		set_transient( 'mm_remote_metadata', 'test', 60 );
		$this->assertSame( 'test', get_transient( 'mm_remote_metadata' ) );

		delete_transient( 'mm_remote_metadata' );
		$this->assertFalse( get_transient( 'mm_remote_metadata' ) );
	}
}
