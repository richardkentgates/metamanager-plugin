<?php
/**
 * Integration tests for the module system (schema, social, head-meta, media detection).
 *
 * Uses MM_Head_Emitter which orchestrates all modules.
 *
 * @package Metamanager\Tests\Integration
 */

defined( 'ABSPATH' ) || exit;

class Test_MM_Modules extends WP_UnitTestCase {

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Navigate to the attachment page and capture the head output via MM_Head_Emitter.
	 */
	private function get_head_output( int $id ): string {
		$this->go_to( get_attachment_link( $id ) );
		global $wp_query;
		$wp_query->is_attachment = true;
		$wp_query->is_singular   = true;

		global $post;
		$post = get_post( $id );
		setup_postdata( $post );

		$settings  = MM_Site_Settings::get_instance();
		$context   = new MM_Page_Context();
		$emitter   = new MM_Head_Emitter( $context, $settings );

		// Add all modules that the real plugin registers.
		$modules = [
			'MM_Mod_Head_Meta',
			'MM_Mod_Social',
			'MM_Mod_Schema',
		];
		foreach ( $modules as $class ) {
			$emitter->add_module( new $class( $settings ) );
		}

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		wp_reset_postdata();
		return $output;
	}

	/**
	 * Find a schema node by @type in all JSON-LD blocks.
	 */
	private function find_schema_by_type( string $html, string $type ): ?array {
		$blocks = mm_test_extract_json_ld( $html );
		foreach ( $blocks as $block ) {
			// Check top-level @type.
			if ( ( $block['@type'] ?? '' ) === $type ) {
				return $block;
			}
			// Check inside @graph.
			if ( isset( $block['@graph'] ) && is_array( $block['@graph'] ) ) {
				foreach ( $block['@graph'] as $node ) {
					if ( ( $node['@type'] ?? '' ) === $type ) {
						return $node;
					}
				}
			}
		}
		return null;
	}

	// -----------------------------------------------------------------------
	// ImageObject JSON-LD
	// -----------------------------------------------------------------------

	public function test_image_schema_type_is_image_object(): void {
		$id     = mm_test_make_image_attachment();
		$output = $this->get_head_output( $id );
		$schema = $this->find_schema_by_type( $output, 'ImageObject' );

		$this->assertNotNull( $schema, 'ImageObject schema should be present for an image attachment.' );
		$this->assertSame( 'ImageObject', $schema['@type'] );
	}

	public function test_image_schema_includes_title(): void {
		$id     = mm_test_make_image_attachment();
		$output = $this->get_head_output( $id );
		$schema = $this->find_schema_by_type( $output, 'ImageObject' );

		$this->assertSame( 'Test Image', $schema['name'] );
	}

	public function test_image_schema_includes_creator(): void {
		$id     = mm_test_make_image_attachment( [ MM_Metadata::META_CREATOR => 'Jane Doe' ] );
		$output = $this->get_head_output( $id );
		$schema = $this->find_schema_by_type( $output, 'ImageObject' );

		$this->assertArrayHasKey( 'creator', $schema );
		$this->assertSame( 'Person', $schema['creator']['@type'] );
		$this->assertSame( 'Jane Doe', $schema['creator']['name'] );
	}

	public function test_image_schema_includes_copyright_notice(): void {
		$id     = mm_test_make_image_attachment( [ MM_Metadata::META_COPYRIGHT => '© 2026 Jane Doe' ] );
		$output = $this->get_head_output( $id );
		$schema = $this->find_schema_by_type( $output, 'ImageObject' );

		$this->assertSame( '© 2026 Jane Doe', $schema['copyrightNotice'] );
	}

	public function test_image_schema_includes_keywords_as_array(): void {
		$id     = mm_test_make_image_attachment( [ MM_Metadata::META_KEYWORDS => 'landscape;sunrise;nature' ] );
		$output = $this->get_head_output( $id );
		$schema = $this->find_schema_by_type( $output, 'ImageObject' );

		$this->assertSame( [ 'landscape', 'sunrise', 'nature' ], $schema['keywords'] );
	}

	public function test_image_schema_includes_geocoordinates_when_gps_set(): void {
		$id = mm_test_make_image_attachment( [
			MM_Metadata::META_GPS_LAT => '40.014984',
			MM_Metadata::META_GPS_LON => '-105.270546',
			MM_Metadata::META_GPS_ALT => '1655.0',
		] );
		$output = $this->get_head_output( $id );
		$schema = $this->find_schema_by_type( $output, 'ImageObject' );

		$this->assertArrayHasKey( 'locationCreated', $schema );
		$geo = $schema['locationCreated']['geo'];
		$this->assertSame( 'GeoCoordinates', $geo['@type'] );
		$this->assertEqualsWithDelta( 40.014984, $geo['latitude'],  0.000001 );
		$this->assertEqualsWithDelta( -105.270546, $geo['longitude'], 0.000001 );
		$this->assertEqualsWithDelta( 1655.0, $geo['elevation'],  0.1 );
	}

	public function test_image_schema_omits_geocoordinates_when_no_gps(): void {
		$id     = mm_test_make_image_attachment();
		$output = $this->get_head_output( $id );
		$schema = $this->find_schema_by_type( $output, 'ImageObject' );

		$this->assertArrayNotHasKey( 'locationCreated', $schema );
	}

	public function test_image_schema_includes_iptc_location_without_gps(): void {
		$id = mm_test_make_image_attachment( [
			MM_Metadata::META_CITY    => 'Boulder',
			MM_Metadata::META_STATE   => 'CO',
			MM_Metadata::META_COUNTRY => 'USA',
		] );
		$output = $this->get_head_output( $id );
		$schema = $this->find_schema_by_type( $output, 'ImageObject' );

		$this->assertArrayHasKey( 'locationCreated', $schema );
		$this->assertSame( 'Boulder, CO, USA', $schema['locationCreated']['name'] );
	}

	// -----------------------------------------------------------------------
	// VideoObject JSON-LD
	// -----------------------------------------------------------------------

	public function test_video_schema_type_is_video_object(): void {
		$id     = mm_test_make_video_attachment();
		$output = $this->get_head_output( $id );
		$schema = $this->find_schema_by_type( $output, 'VideoObject' );

		$this->assertNotNull( $schema, 'VideoObject schema should be present for a video attachment.' );
		$this->assertSame( 'VideoObject', $schema['@type'] );
	}

	public function test_video_schema_has_upload_date(): void {
		$id     = mm_test_make_video_attachment();
		$output = $this->get_head_output( $id );
		$schema = $this->find_schema_by_type( $output, 'VideoObject' );

		$this->assertArrayHasKey( 'uploadDate', $schema );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $schema['uploadDate'] );
	}

	// -----------------------------------------------------------------------
	// AudioObject JSON-LD
	// -----------------------------------------------------------------------

	public function test_audio_schema_type_is_audio_object(): void {
		$id     = mm_test_make_audio_attachment();
		$output = $this->get_head_output( $id );
		$schema = $this->find_schema_by_type( $output, 'AudioObject' );

		$this->assertNotNull( $schema, 'AudioObject schema should be present for an audio attachment.' );
		$this->assertSame( 'AudioObject', $schema['@type'] );
	}

	// -----------------------------------------------------------------------
	// Open Graph tags
	// -----------------------------------------------------------------------

	public function test_image_og_tags_are_present(): void {
		$id     = mm_test_make_image_attachment();
		$output = $this->get_head_output( $id );

		$this->assertStringContainsString( 'og:image', $output );
	}

	public function test_video_og_tag_is_present(): void {
		$id     = mm_test_make_video_attachment();
		$output = $this->get_head_output( $id );

		$this->assertStringContainsString( 'og:video', $output );
	}

	public function test_audio_og_tag_is_present(): void {
		$id     = mm_test_make_audio_attachment();
		$output = $this->get_head_output( $id );

		$this->assertStringContainsString( 'og:audio', $output );
	}

	// -----------------------------------------------------------------------
	// License link / copyright meta
	// -----------------------------------------------------------------------

	public function test_license_link_emitted_for_url_copyright(): void {
		$id = mm_test_make_image_attachment( [
			MM_Metadata::META_COPYRIGHT => 'https://creativecommons.org/licenses/by/4.0/',
		] );
		$output = $this->get_head_output( $id );

		$this->assertStringContainsString( 'rel="license"', $output );
		$this->assertStringContainsString( 'https://creativecommons.org/licenses/by/4.0/', $output );
	}

	public function test_copyright_meta_emitted_for_plain_text_copyright(): void {
		$id = mm_test_make_image_attachment( [
			MM_Metadata::META_COPYRIGHT => '© 2026 Jane Doe',
		] );
		$output = $this->get_head_output( $id );

		$this->assertStringContainsString( 'name="copyright"', $output );
		$this->assertStringContainsString( '© 2026 Jane Doe', $output );
	}

	public function test_no_license_output_when_no_copyright(): void {
		$id     = mm_test_make_image_attachment();
		$output = $this->get_head_output( $id );

		$this->assertStringNotContainsString( 'rel="license"',   $output );
		$this->assertStringNotContainsString( 'name="copyright"', $output );
	}

	// -----------------------------------------------------------------------
	// Edge cases
	// -----------------------------------------------------------------------

	public function test_no_output_for_non_media_attachment(): void {
		$id = $this->factory->attachment->create( [
			'post_mime_type' => 'text/plain',
		] );
		$output = $this->get_head_output( $id );

		$this->assertStringNotContainsString( 'ImageObject', $output );
		$this->assertStringNotContainsString( 'og:image', $output );
	}
}
