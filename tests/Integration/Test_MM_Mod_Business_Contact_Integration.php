<?php
/**
 * Integration tests for MM_Mod_Business_Contact — vCard, JSON, CSV generation.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Mod_Business_Contact_Integration extends WP_UnitTestCase {

	private MM_Site_Settings $settings;

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		$this->settings = MM_Site_Settings::get_instance();
	}

	public function tear_down(): void {
		delete_option( MM_META_OPT_BUSINESS );
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// render_card()
	// ------------------------------------------------------------------

	public function test_render_card_empty_when_no_business_name(): void {
		update_option( MM_META_OPT_BUSINESS, [] );
		MM_Site_Settings::reset_instance();
		$this->settings = MM_Site_Settings::get_instance();

		$module = new MM_Mod_Business_Contact( $this->settings );
		$output = $module->render_card();

		$this->assertSame( '', $output );
	}

	public function test_render_card_contains_business_name(): void {
		update_option( MM_META_OPT_BUSINESS, [
			'name'  => 'Test Business',
			'phone' => '+1-555-0100',
		] );
		MM_Site_Settings::reset_instance();
		$this->settings = MM_Site_Settings::get_instance();

		$module = new MM_Mod_Business_Contact( $this->settings );
		$output = $module->render_card();

		$this->assertStringContainsString( 'Test Business', $output );
	}

	public function test_render_card_contains_schema_json_ld(): void {
		update_option( MM_META_OPT_BUSINESS, [
			'name'  => 'Schema Business',
			'phone' => '+1-555-0100',
		] );
		MM_Site_Settings::reset_instance();
		$this->settings = MM_Site_Settings::get_instance();

		$module = new MM_Mod_Business_Contact( $this->settings );
		$output = $module->render_card();

		$this->assertStringContainsString( 'application/ld+json', $output );
		$this->assertStringContainsString( 'LocalBusiness', $output );
	}

	public function test_render_card_contains_phone_link(): void {
		update_option( MM_META_OPT_BUSINESS, [
			'name'  => 'Phone Business',
			'phone' => '+1-555-0100',
		] );
		update_option( MM_Mod_Business_Contact::OPT_STYLE, [
			'show_phone' => true,
		] );
		MM_Site_Settings::reset_instance();
		$this->settings = MM_Site_Settings::get_instance();

		$module = new MM_Mod_Business_Contact( $this->settings );
		$output = $module->render_card();

		$this->assertStringContainsString( 'tel:+1-555-0100', $output );

		delete_option( MM_Mod_Business_Contact::OPT_STYLE );
	}

	public function test_render_card_hides_phone_when_disabled(): void {
		update_option( MM_META_OPT_BUSINESS, [
			'name'  => 'No Phone Business',
			'phone' => '+1-555-0100',
		] );
		update_option( MM_Mod_Business_Contact::OPT_STYLE, [
			'show_phone' => false,
		] );
		MM_Site_Settings::reset_instance();
		$this->settings = MM_Site_Settings::get_instance();

		$module = new MM_Mod_Business_Contact( $this->settings );
		$output = $module->render_card();

		$this->assertStringNotContainsString( 'tel:', $output );

		delete_option( MM_Mod_Business_Contact::OPT_STYLE );
	}

	// ------------------------------------------------------------------
	// build_schema() — via render_card()
	// ------------------------------------------------------------------

	public function test_schema_includes_address_when_present(): void {
		update_option( MM_META_OPT_BUSINESS, [
			'name'    => 'Address Business',
			'address' => [
				'street'  => '123 Main St',
				'city'    => 'Portland',
				'state'   => 'OR',
				'zip'     => '97201',
				'country' => 'US',
			],
		] );
		MM_Site_Settings::reset_instance();
		$this->settings = MM_Site_Settings::get_instance();

		$module = new MM_Mod_Business_Contact( $this->settings );
		$output = $module->render_card();

		$this->assertStringContainsString( '123 Main St', $output );
		$this->assertStringContainsString( 'Portland', $output );
	}

	public function test_schema_includes_geo_when_coordinates_present(): void {
		update_option( MM_META_OPT_BUSINESS, [
			'name' => 'Geo Business',
			'lat'  => '45.5152',
			'lng'  => '-122.6784',
		] );
		MM_Site_Settings::reset_instance();
		$this->settings = MM_Site_Settings::get_instance();

		$module = new MM_Mod_Business_Contact( $this->settings );
		$output = $module->render_card();

		$this->assertStringContainsString( 'GeoCoordinates', $output );
	}
}
