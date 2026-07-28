<?php
/**
 * Integration tests for bug fixes applied in v2.3.10+.
 *
 * Covers:
 *   - FIX-21: sameAs merges social.accounts + mm_meta_business[accounts]
 *   - FIX-22: og:type is 'website' for pages, 'article' for posts
 *   - FIX-23: meta description falls back to site description when empty
 *
 * @package Metamanager\Tests\Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * @covers MM_Mod_Social
 * @covers MM_Mod_Local
 * @covers MM_Mod_Head_Meta
 */
class Test_MM_Fixes extends WP_UnitTestCase {

	private string $original_blogdescription = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( class_exists( 'MM_DB' ) ) {
			MM_DB::create_or_update_table();
		}
	}

	public function set_up(): void {
		parent::set_up();
		wp_reset_query();
		wp_reset_postdata();
		delete_option( MM_META_OPT_SETTINGS );
		delete_option( MM_META_OPT_BUSINESS );
		$this->original_blogdescription = get_option( 'blogdescription', '' );
		MM_Site_Settings::reset_instance();
	}

	public function tear_down(): void {
		wp_reset_query();
		wp_reset_postdata();
		update_option( 'blogdescription', $this->original_blogdescription );
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	/**
	 * Build an emitter with the specified modules.
	 *
	 * @param string[] $modules Module class names to add.
	 * @return array{emitter: MM_Head_Emitter, context: MM_Page_Context, settings: MM_Site_Settings}
	 */
	private function make_emitter( array $modules = [] ): array {
		$settings = MM_Site_Settings::get_instance();
		$context  = new MM_Page_Context();
		$emitter  = new MM_Head_Emitter( $context, $settings );

		$map = [
			'head_meta' => MM_Mod_Head_Meta::class,
			'social'    => MM_Mod_Social::class,
			'local'     => MM_Mod_Local::class,
		];

		foreach ( $modules as $mod ) {
			if ( isset( $map[ $mod ] ) ) {
				$emitter->add_module( new $map[ $mod ]( $settings ) );
			}
		}

		return compact( 'emitter', 'context', 'settings' );
	}

	/**
	 * Render wp_head with the emitter and return the buffered output.
	 */
	private function render_head( MM_Head_Emitter $emitter ): string {
		add_action( 'wp_head', [ $emitter, 'render' ], 99 );
		ob_start();
		do_action( 'wp_head' );
		$output = ob_get_clean();
		remove_action( 'wp_head', [ $emitter, 'render' ], 99 );
		return $output;
	}

	/**
	 * Extract JSON-LD blocks from HTML output.
	 *
	 * @return array<int, array|string>
	 */
	private function extract_json_ld( string $html ): array {
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
	 * Navigate to a post by ID and return head output.
	 */
	private function navigate_and_render( int $post_id, array $modules ): string {
		$this->go_to( get_permalink( $post_id ) );
		$e = $this->make_emitter( $modules );
		return $this->render_head( $e['emitter'] );
	}

	// ------------------------------------------------------------------
	// FIX-22: og:type for pages vs posts
	// ------------------------------------------------------------------

	/** Pages should get og:type=website, not article. */
	public function test_og_type_is_website_for_pages(): void {
		$page_id = self::factory()->post->create( [
			'post_title'  => 'Test Page',
			'post_status' => 'publish',
			'post_type'   => 'page',
		] );

		$html = $this->navigate_and_render( $page_id, [ 'social' ] );

		$this->assertStringContainsString( 'property="og:type"', $html );
		$this->assertStringContainsString( 'content="website"', $html );
		$this->assertStringNotContainsString( 'content="article"', $html );
	}

	/** Posts should get og:type=article. */
	public function test_og_type_is_article_for_posts(): void {
		$post_id = self::factory()->post->create( [
			'post_title'  => 'Test Post',
			'post_status' => 'publish',
			'post_type'   => 'post',
		] );

		$html = $this->navigate_and_render( $post_id, [ 'social' ] );

		$this->assertStringContainsString( 'property="og:type"', $html );
		$this->assertStringContainsString( 'content="article"', $html );
	}

	// ------------------------------------------------------------------
	// FIX-21: sameAs merges both sources
	// ------------------------------------------------------------------

	/** sameAs includes LinkedIn from business profile. */
	public function test_sameas_includes_business_profile_accounts(): void {
		$page_id = self::factory()->post->create( [
			'post_title'  => 'Home',
			'post_status' => 'publish',
			'post_type'   => 'page',
		] );

		update_option( MM_META_OPT_BUSINESS, [
			'name'    => 'Test Business',
			'type'    => 'InsuranceAgency',
			'accounts' => [
				'linkedin'  => 'https://www.linkedin.com/in/test/',
			],
		] );
		MM_Site_Settings::reset_instance();

		$html = $this->navigate_and_render( $page_id, [ 'local' ] );

		$json_ld = $this->extract_json_ld( $html );
		$found   = false;
		foreach ( $json_ld as $block ) {
			$graph = $block['@graph'] ?? $block;
			if ( is_array( $graph ) ) {
				foreach ( (array) $graph as $node ) {
					if ( ! empty( $node['sameAs'] ) ) {
						$found = true;
						$this->assertContains( 'https://www.linkedin.com/in/test/', $node['sameAs'] );
					}
				}
			}
		}
		$this->assertTrue( $found, 'Expected sameAs in JSON-LD output' );
	}

	/** sameAs merges social.accounts and business profile accounts. */
	public function test_sameas_merges_social_and_business_accounts(): void {
		$page_id = self::factory()->post->create( [
			'post_title'  => 'Home',
			'post_status' => 'publish',
			'post_type'   => 'page',
		] );

		update_option( MM_META_OPT_SETTINGS, [
			'social' => [
				'accounts' => [
					'facebook'  => 'https://fb.com/social-settings',
				],
			],
		] );
		update_option( MM_META_OPT_BUSINESS, [
			'name'    => 'Test Business',
			'type'    => 'InsuranceAgency',
			'accounts' => [
				'linkedin'  => 'https://www.linkedin.com/in/biz/',
			],
		] );
		MM_Site_Settings::reset_instance();

		$settings = MM_Site_Settings::get_instance();
		$social_accts = $settings->get( 'social.accounts', [] );
		$biz = $settings->all_business();
		$biz_accts = $biz['accounts'] ?? [];
		$this->assertArrayHasKey( 'facebook', $social_accts );
		$this->assertSame( 'https://fb.com/social-settings', $social_accts['facebook'] );
		$this->assertArrayHasKey( 'linkedin', $biz_accts );
		$this->assertSame( 'https://www.linkedin.com/in/biz/', $biz_accts['linkedin'] );

		$html = $this->navigate_and_render( $page_id, [ 'local' ] );

		$json_ld = $this->extract_json_ld( $html );
		$same_as = [];
		foreach ( $json_ld as $block ) {
			$graph = $block['@graph'] ?? $block;
			if ( is_array( $graph ) ) {
				foreach ( (array) $graph as $node ) {
					if ( ! empty( $node['sameAs'] ) ) {
						$same_as = array_merge( $same_as, $node['sameAs'] );
					}
				}
			}
		}

		$this->assertNotEmpty( $same_as, 'Expected sameAs URLs in JSON-LD output' );
		$this->assertContains( 'https://fb.com/social-settings', $same_as );
		$this->assertContains( 'https://www.linkedin.com/in/biz/', $same_as );
	}

	// ------------------------------------------------------------------
	// FIX-23: meta description fallback to site description
	// ------------------------------------------------------------------

	/** auto_description returns empty for page with no excerpt/content. */
	public function test_auto_description_returns_empty_for_empty_page(): void {
		$page = self::factory()->post->create_and_get( [
			'post_title'   => 'Empty Page',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '',
			'post_excerpt' => '',
		] );

		$settings = MM_Site_Settings::get_instance();
		$mod = new MM_Mod_Head_Meta( $settings );
		$reflection = new ReflectionClass( $mod );

		// Access the private auto_description method.
		$method = $reflection->getMethod( 'auto_description' );
		$method->setAccessible( true );

		$result = $method->invoke( $mod, $page, 'excerpt' );
		$this->assertSame( '', $result );
	}

	/** auto_description returns excerpt when available. */
	public function test_auto_description_returns_excerpt(): void {
		$post = self::factory()->post->create_and_get( [
			'post_title'   => 'Post With Excerpt',
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_content' => 'Long content that should not be used.',
			'post_excerpt' => 'This is the excerpt.',
		] );

		$settings = MM_Site_Settings::get_instance();
		$mod = new MM_Mod_Head_Meta( $settings );
		$reflection = new ReflectionClass( $mod );
		$method = $reflection->getMethod( 'auto_description' );
		$method->setAccessible( true );

		$result = $method->invoke( $mod, $post, 'excerpt' );
		$this->assertSame( 'This is the excerpt.', $result );
	}

	/** resolve_description falls back to site description when auto_description returns empty. */
	public function test_resolve_description_falls_back_to_site_description(): void {
		update_option( 'blogdescription', 'Site Description Fallback' );

		$page_id = self::factory()->post->create( [
			'post_title'   => 'Empty Page',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '',
			'post_excerpt' => '',
		] );

		$this->go_to( get_permalink( $page_id ) );
		$settings = MM_Site_Settings::get_instance();
		$context  = new MM_Page_Context();
		$mod      = new MM_Mod_Head_Meta( $settings );

		$reflection = new ReflectionClass( $mod );
		$method = $reflection->getMethod( 'resolve_description' );
		$method->setAccessible( true );

		$result = $method->invoke( $mod, $context, $settings );
		$this->assertSame( 'Site Description Fallback', $result );
	}

	/** Per-post _mm_meta description takes priority. */
	public function test_per_post_meta_description_takes_priority(): void {
		update_option( 'blogdescription', 'Fallback Tagline' );

		$page_id = self::factory()->post->create( [
			'post_title'   => 'Page With Custom Desc',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '',
			'post_excerpt' => '',
		] );
		update_post_meta( $page_id, MM_META_KEY, wp_json_encode( [ 'description' => 'Custom per-post description.' ] ) );

		$this->go_to( get_permalink( $page_id ) );
		$settings = MM_Site_Settings::get_instance();
		$context  = new MM_Page_Context();
		$mod      = new MM_Mod_Head_Meta( $settings );

		$reflection = new ReflectionClass( $mod );
		$method = $reflection->getMethod( 'resolve_description' );
		$method->setAccessible( true );

		$result = $method->invoke( $mod, $context, $settings );
		$this->assertSame( 'Custom per-post description.', $result );
	}
}
