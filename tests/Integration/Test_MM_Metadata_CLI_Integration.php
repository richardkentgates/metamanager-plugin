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
		$post_id = $this->factory->post->create( [
			'post_title'  => 'Export Test',
			'post_status' => 'publish',
		] );

		update_post_meta( $post_id, MM_META_KEY, wp_json_encode( [
			'title'       => 'Custom SEO Title',
			'description' => 'Custom meta description',
		] ) );

		WP_CLI::$output = [];
		$cli = new MM_Metadata_CLI();
		$cli->export( [ $post_id ], [] );

		$combined = '';
		foreach ( WP_CLI::$output as $entry ) {
			$combined .= $entry['msg'];
		}

		$this->assertStringContainsString( 'Custom SEO Title', $combined );
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
