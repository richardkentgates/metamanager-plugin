<?php
/**
 * Integration tests for MM_CLI.
 *
 * WP-CLI stubs are defined in tests/bootstrap.php before the plugin loads,
 * so class-mm-cli.php defines its class normally.
 *
 * @package Metamanager\Tests\Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * @covers MM_CLI
 */
class Test_MM_CLI extends WP_UnitTestCase {

private array $tmp_files = [];

// ------------------------------------------------------------------
// Setup / teardown
// ------------------------------------------------------------------

public static function set_up_before_class(): void {
parent::set_up_before_class();
MM_DB::create_or_update_table();
foreach ( [ MM_JOB_COMPRESS, MM_JOB_META, MM_JOB_DONE, MM_JOB_FAILED ] as $dir ) {
if ( ! is_dir( $dir ) ) {
mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test setup, WP_Filesystem not available
}
}
}

public function set_up(): void {
parent::set_up();
WP_CLI::$output = [];
}

public function tear_down(): void {
foreach ( [ MM_JOB_COMPRESS, MM_JOB_META, MM_JOB_DONE, MM_JOB_FAILED ] as $dir ) {
foreach ( glob( $dir . '*.json' ) ?: [] as $file ) {
wp_delete_file( $file );
}
}
foreach ( $this->tmp_files as $path ) {
wp_delete_file( $path );
}
$this->tmp_files = [];
parent::tear_down();
}

public static function tear_down_after_class(): void {
MM_DB::drop_table();
if ( is_dir( MM_JOB_ROOT ) ) {
$iter = new RecursiveIteratorIterator(
new RecursiveDirectoryIterator( MM_JOB_ROOT, FilesystemIterator::SKIP_DOTS ),
RecursiveIteratorIterator::CHILD_FIRST
);
foreach ( $iter as $item ) {
$item->isDir() ? rmdir( $item->getRealPath() ) : wp_delete_file( $item->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
rmdir( MM_JOB_ROOT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
parent::tear_down_after_class();
}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

private function count_jobs( string $dir ): int {
return count( glob( $dir . '*.json' ) ?: [] );
}

private function create_tmp_attachment( string $mime = 'image/jpeg' ): array {
$ext = match ( $mime ) {
'image/jpeg'      => 'jpg',
'image/png'       => 'png',
'video/mp4'       => 'mp4',
'audio/mpeg'      => 'mp3',
'audio/ogg'       => 'ogg',
'application/pdf' => 'pdf',
default           => explode( '/', $mime )[1] ?? 'bin',
};
$file_path = sys_get_temp_dir() . '/mm_cli_test_' . uniqid() . '.' . $ext;
file_put_contents( $file_path, str_repeat( "\x00", 64 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$id = $this->factory->attachment->create( [ 'post_mime_type' => $mime ] );
update_post_meta( $id, '_wp_attached_file', $file_path );
$this->tmp_files[] = $file_path;
return [ $id, $file_path ];
}

private function run_cli( string $command, array $args = [], array $assoc_args = [] ): void {
( new MM_CLI() )->$command( $args, $assoc_args );
}

// ------------------------------------------------------------------
// compress
// ------------------------------------------------------------------

public function test_compress_single_image_queues_job(): void {
[ $id ] = $this->create_tmp_attachment( 'image/jpeg' );
$this->run_cli( 'compress', [ (string) $id ] );
$this->assertGreaterThan( 0, $this->count_jobs( MM_JOB_COMPRESS ) );
}

public function test_compress_video_queues_job(): void {
[ $id ] = $this->create_tmp_attachment( 'video/mp4' );
$this->run_cli( 'compress', [ (string) $id ] );
$this->assertSame( 1, $this->count_jobs( MM_JOB_COMPRESS ) );
}

public function test_compress_audio_throws_error(): void {
[ $id ] = $this->create_tmp_attachment( 'audio/mpeg' );
$this->expectException( RuntimeException::class );
$this->run_cli( 'compress', [ (string) $id ] );
}

public function test_compress_already_compressed_skipped_without_force(): void {
[ $id ] = $this->create_tmp_attachment( 'image/jpeg' );
$GLOBALS['wpdb']->insert( $GLOBALS['wpdb']->prefix . MM_JOB_TABLE, [
'attachment_id' => $id,
'size'          => 'full',
'job_type'      => 'compression',
'status'        => 'completed',
'file_path'     => '/tmp/fake.jpg',
'image_name'    => 'fake.jpg',
'job_trigger'   => 'upload',
] );
$this->run_cli( 'compress', [ (string) $id ] );
$this->assertSame( 0, $this->count_jobs( MM_JOB_COMPRESS ) );
}

public function test_compress_already_compressed_requeued_with_force(): void {
[ $id ] = $this->create_tmp_attachment( 'image/jpeg' );
$GLOBALS['wpdb']->insert( $GLOBALS['wpdb']->prefix . MM_JOB_TABLE, [
'attachment_id' => $id,
'size'          => 'full',
'job_type'      => 'compression',
'status'        => 'completed',
'file_path'     => '/tmp/fake.jpg',
'image_name'    => 'fake.jpg',
'job_trigger'   => 'upload',
] );
$this->run_cli( 'compress', [ (string) $id ], [ 'force' => true ] );
$this->assertGreaterThan( 0, $this->count_jobs( MM_JOB_COMPRESS ) );
}

public function test_compress_all_empty_succeeds(): void {
$this->run_cli( 'compress', [ 'all' ] );
$last = end( WP_CLI::$output );
$this->assertSame( 'success', $last['type'] );
}

// ------------------------------------------------------------------
// embed
// ------------------------------------------------------------------

public function test_embed_single_image_queues_metadata_jobs(): void {
[ $id ] = $this->create_tmp_attachment( 'image/jpeg' );
$this->run_cli( 'embed', [ (string) $id ] );
$this->assertGreaterThan( 0, $this->count_jobs( MM_JOB_META ) );
}

public function test_embed_audio_queues_metadata_job(): void {
[ $id ] = $this->create_tmp_attachment( 'audio/mpeg' );
$this->run_cli( 'embed', [ (string) $id ] );
$this->assertSame( 1, $this->count_jobs( MM_JOB_META ) );
}

public function test_embed_all_empty_succeeds(): void {
$this->run_cli( 'embed', [ 'all' ] );
$last = end( WP_CLI::$output );
$this->assertSame( 'success', $last['type'] );
}

// ------------------------------------------------------------------
// import
// ------------------------------------------------------------------

public function test_import_all_empty_succeeds(): void {
$this->run_cli( 'import', [ 'all' ] );
$last = end( WP_CLI::$output );
$this->assertSame( 'success', $last['type'] );
}

public function test_import_unsupported_type_throws_error(): void {
$id = $this->factory->attachment->create( [ 'post_mime_type' => 'text/plain' ] );
$this->expectException( RuntimeException::class );
$this->run_cli( 'import', [ (string) $id ] );
}

// ------------------------------------------------------------------
// scan
// ------------------------------------------------------------------

public function test_scan_queues_jobs_for_unsynced_image(): void {
$this->create_tmp_attachment( 'image/jpeg' );
$this->run_cli( 'scan' );
$this->assertGreaterThan( 0, $this->count_jobs( MM_JOB_META ) + $this->count_jobs( MM_JOB_COMPRESS ) );
}

public function test_scan_skips_already_synced_files(): void {
[ $id ] = $this->create_tmp_attachment( 'image/jpeg' );
$GLOBALS['wpdb']->insert( $GLOBALS['wpdb']->prefix . MM_JOB_TABLE, [
'attachment_id' => $id,
'size'          => 'full',
'job_type'      => 'metadata',
'status'        => 'completed',
'file_path'     => '/tmp/fake.jpg',
'image_name'    => 'fake.jpg',
'job_trigger'   => 'upload',
] );
$this->run_cli( 'scan' );
$last = end( WP_CLI::$output );
$this->assertStringContainsString( 'already scanned', strtolower( $last['msg'] ) );
}

public function test_scan_nothing_to_do_succeeds(): void {
$this->run_cli( 'scan' );
$last = end( WP_CLI::$output );
$this->assertSame( 'success', $last['type'] );
}

// ------------------------------------------------------------------
// stats
// ------------------------------------------------------------------

public function test_stats_outputs_no_error(): void {
$this->run_cli( 'stats' );
$errors = array_filter( WP_CLI::$output, fn( $o ) => 'error' === $o['type'] );
$this->assertEmpty( $errors );
}

// ------------------------------------------------------------------
// queue status
// ------------------------------------------------------------------

public function test_queue_status_outputs_no_error(): void {
$this->run_cli( 'queue', [ 'status' ] );
$errors = array_filter( WP_CLI::$output, fn( $o ) => 'error' === $o['type'] );
$this->assertEmpty( $errors );
}

public function test_queue_unknown_subcommand_throws_error(): void {
$this->expectException( RuntimeException::class );
$this->run_cli( 'queue', [ 'bogus' ] );
}
}
