<?php
/**
 * Unit tests for the Site Assistant page (AI phase A4).
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_AI_Page_Unit extends WP_UnitTestCase {

	public function test_page_renders_the_assistant_shell(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		MM_AI_Page::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'mm-ai-log', $html );
		$this->assertStringContainsString( 'mm-ai-cards', $html );
		$this->assertStringContainsString( 'mm-ai-audit', $html );
		$this->assertStringContainsString( 'Site Assistant', $html );
		$this->assertStringContainsString( 'X-WP-Nonce', $html );
		// The safety model is stated on the page itself.
		$this->assertStringContainsString( 'typed confirmation', $html );
	}

	public function test_menu_is_manage_options_under_metamanager(): void {
		// add_submenu_page checks the capability at registration —
		// the registering user must hold it.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		global $submenu;
		$submenu = array();
		MM_AI_Page::add_menu();
		$found = false;
		foreach ( $submenu['metamanager'] ?? array() as $entry ) {
			if ( 'mm-site-assistant' === $entry[2] ) {
				$found = true;
				$this->assertSame( 'manage_options', $entry[1] );
			}
		}
		$this->assertTrue( $found, 'Site Assistant must register under the Metamanager menu' );
	}

	public function test_site_assistant_help_tab_instructs_the_model(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$screen = WP_Screen::get( 'metamanager_page_mm-site-assistant' );
		MM_Admin::add_help_tabs( $screen );
		$tabs = $screen->get_help_tabs();
		$ids  = wp_list_pluck( $tabs, 'id' );
		$this->assertContains( 'mm-ai-overview', $ids );
		$this->assertContains( 'mm-ai-provider', $ids );
		$joined = wp_list_pluck( $tabs, 'content' );
		$this->assertStringContainsString( 'approval cards', implode( ' ', $joined ) );
		$this->assertStringContainsString( 'connectors_ai_google_api_key', implode( ' ', $joined ) );
	}
}
