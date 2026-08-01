<?php
/**
 * Unit tests for MM_Mod_Social — Open Graph + Twitter/X card tags.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Mod_Social_Unit extends WP_UnitTestCase {

	private MM_Site_Settings $settings;
	private MM_Mod_Social $social_module;

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		$this->settings       = MM_Site_Settings::get_instance();
		$this->social_module  = new MM_Mod_Social( $this->settings );
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// populate() — OG disabled
	// ------------------------------------------------------------------

	public function test_populate_returns_early_when_og_disabled(): void {
		$all = $this->settings->all();
		$all['social']['og_enabled'] = false;
		$this->settings->save_settings( $all );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$this->assertEmpty( $data['meta'] );
	}

	// ------------------------------------------------------------------
	// populate() — front page
	// ------------------------------------------------------------------

	public function test_populate_emits_og_on_front_page(): void {
		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$this->assertMetaExists( $data, 'property', 'og:type', 'website' );
		$this->assertMetaExists( $data, 'property', 'og:site_name', get_bloginfo( 'name' ) );
		$this->assertMetaExists( $data, 'property', 'og:url', home_url( '/' ) );
		$this->assertMetaExists( $data, 'property', 'og:locale', 'en_US' );
	}

	public function test_populate_emits_twitter_on_front_page(): void {
		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$this->assertMetaExists( $data, 'name', 'twitter:card', 'summary' );
		$this->assertMetaExists( $data, 'name', 'twitter:title', '' );
	}

	// ------------------------------------------------------------------
	// populate() — twitter disabled
	// ------------------------------------------------------------------

	public function test_populate_no_twitter_when_disabled(): void {
		$all = $this->settings->all();
		$all['social']['twitter_enabled'] = false;
		$this->settings->save_settings( $all );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$twitter_metas = array_filter( $data['meta'], fn( $m ) => str_starts_with( $m['name'] ?? '', 'twitter:' ) );
		$this->assertEmpty( $twitter_metas );
	}

	// ------------------------------------------------------------------
	// populate() — singular post (article type)
	// ------------------------------------------------------------------

	public function test_populate_emits_article_type_on_post(): void {
		$user_id = $this->factory->user->create( [ 'display_name' => 'Article Author' ] );
		$post_id = $this->factory->post->create( [
			'post_author' => $user_id,
			'post_status' => 'publish',
		] );

		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$this->assertMetaExists( $data, 'property', 'og:type', 'article' );
		$this->assertMetaExists( $data, 'property', 'article:author', get_author_posts_url( $user_id ) );
	}

	// ------------------------------------------------------------------
	// populate() — singular page (website type)
	// ------------------------------------------------------------------

	public function test_populate_emits_website_type_on_page(): void {
		$page_id = $this->factory->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
		] );

		$this->go_to( get_permalink( $page_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$this->assertMetaExists( $data, 'property', 'og:type', 'website' );
	}

	// ------------------------------------------------------------------
	// populate() — author archive
	// ------------------------------------------------------------------

	public function test_populate_emits_author_url_on_author_archive(): void {
		$user_id = $this->factory->user->create( [ 'display_name' => 'Archive Author' ] );

		$this->go_to( get_author_posts_url( $user_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$this->assertMetaExists( $data, 'property', 'og:url', get_author_posts_url( $user_id ) );
	}

	// ------------------------------------------------------------------
	// populate() — Pinterest verification
	// ------------------------------------------------------------------

	public function test_populate_emits_pinterest_verification(): void {
		$all = $this->settings->all();
		$all['social']['pinterest_verify'] = 'abc123';
		$this->settings->save_settings( $all );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$this->assertMetaExists( $data, 'name', 'p:domain_verify', 'abc123' );
	}

	// ------------------------------------------------------------------
	// populate() — twitter site handle normalization
	// ------------------------------------------------------------------

	public function test_populate_normalizes_twitter_site_handle(): void {
		$all = $this->settings->all();
		$all['social']['twitter_site'] = 'mysite';
		$this->settings->save_settings( $all );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$this->assertMetaExists( $data, 'name', 'twitter:site', '@mysite' );
	}

	public function test_populate_preserves_at_prefix_in_twitter_site_handle(): void {
		$all = $this->settings->all();
		$all['social']['twitter_site'] = '@mysite';
		$this->settings->save_settings( $all );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$this->assertMetaExists( $data, 'name', 'twitter:site', '@mysite' );
	}

	// ------------------------------------------------------------------
	// populate() — twitter card type based on image
	// ------------------------------------------------------------------

	public function test_twitter_card_summary_without_image(): void {
		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$this->assertMetaExists( $data, 'name', 'twitter:card', 'summary' );
	}

	public function test_twitter_card_large_image_when_configured(): void {
		$all = $this->settings->all();
		$all['social']['twitter_card_type'] = 'summary_large_image';
		$this->settings->save_settings( $all );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		// Without an image, card should still be 'summary' regardless of setting.
		$this->assertMetaExists( $data, 'name', 'twitter:card', 'summary' );
	}

	// ------------------------------------------------------------------
	// populate() — og:description from meta description
	// ------------------------------------------------------------------

	public function test_og_description_from_meta_description(): void {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, MM_META_KEY, wp_json_encode( [ 'description' => 'Custom OG description.' ] ) );

		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();
		// Pre-populate title as the Head Meta module would.
		$data['title'] = 'Post Title';
		$data['meta'][] = [ 'name' => 'description', 'content' => 'Custom OG description.' ];

		$this->social_module->populate( $data, $context, $this->settings );

		$this->assertMetaExists( $data, 'property', 'og:description', 'Custom OG description.' );
	}

	// ------------------------------------------------------------------
	// populate() — fb:app_id
	// ------------------------------------------------------------------

	public function test_populate_emits_fb_app_id(): void {
		$all = $this->settings->all();
		$all['social']['fb_app_id'] = '123456789';
		$this->settings->save_settings( $all );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$this->assertMetaExists( $data, 'property', 'fb:app_id', '123456789' );
	}

	public function test_populate_no_fb_app_id_when_empty(): void {
		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->social_module->populate( $data, $context, $this->settings );

		$fb_metas = array_filter( $data['meta'], fn( $m ) => ( $m['property'] ?? '' ) === 'fb:app_id' );
		$this->assertEmpty( $fb_metas );
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

	private function assertMetaExists( array $data, string $key, string $value, string $expected_content ): void {
		$found = false;
		foreach ( $data['meta'] as $meta ) {
			if ( ( $meta[ $key ] ?? '' ) === $value && ( $meta['content'] ?? '' ) === $expected_content ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, "Expected meta {$key}=\"{$value}\" with content \"{$expected_content}\" not found." );
	}
}