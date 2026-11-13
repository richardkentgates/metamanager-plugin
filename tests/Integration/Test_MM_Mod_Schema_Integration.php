<?php
/**
 * Integration tests for MM_Mod_Schema — JSON-LD structure and correctness.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Mod_Schema_Integration extends WP_UnitTestCase {

	private MM_Site_Settings $settings;
	private MM_Page_Context $context;
	private MM_Head_Emitter $emitter;

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
		MM_Site_Settings::reset_instance();

		$this->settings = MM_Site_Settings::get_instance();
		$this->context  = new MM_Page_Context();
		$this->emitter  = new MM_Head_Emitter( $this->context, $this->settings );
		$this->emitter->add_module( new MM_Mod_Schema( $this->settings ) );
	}

	public function tear_down(): void {
		wp_reset_query();
		wp_reset_postdata();
		delete_option( MM_META_OPT_SETTINGS );
		delete_option( MM_META_OPT_BUSINESS );
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	private function render_head(): string {
		add_action( 'wp_head', [ $this->emitter, 'render' ], 99 );
		ob_start();
		do_action( 'wp_head' );
		$output = ob_get_clean();
		remove_action( 'wp_head', [ $this->emitter, 'render' ], 99 );
		return $output;
	}

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

	// ------------------------------------------------------------------
	// WebSite node
	// ------------------------------------------------------------------

	public function test_websites_node_emitted_on_homepage(): void {
		$this->go_to( '/' );
		$output    = $this->render_head();
		$json_ld   = $this->extract_json_ld( $output );

		$this->assertNotEmpty( $json_ld, 'Should have JSON-LD output on homepage' );

		// Find the @graph.
		$graph = $json_ld[0]['@graph'] ?? $json_ld[0];
		$types = array_column( $graph, '@type' );
		$this->assertContains( 'WebSite', $types );
	}

	public function test_websites_node_has_name(): void {
		$this->go_to( '/' );
		$output  = $this->render_head();
		$json_ld = $this->extract_json_ld( $output );
		$graph   = $json_ld[0]['@graph'] ?? $json_ld[0];

		$website = null;
		foreach ( $graph as $node ) {
			if ( ( $node['@type'] ?? '' ) === 'WebSite' ) {
				$website = $node;
				break;
			}
		}

		$this->assertNotNull( $website, 'WebSite node should exist' );
		$this->assertNotEmpty( $website['name'] );
	}

	// ------------------------------------------------------------------
	// BreadcrumbList
	// ------------------------------------------------------------------

	public function test_breadcrumb_on_single_post(): void {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'Breadcrumb Test',
			'post_status' => 'publish',
		] );

		$this->go_to( get_permalink( $post_id ) );
		$output  = $this->render_head();
		$json_ld = $this->extract_json_ld( $output );
		$graph   = $json_ld[0]['@graph'] ?? [];

		$has_breadcrumb = false;
		foreach ( $graph as $node ) {
			if ( ( $node['@type'] ?? '' ) === 'BreadcrumbList' ) {
				$has_breadcrumb = true;
				break;
			}
		}

		$this->assertTrue( $has_breadcrumb, 'BreadcrumbList should be emitted on single posts' );
	}

	// ------------------------------------------------------------------
	// WebPage node
	// ------------------------------------------------------------------

	public function test_webpage_node_emitted_on_single_post(): void {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'WebPage Test',
			'post_status' => 'publish',
		] );

		$this->go_to( get_permalink( $post_id ) );
		$output  = $this->render_head();
		$json_ld = $this->extract_json_ld( $output );
		$graph   = $json_ld[0]['@graph'] ?? [];

		$types = array_column( $graph, '@type' );
		$this->assertContains( 'WebPage', $types );
	}

	public function test_website_knowledge_entity_roots_graph_on_website_node(): void {
		update_option( MM_META_OPT_SETTINGS, [ 'schema' => [ 'knowledge_entity' => 'WebSite' ] ] );
		MM_Site_Settings::reset_instance();

		$post_id = $this->factory->post->create( [
			'post_title'  => 'Entity Test',
			'post_status' => 'publish',
		] );
		$this->go_to( get_permalink( $post_id ) );
		$output  = $this->render_head();
		$json_ld = $this->extract_json_ld( $output );

		$ids = array();
		foreach ( $json_ld as $doc ) {
			foreach ( ( $doc['@graph'] ?? array() ) as $n ) {
				if ( isset( $n['@id'] ) ) {
					$ids[] = $n['@id'];
				}
			}
		}
		$website = trailingslashit( home_url() ) . '#website';
		$org     = trailingslashit( home_url() ) . '#organization';

		$this->assertContains( $website, $ids, 'WebSite node must be the graph root' );
		$this->assertNotContains( $org, $ids, 'No organization node for a WebSite knowledge entity' );
		// References to #organization resolve to #website instead.
		$this->assertContains( $website, $ids );
	}

	public function test_tourist_destination_fields_merge_into_its_node(): void {
		MM_Schema_Post_Types::register_post_types();
		$post_id = $this->factory->post->create( [
			'post_type'   => 'mm_destination',
			'post_title'  => 'Destination Fields Test',
			'post_status' => 'publish',
		] );
		update_post_meta( $post_id, 'mm_schema_fields', [
			'destination_address'     => '820 Kiroli Road, West Monroe, LA 71291',
			'destination_lat'         => '32.5',
			'destination_lng'         => '-92.1',
			'destination_attractions' => 'Sunken Gardens, Suspension Bridge',
		] );

		// Core query vars (post_type + p) resolve without rewrite rules or
		// mid-test query-var registration, so the test does not depend on
		// suite order or the state of the rules option.
		$this->go_to( add_query_arg( [ 'post_type' => 'mm_destination', 'p' => $post_id ], home_url( '/' ) ) );
		$output  = $this->render_head();
		$json_ld = $this->extract_json_ld( $output );

		$node = null;
		foreach ( $json_ld as $doc ) {
			foreach ( ( $doc['@graph'] ?? [] ) as $n ) {
				if ( ( $n['@type'] ?? '' ) === 'TouristDestination' ) {
					$node = $n;
					break 2;
				}
			}
		}
		$this->assertNotNull( $node, 'TouristDestination node should be emitted' );
		$this->assertSame( '820 Kiroli Road, West Monroe, LA 71291', $node['address'] ?? '' );
		$this->assertArrayHasKey( 'geo', $node );
		$this->assertCount( 2, $node['includesAttraction'] ?? [] );

		// Place vocabulary compliance: CreativeWork/WebPage-only fields must
		// not land on the TouristDestination node.
		foreach ( [ 'isPartOf', 'breadcrumb', 'datePublished', 'dateModified', 'primaryImageOfPage', 'headline' ] as $field ) {
			$this->assertArrayNotHasKey( $field, $node, "$field is not valid on TouristDestination" );
		}

		// Canonical, stable @id for the Place node.
		$this->assertSame( get_permalink( $post_id ) . '#touristdestination', $node['@id'] ?? '' );

		// The WebPage container links to the Place and back (link-based
		// selection: the suite renders duplicate graphs and only the
		// correctly-typed one carries the link).
		$webpage = null;
		foreach ( $json_ld as $doc ) {
			foreach ( ( $doc['@graph'] ?? [] ) as $n ) {
				if ( ( $n['@type'] ?? '' ) === 'WebPage'
					&& ( $n['mainEntity']['@id'] ?? '' ) === $node['@id'] ) {
					$webpage = $n;
					break 2;
				}
			}
		}
		$this->assertNotNull( $webpage, 'WebPage container node links to the TouristDestination node' );
		$this->assertSame(
			$webpage['@id'] ?? '',
			$node['mainEntityOfPage']['@id'] ?? '',
			'TouristDestination.mainEntityOfPage points back at the WebPage node'
		);
	}

	// ------------------------------------------------------------------
	// Content-type node (BlogPosting)
	// ------------------------------------------------------------------

	public function test_blog_posting_emitted_for_posts(): void {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'Blog Post Test',
			'post_status' => 'publish',
			'post_type'   => 'post',
		] );

		$this->go_to( get_permalink( $post_id ) );
		$output  = $this->render_head();
		$json_ld = $this->extract_json_ld( $output );

		$this->assertNotEmpty( $json_ld, 'Should have JSON-LD output on a post' );
		$graph = $json_ld[0]['@graph'] ?? $json_ld[0];
		$types = array_column( $graph, '@type' );
		$this->assertContains( 'BlogPosting', $types );
	}

	// ------------------------------------------------------------------
	// @context
	// ------------------------------------------------------------------

	public function test_json_ld_has_schema_context(): void {
		$this->go_to( '/' );
		$output  = $this->render_head();
		$json_ld = $this->extract_json_ld( $output );

		$this->assertNotEmpty( $json_ld );
		$this->assertSame( 'https://schema.org', $json_ld[0]['@context'] ?? '' );
	}

	// ------------------------------------------------------------------
	// Custom JSON-LD
	// ------------------------------------------------------------------

	public function test_custom_json_ld_appended(): void {
		$custom = '{"@type":"Organization","name":"Custom Entity"}';
		update_option( MM_META_OPT_SETTINGS, [
			'schema' => [ 'custom_json_ld' => $custom ],
		] );
		MM_Site_Settings::reset_instance();

		$this->settings = MM_Site_Settings::get_instance();
		$this->context  = new MM_Page_Context();
		$this->emitter  = new MM_Head_Emitter( $this->context, $this->settings );
		$this->emitter->add_module( new MM_Mod_Schema( $this->settings ) );

		$this->go_to( '/' );
		$output  = $this->render_head();
		$json_ld = $this->extract_json_ld( $output );
		$graph   = $json_ld[0]['@graph'] ?? [];

		$types = array_column( $graph, '@type' );
		$this->assertContains( 'Organization', $types );
	}
}
