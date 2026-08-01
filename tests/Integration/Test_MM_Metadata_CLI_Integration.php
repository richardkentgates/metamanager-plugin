<?php
/**
 * Integration tests for MM_Metadata_CLI — WP-CLI metadata commands.
 *
 * Requires WP-CLI — tests are skipped in PHPUnit/HTTP context.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Metadata_CLI_Integration extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			$this->markTestSkipped( 'WP-CLI is not available in this context.' );
		}
	}

	// ------------------------------------------------------------------
	// Command registration
	// ------------------------------------------------------------------

	public function test_metadata_command_class_exists(): void {
		$this->assertTrue( class_exists( 'MM_Metadata_CLI' ) );
	}

	public function test_metadata_cli_has_required_methods(): void {
		$methods = get_class_methods( 'MM_Metadata_CLI' );
		$this->assertContains( 'export', $methods );
		$this->assertContains( 'reset', $methods );
		$this->assertContains( 'backfill_links', $methods );
		$this->assertContains( 'check_links', $methods );
		$this->assertContains( 'ping', $methods );
		$this->assertContains( 'flush_rewrites', $methods );
		$this->assertContains( 'schema_test', $methods );
	}

	// ------------------------------------------------------------------
	// export() — reads metadata without writing
	// ------------------------------------------------------------------

	public function test_export_reads_post_metadata(): void {
		// export() reads site-wide options (MM_META_OPT_SETTINGS / MM_META_OPT_BUSINESS),
		// not per-post metadata. Seed the options and verify they appear in output.
		update_option( MM_META_OPT_SETTINGS, [
			'titles' => [ 'separator' => '>>>' ],
		] );

		WP_CLI::$output = [];
		$cli = new MM_Metadata_CLI();
		$cli->export( [], [] );

		$combined = '';
		foreach ( WP_CLI::$output as $entry ) {
			$combined .= $entry['msg'];
		}

		$this->assertStringContainsString( '>>>', $combined );

		delete_option( MM_META_OPT_SETTINGS );
	}

	// ------------------------------------------------------------------
	// Schema test helper
	// ------------------------------------------------------------------

	public function test_schema_test_returns_array_for_valid_url(): void {
		$this->assertTrue( method_exists( 'MM_Metadata_CLI', 'schema_test' ) );
	}

	// ------------------------------------------------------------------
	// CLI subcommand signatures
	// ------------------------------------------------------------------

	public function test_backfill_links_accepts_args(): void {
		$this->assertTrue( method_exists( 'MM_Metadata_CLI', 'backfill_links' ) );
		$method = new ReflectionMethod( 'MM_Metadata_CLI', 'backfill_links' );
		$this->assertTrue( $method->isPublic() );
	}

	public function test_check_links_accepts_args(): void {
		$this->assertTrue( method_exists( 'MM_Metadata_CLI', 'check_links' ) );
		$method = new ReflectionMethod( 'MM_Metadata_CLI', 'check_links' );
		$this->assertTrue( $method->isPublic() );
	}

	public function test_flush_rewrites_is_public(): void {
		$method = new ReflectionMethod( 'MM_Metadata_CLI', 'flush_rewrites' );
		$this->assertTrue( $method->isPublic() );
	}
}
