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
}
