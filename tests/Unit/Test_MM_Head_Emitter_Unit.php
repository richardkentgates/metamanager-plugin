<?php
/**
 * Unit tests for MM_Head_Emitter — output orchestration.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Head_Emitter_Unit extends WP_UnitTestCase {

	private MM_Site_Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = MM_Site_Settings::get_instance();
	}

	// ------------------------------------------------------------------
	// Constructor and module registration
	// ------------------------------------------------------------------

	public function test_constructor_accepts_context_and_settings(): void {
		$context = new MM_Page_Context();
		$emitter = new MM_Head_Emitter( $context, $this->settings );
		$this->assertInstanceOf( MM_Head_Emitter::class, $emitter );
	}

	public function test_add_module_accepts_module(): void {
		$context = new MM_Page_Context();
		$emitter = new MM_Head_Emitter( $context, $this->settings );
		$module  = new MM_Mod_Head_Meta( $this->settings );
		$emitter->add_module( $module );
		// No assertion needed — just verify no exception thrown.
		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// render() output
	// ------------------------------------------------------------------

	public function test_render_outputs_version_comment(): void {
		$context = new MM_Page_Context();
		$emitter = new MM_Head_Emitter( $context, $this->settings );

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<!-- Metamanager', $output );
		$this->assertStringContainsString( '<!-- /Metamanager -->', $output );
	}

	public function test_render_outputs_meta_description(): void {
		$context = new MM_Page_Context();
		$emitter = new MM_Head_Emitter( $context, $this->settings );

		// Add a module that writes a meta tag.
		$module = $this->createMock( MM_Mod_Base::class );
		$module->method( 'populate' )->willReturnCallback( function ( array &$data ) {
			$data['meta'][] = [ 'name' => 'description', 'content' => 'Test description' ];
		} );
		$emitter->add_module( $module );

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<meta name="description" content="Test description"', $output );
	}

	public function test_render_outputs_meta_og_property(): void {
		$context = new MM_Page_Context();
		$emitter = new MM_Head_Emitter( $context, $this->settings );

		$module = $this->createMock( MM_Mod_Base::class );
		$module->method( 'populate' )->willReturnCallback( function ( array &$data ) {
			$data['meta'][] = [ 'property' => 'og:title', 'content' => 'OG Title' ];
		} );
		$emitter->add_module( $module );

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<meta property="og:title" content="OG Title"', $output );
	}

	public function test_render_outputs_link_tags(): void {
		$context = new MM_Page_Context();
		$emitter = new MM_Head_Emitter( $context, $this->settings );

		$module = $this->createMock( MM_Mod_Base::class );
		$module->method( 'populate' )->willReturnCallback( function ( array &$data ) {
			$data['links'][] = [ 'rel' => 'canonical', 'href' => 'https://example.com/' ];
		} );
		$emitter->add_module( $module );

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<link rel="canonical"', $output );
		$this->assertStringContainsString( 'https://example.com/', $output );
	}

	public function test_render_outputs_json_ld_schema(): void {
		$context = new MM_Page_Context();
		$emitter = new MM_Head_Emitter( $context, $this->settings );

		$module = $this->createMock( MM_Mod_Base::class );
		$module->method( 'populate' )->willReturnCallback( function ( array &$data ) {
			$data['schema'][] = [
				'@type' => 'WebSite',
				'@id'   => 'https://example.com/#website',
				'name'  => 'Test Site',
			];
		} );
		$emitter->add_module( $module );

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<script type="application/ld+json">', $output );
		$this->assertStringContainsString( '"@type": "WebSite"', $output );
		$this->assertStringContainsString( '"name": "Test Site"', $output );
		$this->assertStringContainsString( '"@context": "https://schema.org"', $output );
	}

	public function test_render_no_schema_when_empty(): void {
		$context = new MM_Page_Context();
		$emitter = new MM_Head_Emitter( $context, $this->settings );

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<script type="application/ld+json">', $output );
	}

	public function test_render_escapes_meta_attributes(): void {
		$context = new MM_Page_Context();
		$emitter = new MM_Head_Emitter( $context, $this->settings );

		$module = $this->createMock( MM_Mod_Base::class );
		$module->method( 'populate' )->willReturnCallback( function ( array &$data ) {
			$data['meta'][] = [ 'name' => 'description', 'content' => 'Test "quotes" & <tags>' ];
		} );
		$emitter->add_module( $module );

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		// esc_attr should encode quotes and ampersands.
		$this->assertStringContainsString( 'Test &quot;quotes&quot; &amp; &lt;tags&gt;', $output );
	}

	// ------------------------------------------------------------------
	// mm_meta_document filter
	// ------------------------------------------------------------------

	public function test_render_applies_mm_meta_document_filter(): void {
		$context = new MM_Page_Context();
		$emitter = new MM_Head_Emitter( $context, $this->settings );

		add_filter( 'mm_meta_document', function ( array $data ): array {
			$data['meta'][] = [ 'name' => 'robots', 'content' => 'noindex' ];
			return $data;
		} );

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<meta name="robots" content="noindex"', $output );

		remove_filter( 'mm_meta_document', '__return_true' );
	}

	// ------------------------------------------------------------------
	// Multiple modules
	// ------------------------------------------------------------------

	public function test_render_calls_multiple_modules(): void {
		$context = new MM_Page_Context();
		$emitter = new MM_Head_Emitter( $context, $this->settings );

		$module1 = $this->createMock( MM_Mod_Base::class );
		$module1->method( 'populate' )->willReturnCallback( function ( array &$data ) {
			$data['meta'][] = [ 'name' => 'description', 'content' => 'Module 1' ];
		} );

		$module2 = $this->createMock( MM_Mod_Base::class );
		$module2->method( 'populate' )->willReturnCallback( function ( array &$data ) {
			$data['meta'][] = [ 'name' => 'robots', 'content' => 'noindex' ];
		} );

		$emitter->add_module( $module1 );
		$emitter->add_module( $module2 );

		ob_start();
		$emitter->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Module 1', $output );
		$this->assertStringContainsString( 'noindex', $output );
	}
}
