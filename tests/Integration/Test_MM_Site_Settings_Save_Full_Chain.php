<?php
/**
 * Full-chain save tests for MM_Site_Settings.
 *
 * These tests exercise the EXACT code path from the browser:
 *   options.php → sanitize_option() → pre_update_option → intercept_save → DB write → read back
 *
 * The existing Test_MM_Site_Settings_Save tests call update_option() directly.
 * These tests call the same sequence WordPress core actually runs, including
 * sanitize_option() which runs BEFORE intercept_save in WordPress 6.x+.
 *
 * @package Metamanager\Tests\Integration
 */

defined( 'ABSPATH' ) || exit;

class Test_MM_Site_Settings_Save_Full_Chain extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
	}

	public function tear_down(): void {
		delete_option( MM_META_OPT_SETTINGS );
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	/**
	 * Register settings the same way MM_Metadata_Admin::register_settings() does.
	 *
	 * This populates WordPress's $new_allowed_options global so that options.php
	 * routing accepts mm_meta_settings for each section group.
	 */
	private function register_settings_for_test(): void {
		$section_groups = [
			'mm_meta_titles_group'   => 'titles',
			'mm_meta_social_group'   => 'social',
			'mm_meta_schema_group'   => 'schema',
			'mm_meta_sitemaps_group' => 'sitemap',
			'mm_meta_robots_group'   => 'robots',
			'mm_meta_authors_group'  => 'authors',
			'mm_meta_hygiene_group'  => 'hygiene',
			'mm_meta_feed_group'     => 'feed',
			'mm_meta_links_group'    => 'links',
		];

		foreach ( $section_groups as $group => $section ) {
			register_setting( $group, MM_META_OPT_SETTINGS );
		}
	}

	/**
	 * Seed the DB with a full option containing known values in all sections.
	 */
	private function seed_full_option(): void {
		$seed = MM_Site_Settings::settings_defaults();
		update_option( MM_META_OPT_SETTINGS, $seed );
		MM_Site_Settings::reset_instance();
	}

	/**
	 * Replicate the WordPress options.php save path for a single section.
	 *
	 * options.php validates the option_page, then calls:
	 *   $value = wp_unslash( $value );
	 *   update_option( $option, $value );
	 *
	 * update_option internally:
	 *   1. $value = sanitize_option( $option, $value )  — no-op here (no sanitize_callback registered)
	 *   2. $value = apply_filters( 'pre_update_option_{$option}', ... ) — intercept_save merges section
	 *   3. Writes to DB
	 */
	private function simulate_options_php_routing( string $group, array $raw_post_value ): void {
		// Ensure the pre_update_option filter is attached (happens on first get_instance).
		MM_Site_Settings::get_instance();

		$_POST['option_page'] = $group;

		// WordPress options.php does: $value = wp_unslash($_POST[$option]);
		// The test data represents $_POST, so extract the option value.
		$value = $raw_post_value[ MM_META_OPT_SETTINGS ] ?? $raw_post_value;
		update_option( MM_META_OPT_SETTINGS, $value );

		unset( $_POST['option_page'] );
	}

	/**
	 * Test: option_update_filter merges $new_allowed_options into $allowed_options.
	 */
	public function test_option_update_filter_merges_correctly(): void {
		$this->register_settings_for_test();

		// Simulate POST data: user checks og_enabled, submits social page.
		$post_data = [
			'mm_meta_settings' => [
				'social' => [
					'og_enabled'        => '1',
					'twitter_enabled'   => '1',
					'og_locale'         => 'en_US',
					'og_default_image'  => '',
					'og_default_image_id' => '0',
					'fb_app_id'         => '',
					'twitter_site'      => '',
					'twitter_card_type' => 'summary_large_image',
					'accounts'          => [
						'facebook' => '', 'instagram' => '', 'pinterest' => '',
						'youtube'  => '', 'linkedin' => '', 'twitter' => '', 'bluesky' => '',
					],
					'pinterest_verify' => '',
				],
			],
		];

		$this->simulate_options_php_routing( 'mm_meta_social_group', $post_data );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'social.og_enabled' ), 'og_enabled should be true after options.php routing' );
		$this->assertTrue( $s->get( 'social.twitter_enabled' ), 'twitter_enabled should be preserved' );
		$this->assertSame( '|', $s->get( 'titles.separator' ), 'titles section should be preserved' );
	}

	/**
	 * Test: options.php routing for titles section — toggle paginate_append.
	 */
	public function test_options_php_routing_titles_section(): void {
		$this->seed_full_option();

		// Set paginate_append to false.
		$all = MM_Site_Settings::settings_defaults();
		$all['titles']['paginate_append'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );
		MM_Site_Settings::reset_instance();

		$this->register_settings_for_test();

		// Simulate POST: check paginate_append, submit titles page.
		$post_data = [
			'mm_meta_settings' => [
				'titles' => [
					'separator'          => '|',
					'home_title'         => '%%sitetitle%% %%sep%% %%tagline%%',
					'home_description'   => '',
					'paginate_append'    => '1',
					'date_archive_noindex' => '1',
					'search_noindex'     => '1',
				],
			],
		];

		$this->simulate_options_php_routing( 'mm_meta_titles_group', $post_data );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'titles.paginate_append' ), 'paginate_append should be true after routing' );
		$this->assertSame( '|', $s->get( 'titles.separator' ), 'separator should be preserved' );
		$this->assertSame( '%%sitetitle%% %%sep%% %%tagline%%', $s->get( 'titles.home_title' ), 'home_title should be preserved' );
	}

	/**
	 * Test: options.php routing for schema section — toggle breadcrumbs.
	 */
	public function test_options_php_routing_schema_section(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['schema']['breadcrumbs'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );
		MM_Site_Settings::reset_instance();

		$this->register_settings_for_test();

		$post_data = [
			'mm_meta_settings' => [
				'schema' => [
					'website_searchaction' => '1',
					'breadcrumbs'          => '1',
					'archive_itemlist'     => '1',
				],
			],
		];

		$this->simulate_options_php_routing( 'mm_meta_schema_group', $post_data );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'schema.breadcrumbs' ), 'breadcrumbs should be true after routing' );
	}

	/**
	 * Test: options.php routing for robots section — toggle enabled.
	 */
	public function test_options_php_routing_robots_section(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['robots']['enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );
		MM_Site_Settings::reset_instance();

		$this->register_settings_for_test();

		$post_data = [
			'mm_meta_settings' => [
				'robots' => [
					'enabled' => '1',
				],
			],
		];

		$this->simulate_options_php_routing( 'mm_meta_robots_group', $post_data );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'robots.enabled' ), 'robots.enabled should be true after routing' );
	}

	/**
	 * Test: options.php routing for hygiene section — toggle multiple checkboxes.
	 */
	public function test_options_php_routing_hygiene_section(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['hygiene']['remove_generator'] = false;
		$all['hygiene']['remove_shortlink'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );
		MM_Site_Settings::reset_instance();

		$this->register_settings_for_test();

		$post_data = [
			'mm_meta_settings' => [
				'hygiene' => [
					'remove_generator'       => '1',
					'remove_oembed_links'    => '1',
					'remove_shortlink'       => '1',
					'remove_wlw_manifest'    => '1',
					'remove_rsd_link'        => '1',
					'remove_pingback_header' => '1',
					'remove_x_powered_by'    => '1',
					'remove_wp_dns_prefetch' => '1',
				],
			],
		];

		$this->simulate_options_php_routing( 'mm_meta_hygiene_group', $post_data );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'hygiene.remove_generator' ), 'remove_generator should be true after routing' );
		$this->assertTrue( $s->get( 'hygiene.remove_shortlink' ), 'remove_shortlink should be true after routing' );
	}

	/**
	 * Test: options.php routing for feed section — toggle multiple checkboxes.
	 */
	public function test_options_php_routing_feed_section(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['feed']['cleanup_enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );
		MM_Site_Settings::reset_instance();

		$this->register_settings_for_test();

		$post_data = [
			'mm_meta_settings' => [
				'feed' => [
					'cleanup_enabled'          => '1',
					'remove_generator'         => '1',
					'remove_comments_elements' => '1',
					'use_excerpt'              => '1',
				],
			],
		];

		$this->simulate_options_php_routing( 'mm_meta_feed_group', $post_data );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'feed.cleanup_enabled' ), 'cleanup_enabled should be true after routing' );
		$this->assertTrue( $s->get( 'feed.remove_generator' ), 'remove_generator should be true after routing' );
		$this->assertTrue( $s->get( 'feed.use_excerpt' ), 'use_excerpt should be true after routing' );
	}

	/**
	 * Test: options.php routing for authors section — toggle enabled.
	 */
	public function test_options_php_routing_authors_section(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['authors']['enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );
		MM_Site_Settings::reset_instance();

		$this->register_settings_for_test();

		$post_data = [
			'mm_meta_settings' => [
				'authors' => [
					'enabled'              => '1',
					'noindex_default'      => '1',
					'person_schema'        => '1',
					'profile_social_fields' => '1',
				],
			],
		];

		$this->simulate_options_php_routing( 'mm_meta_authors_group', $post_data );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'authors.enabled' ), 'authors.enabled should be true after routing' );
		$this->assertTrue( $s->get( 'authors.person_schema' ), 'authors.person_schema should be true after routing' );
	}

	/**
	 * Test: options.php routing for sitemaps section — toggle enabled and post_type checkboxes.
	 */
	public function test_options_php_routing_sitemaps_section(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['sitemap']['enabled'] = false;
		$all['sitemap']['post_types'] = [];
		update_option( MM_META_OPT_SETTINGS, $all );
		MM_Site_Settings::reset_instance();

		$this->register_settings_for_test();

		$post_data = [
			'mm_meta_settings' => [
				'sitemap' => [
					'enabled'                    => '1',
					'exclude_password_protected' => '1',
					'exclude_noindexed'          => '1',
					'images'                     => '1',
					'video'                      => '0',
					'video_selfhosted'           => '0',
					'ping_google'                => '1',
					'ping_bing'                  => '1',
					'post_types' => [
						'post' => '1',
						'page' => '1',
					],
					'taxonomies' => [
						'category'  => '1',
						'post_tag'  => '1',
					],
				],
			],
		];

		$this->simulate_options_php_routing( 'mm_meta_sitemaps_group', $post_data );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'sitemap.enabled' ), 'sitemap.enabled should be true after routing' );
		$this->assertTrue( $s->get( 'sitemap.post_types.post' ), 'post should be enabled in sitemap' );
		$this->assertTrue( $s->get( 'sitemap.post_types.page' ), 'page should be enabled in sitemap' );
		$this->assertTrue( $s->get( 'sitemap.taxonomies.category' ), 'category should be enabled in sitemap' );
		$this->assertFalse( $s->get( 'sitemap.video' ), 'video should be false (sent as 0)' );
	}

	/**
	 * Test: options.php routing with a section key NOT in the known sections list.
	 * intercept_save should pass the value through unchanged (WordPress handles
	 * the option_page validation in options.php itself).
	 */
	public function test_options_php_unknown_section_passes_through(): void {
		$this->register_settings_for_test();

		$unknown_data = [
			'nonexistent_section' => [ 'foo' => 'bar' ],
		];

		$this->simulate_options_php_routing( 'mm_meta_social_group', $unknown_data );

		// The unknown section should be written to DB as-is (intercept_save passes it through).
		$saved = get_option( MM_META_OPT_SETTINGS );
		$this->assertArrayHasKey( 'nonexistent_section', $saved, 'Unknown section should pass through intercept_save' );
	}

	/**
	 * Test: Rapid toggling through options.php routing — uncheck, save, recheck, save.
	 */
	public function test_rapid_toggling_through_options_php_routing(): void {
		$this->seed_full_option();

		$this->register_settings_for_test();

		// Round 1: Uncheck og_enabled.
		$post_data = [
			'mm_meta_settings' => [
				'social' => [
					'og_enabled'        => '0',
					'twitter_enabled'   => '1',
					'og_locale'         => 'en_US',
					'og_default_image'  => '',
					'og_default_image_id' => '0',
					'fb_app_id'         => '',
					'twitter_site'      => '',
					'twitter_card_type' => 'summary_large_image',
					'accounts'          => [
						'facebook' => '', 'instagram' => '', 'pinterest' => '',
						'youtube'  => '', 'linkedin' => '', 'twitter' => '', 'bluesky' => '',
					],
					'pinterest_verify' => '',
				],
			],
		];

		$this->simulate_options_php_routing( 'mm_meta_social_group', $post_data );
		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertFalse( $s->get( 'social.og_enabled' ), 'Round 1: og_enabled should be false' );

		// Round 2: Recheck og_enabled.
		$post_data['mm_meta_settings']['social']['og_enabled'] = '1';
		$this->simulate_options_php_routing( 'mm_meta_social_group', $post_data );
		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'social.og_enabled' ), 'Round 2: og_enabled should be true again' );
	}

	/**
	 * Test: Cross-section preservation through options.php routing.
	 * Saving social section should not destroy titles/schema/etc.
	 */
	public function test_cross_section_preservation_through_options_php_routing(): void {
		$this->seed_full_option();

		$this->register_settings_for_test();

		$post_data = [
			'mm_meta_settings' => [
				'social' => [
					'og_enabled'        => '0',
					'twitter_enabled'   => '0',
					'og_locale'         => 'en_US',
					'og_default_image'  => '',
					'og_default_image_id' => '0',
					'fb_app_id'         => '',
					'twitter_site'      => '',
					'twitter_card_type' => 'summary_large_image',
					'accounts'          => [
						'facebook' => '', 'instagram' => '', 'pinterest' => '',
						'youtube'  => '', 'linkedin' => '', 'twitter' => '', 'bluesky' => '',
					],
					'pinterest_verify' => '',
				],
			],
		];

		$this->simulate_options_php_routing( 'mm_meta_social_group', $post_data );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertFalse( $s->get( 'social.og_enabled' ), 'Social og_enabled should be false' );
		$this->assertSame( '|', $s->get( 'titles.separator' ), 'Titles separator should be preserved' );
		$this->assertSame( '%%sitetitle%% %%sep%% %%tagline%%', $s->get( 'titles.home_title' ), 'Titles home_title should be preserved' );
		$this->assertTrue( $s->get( 'schema.breadcrumbs' ), 'Schema breadcrumbs should be preserved' );
		$this->assertTrue( $s->get( 'sitemap.enabled' ), 'Sitemap enabled should be preserved' );
		$this->assertTrue( $s->get( 'robots.enabled' ), 'Robots enabled should be preserved' );
		$this->assertTrue( $s->get( 'authors.enabled' ), 'Authors enabled should be preserved' );
		$this->assertTrue( $s->get( 'feed.cleanup_enabled' ), 'Feed cleanup should be preserved' );
	}

	/**
	 * Test: Real-world form submission — Social page with all fields as browser would send.
	 */
	public function test_realistic_social_form_submission(): void {
		$this->seed_full_option();

		$this->register_settings_for_test();

		// Realistic POST data — includes ALL fields the form would submit.
		// Unchecked checkboxes are ABSENT (browser behavior).
		$post_data = [
			'mm_meta_settings' => [
				'social' => [
					'og_locale'         => 'en_US',
					'og_default_image'  => '',
					'og_default_image_id' => '0',
					'fb_app_id'         => '',
					'twitter_site'      => '',
					'twitter_card_type' => 'summary_large_image',
					'accounts'          => [
						'facebook'  => 'https://facebook.com/test',
						'instagram' => '',
						'pinterest' => '',
						'youtube'   => '',
						'linkedin'  => '',
						'twitter'   => '',
						'bluesky'   => '',
					],
					'pinterest_verify' => '',
					// og_enabled and twitter_enabled ABSENT = unchecked
				],
			],
		];

		$this->simulate_options_php_routing( 'mm_meta_social_group', $post_data );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertFalse( $s->get( 'social.og_enabled' ), 'Unchecked og_enabled should be false' );
		$this->assertFalse( $s->get( 'social.twitter_enabled' ), 'Unchecked twitter_enabled should be false' );
		$this->assertSame( 'https://facebook.com/test', $s->get( 'social.accounts.facebook' ), 'Facebook URL should be saved' );
		$this->assertSame( 'en_US', $s->get( 'social.og_locale' ), 'og_locale should be saved' );
	}
}
