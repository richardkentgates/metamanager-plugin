<?php
/**
 * Shared test helpers for Metamanager tests.
 *
 * Loaded by bootstrap.php before any test class.
 */

/**
 * Create a temporary image attachment with fake metadata.
 *
 * @param array $meta Additional post meta to set.
 * @return int Attachment ID.
 */
function mm_test_make_image_attachment( array $meta = [] ): int {
	$factory = new WP_UnitTest_Factory();
	$id      = $factory->attachment->create( [
		'post_mime_type' => 'image/jpeg',
		'post_title'     => 'Test Image',
		'post_excerpt'   => 'A test caption.',
		'post_content'   => 'A longer description.',
	] );

	update_post_meta( $id, '_wp_attached_file', 'test-image.jpg' );
	update_post_meta( $id, '_wp_attachment_metadata', [
		'width'  => 1920,
		'height' => 1080,
		'file'   => 'test-image.jpg',
		'sizes'  => [],
	] );

	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}

	return $id;
}

/**
 * Create a temporary video attachment.
 *
 * @param array $meta Additional post meta.
 * @return int Attachment ID.
 */
function mm_test_make_video_attachment( array $meta = [] ): int {
	$factory = new WP_UnitTest_Factory();
	$id      = $factory->attachment->create( [
		'post_mime_type' => 'video/mp4',
		'post_title'     => 'Test Video',
		'post_excerpt'   => 'A video description.',
	] );
	update_post_meta( $id, '_wp_attached_file', 'test-video.mp4' );

	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}

	return $id;
}

/**
 * Create a temporary audio attachment.
 *
 * @param array $meta Additional post meta.
 * @return int Attachment ID.
 */
function mm_test_make_audio_attachment( array $meta = [] ): int {
	$factory = new WP_UnitTest_Factory();
	$id      = $factory->attachment->create( [
		'post_mime_type' => 'audio/mpeg',
		'post_title'     => 'Test Audio',
	] );
	update_post_meta( $id, '_wp_attached_file', 'test-audio.mp3' );

	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}

	return $id;
}

/**
 * Extract and decode all JSON-LD blocks from HTML output.
 *
 * @param string $html HTML containing script[type="application/ld+json"] blocks.
 * @return array<int, array|string> Decoded JSON-LD data.
 */
function mm_test_extract_json_ld( string $html ): array {
	preg_match_all( '/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches );
	$results = [];
	foreach ( $matches[1] as $json ) {
		$data = json_decode( $json, true );
		if ( is_array( $data ) ) {
			$results[] = $data;
		}
	}
	return $results;
}

/**
 * Count jobs in the MM_Job_Queue table.
 *
 * @param string $status Optional status filter.
 * @return int Job count.
 */
function mm_test_count_jobs( string $status = '' ): int {
	global $wpdb;
	$table = $wpdb->prefix . 'mm_jobs';
	$where = '';
	if ( $status ) {
		$where = $wpdb->prepare( ' WHERE status = %s', $status );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where}" );
}

/**
 * Create a temporary file in the job queue directory and return its path.
 *
 * @param string $suffix File suffix (default: .json).
 * @return string Path to the temporary file.
 */
function mm_test_create_tmp_file( string $suffix = '.json' ): string {
	$dir = sys_get_temp_dir() . '/mm_test_' . uniqid();
	wp_mkdir_p( $dir );
	$file = $dir . '/test' . $suffix;
	file_put_contents( $file, '{}' );
	return $file;
}
