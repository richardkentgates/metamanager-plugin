<?php
/**
 * Integration tests for the Directory CPTs (mm_business, mm_organization).
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Directory_CPTs extends WP_UnitTestCase {

	public function test_directory_cpts_registered(): void {
		MM_Schema_Post_Types::register_post_types();
		$this->assertTrue( post_type_exists( 'mm_business' ), 'mm_business should be registered' );
		$this->assertTrue( post_type_exists( 'mm_organization' ), 'mm_organization should be registered' );
	}

	public function test_directory_cpts_map_to_schema_types(): void {
		$this->assertTrue( MM_Schema_Post_Types::is_schema_cpt( 'mm_business' ) );
		$this->assertTrue( MM_Schema_Post_Types::is_schema_cpt( 'mm_organization' ) );
		$this->assertSame( 'LocalBusiness', MM_Schema_Post_Types::slug_to_type( 'mm_business' ) );
		$this->assertSame( 'Organization', MM_Schema_Post_Types::slug_to_type( 'mm_organization' ) );
	}

	public function test_directory_cpt_templates_exist(): void {
		foreach ( [ 'single-mm_business.php', 'archive-mm_business.php',
			'single-mm_organization.php', 'archive-mm_organization.php' ] as $tpl ) {
			$this->assertFileExists( MM_META_DIR . 'templates/' . $tpl );
		}
	}

	public function test_directory_cpt_posts_store_schema_fields(): void {
		MM_Schema_Post_Types::register_post_types();
		$post_id = self::factory()->post->create( [
			'post_type'   => 'mm_business',
			'post_title'  => 'Test Business',
			'post_status' => 'publish',
		] );
		$fields = [ 'business_type' => 'BeautySalon', 'business_telephone' => '318-555-0100' ];
		update_post_meta( $post_id, 'mm_schema_fields', $fields );
		$stored = get_post_meta( $post_id, 'mm_schema_fields', true );
		$this->assertSame( $fields, $stored );

		$additions = MM_Schema_Types::build_node_additions( $stored, 'LocalBusiness' );
		$this->assertSame( 'BeautySalon', $additions['@type'] );
		$this->assertSame( '318-555-0100', $additions['telephone'] );
	}

	public function test_details_table_renders_filled_fields_with_labels(): void {
		MM_Schema_Post_Types::register_post_types();
		$post_id = self::factory()->post->create( [
			'post_type'   => 'mm_destination',
			'post_title'  => 'Details Test',
			'post_status' => 'publish',
		] );
		update_post_meta( $post_id, 'mm_schema_fields', [
			'destination_address'   => '820 Kiroli Road, West Monroe, LA 71291',
			'destination_map_url'   => 'https://example.com/map',
			'destination_attractions' => 'Sunken Gardens',
			'destination_booking_url' => '',
		] );
		ob_start();
		MM_Schema_Post_Types::render_details_table( $post_id );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'mm-schema-details', $out );
		$this->assertStringContainsString( 'Address', $out );
		$this->assertStringContainsString( '820 Kiroli Road, West Monroe, LA 71291', $out );
		$this->assertStringContainsString( 'Map URL', $out );
		$this->assertStringContainsString( 'https://example.com/map', $out );
		// Empty fields are skipped.
		$this->assertStringNotContainsString( 'Booking URL', $out );
	}

	public function test_details_table_renders_dynamic_keys_and_links_urls(): void {
		MM_Schema_Post_Types::register_post_types();
		$post_id = self::factory()->post->create( [
			'post_type'   => 'mm_faq_page',
			'post_title'  => 'FAQ Test',
			'post_status' => 'publish',
		] );
		update_post_meta( $post_id, 'mm_schema_fields', [
			'faq_question_1' => 'What are the hours?',
			'faq_answer_1'   => 'Open daily.',
		] );
		ob_start();
		MM_Schema_Post_Types::render_details_table( $post_id );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'What are the hours?', $out );
		$this->assertStringContainsString( 'Open daily.', $out );
		$this->assertStringContainsString( 'Faq Question 1', $out );
	}

	public function test_details_table_renders_nothing_without_fields(): void {
		MM_Schema_Post_Types::register_post_types();
		$post_id = self::factory()->post->create( [
			'post_type'   => 'mm_service',
			'post_title'  => 'No Fields',
			'post_status' => 'publish',
		] );
		ob_start();
		MM_Schema_Post_Types::render_details_table( $post_id );
		$out = ob_get_clean();
		$this->assertSame( '', $out );
	}
}
