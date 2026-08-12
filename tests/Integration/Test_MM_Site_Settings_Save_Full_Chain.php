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
	 * Replicate the EXACT WordPress options.php save path.
	 *
	 * wp-admin/options.php line 343-345:
	 *   $value = wp_unslash( $value );
	 *   update_option( $option, $value );
	 *
	 * update_option() then internally:
	 *   1. $value     = sanitize_option( $option, $value );
	 *   2. $old_value = get_option( $option );
	 *   3. $value     = apply_filters( 'pre_update_option_{$option}', $value, $old_value, $option );
	 *   4. if ( $value === $old_value || maybe_serialize($value) === maybe_serialize($old_value) ) return false;
	 *   5. $wpdb->update(...)
	 */
	private function simulate_options_php_save( mixed $raw_post_value ): void {
		update_option( MM_META_OPT_SETTINGS, $raw_post_value );
	}

	/**
	 * Seed the DB with a full option containing known values in all sections.
	 */
	private function seed_full_option(): void {
		$seed = MM_Site_Settings::settings_defaults();
		update_option( MM_META_OPT_SETTINGS, $seed );
		MM_Site_Settings::reset_instance();
	}

	// ======================================================================
	// SECTION: Debug — trace sanitize_option behavior
	// ======================================================================

	public function test_sanitize_option_passes_array_through(): void {
		$input = [
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
		];

		$result = sanitize_option( MM_META_OPT_SETTINGS, $input );

		$this->assertIsArray( $result, 'sanitize_option should return an array' );
		$this->assertArrayHasKey( 'social', $result, 'sanitize_option should preserve the social key' );
		$this->assertSame( '1', $result['social']['og_enabled'], 'sanitize_option should not strip the og_enabled value' );
		$this->assertSame( $input, $result, 'sanitize_option should pass the array through unchanged' );
	}

	public function test_intercept_save_returns_full_option(): void {
		$this->seed_full_option();

		// Change og_enabled from true (default) to false to make it different.
		$all = MM_Site_Settings::settings_defaults();
		$all['social']['og_enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );
		MM_Site_Settings::reset_instance();

		$raw_post = [
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
		];

		// Simulate WordPress exact order: sanitize_option then filter.
		$value     = sanitize_option( MM_META_OPT_SETTINGS, $raw_post );
		$old_value = get_option( MM_META_OPT_SETTINGS );

		// Verify old_value is what we seeded.
		$this->assertFalse( $old_value['social']['og_enabled'], 'Pre-condition: og_enabled should be false in DB' );

		// Verify sanitize_option didn't destroy the array.
		$this->assertSame( '1', $value['social']['og_enabled'], 'sanitize_option should pass og_enabled through' );

		$result = apply_filters( 'pre_update_option_' . MM_META_OPT_SETTINGS, $value, $old_value, MM_META_OPT_SETTINGS );

		$this->assertTrue( $result['social']['og_enabled'], 'intercept_save should convert og_enabled to true' );
		$this->assertArrayHasKey( 'titles', $result, 'Result should have titles section' );
		$this->assertSame( '|', $result['titles']['separator'], 'titles.separator should be preserved' );

		// Compare with old_value.
		$this->assertNotSame( $result, $old_value, 'Merged result should differ from old value (social.og_enabled changed)' );
	}

	// ======================================================================
	// SECTION: Basic checkbox toggle through the FULL WordPress chain
	// ======================================================================

	/**
	 * Test: Check a checkbox (enable OG), save through the full chain, read back.
	 * This is the exact flow when a user checks a box and clicks Save.
	 */
	public function test_check_og_enabled_full_chain(): void {
		$this->seed_full_option();

		// Start with og_enabled = false.
		$all = MM_Site_Settings::settings_defaults();
		$all['social']['og_enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );
		MM_Site_Settings::reset_instance();

		$s = MM_Site_Settings::get_instance();
		$this->assertFalse( $s->get( 'social.og_enabled' ), 'Pre-condition: og_enabled should be false' );

		// Simulate form POST: user checks og_enabled checkbox.
		$raw_post = [
			'social' => [
				'og_enabled'        => '1',
				'twitter_enabled'   => '1',
				'og_default_image'  => '',
				'og_default_image_id' => '0',
				'og_locale'         => 'en_US',
				'fb_app_id'         => '',
				'twitter_site'      => '',
				'twitter_card_type' => 'summary_large_image',
				'accounts'          => [
					'facebook'  => '',
					'instagram' => '',
					'pinterest' => '',
					'youtube'   => '',
					'linkedin'  => '',
					'twitter'   => '',
					'bluesky'   => '',
				],
				'pinterest_verify'  => '',
			],
		];

		$this->simulate_options_php_save( $raw_post );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'social.og_enabled' ), 'og_enabled should be true after checking and saving' );
		$this->assertTrue( $s->get( 'social.twitter_enabled' ), 'twitter_enabled should still be true' );
	}

	/**
	 * Test: Uncheck a checkbox (disable OG), save through the full chain, read back.
	 */
	public function test_uncheck_og_enabled_full_chain(): void {
		$this->seed_full_option();

		// Start with og_enabled = true (default).
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'social.og_enabled' ), 'Pre-condition: og_enabled should be true' );

		// Simulate form POST: user unchecks og_enabled (absent from POST).
		$raw_post = [
			'social' => [
				// og_enabled deliberately absent (unchecked checkbox).
				'twitter_enabled'   => '1',
				'og_default_image'  => '',
				'og_default_image_id' => '0',
				'og_locale'         => 'en_US',
				'fb_app_id'         => '',
				'twitter_site'      => '',
				'twitter_card_type' => 'summary_large_image',
				'accounts'          => [
					'facebook'  => '',
					'instagram' => '',
					'pinterest' => '',
					'youtube'   => '',
					'linkedin'  => '',
					'twitter'   => '',
					'bluesky'   => '',
				],
				'pinterest_verify'  => '',
			],
		];

		$this->simulate_options_php_save( $raw_post );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertFalse( $s->get( 'social.og_enabled' ), 'og_enabled should be false after unchecking and saving' );
	}

	/**
	 * Test: Toggle OG on → off → on through the full chain.
	 */
	public function test_toggle_og_on_off_on_full_chain(): void {
		$this->seed_full_option();

		// Step 1: Start with false.
		$all = MM_Site_Settings::settings_defaults();
		$all['social']['og_enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );

		// Step 2: Check it (enable).
		$this->simulate_options_php_save( [
			'social' => [
				'og_enabled'        => '1',
				'twitter_enabled'   => '1',
				'og_default_image'  => '',
				'og_default_image_id' => '0',
				'og_locale'         => 'en_US',
				'fb_app_id'         => '',
				'twitter_site'      => '',
				'twitter_card_type' => 'summary_large_image',
				'accounts'          => [
					'facebook'  => '', 'instagram' => '', 'pinterest' => '',
					'youtube'   => '', 'linkedin'  => '', 'twitter'   => '', 'bluesky' => '',
				],
				'pinterest_verify'  => '',
			],
		] );

		MM_Site_Settings::reset_instance();
		$this->assertTrue( MM_Site_Settings::get_instance()->get( 'social.og_enabled' ), 'Step 2: should be true' );

		// Step 3: Uncheck it (disable).
		$this->simulate_options_php_save( [
			'social' => [
				// og_enabled absent (unchecked).
				'twitter_enabled'   => '1',
				'og_default_image'  => '',
				'og_default_image_id' => '0',
				'og_locale'         => 'en_US',
				'fb_app_id'         => '',
				'twitter_site'      => '',
				'twitter_card_type' => 'summary_large_image',
				'accounts'          => [
					'facebook'  => '', 'instagram' => '', 'pinterest' => '',
					'youtube'   => '', 'linkedin'  => '', 'twitter'   => '', 'bluesky' => '',
				],
				'pinterest_verify'  => '',
			],
		] );

		MM_Site_Settings::reset_instance();
		$this->assertFalse( MM_Site_Settings::get_instance()->get( 'social.og_enabled' ), 'Step 3: should be false' );

		// Step 4: Check it again (enable).
		$this->simulate_options_php_save( [
			'social' => [
				'og_enabled'        => '1',
				'twitter_enabled'   => '1',
				'og_default_image'  => '',
				'og_default_image_id' => '0',
				'og_locale'         => 'en_US',
				'fb_app_id'         => '',
				'twitter_site'      => '',
				'twitter_card_type' => 'summary_large_image',
				'accounts'          => [
					'facebook'  => '', 'instagram' => '', 'pinterest' => '',
					'youtube'   => '', 'linkedin'  => '', 'twitter'   => '', 'bluesky' => '',
				],
				'pinterest_verify'  => '',
			],
		] );

		MM_Site_Settings::reset_instance();
		$this->assertTrue( MM_Site_Settings::get_instance()->get( 'social.og_enabled' ), 'Step 4: should be true again' );
	}

	// ======================================================================
	// SECTION: Every section — checkbox checked saves correctly
	// ======================================================================

	/**
	 * Test: Sitemap enabled checkbox — check → save → persists.
	 */
	public function test_sitemap_enable_check_persists(): void {
		$this->seed_full_option();

		// Disable sitemap first.
		$all = MM_Site_Settings::settings_defaults();
		$all['sitemap']['enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );

		// Check it.
		$this->simulate_options_php_save( [
			'sitemap' => [
				'enabled'                    => '1',
				'post_types'                 => [ 'post' => '1', 'page' => '1' ],
				'taxonomies'                 => [ 'category' => '1' ],
				'images'                     => '1',
				'video'                      => '1',
				'video_selfhosted'           => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed'          => '1',
				'records_per_file'           => '1000',
				'ping_google'                => '1',
				'ping_bing'                  => '1',
				'html_sitemap'               => [
					'enabled'            => '1',
					'post_types'         => [ 'page', 'post' ],
					'taxonomies'         => [],
					'columns'            => '1',
					'order_by'           => 'menu_order',
					'exclude_ids'        => [],
					'flat_limit'         => '500',
					'hierarchical_limit' => '500',
				],
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'sitemap.enabled' ), 'sitemap.enabled should be true after checking' );
	}

	/**
	 * Test: Schema breadcrumbs checkbox — check → save → persists.
	 */
	public function test_schema_breadcrumbs_check_persists(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['schema']['breadcrumbs'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );

		$this->simulate_options_php_save( [
			'schema' => [
				'knowledge_entity'     => 'LocalBusiness',
				'website_searchaction' => '1',
				'breadcrumbs'          => '1',
				'archive_itemlist'     => '1',
				'post_type_types'      => [
					'post'    => 'BlogPosting',
					'page'    => 'WebPage',
					'product' => 'Product',
					'course'  => 'Course',
				],
				'custom_json_ld'       => '',
			],
		] );

		MM_Site_Settings::reset_instance();
		$this->assertTrue( MM_Site_Settings::get_instance()->get( 'schema.breadcrumbs' ), 'breadcrumbs should be true after checking' );
	}

	/**
	 * Test: Robots enabled checkbox — check → save → persists.
	 */
	public function test_robots_enabled_check_persists(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['robots']['enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );

		$this->simulate_options_php_save( [
			'robots' => [
				'enabled'     => '1',
				'disallow'    => [ '/wp-admin/' ],
				'allow'       => [ '/wp-admin/admin-ajax.php' ],
				'crawl_delay' => '',
				'custom'      => '',
			],
		] );

		MM_Site_Settings::reset_instance();
		$this->assertTrue( MM_Site_Settings::get_instance()->get( 'robots.enabled' ), 'robots.enabled should be true after checking' );
	}

	/**
	 * Test: Authors enabled checkbox — check → save → persists.
	 */
	public function test_authors_enabled_check_persists(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['authors']['enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );

		$this->simulate_options_php_save( [
			'authors' => [
				'enabled'              => '1',
				'noindex_default'      => '',
				'title_template'       => 'Articles by %%author_name%% %%sep%% %%sitetitle%%',
				'description_template' => '%%author_bio%%',
				'person_schema'        => '1',
				'profile_social_fields'=> '1',
			],
		] );

		MM_Site_Settings::reset_instance();
		$this->assertTrue( MM_Site_Settings::get_instance()->get( 'authors.enabled' ), 'authors.enabled should be true after checking' );
	}

	/**
	 * Test: Hygiene remove_generator checkbox — check → save → persists.
	 */
	public function test_hygiene_remove_generator_check_persists(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['hygiene']['remove_generator'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );

		$this->simulate_options_php_save( [
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
		] );

		MM_Site_Settings::reset_instance();
		$this->assertTrue( MM_Site_Settings::get_instance()->get( 'hygiene.remove_generator' ), 'remove_generator should be true after checking' );
	}

	/**
	 * Test: Feed cleanup_enabled checkbox — check → save → persists.
	 */
	public function test_feed_cleanup_enabled_check_persists(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['feed']['cleanup_enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );

		$this->simulate_options_php_save( [
			'feed' => [
				'cleanup_enabled'          => '1',
				'remove_generator'         => '1',
				'remove_comments_elements' => '1',
				'use_excerpt'              => '',
				'feed_title'               => '',
				'feed_copyright'           => '',
			],
		] );

		MM_Site_Settings::reset_instance();
		$this->assertTrue( MM_Site_Settings::get_instance()->get( 'feed.cleanup_enabled' ), 'cleanup_enabled should be true after checking' );
	}

	/**
	 * Test: Links enabled checkbox — check → save → persists.
	 */
	public function test_links_enabled_check_persists(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['links']['enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );

		$this->simulate_options_php_save( [
			'links' => [
				'enabled'        => '1',
				'cron_frequency' => 'twicedaily',
				'timeout'        => '10',
				'batch_size'     => '50',
				'check_external' => '1',
				'ignore_domains' => [],
				'email_alerts'   => '',
				'email_address'  => '',
			],
		] );

		MM_Site_Settings::reset_instance();
		$this->assertTrue( MM_Site_Settings::get_instance()->get( 'links.enabled' ), 'links.enabled should be true after checking' );
	}

	/**
	 * Test: Discovery llms_txt_enabled checkbox — check → save → persists.
	 */
	public function test_discovery_llms_check_persists(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['discovery']['llms_txt_enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );

		$this->simulate_options_php_save( [
			'discovery' => [
				'llms_txt_enabled' => '1',
				'mcp_server'       => '1',
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'discovery.llms_txt_enabled' ), 'llms_txt_enabled should be true after checking' );
		$this->assertTrue( $s->get( 'discovery.mcp_server' ), 'mcp_server should be true after checking' );
	}

	// ======================================================================
	// SECTION: Checkbox states — unchecked persists correctly
	// ======================================================================

	/**
	 * Test: Uncheck sitemap enabled → save → stays unchecked.
	 */
	public function test_sitemap_unchecked_persists(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'sitemap' => [
				// enabled absent (unchecked).
				'post_types'                 => [ 'post' => '1', 'page' => '1' ],
				'taxonomies'                 => [ 'category' => '1' ],
				'images'                     => '1',
				'video'                      => '1',
				'video_selfhosted'           => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed'          => '1',
				'records_per_file'           => '1000',
				'ping_google'                => '1',
				'ping_bing'                  => '1',
				'html_sitemap'               => [
					'enabled'            => '1',
					'post_types'         => [ 'page', 'post' ],
					'taxonomies'         => [],
					'columns'            => '1',
					'order_by'           => 'menu_order',
					'exclude_ids'        => [],
					'flat_limit'         => '500',
					'hierarchical_limit' => '500',
				],
			],
		] );

		MM_Site_Settings::reset_instance();
		$this->assertFalse( MM_Site_Settings::get_instance()->get( 'sitemap.enabled' ), 'sitemap.enabled should be false when unchecked' );
	}

	/**
	 * Test: Uncheck authors enabled → save → stays unchecked.
	 */
	public function test_authors_unchecked_persists(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'authors' => [
				// enabled absent (unchecked).
				'noindex_default'       => '',
				'title_template'        => 'Articles by %%author_name%% %%sep%% %%sitetitle%%',
				'description_template'  => '%%author_bio%%',
				'person_schema'         => '1',
				'profile_social_fields' => '1',
			],
		] );

		MM_Site_Settings::reset_instance();
		$this->assertFalse( MM_Site_Settings::get_instance()->get( 'authors.enabled' ), 'authors.enabled should be false when unchecked' );
	}

	// ======================================================================
	// SECTION: Cross-section preservation through full chain
	// ======================================================================

	/**
	 * Test: Save social section — all other sections preserved through full chain.
	 */
	public function test_social_save_preserves_all_other_sections_full_chain(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'social' => [
				'og_enabled'        => '1',
				'twitter_enabled'   => '1',
				'og_default_image'  => '',
				'og_default_image_id' => '0',
				'og_locale'         => 'en_GB',
				'fb_app_id'         => '',
				'twitter_site'      => '@test',
				'twitter_card_type' => 'summary',
				'accounts'          => [
					'facebook'  => '', 'instagram' => '', 'pinterest' => '',
					'youtube'   => '', 'linkedin'  => '', 'twitter'   => '', 'bluesky' => '',
				],
				'pinterest_verify'  => '',
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();

		$this->assertSame( 'en_GB', $s->get( 'social.og_locale' ) );
		$this->assertSame( '@test', $s->get( 'social.twitter_site' ) );
		$this->assertSame( 'summary', $s->get( 'social.twitter_card_type' ) );

		// All other sections preserved.
		$this->assertSame( '|', $s->get( 'titles.separator' ) );
		$this->assertSame( 'LocalBusiness', $s->get( 'schema.knowledge_entity' ) );
		$this->assertTrue( $s->get( 'sitemap.enabled' ) );
		$this->assertTrue( $s->get( 'authors.enabled' ) );
		$this->assertTrue( $s->get( 'robots.enabled' ) );
		$this->assertTrue( $s->get( 'hygiene.remove_generator' ) );
		$this->assertTrue( $s->get( 'feed.cleanup_enabled' ) );
		$this->assertTrue( $s->get( 'links.enabled' ) );
		$this->assertTrue( $s->get( 'discovery.llms_txt_enabled' ) );
	}

	// ======================================================================
	// SECTION: Boolean type casting through full chain
	// ======================================================================

	/**
	 * Test: Checkbox value '1' (string from POST) becomes true (bool).
	 */
	public function test_checkbox_string_one_becomes_bool_true_full_chain(): void {
		$this->seed_full_option();

		$all = MM_Site_Settings::settings_defaults();
		$all['authors']['enabled'] = false;
		update_option( MM_META_OPT_SETTINGS, $all );

		$this->simulate_options_php_save( [
			'authors' => [
				'enabled'              => '1',
				'noindex_default'      => '',
				'title_template'       => 'Test',
				'description_template' => 'Test',
				'person_schema'        => '1',
				'profile_social_fields'=> '1',
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertIsBool( $s->get( 'authors.enabled' ) );
		$this->assertTrue( $s->get( 'authors.enabled' ) );
	}

	/**
	 * Test: Checkbox absent from POST becomes false (bool).
	 */
	public function test_checkbox_absent_becomes_bool_false_full_chain(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'authors' => [
				// All checkboxes absent (unchecked).
				'title_template'       => 'Test',
				'description_template' => 'Test',
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertIsBool( $s->get( 'authors.enabled' ) );
		$this->assertFalse( $s->get( 'authors.enabled' ) );
		$this->assertIsBool( $s->get( 'authors.noindex_default' ) );
		$this->assertFalse( $s->get( 'authors.noindex_default' ) );
		$this->assertIsBool( $s->get( 'authors.person_schema' ) );
		$this->assertFalse( $s->get( 'authors.person_schema' ) );
	}

	// ======================================================================
	// SECTION: Nested/dynamic fields through full chain
	// ======================================================================

	/**
	 * Test: Sitemap post_types checkboxes (nested assoc array).
	 */
	public function test_sitemap_post_types_nested_checkbox_full_chain(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'sitemap' => [
				'enabled'                    => '1',
				'post_types'                 => [ 'post' => '1', 'page' => '1' ],
				'taxonomies'                 => [ 'category' => '1' ],
				'images'                     => '1',
				'video'                      => '1',
				'video_selfhosted'           => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed'          => '1',
				'records_per_file'           => '1000',
				'ping_google'                => '1',
				'ping_bing'                  => '1',
				'html_sitemap'               => [
					'enabled'            => '1',
					'post_types'         => [ 'page', 'post' ],
					'taxonomies'         => [],
					'columns'            => '1',
					'order_by'           => 'menu_order',
					'exclude_ids'        => [],
					'flat_limit'         => '500',
					'hierarchical_limit' => '500',
				],
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'sitemap.post_types.post' ), 'post checkbox should be true' );
		$this->assertTrue( $s->get( 'sitemap.post_types.page' ), 'page checkbox should be true' );
		$this->assertTrue( $s->get( 'sitemap.taxonomies.category' ), 'category checkbox should be true' );
	}

	/**
	 * Test: Custom post type keys preserved through full chain.
	 * This tests the unknown-keys preservation fix.
	 */
	public function test_sitemap_custom_post_type_preserved_full_chain(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'sitemap' => [
				'enabled'                    => '1',
				'post_types'                 => [
					'post'    => '1',
					'page'    => '1',
					'product' => '1',  // Custom post type — not in defaults.
				],
				'taxonomies'                 => [ 'category' => '1' ],
				'images'                     => '1',
				'video'                      => '1',
				'video_selfhosted'           => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed'          => '1',
				'records_per_file'           => '1000',
				'ping_google'                => '1',
				'ping_bing'                  => '1',
				'html_sitemap'               => [
					'enabled'            => '1',
					'post_types'         => [ 'page', 'post' ],
					'taxonomies'         => [],
					'columns'            => '1',
					'order_by'           => 'menu_order',
					'exclude_ids'        => [],
					'flat_limit'         => '500',
					'hierarchical_limit' => '500',
				],
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'sitemap.post_types.post' ) );
		$this->assertTrue( $s->get( 'sitemap.post_types.page' ) );
		$this->assertSame( '1', $s->get( 'sitemap.post_types.product' ), 'Custom post type product should be preserved (string from POST, no type inference for unknown keys)' );
	}

	/**
	 * Test: Titles custom post type preserved through full chain.
	 */
	public function test_titles_custom_post_type_preserved_full_chain(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'titles' => [
				'separator'                  => '—',
				'home_title'                 => 'Custom Home',
				'home_description'           => '',
				'author_archive_title'       => 'Articles by %%author_name%% %%sep%% %%sitetitle%%',
				'author_archive_description' => '%%author_bio%%',
				'search_title'               => 'Search Results for %%search_query%% %%sep%% %%sitetitle%%',
				'404_title'                  => 'Page Not Found %%sep%% %%sitetitle%%',
				'paginate_append'            => '1',
				'date_archive_noindex'       => '1',
				'search_noindex'             => '1',
				'post_types' => [
					'post' => [
						'single_title'       => '%%post_title%% %%sep%% %%sitetitle%%',
						'archive_title'      => 'Blog %%sep%% %%sitetitle%%',
						'description_source' => 'excerpt',
						'noindex'            => '',
						'noindex_archive'    => '',
					],
					'page' => [
						'single_title'       => '%%post_title%% %%sep%% %%sitetitle%%',
						'description_source' => 'excerpt',
						'noindex'            => '',
					],
					'product' => [
						'single_title'       => '%%post_title%% %%sep%% %%sitetitle%%',
						'archive_title'      => 'Products %%sep%% %%sitetitle%%',
						'description_source' => 'excerpt',
						'noindex'            => '',
					],
				],
				'taxonomies' => [
					'category' => [
						'archive_title'      => '%%term_title%% %%sep%% %%sitetitle%%',
						'description_source' => 'term_description',
						'noindex'            => '',
					],
					'post_tag' => [
						'archive_title'      => '%%term_title%% %%sep%% %%sitetitle%%',
						'description_source' => 'term_description',
						'noindex'            => '',
					],
					'product_cat' => [
						'archive_title'      => '%%term_title%% %%sep%% %%sitetitle%%',
						'description_source' => 'term_description',
						'noindex'            => '',
					],
				],
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();

		$this->assertSame( '—', $s->get( 'titles.separator' ) );
		$this->assertSame( 'Custom Home', $s->get( 'titles.home_title' ) );
		$this->assertTrue( $s->get( 'titles.paginate_append' ) );
		$this->assertSame( '%%post_title%% %%sep%% %%sitetitle%%', $s->get( 'titles.post_types.product.single_title' ), 'Custom post type product should be preserved' );
		$this->assertSame( '%%term_title%% %%sep%% %%sitetitle%%', $s->get( 'titles.taxonomies.product_cat.archive_title' ), 'Custom taxonomy product_cat should be preserved' );
	}

	// ======================================================================
	// SECTION: Integer fields through full chain
	// ======================================================================

	/**
	 * Test: records_per_file string '500' becomes int 500.
	 */
	public function test_records_per_file_int_cast_full_chain(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'sitemap' => [
				'enabled'                    => '1',
				'post_types'                 => [ 'post' => '1', 'page' => '1' ],
				'taxonomies'                 => [ 'category' => '1' ],
				'images'                     => '1',
				'video'                      => '1',
				'video_selfhosted'           => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed'          => '1',
				'records_per_file'           => '500',
				'ping_google'                => '1',
				'ping_bing'                  => '1',
				'html_sitemap'               => [
					'enabled'            => '1',
					'post_types'         => [ 'page', 'post' ],
					'taxonomies'         => [],
					'columns'            => '1',
					'order_by'           => 'menu_order',
					'exclude_ids'        => [],
					'flat_limit'         => '500',
					'hierarchical_limit' => '500',
				],
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertIsInt( $s->get( 'sitemap.records_per_file' ) );
		$this->assertSame( 500, $s->get( 'sitemap.records_per_file' ) );
	}

	// ======================================================================
	// SECTION: Rapid toggling through full chain
	// ======================================================================

	/**
	 * Test: Toggle sitemap enabled 5 times through full chain.
	 */
	public function test_rapid_toggle_sitemap_full_chain(): void {
		$this->seed_full_option();

		$base_data = [
			'post_types'                 => [ 'post' => '1', 'page' => '1' ],
			'taxonomies'                 => [ 'category' => '1' ],
			'images'                     => '1',
			'video'                      => '1',
			'video_selfhosted'           => '1',
			'exclude_password_protected' => '1',
			'exclude_noindexed'          => '1',
			'records_per_file'           => '1000',
			'ping_google'                => '1',
			'ping_bing'                  => '1',
			'html_sitemap'               => [
				'enabled'            => '1',
				'post_types'         => [ 'page', 'post' ],
				'taxonomies'         => [],
				'columns'            => '1',
				'order_by'           => 'menu_order',
				'exclude_ids'        => [],
				'flat_limit'         => '500',
				'hierarchical_limit' => '500',
			],
		];

		$states = [ true, false, true, false, true ];
		foreach ( $states as $i => $expected ) {
			$data            = $base_data;
			$data['enabled'] = $expected ? '1' : '';
			$this->simulate_options_php_save( [ 'sitemap' => $data ] );

			MM_Site_Settings::reset_instance();
			$s = MM_Site_Settings::get_instance();
			$this->assertSame(
				$expected,
				$s->get( 'sitemap.enabled' ),
				"Iteration {$i}: sitemap.enabled should be " . var_export( $expected, true )
			);
		}
	}

	// ======================================================================
	// SECTION: All options in full option after partial save
	// ======================================================================

	/**
	 * Test: After partial save, all top-level sections exist.
	 */
	public function test_all_sections_exist_after_partial_save_full_chain(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'authors' => [
				'enabled'              => '1',
				'noindex_default'      => '',
				'title_template'       => 'Test',
				'description_template' => 'Test',
				'person_schema'        => '1',
				'profile_social_fields'=> '1',
			],
		] );

		$raw = get_option( MM_META_OPT_SETTINGS, [] );
		$this->assertArrayHasKey( 'titles', $raw, 'titles section missing after partial save' );
		$this->assertArrayHasKey( 'social', $raw, 'social section missing after partial save' );
		$this->assertArrayHasKey( 'schema', $raw, 'schema section missing after partial save' );
		$this->assertArrayHasKey( 'sitemap', $raw, 'sitemap section missing after partial save' );
		$this->assertArrayHasKey( 'robots', $raw, 'robots section missing after partial save' );
		$this->assertArrayHasKey( 'authors', $raw, 'authors section missing after partial save' );
		$this->assertArrayHasKey( 'links', $raw, 'links section missing after partial save' );
		$this->assertArrayHasKey( 'hygiene', $raw, 'hygiene section missing after partial save' );
		$this->assertArrayHasKey( 'feed', $raw, 'feed section missing after partial save' );
		$this->assertArrayHasKey( 'discovery', $raw, 'discovery section missing after partial save' );
		$this->assertCount( 10, $raw, 'Should have exactly 10 sections' );
	}

	// ======================================================================
	// SECTION: Real-world form submission scenarios
	// ======================================================================

	/**
	 * Test: User only changes separator on Titles page — all other title fields preserved.
	 */
	public function test_titles_separator_change_only(): void {
		$this->seed_full_option();

		// Change only separator.
		$this->simulate_options_php_save( [
			'titles' => [
				'separator'                  => '—',
				'home_title'                 => '%%sitetitle%% %%sep%% %%tagline%%',
				'home_description'           => '',
				'author_archive_title'       => 'Articles by %%author_name%% %%sep%% %%sitetitle%%',
				'author_archive_description' => '%%author_bio%%',
				'search_title'               => 'Search Results for %%search_query%% %%sep%% %%sitetitle%%',
				'404_title'                  => 'Page Not Found %%sep%% %%sitetitle%%',
				'paginate_append'            => '1',
				'date_archive_noindex'       => '1',
				'search_noindex'             => '1',
				'post_types' => [
					'post' => [
						'single_title'       => '%%post_title%% %%sep%% %%sitetitle%%',
						'archive_title'      => 'Blog %%sep%% %%sitetitle%%',
						'description_source' => 'excerpt',
						'noindex'            => '',
						'noindex_archive'    => '',
					],
					'page' => [
						'single_title'       => '%%post_title%% %%sep%% %%sitetitle%%',
						'description_source' => 'excerpt',
						'noindex'            => '',
					],
				],
				'taxonomies' => [
					'category' => [
						'archive_title'      => '%%term_title%% %%sep%% %%sitetitle%%',
						'description_source' => 'term_description',
						'noindex'            => '',
					],
					'post_tag' => [
						'archive_title'      => '%%term_title%% %%sep%% %%sitetitle%%',
						'description_source' => 'term_description',
						'noindex'            => '',
					],
				],
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertSame( '—', $s->get( 'titles.separator' ) );
		$this->assertSame( '%%sitetitle%% %%sep%% %%tagline%%', $s->get( 'titles.home_title' ) );
	}

	/**
	 * Test: Business profile payment checkboxes (standalone option with sanitize_callback).
	 */
	public function test_business_payment_checkboxes(): void {
		$defaults = MM_Site_Settings::business_defaults();
		update_option( MM_META_OPT_BUSINESS, $defaults );

		// Submit with payment checkboxes checked.
		$raw = $defaults;
		$raw['payment_accepted'] = [ 'cash', 'credit_card', 'check' ];

		$sanitized = MM_Site_Settings::deep_sanitize_section( $raw, MM_Site_Settings::business_defaults() );
		update_option( MM_META_OPT_BUSINESS, $sanitized );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertSame( [ 'cash', 'credit_card', 'check' ], $s->get_business( 'payment_accepted' ) );
	}
}
