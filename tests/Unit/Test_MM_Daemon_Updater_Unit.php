<?php
/**
 * Unit tests for MM_Daemon_Updater version parsing and compatibility map logic.
 *
 * These tests mock the filesystem by testing the pure logic methods.
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

	// ------------------------------------------------------------------
	// get_required_daemon_version() — compatibility map parsing
	// ------------------------------------------------------------------

	public function test_get_required_daemon_version_returns_string_or_null(): void {
		$result = MM_Daemon_Updater::get_required_daemon_version();
		$this->assertTrue( is_string( $result ) || is_null( $result ) );
	}

	// ------------------------------------------------------------------
	// check_version() — version comparison
	// ------------------------------------------------------------------

	public function test_check_version_returns_array(): void {
		$result = MM_Daemon_Updater::check_version();
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'match', $result );
		$this->assertArrayHasKey( 'current', $result );
		$this->assertArrayHasKey( 'required', $result );
	}

	// ------------------------------------------------------------------
	// daemon-compatibility.json file integrity
	// ------------------------------------------------------------------

	public function test_daemon_compatibility_json_is_valid_json(): void {
		$file   = MM_PLUGIN_DIR . 'daemon-compatibility.json';
		$content = file_get_contents( $file );
		$this->assertNotFalse( $content, 'daemon-compatibility.json should be readable' );

		$decoded = json_decode( $content, true );
		$this->assertNotNull( $decoded, 'daemon-compatibility.json should be valid JSON' );
		$this->assertIsArray( $decoded );
	}

	public function test_daemon_compatibility_json_keys_are_valid_semver(): void {
		$file    = MM_PLUGIN_DIR . 'daemon-compatibility.json';
		$decoded = json_decode( file_get_contents( $file ), true );

		foreach ( $decoded as $key => $value ) {
			$this->assertMatchesRegularExpression(
				'/^\d+\.\d+\.\d+$/',
				$key,
				"Key '{$key}' should be valid semver"
			);
			$this->assertMatchesRegularExpression(
				'/^\d+\.\d+\.\d+$/',
				$value,
				"Value for key '{$key}' should be valid semver, got '{$value}'"
			);
		}
	}

	public function test_daemon_compatibility_json_has_current_version(): void {
		$file    = MM_PLUGIN_DIR . 'daemon-compatibility.json';
		$decoded = json_decode( file_get_contents( $file ), true );

		$this->assertArrayHasKey(
			MM_VERSION,
			$decoded,
			"daemon-compatibility.json should have an entry for current version " . MM_VERSION
		);
	}

	public function test_daemon_compatibility_json_all_values_are_strings(): void {
		$file    = MM_PLUGIN_DIR . 'daemon-compatibility.json';
		$decoded = json_decode( file_get_contents( $file ), true );

		foreach ( $decoded as $key => $value ) {
			$this->assertIsString( $value, "Value for key '{$key}' should be a string" );
		}
	}
}
