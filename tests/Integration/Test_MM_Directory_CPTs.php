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
}
