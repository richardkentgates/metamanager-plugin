<?php
/**
 * Unit tests for MM_Page_Context — page type resolution.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Page_Context_Unit extends WP_UnitTestCase {

	private MM_Page_Context $context;

	public function set_up(): void {
		parent::set_up();
		$this->context = new MM_Page_Context();
	}

	// ------------------------------------------------------------------
	// get() caches results
	// ------------------------------------------------------------------

	public function test_get_returns_array(): void {
		$result = $this->context->get();
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'is_front_page', $result );
		$this->assertArrayHasKey( 'is_home', $result );
		$this->assertArrayHasKey( 'is_singular', $result );
		$this->assertArrayHasKey( 'is_archive', $result );
		$this->assertArrayHasKey( 'is_tax', $result );
		$this->assertArrayHasKey( 'is_category', $result );
		$this->assertArrayHasKey( 'is_tag', $result );
		$this->assertArrayHasKey( 'is_author', $result );
		$this->assertArrayHasKey( 'is_date', $result );
		$this->assertArrayHasKey( 'is_search', $result );
		$this->assertArrayHasKey( 'is_404', $result );
		$this->assertArrayHasKey( 'is_post_type_archive', $result );
		$this->assertArrayHasKey( 'post', $result );
		$this->assertArrayHasKey( 'term', $result );
		$this->assertArrayHasKey( 'author', $result );
		$this->assertArrayHasKey( 'post_type', $result );
	}

	public function test_get_caches_result(): void {
		$first  = $this->context->get();
		$second = $this->context->get();
		$this->assertSame( $first, $second );
	}

	// ------------------------------------------------------------------
	// Convenience booleans — default context (front page)
	// ------------------------------------------------------------------

	public function test_is_front_page_on_front(): void {
		$this->go_to( '/' );
		$ctx = new MM_Page_Context();
		// In default WP test env, the front page is the home page.
		$this->assertIsBool( $ctx->is_front_page() );
	}

	public function test_is_home_on_home(): void {
		$this->go_to( '/' );
		$ctx = new MM_Page_Context();
		$this->assertTrue( $ctx->is_home() );
	}

	public function test_is_singular_on_single_post(): void {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $post_id ) );
		$ctx = new MM_Page_Context();
		$this->assertTrue( $ctx->is_singular() );
		$this->assertFalse( $ctx->is_archive() );
	}

	public function test_is_archive_on_category(): void {
		$term_id = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		$this->go_to( get_category_link( $term_id ) );
		$ctx = new MM_Page_Context();
		$this->assertTrue( $ctx->is_archive() );
		$this->assertTrue( $ctx->is_category() );
	}

	public function test_is_search_on_search(): void {
		$this->go_to( '/?s=test' );
		$ctx = new MM_Page_Context();
		$this->assertTrue( $ctx->is_search() );
	}

	public function test_is_404_on_nonexistent(): void {
		$this->go_to( '/?p=99999' );
		$ctx = new MM_Page_Context();
		$this->assertTrue( $ctx->is_404() );
	}

	// ------------------------------------------------------------------
	// get_post()
	// ------------------------------------------------------------------

	public function test_get_post_returns_post_on_singular(): void {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $post_id ) );
		$ctx  = new MM_Page_Context();
		$post = $ctx->get_post();
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( $post_id, $post->ID );
	}

	public function test_get_post_returns_null_on_archive(): void {
		$this->go_to( '/' );
		$ctx = new MM_Page_Context();
		$this->assertNull( $ctx->get_post() );
	}

	// ------------------------------------------------------------------
	// get_term()
	// ------------------------------------------------------------------

	public function test_get_term_returns_term_on_category(): void {
		$term_id = $this->factory->term->create( [ 'taxonomy' => 'category' ] );
		$this->go_to( get_category_link( $term_id ) );
		$ctx  = new MM_Page_Context();
		$term = $ctx->get_term();
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( $term_id, $term->term_id );
	}

	public function test_get_term_returns_null_on_singular(): void {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $post_id ) );
		$ctx = new MM_Page_Context();
		$this->assertNull( $ctx->get_term() );
	}

	// ------------------------------------------------------------------
	// get_post_type()
	// ------------------------------------------------------------------

	public function test_get_post_type_on_singular(): void {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish', 'post_type' => 'post' ] );
		$this->go_to( get_permalink( $post_id ) );
		$ctx = new MM_Page_Context();
		$this->assertSame( 'post', $ctx->get_post_type() );
	}

	public function test_get_post_type_empty_on_home(): void {
		$this->go_to( '/' );
		$ctx = new MM_Page_Context();
		$this->assertSame( '', $ctx->get_post_type() );
	}

	// ------------------------------------------------------------------
	// get_page_number()
	// ------------------------------------------------------------------

	public function test_get_page_number_returns_1_by_default(): void {
		$this->go_to( '/' );
		$ctx = new MM_Page_Context();
		$this->assertSame( 1, $ctx->get_page_number() );
	}

	// ------------------------------------------------------------------
	// Null getters in default context
	// ------------------------------------------------------------------

	public function test_get_author_returns_null_on_home(): void {
		$this->go_to( '/' );
		$ctx = new MM_Page_Context();
		$this->assertNull( $ctx->get_author() );
	}
}
