<?php
/**
 * Performance tests for Metamanager.
 *
 * Covers: query count baselines on key page types, sitemap cache effectiveness,
 * and N+1 query detection on archive pages.
 *
 * Query counts are measured with get_num_queries() after a go_to() + full
 * render cycle. Thresholds are generous to avoid flaky tests on varying
 * WordPress versions, but tight enough to catch major regressions.
 *
 * @package Metamanager\Tests\Integration
 */

defined( 'ABSPATH' ) || exit;

class Test_MM_Performance extends WP_UnitTestCase {

	/** Maximum queries allowed for homepage render. */
	private const HOME_QUERIES_MAX = 30;
	/** Maximum queries for single post render. */
	private const POST_QUERIES_MAX = 35;
	/** Maximum queries for category archive render. */
	private const CATEGORY_QUERIES_MAX = 35;
	/** Maximum queries for sitemap XML render. */
	private const SITEMAP_QUERIES_MAX = 25;

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Capture full wp_head + wp_footer output for a given URL.
	 *
	 * Uses go_to() to simulate the request, then fires the standard
	 * WordPress template hooks to generate realistic output.
	 */
	private function capture_page_output( string $path ): string {
		$this->go_to( $path );

		ob_start();
		// Fire wp_head to trigger all head modules.
		do_action( 'wp_head' );
		// Fire wp_footer.
		do_action( 'wp_footer' );
		return ob_get_clean();
	}

	// -----------------------------------------------------------------------
	// 1. Homepage query count
	// -----------------------------------------------------------------------

	public function test_homepage_query_count(): void {
		$num = get_num_queries();
		$this->capture_page_output( '/' );
		$queries = get_num_queries() - $num;

		$this->assertLessThanOrEqual(
			self::HOME_QUERIES_MAX,
			$queries,
			"Homepage used $queries queries (max " . self::HOME_QUERIES_MAX . ').'
		);
	}

	// -----------------------------------------------------------------------
	// 2. Single post query count
	// -----------------------------------------------------------------------

	public function test_single_post_query_count(): void {
		$post_id = $this->factory->post->create( [
			'post_title'   => 'Performance Test Post',
			'post_content' => 'Test content for query count.',
			'post_status'  => 'publish',
		] );

		$num = get_num_queries();
		$this->capture_page_output( get_permalink( $post_id ) );
		$queries = get_num_queries() - $num;

		$this->assertLessThanOrEqual(
			self::POST_QUERIES_MAX,
			$queries,
			"Single post used $queries queries (max " . self::POST_QUERIES_MAX . ').'
		);
	}

	// -----------------------------------------------------------------------
	// 3. Category archive query count
	// -----------------------------------------------------------------------

	public function test_category_archive_query_count(): void {
		$cat_id = $this->factory->category->create( [ 'name' => 'Perf Test' ] );
		// Create 5 posts in the category.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->factory->post->create( [
				'post_title'  => "Perf Post $i",
				'post_status' => 'publish',
				'post_category' => [ $cat_id ],
			] );
		}

		$num = get_num_queries();
		$this->capture_page_output( get_category_link( $cat_id ) );
		$queries = get_num_queries() - $num;

		$this->assertLessThanOrEqual(
			self::CATEGORY_QUERIES_MAX,
			$queries,
			"Category archive used $queries queries (max " . self::CATEGORY_QUERIES_MAX . ').'
		);
	}

	// -----------------------------------------------------------------------
	// 4. Sitemap query count
	// -----------------------------------------------------------------------

	public function test_sitemap_query_count(): void {
		// Flush rewrite rules so sitemap endpoints are recognized.
		global $wp_rewrite;
		$wp_rewrite->flush_rules();

		$num = get_num_queries();
		$this->capture_page_output( home_url( '/sitemap.xml' ) );
		$queries = get_num_queries() - $num;

		$this->assertLessThanOrEqual(
			self::SITEMAP_QUERIES_MAX,
			$queries,
			"Sitemap used $queries queries (max " . self::SITEMAP_QUERIES_MAX . ').'
		);
	}

	// -----------------------------------------------------------------------
	// 5. Sitemap cache — second request reuses cached data
	// -----------------------------------------------------------------------

	public function test_sitemap_cache_reduces_queries(): void {
		global $wp_rewrite;
		$wp_rewrite->flush_rules();

		// First request — populates cache.
		$num1 = get_num_queries();
		$this->capture_page_output( home_url( '/sitemap.xml' ) );
		$first_queries = get_num_queries() - $num1;

		// Second request — should hit cache.
		$num2 = get_num_queries();
		$this->capture_page_output( home_url( '/sitemap.xml' ) );
		$second_queries = get_num_queries() - $num2;

		// Cache hit should use fewer or equal queries.
		$this->assertLessThanOrEqual(
			$first_queries,
			$second_queries,
			"Second sitemap request ($second_queries queries) should not exceed first ($first_queries queries)."
		);
	}

	// -----------------------------------------------------------------------
	// 6. No N+1 queries on archive pages
	// -----------------------------------------------------------------------

	public function test_no_n_plus_one_on_category_archive(): void {
		$cat_id = $this->factory->category->create( [ 'name' => 'N1 Test' ] );
		// Create 10 posts — N+1 would mean 10 extra queries for post meta.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->factory->post->create( [
				'post_title'  => "N1 Post $i",
				'post_status' => 'publish',
				'post_category' => [ $cat_id ],
			] );
		}

		$num = get_num_queries();
		$this->capture_page_output( get_category_link( $cat_id ) );
		$queries = get_num_queries() - $num;

		// With 10 posts, N+1 would easily exceed 40 queries.
		// A well-behaved archive should stay under 30 even with 10 posts.
		$this->assertLessThanOrEqual(
			30,
			$queries,
			"Category archive with 10 posts used $queries queries — possible N+1 problem."
		);
	}
}
