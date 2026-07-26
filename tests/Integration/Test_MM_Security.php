<?php
/**
 * Security tests for Metamanager.
 *
 * Covers: output escaping on rendered HTML, input sanitization, and
 * capability checks on form saves. AJAX handler auth is covered by
 * Test_MM_REST; these tests focus on the head-emitter output path.
 *
 * @package Metamanager\Tests\Integration
 */

defined( 'ABSPATH' ) || exit;

class Test_MM_Security extends WP_UnitTestCase {

	/** @var int Author (low-privilege) user. */
	private int $author_id;
	/** @var int Subscriber (lowest-privilege) user. */
	private int $subscriber_id;

	public function set_up(): void {
		parent::set_up();
		$this->author_id    = $this->factory->user->create( [ 'role' => 'author' ] );
		$this->subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
	}

	// -----------------------------------------------------------------------
	// 1. Output escaping — title tag
	// -----------------------------------------------------------------------

	public function test_output_escapes_html_in_title(): void {
		$post_id = $this->factory->post->create( [
			'post_title' => '<script>alert("XSS")</script>',
			'post_type'  => 'post',
		] );

		$this->go_to( get_permalink( $post_id ) );
		$title = wp_title( '|', false, 'right' );

		// WordPress strips HTML tags from titles — raw tags must never appear.
		$this->assertStringNotContainsString( '<script>', $title, 'Raw <script> tag must not appear in title.' );
		$this->assertStringNotContainsString( '</script>', $title, 'Closing </script> tag must not appear in title.' );
	}

	// -----------------------------------------------------------------------
	// 2. Output escaping — meta description
	// -----------------------------------------------------------------------

	public function test_output_escapes_html_in_description(): void {
		$post_id = $this->factory->post->create( [
			'post_title'   => 'Normal Title',
			'post_excerpt' => 'Safe text <img src=x onerror=alert(1)> and more text.',
		] );

		$this->go_to( get_permalink( $post_id ) );
		$context  = new MM_Page_Context();
		$settings = MM_Site_Settings::get_instance();
		$emitter  = new MM_Head_Emitter( $context, $settings );
		$emitter->add_module( new MM_Mod_Head_Meta( $settings ) );

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'onerror=', $output, 'onerror handler must not appear unescaped.' );
		$this->assertStringContainsString( 'name="description"', $output, 'Description meta tag should be present.' );
	}

	// -----------------------------------------------------------------------
	// 3. Output escaping — OG tags with special characters
	// -----------------------------------------------------------------------

	public function test_output_escapes_special_chars_in_og_tags(): void {
		$post_id = $this->factory->post->create( [
			'post_title'   => 'Tom & Jerry\'s "Big" <Adventure>',
			'post_excerpt' => 'A "description" with <special> & characters.',
		] );

		$this->go_to( get_permalink( $post_id ) );
		$context  = new MM_Page_Context();
		$settings = MM_Site_Settings::get_instance();
		$emitter  = new MM_Head_Emitter( $context, $settings );
		$emitter->add_module( new MM_Mod_Social( $settings ) );

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'og:title', $output, 'OG title tag should be present.' );
		$this->assertStringNotContainsString( '<Adventure>', $output, 'Raw angle brackets must be escaped in OG output.' );
	}

	// -----------------------------------------------------------------------
	// 4. Output escaping — JSON-LD with special characters
	// -----------------------------------------------------------------------

	public function test_json_ld_escapes_special_chars(): void {
		$post_id = $this->factory->post->create( [
			'post_title'   => 'Quotes "and" Ampersands & <Tags>',
			'post_type'    => 'post',
			'post_content' => 'Content.',
		] );

		$this->go_to( get_permalink( $post_id ) );
		$context  = new MM_Page_Context();
		$settings = MM_Site_Settings::get_instance();
		$emitter  = new MM_Head_Emitter( $context, $settings );
		$emitter->add_module( new MM_Mod_Schema( $settings ) );

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'application/ld+json', $output, 'JSON-LD block should be present.' );
		preg_match( '/<script type="application\/ld\+json">(.*?)<\/script>/s', $output, $matches );
		$this->assertNotEmpty( $matches, 'Should find JSON-LD block.' );
		$json = json_decode( $matches[1], true );
		$this->assertNotNull( $json, 'JSON-LD should decode without error.' );
	}

	// -----------------------------------------------------------------------
	// 5. SQL injection — orderby (from Test_MM_DB)
	// -----------------------------------------------------------------------

	public function test_sql_injection_in_orderby_is_rejected(): void {
		MM_DB::create_or_update_table();
		$jobs = MM_DB::get_jobs( [
			'orderby' => 'id DROP TABLE',
			'order'   => 'ASC',
		] );
		$this->assertIsArray( $jobs );
	}

	// -----------------------------------------------------------------------
	// 6. IP sanitization (from Test_MM_Settings)
	// -----------------------------------------------------------------------

	public function test_ip_sanitization_strips_xss(): void {
		$sanitized = MM_Settings::get_current_ip();
		$this->assertIsString( $sanitized );
		$this->assertStringNotContainsString( '<', $sanitized );
		$this->assertStringNotContainsString( '>', $sanitized );
	}

	// -----------------------------------------------------------------------
	// 7. Settings page requires manage_options
	// -----------------------------------------------------------------------

	public function test_settings_page_requires_admin(): void {
		wp_set_current_user( $this->author_id );
		$settings = new MM_Settings();
		ob_start();
		try {
			$settings->render_page();
		} catch ( \WPDieException $e ) {
			ob_end_clean();
			// Expected — author cannot manage settings.
			$this->assertStringContainsString( 'permission', strtolower( $e->getMessage() ) );
			return;
		}
		ob_end_clean();
		$this->fail( 'Expected WPDieException for unauthorized settings access.' );
	}

	// -----------------------------------------------------------------------
	// 8. Post meta save requires edit_post capability
	// -----------------------------------------------------------------------

	public function test_post_meta_save_rejects_unauthorized_user(): void {
		$post_id = $this->factory->post->create( [ 'post_author' => 999 ] );
		$settings = MM_Site_Settings::get_instance();
		$panel    = new MM_Post_Meta_Panel( $settings );

		wp_set_current_user( $this->subscriber_id );
		$_POST = [
			'_mm_meta_nonce' => wp_create_nonce( 'mm_meta_post_meta_save' ),
			'_mm_meta'       => [ 'title' => 'Hacked Title' ],
		];

		$panel->save_meta( $post_id, get_post( $post_id ) );
		$stored = get_post_meta( $post_id, '_mm_meta', true );
		$this->assertEmpty( $stored, 'Unauthorized user should not be able to save post meta.' );
	}

	// -----------------------------------------------------------------------
	// 9. Term meta save requires manage_categories
	// -----------------------------------------------------------------------

	public function test_term_meta_save_rejects_unauthorized_user(): void {
		$term = $this->factory->category->create( [ 'name' => 'Security Test' ] );
		$settings = MM_Site_Settings::get_instance();
		$panel    = new MM_Term_Meta_Panel( $settings );

		wp_set_current_user( $this->author_id );
		$_POST = [
			'_mm_meta_nonce' => wp_create_nonce( 'mm_meta_term_meta_save' ),
			'_mm_meta'       => [ 'title' => 'Hacked Term' ],
		];

		$panel->save_meta( $term );
		$stored = get_term_meta( $term, '_mm_meta', true );
		$this->assertEmpty( $stored, 'Author should not be able to save term meta.' );
	}

	// -----------------------------------------------------------------------
	// 10. User meta save requires edit_user
	// -----------------------------------------------------------------------

	public function test_user_meta_save_rejects_unauthorized_user(): void {
		$settings = MM_Site_Settings::get_instance();
		$panel    = new MM_User_Meta_Panel( $settings );

		wp_set_current_user( $this->subscriber_id );
		$_POST = [
			'_mm_meta_nonce' => wp_create_nonce( 'mm_meta_user_meta_save' ),
			'_mm_meta'       => [ 'description' => 'Hacked Bio' ],
		];

		$panel->save_meta( $this->author_id );
		$stored = get_user_meta( $this->author_id, '_mm_meta', true );
		$this->assertEmpty( $stored, 'Subscriber should not be able to save user meta.' );
	}

	// -----------------------------------------------------------------------
	// 11. REST API requires authentication
	// -----------------------------------------------------------------------

	public function test_rest_stats_requires_auth(): void {
		MM_DB::create_or_update_table();
		$request  = new WP_REST_Request( 'GET', '/metamanager/v1/stats' );
		$response = rest_do_request( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_rest_jobs_requires_editor(): void {
		MM_DB::create_or_update_table();
		wp_set_current_user( $this->author_id );
		$request  = new WP_REST_Request( 'GET', '/metamanager/v1/jobs' );
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}
}
