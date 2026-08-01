<?php
/**
 * Integration tests for activation/deactivation hooks.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Activation extends WP_UnitTestCase {

	// ------------------------------------------------------------------
	// mm_activate_single_site()
	// ------------------------------------------------------------------

	public function test_activate_creates_job_directories(): void {
		mm_activate_single_site();

		$this->assertDirectoryExists( MM_JOB_ROOT );
		$this->assertDirectoryExists( MM_JOB_COMPRESS );
		$this->assertDirectoryExists( MM_JOB_META );
		$this->assertDirectoryExists( MM_JOB_DONE );
		$this->assertDirectoryExists( MM_JOB_FAILED );
	}

	public function test_activate_schedules_cron(): void {
		mm_activate_single_site();

		$timestamp = wp_next_scheduled( 'mm_import_completed_jobs' );
		$this->assertNotFalse( $timestamp );
	}

	public function test_activate_creates_database_table(): void {
		mm_activate_single_site();

		global $wpdb;
		$table = $wpdb->prefix . MM_JOB_TABLE;
		// Suppress DB errors so PHPUnit doesn't intercept them as Errors.
		// SELECT on a missing table emits a DB error; suppressing lets us
		// handle it as a normal assertion failure instead.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->suppress_errors( true );
		$result = $wpdb->query( "SELECT 1 FROM {$table} LIMIT 0" );
		$wpdb->suppress_errors( false );
		$this->assertNotFalse( $result, "Table {$table} should exist after activation." );
	}

	public function test_activate_migrates_legacy_options(): void {
		// Simulate legacy option.
		update_option( 'gcm_seo_settings', [ 'titles' => [ 'title_template' => '%%title%%' ] ] );
		delete_option( MM_META_OPT_SETTINGS );

		mm_activate_single_site();

		// Legacy option should have been migrated.
		$new_value = get_option( MM_META_OPT_SETTINGS );
		$this->assertNotFalse( $new_value );

		// Cleanup.
		delete_option( 'gcm_seo_settings' );
		delete_option( MM_META_OPT_SETTINGS );
	}

	public function test_activate_does_not_overwrite_existing_options(): void {
		// Use 'separator' — it exists in settings_defaults so intercept_save
		// won't strip it during the pre_update_option_ filter.
		$existing = [ 'titles' => [ 'separator' => 'CustomSep' ] ];
		update_option( MM_META_OPT_SETTINGS, $existing );
		update_option( 'gcm_seo_settings', [ 'titles' => [ 'separator' => 'LegacySep' ] ] );

		mm_activate_single_site();

		$current = get_option( MM_META_OPT_SETTINGS );
		$this->assertIsArray( $current, 'MM_META_OPT_SETTINGS should be an array after activation.' );
		$this->assertArrayHasKey( 'titles', $current, 'Settings should contain a titles key.' );
		$this->assertSame( 'CustomSep', $current['titles']['separator'] ?? '' );

		// Cleanup.
		delete_option( MM_META_OPT_SETTINGS );
		delete_option( 'gcm_seo_settings' );
	}

	// ------------------------------------------------------------------
	// mm_deactivate()
	// ------------------------------------------------------------------

	public function test_deactivate_clears_cron_hooks(): void {
		// Schedule first.
		wp_schedule_event( time(), 'twicedaily', 'mm_import_completed_jobs' );
		wp_schedule_event( time(), 'twicedaily', 'mm_send_upload_receipt' );

		mm_deactivate( false );

		$this->assertFalse( wp_next_scheduled( 'mm_import_completed_jobs' ) );
		$this->assertFalse( wp_next_scheduled( 'mm_send_upload_receipt' ) );
	}

	// ------------------------------------------------------------------
	// Constants
	// ------------------------------------------------------------------

	public function test_job_root_constant(): void {
		$this->assertStringContainsString( 'metamanager-jobs', MM_JOB_ROOT );
	}

	public function test_job_compress_constant(): void {
		$this->assertStringContainsString( 'compress', MM_JOB_COMPRESS );
	}

	public function test_job_meta_constant(): void {
		$this->assertStringContainsString( 'meta', MM_JOB_META );
	}

	public function test_job_done_constant(): void {
		$this->assertStringContainsString( 'completed', MM_JOB_DONE );
	}

	public function test_job_failed_constant(): void {
		$this->assertStringContainsString( 'failed', MM_JOB_FAILED );
	}
}
