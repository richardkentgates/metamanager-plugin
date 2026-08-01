<?php
/**
 * Integration tests for MM_Site_Settings save flow.
 *
 * Tests the pre_update_option_mm_meta_settings filter (intercept_save) that
 * fixes the options.php partial-save bug: options.php calls
 * update_option('mm_meta_settings', $_POST['mm_meta_settings']) which only
 * contains one section's data, replacing the entire option.
 *
 * These tests prove that:
 *   1. Each section can be saved independently without destroying other sections
 *   2. Checkbox strings ('1') are cast to booleans
 *   3. Unchecked checkboxes (absent from POST) become false
 *   4. Settings read back correctly via MM_Site_Settings::get()
 *   5. checked() renders correctly for both true and false values
 *
 * @package Metamanager\Tests\Integration
 */

defined( 'ABSPATH' ) || exit;

class Test_MM_Site_Settings_Save extends WP_UnitTestCase {

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
	 * Helper: simulate what options.php does for a section form submit.
	 *
	 * This is the exact code path from wp-admin/options.php:
	 *   $value = $_POST['mm_meta_settings']; // e.g. ['sitemap' => [...]]
	 *   update_option('mm_meta_settings', $value);
	 */
	private function simulate_options_php_save( array $section_data ): void {
		update_option( MM_META_OPT_SETTINGS, $section_data );
	}

	/**
	 * Helper: set up a full option with known values in all sections.
	 */
	private function seed_full_option(): void {
		$seed = MM_Site_Settings::settings_defaults();
		update_option( MM_META_OPT_SETTINGS, $seed );
		MM_Site_Settings::reset_instance();
	}

	// -----------------------------------------------------------------------
	// SECTION: titles
	// -----------------------------------------------------------------------

	public function test_titles_save_preserves_other_sections(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'titles' => [
				'separator'    => '—',
				'home_title'   => 'Custom Home',
				'home_description' => '',
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

		$s = MM_Site_Settings::get_instance();

		// Titles section should be updated.
		$this->assertSame( '—', $s->get( 'titles.separator' ) );
		$this->assertSame( 'Custom Home', $s->get( 'titles.home_title' ) );

		// Other sections should be preserved with default values.
		$this->assertTrue( $s->get( 'social.og_enabled' ) );
		$this->assertSame( 'LocalBusiness', $s->get( 'schema.knowledge_entity' ) );
		$this->assertTrue( $s->get( 'sitemap.enabled' ) );
		$this->assertTrue( $s->get( 'authors.enabled' ) );
	}

	// -----------------------------------------------------------------------
	// SECTION: social
	// -----------------------------------------------------------------------

	public function test_social_save_preserves_other_sections(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'social' => [
				'og_enabled'        => '',
				'twitter_enabled'   => '1',
				'og_default_image'  => '',
				'og_default_image_id' => '0',
				'og_locale'         => 'en_GB',
				'fb_app_id'         => '',
				'twitter_site'      => '@test',
				'twitter_card_type' => 'summary',
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
		] );

		$s = MM_Site_Settings::get_instance();

		// Social section: og_enabled unchecked (absent) → false, twitter_enabled checked → true.
		$this->assertFalse( $s->get( 'social.og_enabled' ) );
		$this->assertTrue( $s->get( 'social.twitter_enabled' ) );
		$this->assertSame( 'en_GB', $s->get( 'social.og_locale' ) );
		$this->assertSame( '@test', $s->get( 'social.twitter_site' ) );
		$this->assertSame( 'summary', $s->get( 'social.twitter_card_type' ) );

		// Other sections preserved.
		$this->assertSame( '|', $s->get( 'titles.separator' ) );
		$this->assertTrue( $s->get( 'sitemap.enabled' ) );
		$this->assertTrue( $s->get( 'authors.enabled' ) );
	}

	// -----------------------------------------------------------------------
	// SECTION: schema
	// -----------------------------------------------------------------------

	public function test_schema_save_preserves_other_sections(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'schema' => [
				'knowledge_entity'     => 'Organization',
				'website_searchaction' => '',
				'breadcrumbs'          => '1',
				'archive_itemlist'     => '1',
				'post_type_types'      => [
					'post'    => 'Article',
					'page'    => 'WebPage',
					'product' => 'Product',
					'course'  => 'Course',
				],
				'custom_json_ld'       => '',
			],
		] );

		$s = MM_Site_Settings::get_instance();

		$this->assertSame( 'Organization', $s->get( 'schema.knowledge_entity' ) );
		$this->assertFalse( $s->get( 'schema.website_searchaction' ) );
		$this->assertTrue( $s->get( 'schema.breadcrumbs' ) );

		// Other sections preserved.
		$this->assertSame( '|', $s->get( 'titles.separator' ) );
		$this->assertTrue( $s->get( 'social.og_enabled' ) );
		$this->assertTrue( $s->get( 'authors.enabled' ) );
	}

	// -----------------------------------------------------------------------
	// SECTION: sitemap (the section the user reported as broken)
	// -----------------------------------------------------------------------

	public function test_sitemap_save_preserves_other_sections(): void {
		$this->seed_full_option();

		// Simulate: user unchecked "Enable Sitemap" — absent from POST data.
		$this->simulate_options_php_save( [
			'sitemap' => [
				'post_types'    => [ 'post' => '1', 'page' => '1' ],
				'taxonomies'    => [ 'category' => '1' ],
				'images'        => '1',
				'video'         => '1',
				'video_selfhosted' => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed' => '1',
				'records_per_file' => '1000',
				'ping_google'   => '1',
				'ping_bing'     => '1',
				// enabled deliberately absent — unchecked checkbox
			],
		] );

		$s = MM_Site_Settings::get_instance();

		// sitemap.enabled was unchecked (absent) → should be false.
		$this->assertFalse( $s->get( 'sitemap.enabled' ) );

		// Other sitemap fields present → should be saved.
		$this->assertSame( [ 'post' => true, 'page' => true ], $s->get( 'sitemap.post_types' ) );
		$this->assertSame( 1000, $s->get( 'sitemap.records_per_file' ) );

		// Other sections preserved.
		$this->assertSame( '|', $s->get( 'titles.separator' ) );
		$this->assertTrue( $s->get( 'social.og_enabled' ) );
		$this->assertTrue( $s->get( 'authors.enabled' ) );
		$this->assertSame( 'LocalBusiness', $s->get( 'schema.knowledge_entity' ) );
	}

	public function test_sitemap_enable_toggle(): void {
		$this->seed_full_option();

		// Step 1: Uncheck enabled.
		$this->simulate_options_php_save( [
			'sitemap' => [
				'enabled' => '', // unchecked
				'post_types' => [ 'post' => '1', 'page' => '1' ],
				'taxonomies' => [ 'category' => '1' ],
				'images' => '1',
				'video'  => '1',
				'video_selfhosted' => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed' => '1',
				'records_per_file' => '1000',
				'ping_google' => '1',
				'ping_bing'   => '1',
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertFalse( $s->get( 'sitemap.enabled' ) );

		// Step 2: Re-enable.
		$this->simulate_options_php_save( [
			'sitemap' => [
				'enabled' => '1', // checked
				'post_types' => [ 'post' => '1', 'page' => '1' ],
				'taxonomies' => [ 'category' => '1' ],
				'images' => '1',
				'video'  => '1',
				'video_selfhosted' => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed' => '1',
				'records_per_file' => '1000',
				'ping_google' => '1',
				'ping_bing'   => '1',
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertTrue( $s->get( 'sitemap.enabled' ) );
	}

	public function test_sitemap_checked_output_after_save(): void {
		$this->seed_full_option();

		// Simulate unchecked save.
		$this->simulate_options_php_save( [
			'sitemap' => [
				'enabled' => '', // unchecked
				'post_types' => [ 'post' => '1', 'page' => '1' ],
				'taxonomies' => [ 'category' => '1' ],
				'images' => '1',
				'video'  => '1',
				'video_selfhosted' => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed' => '1',
				'records_per_file' => '1000',
				'ping_google' => '1',
				'ping_bing'   => '1',
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();

		// checked() should produce empty string for unchecked value.
		ob_start();
		checked( $s->get( 'sitemap.enabled', true ) );
		$checked_html = ob_get_clean();
		$this->assertEmpty( $checked_html, 'checked() should be empty for sitemap.enabled=false' );

		// Now re-enable and check.
		$this->simulate_options_php_save( [
			'sitemap' => [
				'enabled' => '1', // checked
				'post_types' => [ 'post' => '1', 'page' => '1' ],
				'taxonomies' => [ 'category' => '1' ],
				'images' => '1',
				'video'  => '1',
				'video_selfhosted' => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed' => '1',
				'records_per_file' => '1000',
				'ping_google' => '1',
				'ping_bing'   => '1',
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();

		ob_start();
		checked( $s->get( 'sitemap.enabled', true ) );
		$checked_html = ob_get_clean();
		$this->assertNotEmpty( $checked_html, 'checked() should not be empty for sitemap.enabled=true' );
	}

	// -----------------------------------------------------------------------
	// SECTION: robots
	// -----------------------------------------------------------------------

	public function test_robots_save_preserves_other_sections(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'robots' => [
				'enabled'     => '',
				'disallow'    => [ '/wp-admin/' ],
				'allow'       => [ '/wp-admin/admin-ajax.php' ],
				'crawl_delay' => '5',
				'custom'      => 'User-agent: *',
			],
		] );

		$s = MM_Site_Settings::get_instance();

		$this->assertFalse( $s->get( 'robots.enabled' ) );
		$this->assertSame( [ '/wp-admin/' ], $s->get( 'robots.disallow' ) );
		$this->assertSame( '5', $s->get( 'robots.crawl_delay' ) );

		// Other sections preserved.
		$this->assertSame( '|', $s->get( 'titles.separator' ) );
		$this->assertTrue( $s->get( 'authors.enabled' ) );
	}

	// -----------------------------------------------------------------------
	// SECTION: authors
	// -----------------------------------------------------------------------

	public function test_authors_save_preserves_other_sections(): void {
		$this->seed_full_option();

		// Simulate: uncheck enabled, check noindex_default.
		$this->simulate_options_php_save( [
			'authors' => [
				'enabled'              => '',  // unchecked
				'noindex_default'      => '1', // checked
				'title_template'       => 'Articles by %%author_name%% %%sep%% %%sitetitle%%',
				'description_template' => '%%author_bio%%',
				'person_schema'        => '1', // checked
				'profile_social_fields'=> '1', // checked
			],
		] );

		$s = MM_Site_Settings::get_instance();

		$this->assertFalse( $s->get( 'authors.enabled' ) );
		$this->assertTrue( $s->get( 'authors.noindex_default' ) );
		$this->assertTrue( $s->get( 'authors.person_schema' ) );
		$this->assertTrue( $s->get( 'authors.profile_social_fields' ) );

		// Other sections preserved.
		$this->assertSame( '|', $s->get( 'titles.separator' ) );
		$this->assertTrue( $s->get( 'social.og_enabled' ) );
		$this->assertTrue( $s->get( 'sitemap.enabled' ) );
	}

	public function test_authors_checked_output(): void {
		$this->seed_full_option();

		// Save with enabled unchecked.
		$this->simulate_options_php_save( [
			'authors' => [
				'enabled'              => '',  // unchecked
				'noindex_default'      => '',
				'title_template'       => 'Articles by %%author_name%% %%sep%% %%sitetitle%%',
				'description_template' => '%%author_bio%%',
				'person_schema'        => '1',
				'profile_social_fields'=> '1',
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();

		ob_start();
		checked( $s->get( 'authors.enabled', true ) );
		$this->assertEmpty( ob_get_clean(), 'enabled unchecked → checked() empty' );

		ob_start();
		checked( $s->get( 'authors.noindex_default', false ) );
		$this->assertEmpty( ob_get_clean(), 'noindex_default unchecked → checked() empty' );

		ob_start();
		checked( $s->get( 'authors.person_schema', true ) );
		$this->assertNotEmpty( ob_get_clean(), 'person_schema checked → checked() not empty' );

		ob_start();
		checked( $s->get( 'authors.profile_social_fields', true ) );
		$this->assertNotEmpty( ob_get_clean(), 'profile_social_fields checked → checked() not empty' );
	}

	// -----------------------------------------------------------------------
	// SECTION: hygiene
	// -----------------------------------------------------------------------

	public function test_hygiene_save_preserves_other_sections(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'hygiene' => [
				'remove_generator'       => '',
				'remove_oembed_links'    => '1',
				'remove_shortlink'       => '1',
				'remove_wlw_manifest'    => '1',
				'remove_rsd_link'        => '1',
				'remove_pingback_header' => '1',
				'remove_x_powered_by'   => '1',
				'remove_wp_dns_prefetch' => '1',
			],
		] );

		$s = MM_Site_Settings::get_instance();

		$this->assertFalse( $s->get( 'hygiene.remove_generator' ) );
		$this->assertTrue( $s->get( 'hygiene.remove_oembed_links' ) );

		// Other sections preserved.
		$this->assertSame( '|', $s->get( 'titles.separator' ) );
		$this->assertTrue( $s->get( 'authors.enabled' ) );
	}

	// -----------------------------------------------------------------------
	// SECTION: feed
	// -----------------------------------------------------------------------

	public function test_feed_save_preserves_other_sections(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'feed' => [
				'cleanup_enabled'          => '',
				'remove_generator'         => '1',
				'remove_comments_elements' => '1',
				'use_excerpt'              => '1',
				'feed_title'               => 'My Feed',
				'feed_copyright'           => '',
			],
		] );

		$s = MM_Site_Settings::get_instance();

		$this->assertFalse( $s->get( 'feed.cleanup_enabled' ) );
		$this->assertTrue( $s->get( 'feed.remove_generator' ) );
		$this->assertSame( 'My Feed', $s->get( 'feed.feed_title' ) );

		// Other sections preserved.
		$this->assertSame( '|', $s->get( 'titles.separator' ) );
		$this->assertTrue( $s->get( 'authors.enabled' ) );
	}

	// -----------------------------------------------------------------------
	// SECTION: links
	// -----------------------------------------------------------------------

	public function test_links_save_preserves_other_sections(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'links' => [
				'enabled'        => '',
				'cron_frequency' => 'daily',
				'timeout'        => '30',
				'batch_size'     => '100',
				'check_external' => '',
				'ignore_domains' => [ 'example.com' ],
				'email_alerts'   => '1',
				'email_address'  => 'test@example.com',
			],
		] );

		$s = MM_Site_Settings::get_instance();

		$this->assertFalse( $s->get( 'links.enabled' ) );
		$this->assertSame( 'daily', $s->get( 'links.cron_frequency' ) );
		$this->assertSame( 30, $s->get( 'links.timeout' ) );
		$this->assertFalse( $s->get( 'links.check_external' ) );

		// Other sections preserved.
		$this->assertSame( '|', $s->get( 'titles.separator' ) );
		$this->assertTrue( $s->get( 'authors.enabled' ) );
	}

	// -----------------------------------------------------------------------
	// SECTION: discovery
	// -----------------------------------------------------------------------

	public function test_discovery_save_preserves_other_sections(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'discovery' => [
				'llms_txt_enabled' => '',
				'mcp_server'       => '',
			],
		] );

		$s = MM_Site_Settings::get_instance();

		$this->assertFalse( $s->get( 'discovery.llms_txt_enabled' ) );
		$this->assertFalse( $s->get( 'discovery.mcp_server' ) );

		// Other sections preserved.
		$this->assertSame( '|', $s->get( 'titles.separator' ) );
		$this->assertTrue( $s->get( 'authors.enabled' ) );
	}

	// -----------------------------------------------------------------------
	// Cross-section: rapid toggling
	// -----------------------------------------------------------------------

	public function test_rapid_toggling_sitemap_enabled(): void {
		$this->seed_full_option();

		$sitemap_data = [
			'post_types'    => [ 'post' => '1', 'page' => '1' ],
			'taxonomies'    => [ 'category' => '1' ],
			'images'        => '1',
			'video'         => '1',
			'video_selfhosted' => '1',
			'exclude_password_protected' => '1',
			'exclude_noindexed' => '1',
			'records_per_file' => '1000',
			'ping_google'   => '1',
			'ping_bing'     => '1',
		];

		// Toggle 5 times: enable → disable → enable → disable → enable.
		$states = [ true, false, true, false, true ];
		foreach ( $states as $i => $expected ) {
			$data = $sitemap_data;
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

	public function test_rapid_toggling_authors_enabled(): void {
		$this->seed_full_option();

		$authors_data = [
			'noindex_default'       => '',
			'title_template'        => 'Articles by %%author_name%% %%sep%% %%sitetitle%%',
			'description_template'  => '%%author_bio%%',
			'person_schema'         => '1',
			'profile_social_fields' => '1',
		];

		$states = [ false, true, false, true ];
		foreach ( $states as $i => $expected ) {
			$data = $authors_data;
			$data['enabled'] = $expected ? '1' : '';
			$this->simulate_options_php_save( [ 'authors' => $data ] );
			MM_Site_Settings::reset_instance();
			$s = MM_Site_Settings::get_instance();
			$this->assertSame(
				$expected,
				$s->get( 'authors.enabled' ),
				"Iteration {$i}: authors.enabled should be " . var_export( $expected, true )
			);
		}
	}

	// -----------------------------------------------------------------------
	// Type casting: booleans
	// -----------------------------------------------------------------------

	public function test_checkbox_string_true_becomes_bool_true(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'authors' => [
				'enabled'              => '1',  // string from checkbox POST
				'noindex_default'      => '',
				'title_template'       => 'Articles by %%author_name%% %%sep%% %%sitetitle%%',
				'description_template' => '%%author_bio%%',
				'person_schema'        => '1',
				'profile_social_fields'=> '1',
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertIsBool( $s->get( 'authors.enabled' ) );
		$this->assertTrue( $s->get( 'authors.enabled' ) );
		$this->assertIsBool( $s->get( 'authors.noindex_default' ) );
		$this->assertFalse( $s->get( 'authors.noindex_default' ) );
	}

	public function test_checkbox_absent_becomes_bool_false(): void {
		$this->seed_full_option();

		// Submit authors section with ALL checkboxes absent (unchecked).
		$this->simulate_options_php_save( [
			'authors' => [
				'title_template'       => 'Articles by %%author_name%% %%sep%% %%sitetitle%%',
				'description_template' => '%%author_bio%%',
				// All checkbox fields absent.
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
		$this->assertIsBool( $s->get( 'authors.profile_social_fields' ) );
		$this->assertFalse( $s->get( 'authors.profile_social_fields' ) );
	}

	// -----------------------------------------------------------------------
	// Type casting: integers
	// -----------------------------------------------------------------------

	public function test_integer_string_becomes_int(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'sitemap' => [
				'enabled'    => '1',
				'post_types' => [ 'post' => '1', 'page' => '1' ],
				'taxonomies' => [ 'category' => '1' ],
				'images'     => '1',
				'video'      => '1',
				'video_selfhosted' => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed' => '1',
				'records_per_file' => '500', // string from POST
				'ping_google' => '1',
				'ping_bing'   => '1',
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();
		$this->assertIsInt( $s->get( 'sitemap.records_per_file' ) );
		$this->assertSame( 500, $s->get( 'sitemap.records_per_file' ) );
	}

	// -----------------------------------------------------------------------
	// Section missing keys: fills from defaults
	// -----------------------------------------------------------------------

	public function test_missing_keys_fill_from_defaults(): void {
		$this->seed_full_option();

		// Submit sitemap section with only enabled — all other keys absent.
		$this->simulate_options_php_save( [
			'sitemap' => [
				'enabled' => '1',
			],
		] );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();

		$this->assertTrue( $s->get( 'sitemap.enabled' ) );
		// Missing bool keys (unchecked checkboxes) default to false, not the default value.
		$this->assertFalse( $s->get( 'sitemap.images' ) );
		$this->assertFalse( $s->get( 'sitemap.video' ) );
		$this->assertSame( 1000, $s->get( 'sitemap.records_per_file' ) );
		$this->assertFalse( $s->get( 'sitemap.ping_google' ) );
		$this->assertFalse( $s->get( 'sitemap.ping_bing' ) );
	}

	// -----------------------------------------------------------------------
	// Filter returns full option, not partial
	// -----------------------------------------------------------------------

	public function test_filter_returns_full_option(): void {
		$this->seed_full_option();

		$this->simulate_options_php_save( [
			'authors' => [
				'enabled'         => '',
				'title_template'  => 'Test',
				'description_template' => 'Test',
			],
		] );

		$raw = get_option( MM_META_OPT_SETTINGS, [] );

		// Should have all sections, not just authors.
		$this->assertArrayHasKey( 'titles', $raw );
		$this->assertArrayHasKey( 'social', $raw );
		$this->assertArrayHasKey( 'schema', $raw );
		$this->assertArrayHasKey( 'sitemap', $raw );
		$this->assertArrayHasKey( 'robots', $raw );
		$this->assertArrayHasKey( 'authors', $raw );
		$this->assertArrayHasKey( 'links', $raw );
		$this->assertArrayHasKey( 'hygiene', $raw );
		$this->assertArrayHasKey( 'feed', $raw );
		$this->assertArrayHasKey( 'discovery', $raw );
	}

	// -----------------------------------------------------------------------
	// Non-section data passes through untouched
	// -----------------------------------------------------------------------

	public function test_full_option_save_passes_through(): void {
		$this->seed_full_option();

		// Simulate a full save (like the tools reset) — multiple sections.
		$full = MM_Site_Settings::settings_defaults();
		$full['titles']['separator'] = '>>>';
		update_option( MM_META_OPT_SETTINGS, $full );
		MM_Site_Settings::reset_instance();

		$s = MM_Site_Settings::get_instance();
		$this->assertSame( '>>>', $s->get( 'titles.separator' ) );
		$this->assertTrue( $s->get( 'sitemap.enabled' ) );
	}

	// -----------------------------------------------------------------------
	// Full WordPress save chain: sanitize_option → pre_update_option
	//
	// WordPress update_option() calls sanitize_option() (line 884 of
	// wp-includes/option.php) which fires sanitize_option_mm_meta_settings,
	// then calls pre_update_option_mm_meta_settings. These tests exercise
	// the exact same code path the browser uses.
	// -----------------------------------------------------------------------

	/**
	 * Prove that WITHOUT sanitize_callbacks on the shared option,
	 * a partial save through sanitize_option() → pre_update_option_ preserves
	 * the POST data all the way to intercept_save.
	 */
	public function test_full_chain_without_sanitize_callbacks_preserves_post_data(): void {
		$this->seed_full_option();

		// Simulate the raw POST value (single section).
		$post_value = [
			'sitemap' => [
				'enabled' => '', // unchecked
				'post_types' => [ 'post' => '1', 'page' => '1' ],
				'taxonomies' => [ 'category' => '1' ],
				'images' => '1',
				'video'  => '1',
				'video_selfhosted' => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed' => '1',
				'records_per_file' => '1000',
				'ping_google' => '1',
				'ping_bing'   => '1',
			],
		];

		// This is exactly what WordPress core does in update_option():
		//   $value = sanitize_option('mm_meta_settings', $value);
		//   $value = apply_filters('pre_update_option_mm_meta_settings', ...);
		$value = sanitize_option( MM_META_OPT_SETTINGS, $post_value );
		$value = apply_filters( 'pre_update_option_mm_meta_settings', $value, get_option( MM_META_OPT_SETTINGS ), MM_META_OPT_SETTINGS );
		update_option( MM_META_OPT_SETTINGS, $value );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();

		$this->assertFalse( $s->get( 'sitemap.enabled' ), 'sitemap.enabled should be false (unchecked)' );
		$this->assertTrue( $s->get( 'authors.enabled' ), 'authors section should be preserved' );
		$this->assertSame( '|', $s->get( 'titles.separator' ), 'titles section should be preserved' );
		$this->assertSame( 10, count( array_keys( $s->all() ) ), 'All sections should be present' );
	}

	/**
	 * Prove that WITH stale sanitize_callbacks still registered (the old bug),
	 * the POST data is lost through the chain.
	 */
	public function test_full_chain_with_sanitize_callbacks_destroys_post_data(): void {
		$this->seed_full_option();

		// Register fake sanitize_callbacks like the old code did.
		// Each one reloads from DB — this is what destroys the POST data.
		$groups = [
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

		foreach ( $groups as $group => $section ) {
			add_filter( "sanitize_option_" . MM_META_OPT_SETTINGS, function ( $raw ) use ( $section ) {
				$defaults = MM_Site_Settings::settings_defaults();
				$current  = get_option( MM_META_OPT_SETTINGS, [] );
				$submitted = ( is_array( $raw ) && array_key_exists( $section, $raw ) ) ? $raw[ $section ] : ( is_array( $raw ) ? $raw : [] );
				$current[ $section ] = $submitted;
				return $current;
			} );
		}

		$post_value = [
			'sitemap' => [
				'enabled' => '',
				'post_types' => [ 'post' => '1', 'page' => '1' ],
				'taxonomies' => [ 'category' => '1' ],
				'images' => '1',
				'video'  => '1',
				'video_selfhosted' => '1',
				'exclude_password_protected' => '1',
				'exclude_noindexed' => '1',
				'records_per_file' => '1000',
				'ping_google' => '1',
				'ping_bing'   => '1',
			],
		];

		$value = sanitize_option( MM_META_OPT_SETTINGS, $post_value );
		$value = apply_filters( 'pre_update_option_mm_meta_settings', $value, get_option( MM_META_OPT_SETTINGS ), MM_META_OPT_SETTINGS );
		update_option( MM_META_OPT_SETTINGS, $value );

		MM_Site_Settings::reset_instance();
		$s = MM_Site_Settings::get_instance();

		// The sanitize callbacks destroy the POST data — sitemap.enabled stays true
		// because the chain reloads from DB each time.
		$this->assertTrue(
			$s->get( 'sitemap.enabled' ),
			'sitemap.enabled stays true because sanitize callbacks destroy the POST data'
		);
	}
}
