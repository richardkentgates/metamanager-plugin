<?php
/**
 * Integration tests for mm_import_completed_jobs() — the daemon-to-DB bridge.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Import_Completed_Jobs extends WP_UnitTestCase {

	private string $original_blogdescription = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		MM_DB::create_or_update_table();
	}

	public function set_up(): void {
		parent::set_up();
		$this->original_blogdescription = get_option( 'blogdescription', '' );
		MM_Site_Settings::reset_instance();
		delete_transient( 'mm_import_lock' );
		wp_mkdir_p( MM_JOB_DONE );
		wp_mkdir_p( MM_JOB_FAILED );
	}

	public function tear_down(): void {
		update_option( 'blogdescription', $this->original_blogdescription );
		MM_Site_Settings::reset_instance();
		delete_transient( 'mm_import_lock' );
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// Concurrency lock
	// ------------------------------------------------------------------

	public function test_import_skips_when_lock_exists(): void {
		set_transient( 'mm_import_lock', '1', 30 );

		ob_start();
		mm_import_completed_jobs();
		$output = ob_get_clean();

		// Should return immediately — no output, no DB writes.
		$this->assertSame( '', $output );
	}

	// ------------------------------------------------------------------
	// File processing
	// ------------------------------------------------------------------

	public function test_import_processes_completed_job_file(): void {
		$job_dir = MM_JOB_DONE;
		wp_mkdir_p( $job_dir );

		$job = [
			'id'          => 'test-import-001',
			'job_type'    => 'import',
			'attachment_id' => 0,
			'status'      => 'completed',
			'embedded_tags' => [],
		];

		$file = $job_dir . '/test-import-001.json';
		file_put_contents( $file, wp_json_encode( $job ) );

		ob_start();
		mm_import_completed_jobs();
		ob_get_clean();

		// File should be deleted after processing.
		$this->assertFileDoesNotExist( $file );
	}

	public function test_import_renames_unparseable_json(): void {
		$job_dir = MM_JOB_DONE;

		$file = $job_dir . '/bad-job.json';
		file_put_contents( $file, 'not valid json {{{' );

		ob_start();
		mm_import_completed_jobs();
		ob_get_clean();

		// Should be renamed to .json.unparseable.
		$this->assertFileDoesNotExist( $file );
		$this->assertFileExists( $job_dir . '/bad-job.json.unparseable' );

		// Cleanup.
		wp_delete_file( $job_dir . '/bad-job.json.unparseable' );
	}

	public function test_import_processes_failed_job_file(): void {
		$job_dir = MM_JOB_FAILED;
		wp_mkdir_p( $job_dir );

		$job = [
			'id'       => 'test-fail-001',
			'job_type' => 'import',
			'status'   => 'failed',
			'details'  => [ 'error' => 'ExifTool not found' ],
		];

		$file = $job_dir . '/test-fail-001.json';
		file_put_contents( $file, wp_json_encode( $job ) );

		ob_start();
		mm_import_completed_jobs();
		ob_get_clean();

		$this->assertFileDoesNotExist( $file );
	}

	// ------------------------------------------------------------------
	// DB logging
	// ------------------------------------------------------------------

	public function test_import_logs_job_to_database(): void {
		$job_dir = MM_JOB_DONE;
		wp_mkdir_p( $job_dir );

		$job = [
			'id'          => 'test-log-001',
			'job_type'    => 'import',
			'attachment_id' => 0,
			'status'      => 'completed',
			'embedded_tags' => [],
		];

		$file = $job_dir . '/test-log-001.json';
		file_put_contents( $file, wp_json_encode( $job ) );

		ob_start();
		mm_import_completed_jobs();
		ob_get_clean();

		// Check the job was logged.
		global $wpdb;
		$logged = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}mm_jobs WHERE job_id = %s",
			'test-log-001'
		) );

		$this->assertGreaterThan( 0, (int) $logged );
	}

	// ------------------------------------------------------------------
	// Lock cleanup
	// ------------------------------------------------------------------

	public function test_import_releases_lock_after_processing(): void {
		ob_start();
		mm_import_completed_jobs();
		ob_get_clean();

		// Lock should be deleted.
		$this->assertFalse( get_transient( 'mm_import_lock' ) );
	}

	// ------------------------------------------------------------------
	// Empty directories
	// ------------------------------------------------------------------

	public function test_import_handles_empty_directories(): void {
		wp_mkdir_p( MM_JOB_DONE );
		wp_mkdir_p( MM_JOB_FAILED );

		ob_start();
		mm_import_completed_jobs();
		ob_get_clean();

		// Should complete without error.
		$this->assertTrue( true );
	}
}
