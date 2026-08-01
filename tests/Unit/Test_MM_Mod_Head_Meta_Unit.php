<?php
/**
 * Unit tests for MM_Mod_Head_Meta — title, canonical, robots directives.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Mod_Head_Meta_Unit extends WP_UnitTestCase {

	private MM_Site_Settings $settings;
	private MM_Mod_Head_Meta $head_meta_module;

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		$this->settings          = MM_Site_Settings::get_instance();
		$this->head_meta_module  = new MM_Mod_Head_Meta( $this->settings );
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// Title — 9-level resolution
	// ------------------------------------------------------------------

	public function test_title_front_page(): void {
		$this->go_to( '/' );
		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$this->assertNotEmpty( $data['title'] );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $data['title'] );
	}

	public function test_title_singular_post_with_override(): void {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'My Post',
			'post_status' => 'publish',
		] );

		update_post_meta( $post_id, MM_META_KEY, wp_json_encode( [ 'title' => 'Custom Title %%sep%% %%sitetitle%%' ] ) );
		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$this->assertStringContainsString( 'Custom Title', $data['title'] );
	}

	public function test_title_singular_post_default_template(): void {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'Auto Title Post',
			'post_status' => 'publish',
		] );

		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$this->assertStringContainsString( 'Auto Title Post', $data['title'] );
	}

	public function test_title_blog_index(): void {
		update_option( 'show_on_front', 'page' );
		$posts_page = $this->factory->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_option( 'page_for_posts', $posts_page );
		$this->go_to( get_permalink( $posts_page ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$this->assertStringContainsString( 'Blog', $data['title'] );
	}

	public function test_title_taxonomy_archive(): void {
		$term_id = $this->factory->term->create( [ 'taxonomy' => 'category', 'name' => 'Test Category' ] );
		$this->go_to( get_term_link( $term_id, 'category' ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$this->assertStringContainsString( 'Test Category', $data['title'] );
	}

	public function test_title_author_archive(): void {
		$user_id = $this->factory->user->create( [ 'display_name' => 'Author Name' ] );
		$this->go_to( get_author_posts_url( $user_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$this->assertStringContainsString( 'Author Name', $data['title'] );
	}

	public function test_title_search_results(): void {
		$this->go_to( '/?s=test' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$this->assertStringContainsString( 'Search Results', $data['title'] );
	}

	public function test_title_404(): void {
		$this->go_to( '/?p=99999' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$this->assertStringContainsString( 'Page Not Found', $data['title'] );
	}

	// ------------------------------------------------------------------
	// Meta description
	// ------------------------------------------------------------------

	public function test_description_front_page(): void {
		update_option( 'blogdescription', 'A test site description.' );
		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$desc = $this->find_meta_by_name( $data, 'description' );
		$this->assertNotNull( $desc );
		$this->assertSame( 'A test site description.', $desc['content'] );
	}

	public function test_description_singular_post_with_override(): void {
		$post_id = $this->factory->post->create( [
			'post_status' => 'publish',
		] );

		update_post_meta( $post_id, MM_META_KEY, wp_json_encode( [ 'description' => 'Custom description here.' ] ) );
		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$desc = $this->find_meta_by_name( $data, 'description' );
		$this->assertNotNull( $desc );
		$this->assertSame( 'Custom description here.', $desc['content'] );
	}

	public function test_description_singular_post_fallback_to_excerpt(): void {
		$post_id = $this->factory->post->create( [
			'post_excerpt'  => 'This is the excerpt text.',
			'post_status'   => 'publish',
		] );

		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$desc = $this->find_meta_by_name( $data, 'description' );
		$this->assertNotNull( $desc );
		$this->assertStringContainsString( 'This is the excerpt text.', $desc['content'] );
	}

	public function test_description_singular_post_fallback_to_content(): void {
		$post_id = $this->factory->post->create( [
			'post_content'  => 'Long content body text that should be trimmed.',
			'post_status'   => 'publish',
		] );

		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$desc = $this->find_meta_by_name( $data, 'description' );
		$this->assertNotNull( $desc );
		$this->assertNotEmpty( $desc['content'] );
	}

	public function test_description_home(): void {
		update_option( 'blogdescription', 'Home description test.' );
		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$desc = $this->find_meta_by_name( $data, 'description' );
		$this->assertNotNull( $desc );
		$this->assertSame( 'Home description test.', $desc['content'] );
	}

	// ------------------------------------------------------------------
	// Canonical
	// ------------------------------------------------------------------

	public function test_canonical_front_page(): void {
		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$canonical = $this->find_link_by_rel( $data, 'canonical' );
		$this->assertNotNull( $canonical );
		$this->assertSame( home_url( '/' ), $canonical['href'] );
	}

	public function test_canonical_singular_post(): void {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$canonical = $this->find_link_by_rel( $data, 'canonical' );
		$this->assertNotNull( $canonical );
		$this->assertSame( get_permalink( $post_id ), $canonical['href'] );
	}

	public function test_canonical_singular_post_with_override(): void {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, MM_META_KEY, wp_json_encode( [ 'canonical' => 'https://example.com/custom-canonical' ] ) );
		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$canonical = $this->find_link_by_rel( $data, 'canonical' );
		$this->assertNotNull( $canonical );
		$this->assertSame( 'https://example.com/custom-canonical', $canonical['href'] );
	}

	public function test_canonical_taxonomy_archive(): void {
		$term_id = $this->factory->term->create( [ 'taxonomy' => 'category', 'name' => 'Canonical Cat' ] );
		$this->go_to( get_term_link( $term_id, 'category' ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$canonical = $this->find_link_by_rel( $data, 'canonical' );
		$this->assertNotNull( $canonical );
		$this->assertSame( get_term_link( $term_id, 'category' ), $canonical['href'] );
	}

	public function test_canonical_author_archive(): void {
		$user_id = $this->factory->user->create();
		$this->go_to( get_author_posts_url( $user_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$canonical = $this->find_link_by_rel( $data, 'canonical' );
		$this->assertNotNull( $canonical );
		$this->assertSame( get_author_posts_url( $user_id ), $canonical['href'] );
	}

	// ------------------------------------------------------------------
	// Robots
	// ------------------------------------------------------------------

	public function test_robots_default_index_follow(): void {
		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$robots = $this->find_meta_by_name( $data, 'robots' );
		$this->assertNotNull( $robots );
		$this->assertStringContainsString( 'index', $robots['content'] );
		$this->assertStringContainsString( 'follow', $robots['content'] );
	}

	public function test_robots_404_page(): void {
		$this->go_to( '/?p=99999' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$robots = $this->find_meta_by_name( $data, 'robots' );
		$this->assertNotNull( $robots );
		$this->assertStringContainsString( 'noindex', $robots['content'] );
	}

	public function test_robots_singular_post_noindex(): void {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, MM_META_KEY, wp_json_encode( [ 'noindex' => true ] ) );
		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$robots = $this->find_meta_by_name( $data, 'robots' );
		$this->assertNotNull( $robots );
		$this->assertStringContainsString( 'noindex', $robots['content'] );
	}

	public function test_robots_singular_post_nofollow(): void {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, MM_META_KEY, wp_json_encode( [ 'nofollow' => true ] ) );
		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$robots = $this->find_meta_by_name( $data, 'robots' );
		$this->assertNotNull( $robots );
		$this->assertStringContainsString( 'nofollow', $robots['content'] );
	}

	public function test_robots_singular_post_noarchive(): void {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, MM_META_KEY, wp_json_encode( [ 'noarchive' => true ] ) );
		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$robots = $this->find_meta_by_name( $data, 'robots' );
		$this->assertNotNull( $robots );
		$this->assertStringContainsString( 'noarchive', $robots['content'] );
	}

	public function test_robots_singular_post_nosnippet(): void {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, MM_META_KEY, wp_json_encode( [ 'nosnippet' => true ] ) );
		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$robots = $this->find_meta_by_name( $data, 'robots' );
		$this->assertNotNull( $robots );
		$this->assertStringContainsString( 'nosnippet', $robots['content'] );
	}

	public function test_robots_singular_post_noimageindex(): void {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, MM_META_KEY, wp_json_encode( [ 'noimageindex' => true ] ) );
		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$robots = $this->find_meta_by_name( $data, 'robots' );
		$this->assertNotNull( $robots );
		$this->assertStringContainsString( 'noimageindex', $robots['content'] );
	}

	public function test_robots_taxonomy_noindex(): void {
		$term_id = $this->factory->term->create( [ 'taxonomy' => 'category', 'name' => 'Noindex Cat' ] );
		$this->settings->save_settings( [ 'titles' => [ 'taxonomies' => [ 'category' => [ 'noindex' => true ] ] ] ] );
		$this->go_to( get_term_link( $term_id, 'category' ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$robots = $this->find_meta_by_name( $data, 'robots' );
		$this->assertNotNull( $robots );
		$this->assertStringContainsString( 'noindex', $robots['content'] );
	}

	public function test_robots_author_noindex(): void {
		$user_id = $this->factory->user->create();
		$this->settings->save_settings( [ 'authors' => [ 'noindex_default' => true ] ] );
		$this->go_to( get_author_posts_url( $user_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$robots = $this->find_meta_by_name( $data, 'robots' );
		$this->assertNotNull( $robots );
		$this->assertStringContainsString( 'noindex', $robots['content'] );
	}

	public function test_robots_date_archive(): void {
		$this->settings->save_settings( [ 'titles' => [ 'date_archive_noindex' => true ] ] );
		$this->go_to( '/?year=2026' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->head_meta_module->populate( $data, $context, $this->settings );

		$robots = $this->find_meta_by_name( $data, 'robots' );
		$this->assertNotNull( $robots );
		$this->assertStringContainsString( 'noindex', $robots['content'] );
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

	private function find_meta_by_name( array $data, string $name ): ?array {
		foreach ( $data['meta'] as $meta ) {
			if ( ( $meta['name'] ?? '' ) === $name ) {
				return $meta;
			}
		}
		return null;
	}

	private function find_link_by_rel( array $data, string $rel ): ?array {
		foreach ( $data['links'] as $link ) {
			if ( ( $link['rel'] ?? '' ) === $rel ) {
				return $link;
			}
		}
		return null;
	}
}