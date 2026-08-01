<?php
/**
 * Unit tests for MM_Mod_Author — Person schema nodes.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Mod_Author_Unit extends WP_UnitTestCase {

	private MM_Site_Settings $settings;
	private MM_Mod_Author $author_module;

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		$this->settings       = MM_Site_Settings::get_instance();
		$this->author_module  = new MM_Mod_Author( $this->settings );
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// populate() — disabled setting
	// ------------------------------------------------------------------

	public function test_populate_returns_early_when_disabled(): void {
		$this->settings->save_settings( [ 'authors' => [ 'enabled' => false ] ] );

		$data    = $this->empty_data();
		$context = new MM_Page_Context();

		$this->author_module->populate( $data, $context, $this->settings );

		$this->assertEmpty( $data['schema'] );
	}

	// ------------------------------------------------------------------
	// populate() — author archive
	// ------------------------------------------------------------------

	public function test_populate_adds_person_node_on_author_archive(): void {
		$user_id = $this->factory->user->create( [
			'display_name' => 'Test Author',
			'description'  => 'A test author bio.',
		] );

		$this->go_to( get_author_posts_url( $user_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->author_module->populate( $data, $context, $this->settings );

		$nodes = array_filter( $data['schema'], fn( $n ) => ( $n['@type'] ?? '' ) === 'Person' );
		$this->assertCount( 1, $nodes );

		$person = reset( $nodes );
		$this->assertSame( 'Test Author', $person['name'] );
		$this->assertArrayHasKey( 'url', $person );
		$this->assertArrayHasKey( '@id', $person );
		$this->assertStringContainsString( '#person', $person['@id'] );
	}

	public function test_populate_includes_description_on_author_archive(): void {
		$user_id = $this->factory->user->create( [
			'display_name' => 'Author With Bio',
			'description'  => 'This is the bio.',
		] );

		$this->go_to( get_author_posts_url( $user_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->author_module->populate( $data, $context, $this->settings );

		$person = $this->find_person_node( $data );
		$this->assertNotNull( $person );
		$this->assertSame( 'This is the bio.', $person['description'] );
	}

	public function test_populate_includes_avatar(): void {
		$user_id = $this->factory->user->create( [ 'display_name' => 'Avatar User' ] );

		$this->go_to( get_author_posts_url( $user_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->author_module->populate( $data, $context, $this->settings );

		$person = $this->find_person_node( $data );
		$this->assertNotNull( $person );
		$this->assertArrayHasKey( 'image', $person );
		$this->assertSame( 'ImageObject', $person['image']['@type'] );
		$this->assertNotEmpty( $person['image']['url'] );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function empty_data(): array {
		return [
			'title'  => '',
			'meta'   => [],
			'links'  => [],
			'schema' => [],
		];
	}

	private function find_person_node( array $data ): ?array {
		foreach ( $data['schema'] as $node ) {
			if ( ( $node['@type'] ?? '' ) === 'Person' ) {
				return $node;
			}
		}
		return null;
	}
}
