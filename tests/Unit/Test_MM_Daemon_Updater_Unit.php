<?php
/**
 * Unit tests for MM_Daemon_Updater version detection.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Daemon_Updater_Unit extends WP_UnitTestCase {

	// ------------------------------------------------------------------
	// get_daemon_version()
	// ------------------------------------------------------------------

	public function test_get_daemon_version_returns_string_or_null(): void {
		$result = MM_Daemon_Updater::get_daemon_version();
		$this->assertTrue( is_string( $result ) || is_null( $result ) );
	}
}
