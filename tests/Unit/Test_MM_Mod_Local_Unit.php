<?php
/**
 * Unit tests for MM_Mod_Local — LocalBusiness schema nodes.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Mod_Local_Unit extends WP_UnitTestCase {

	private MM_Site_Settings $settings;
	private MM_Mod_Local $local_module;

	public function set_up(): void {
		parent::set_up();
		MM_Site_Settings::reset_instance();
		$this->settings      = MM_Site_Settings::get_instance();
		$this->local_module  = new MM_Mod_Local( $this->settings );
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// populate() — no business profile (fallback)
	// ------------------------------------------------------------------

	public function test_populate_adds_organization_when_no_name(): void {
		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$nodes = array_filter( $data['schema'], fn( $n ) => ( $n['@type'] ?? '' ) === 'Organization' );
		$this->assertCount( 1, $nodes );

		$org = reset( $nodes );
		$this->assertSame( get_bloginfo( 'name' ), $org['name'] );
		$this->assertArrayHasKey( '@id', $org );
		$this->assertArrayHasKey( 'url', $org );
	}

	// ------------------------------------------------------------------
	// populate() — business profile configured
	// ------------------------------------------------------------------

	public function test_populate_adds_local_business_node(): void {
		$this->settings->save_business( [
			'name' => 'Test Business',
			'type' => 'Restaurant',
		] );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$nodes = array_filter( $data['schema'], fn( $n ) => ( $n['@type'] ?? '' ) === 'Restaurant' );
		$this->assertCount( 1, $nodes );

		$biz = reset( $nodes );
		$this->assertSame( 'Test Business', $biz['name'] );
		$this->assertArrayHasKey( '@id', $biz );
	}

	public function test_populate_falls_back_to_local_business_for_invalid_type(): void {
		$this->settings->save_business( [
			'name' => 'Test Business',
			'type' => 'InvalidType',
		] );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$nodes = array_filter( $data['schema'], fn( $n ) => ( $n['@type'] ?? '' ) === 'LocalBusiness' );
		$this->assertCount( 1, $nodes );
	}

	// ------------------------------------------------------------------
	// populate() — contact info
	// ------------------------------------------------------------------

	public function test_populate_includes_phone(): void {
		$this->settings->save_business( [
			'name'  => 'Phone Business',
			'phone' => '555-123-4567',
		] );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$biz = $this->find_business_node( $data );
		$this->assertNotNull( $biz );
		$this->assertSame( '555-123-4567', $biz['telephone'] );
	}

	public function test_populate_includes_email(): void {
		$this->settings->save_business( [
			'name'  => 'Email Business',
			'email' => 'info@example.com',
		] );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$biz = $this->find_business_node( $data );
		$this->assertNotNull( $biz );
		$this->assertSame( 'info@example.com', $biz['email'] );
	}

	// ------------------------------------------------------------------
	// populate() — address
	// ------------------------------------------------------------------

	public function test_populate_includes_address(): void {
		$this->settings->save_business( [
			'name'    => 'Address Business',
			'address' => [
				'street'  => '123 Main St',
				'city'    => 'Springfield',
				'state'   => 'IL',
				'zip'     => '62704',
				'country' => 'US',
			],
		] );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$biz = $this->find_business_node( $data );
		$this->assertNotNull( $biz );
		$this->assertArrayHasKey( 'address', $biz );
		$this->assertSame( 'PostalAddress', $biz['address']['@type'] );
		$this->assertSame( '123 Main St', $biz['address']['streetAddress'] );
		$this->assertSame( 'Springfield', $biz['address']['addressLocality'] );
		$this->assertSame( 'IL', $biz['address']['addressRegion'] );
		$this->assertSame( '62704', $biz['address']['postalCode'] );
		$this->assertSame( 'US', $biz['address']['addressCountry'] );
	}

	// ------------------------------------------------------------------
	// populate() — geo coordinates
	// ------------------------------------------------------------------

	public function test_populate_includes_geo(): void {
		$this->settings->save_business( [
			'name' => 'Geo Business',
			'lat'  => '40.7128',
			'lng'  => '-74.0060',
		] );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$biz = $this->find_business_node( $data );
		$this->assertNotNull( $biz );
		$this->assertArrayHasKey( 'geo', $biz );
		$this->assertSame( 'GeoCoordinates', $biz['geo']['@type'] );
		$this->assertSame( 40.7128, $biz['geo']['latitude'] );
		$this->assertSame( -74.006, $biz['geo']['longitude'] );
	}

	// ------------------------------------------------------------------
	// populate() — price range
	// ------------------------------------------------------------------

	public function test_populate_includes_price_range(): void {
		$this->settings->save_business( [
			'name'         => 'Price Business',
			'price_range'  => '$$',
		] );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$biz = $this->find_business_node( $data );
		$this->assertNotNull( $biz );
		$this->assertSame( '$$', $biz['priceRange'] );
	}

	// ------------------------------------------------------------------
	// populate() — opening hours
	// ------------------------------------------------------------------

	public function test_populate_includes_opening_hours(): void {
		$this->settings->save_business( [
			'name'  => 'Hours Business',
			'hours' => [
				[
					'days'  => [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ],
					'open'  => '09:00',
					'close' => '17:00',
				],
				[
					'days'  => [ 'Saturday' ],
					'open'  => '10:00',
					'close' => '14:00',
				],
			],
		] );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$biz = $this->find_business_node( $data );
		$this->assertNotNull( $biz );
		$this->assertArrayHasKey( 'openingHoursSpecification', $biz );

		$hours = $biz['openingHoursSpecification'];
		$this->assertCount( 6, $hours ); // 5 weekdays + 1 Saturday
		$this->assertSame( '09:00', $hours[0]['opens'] );
		$this->assertSame( '17:00', $hours[0]['closes'] );
		$this->assertSame( '10:00', $hours[5]['opens'] );
		$this->assertSame( '14:00', $hours[5]['closes'] );
	}

	// ------------------------------------------------------------------
	// populate() — service areas
	// ------------------------------------------------------------------

	public function test_populate_includes_service_areas(): void {
		$this->settings->save_business( [
			'name'           => 'Service Business',
			'service_areas'  => [ 'Springfield', 'Shelbyville', 'Capital City' ],
		] );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$biz = $this->find_business_node( $data );
		$this->assertNotNull( $biz );
		$this->assertArrayHasKey( 'areaServed', $biz );
		$this->assertCount( 3, $biz['areaServed'] );
		$this->assertSame( 'Springfield', $biz['areaServed'][0]['name'] );
	}

	// ------------------------------------------------------------------
	// populate() — social profiles (sameAs)
	// ------------------------------------------------------------------

	public function test_populate_includes_social_profiles(): void {
		$this->settings->save_business( [
			'name' => 'Social Business',
		] );
		$this->settings->save_settings( [
			'social' => [
				'accounts' => [
					'facebook'  => 'https://facebook.com/testbusiness',
					'twitter'   => 'https://twitter.com/testbusiness',
					'instagram' => 'https://instagram.com/testbusiness',
				],
			],
		] );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$biz = $this->find_business_node( $data );
		$this->assertNotNull( $biz );
		$this->assertArrayHasKey( 'sameAs', $biz );
		$this->assertCount( 3, $biz['sameAs'] );
		$this->assertContains( 'https://facebook.com/testbusiness', $biz['sameAs'] );
		$this->assertContains( 'https://twitter.com/testbusiness', $biz['sameAs'] );
		$this->assertContains( 'https://instagram.com/testbusiness', $biz['sameAs'] );
	}

	// ------------------------------------------------------------------
	// populate() — payment accepted
	// ------------------------------------------------------------------

	public function test_populate_includes_payment_accepted(): void {
		$this->settings->save_business( [
			'name'              => 'Payment Business',
			'payment_accepted'  => [ 'Cash', 'Credit Card', 'Check' ],
		] );

		$this->go_to( '/' );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$biz = $this->find_business_node( $data );
		$this->assertNotNull( $biz );
		$this->assertStringContainsString( 'Cash', $biz['paymentAccepted'] );
		$this->assertStringContainsString( 'Credit Card', $biz['paymentAccepted'] );
		$this->assertStringContainsString( 'Check', $biz['paymentAccepted'] );
	}

	// ------------------------------------------------------------------
	// populate() — on non-front page
	// ------------------------------------------------------------------

	public function test_populate_adds_node_on_singular_post(): void {
		$this->settings->save_business( [
			'name' => 'Singular Business',
		] );

		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $post_id ) );

		$context = new MM_Page_Context();
		$data    = $this->empty_data();

		$this->local_module->populate( $data, $context, $this->settings );

		$biz = $this->find_business_node( $data );
		$this->assertNotNull( $biz );
		$this->assertSame( 'Singular Business', $biz['name'] );
	}

	// ------------------------------------------------------------------
	// get_business_types()
	// ------------------------------------------------------------------

	public function test_get_business_types_returns_grouped_types(): void {
		$types = MM_Mod_Local::get_business_types();

		$this->assertIsArray( $types );
		$this->assertArrayHasKey( 'General', $types );
		$this->assertArrayHasKey( 'Food & Dining', $types );
		$this->assertArrayHasKey( 'Restaurant', $types['Food & Dining'] );
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

	private function find_business_node( array $data ): ?array {
		foreach ( $data['schema'] as $node ) {
			$type = $node['@type'] ?? '';
			if ( in_array( $type, [ 'LocalBusiness', 'Organization', 'Restaurant', 'Hotel', 'Store' ], true ) ) {
				return $node;
			}
		}
		return null;
	}
}