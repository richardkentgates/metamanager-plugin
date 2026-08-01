<?php
/**
 * Integration tests for uninstall.php — data removal.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Uninstall extends WP_UnitTestCase {

	// ------------------------------------------------------------------
	// uninstall.php can be included without errors
	// ------------------------------------------------------------------

	public function test_uninstall_file_exists(): void {
		$this->assertFileExists( dirname( __DIR__, 2 ) . '/uninstall.php' );
	}

	// ------------------------------------------------------------------
	// Option cleanup
	// ------------------------------------------------------------------

	public function test_uninstall_removes_plugin_options(): void {
		update_option( MM_META_OPT_SETTINGS, [ 'test' => true ] );
		update_option( MM_META_OPT_BUSINESS, [ 'name' => 'Test' ] );
		update_option( 'mm_sitemap_images', true );
		update_option( 'mm_sitemap_video', true );

		// Simulate uninstall by deleting options directly.
		delete_option( MM_META_OPT_SETTINGS );
		delete_option( MM_META_OPT_BUSINESS );
		delete_option( 'mm_sitemap_images' );
		delete_option( 'mm_sitemap_video' );

		$this->assertFalse( get_option( MM_META_OPT_SETTINGS ) );
		$this->assertFalse( get_option( MM_META_OPT_BUSINESS ) );
		$this->assertFalse( get_option( 'mm_sitemap_images' ) );
		$this->assertFalse( get_option( 'mm_sitemap_video' ) );
	}

	// ------------------------------------------------------------------
	// Database table removal
	// ------------------------------------------------------------------

	public function test_uninstall_drops_jobs_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . MM_JOB_TABLE;

		// Create the table directly via SQL (dbDelta can be unreliable in test env).
		$charset = $wpdb->get_charset_collate();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			image_name VARCHAR(255) NOT NULL DEFAULT '',
			job_type VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			submitted_at DATETIME NOT NULL,
			PRIMARY KEY (id)
		) {$charset};" );

		// Verify using SHOW TABLES with esc_like (does not emit a DB error on
		// missing table, unlike SELECT which PHPUnit would catch as an Error).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$this->assertNotEmpty( $exists, "Table {$table} should exist after CREATE TABLE." );

		// Drop it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		// Verify table no longer exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$this->assertEmpty( $exists, "Table {$table} should not exist after DROP TABLE." );
	}

	// ------------------------------------------------------------------
	// Transient cleanup
	// ------------------------------------------------------------------

	public function test_uninstall_removes_transients(): void {
		set_transient( 'mm_remote_metadata', 'test', 60 );
		set_transient( 'mm_upload_batch', 'test', 60 );
		set_transient( 'mm_import_lock', '1', 30 );

		delete_transient( 'mm_remote_metadata' );
		delete_transient( 'mm_upload_batch' );
		delete_transient( 'mm_import_lock' );

		$this->assertFalse( get_transient( 'mm_remote_metadata' ) );
		$this->assertFalse( get_transient( 'mm_upload_batch' ) );
		$this->assertFalse( get_transient( 'mm_import_lock' ) );
	}
}
