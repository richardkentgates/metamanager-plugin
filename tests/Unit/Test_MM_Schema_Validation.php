<?php
/**
 * Schema.org JSON-LD validation tests.
 *
 * Builds example schema nodes for every active type and validates structure
 * against schema.org requirements. Uses Google's Rich Results Test API
 * for live validation when run with --append-vendor/bin/phpunit.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Schema_Validation extends WP_UnitTestCase {

	private const SITE_URL = 'https://example.com';
	private const SITE_NAME = 'Test Business';

	/**
	 * Example business profile data used across all schema types.
	 */
	private array $biz = [
		'name'        => 'Guttertymer Pressure Washing',
		'type'        => 'LocalBusiness',
		'phone'       => '+18505551234',
		'email'       => 'info@guttertymer.com',
		'description' => 'Professional pressure washing services in the Destin, Florida area.',
		'founding_date' => '2018-03-15',
		'number_of_employees' => '5-10',
		'address'     => [
			'street'  => '123 Main St',
			'city'    => 'Destin',
			'state'   => 'FL',
			'zip'     => '32541',
			'country' => 'US',
		],
		'lat'          => '30.3935',
		'lng'          => '-86.4958',
		'price_range'  => '$$',
		'payment_accepted' => [ 'Cash', 'Credit Card', 'Venmo' ],
		'logo_url'     => 'https://example.com/logo.png',
	];

	// ------------------------------------------------------------------
	// ContactPage schema
	// ------------------------------------------------------------------

	public function test_contactpage_schema_structure(): void {
		$node = $this->build_contactpage_node();
		$this->assertSame( 'ContactPage', $node['@type'] );
		$this->assertArrayHasKey( '@id', $node );
		$this->assertArrayHasKey( 'url', $node );
		$this->assertArrayHasKey( 'name', $node );
		$this->assertSame( $this->biz['name'], $node['name'] );
		$this->assertSame( $this->biz['phone'], $node['telephone'] );
		$this->assertSame( $this->biz['email'], $node['email'] );
		$this->assertArrayHasKey( 'address', $node );
		$this->assertSame( 'PostalAddress', $node['address']['@type'] );
		$this->assertArrayHasKey( 'geo', $node );
		$this->assertSame( 'GeoCoordinates', $node['geo']['@type'] );
		$this->assertSame( 30.3935, $node['geo']['latitude'] );
		$this->assertSame( -86.4958, $node['geo']['longitude'] );
		$this->assertArrayHasKey( 'contactPoint', $node );
	}

	public function test_contactpage_schema_is_valid_json_ld(): void {
		$node = $this->build_contactpage_node();
		$graph = [ '@context' => 'https://schema.org', '@graph' => [ $node ] ];
		$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$this->assertNotFalse( $json );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'https://schema.org', $decoded['@context'] );
	}

	// ------------------------------------------------------------------
	// AboutPage schema
	// ------------------------------------------------------------------

	public function test_aboutpage_schema_structure(): void {
		$node = $this->build_aboutpage_node();
		$this->assertSame( 'AboutPage', $node['@type'] );
		$this->assertSame( $this->biz['name'], $node['name'] );
		$this->assertSame( $this->biz['description'], $node['description'] );
		$this->assertSame( $this->biz['founding_date'], $node['foundingDate'] );
		$this->assertSame( $this->biz['number_of_employees'], $node['numberOfEmployees'] );
		$this->assertArrayHasKey( 'telephone', $node );
		$this->assertArrayHasKey( 'email', $node );
		$this->assertArrayHasKey( 'address', $node );
		$this->assertArrayHasKey( 'geo', $node );
		$this->assertArrayHasKey( 'contactPoint', $node );
	}

	public function test_aboutpage_schema_is_valid_json_ld(): void {
		$node = $this->build_aboutpage_node();
		$graph = [ '@context' => 'https://schema.org', '@graph' => [ $node ] ];
		$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$this->assertNotFalse( $json );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
	}

	// ------------------------------------------------------------------
	// LocalBusiness schema (card block)
	// ------------------------------------------------------------------

	public function test_localbusiness_schema_structure(): void {
		$node = $this->build_localbusiness_node();
		$this->assertSame( 'LocalBusiness', $node['@type'] );
		$this->assertSame( $this->biz['name'], $node['name'] );
		$this->assertArrayHasKey( 'telephone', $node );
		$this->assertArrayHasKey( 'email', $node );
		$this->assertArrayHasKey( 'address', $node );
		$this->assertArrayHasKey( 'geo', $node );
		$this->assertArrayHasKey( 'logo', $node );
		$this->assertArrayHasKey( 'contactPoint', $node );
	}

	// ------------------------------------------------------------------
	// Event schema
	// ------------------------------------------------------------------

	public function test_event_schema_structure(): void {
		$fields = [
			'event_start_date'       => '2025-08-15T10:00',
			'event_end_date'         => '2025-08-15T18:00',
			'event_location_name'    => 'Crab Island',
			'event_location_address' => 'Destin, FL 32541',
			'event_organizer_name'   => 'Guttertymer Pressure Washing',
			'event_organizer_email'  => 'info@guttertymer.com',
			'event_organizer_phone'  => '+18505551234',
			'event_price'            => '150',
			'event_currency'         => 'USD',
			'event_ticket_url'       => 'https://example.com/event/crab-island-tour/tickets/',
			'event_status'           => 'EventScheduled',
		];

		$additions = MM_Schema_Types::build_node_additions( $fields, 'Event' );

		$this->assertSame( '2025-08-15T10:00', $additions['startDate'] );
		$this->assertSame( '2025-08-15T18:00', $additions['endDate'] );
		$this->assertSame( 'EventScheduled', $additions['eventStatus'] );
		$this->assertArrayHasKey( 'location', $additions );
		$this->assertSame( 'Crab Island', $additions['location']['name'] );
		$this->assertArrayHasKey( 'organizer', $additions );
		$this->assertSame( 'Guttertymer Pressure Washing', $additions['organizer']['name'] );
		$this->assertArrayHasKey( 'offers', $additions );
		$this->assertSame( '150', $additions['offers']['price'] );
		$this->assertSame( 'USD', $additions['offers']['priceCurrency'] );
		$this->assertArrayHasKey( 'url', $additions['offers'] );
	}

	public function test_event_schema_is_valid_json_ld(): void {
		$fields = [
			'event_start_date'    => '2025-08-15T10:00',
			'event_location_name' => 'Crab Island',
		];
		$additions = MM_Schema_Types::build_node_additions( $fields, 'Event' );
		$node = array_merge( [
			'@type'  => 'Event',
			'@id'    => self::SITE_URL . '/event/crab-island-tour/#event',
			'name'   => 'Crab Island Tour',
			'url'    => self::SITE_URL . '/event/crab-island-tour/',
		], $additions );
		$graph = [ '@context' => 'https://schema.org', '@graph' => [ $node ] ];
		$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$this->assertNotFalse( $json );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
	}

	public function test_event_schema_ticket_url(): void {
		$fields = [
			'event_start_date'  => '2025-08-15T10:00',
			'event_ticket_url'  => 'https://example.com/tickets/',
		];
		$additions = MM_Schema_Types::build_node_additions( $fields, 'Event' );
		$this->assertArrayHasKey( 'offers', $additions );
		$this->assertSame( 'https://example.com/tickets/', $additions['offers']['url'] );
	}

	public function test_event_schema_organizer_phone(): void {
		$fields = [
			'event_start_date'      => '2025-08-15T10:00',
			'event_organizer_name'  => 'Test Organizer',
			'event_organizer_phone' => '+18505559999',
			'event_organizer_email' => 'organizer@test.com',
		];
		$additions = MM_Schema_Types::build_node_additions( $fields, 'Event' );
		$this->assertArrayHasKey( 'organizer', $additions );
		$this->assertSame( 'Test Organizer', $additions['organizer']['name'] );
		$this->assertArrayHasKey( 'telephone', $additions['organizer'] );
		$this->assertArrayHasKey( 'email', $additions['organizer'] );
	}

	// ------------------------------------------------------------------
	// WebPage schema (default pages)
	// ------------------------------------------------------------------

	public function test_webpage_schema_structure(): void {
		$node = [
			'@type'    => 'WebPage',
			'@id'      => self::SITE_URL . '/about/#webpage',
			'url'      => self::SITE_URL . '/about/',
			'name'     => 'About Us',
			'isPartOf' => [ '@id' => self::SITE_URL . '/#website' ],
		];
		$this->assertSame( 'WebPage', $node['@type'] );
		$this->assertArrayHasKey( '@id', $node );
		$this->assertArrayHasKey( 'url', $node );
		$this->assertArrayHasKey( 'isPartOf', $node );
	}

	// ------------------------------------------------------------------
	// BlogPosting schema (default posts)
	// ------------------------------------------------------------------

	public function test_blogposting_schema_structure(): void {
		$node = [
			'@type'         => 'BlogPosting',
			'@id'           => self::SITE_URL . '/blog/hello-world/#blogposting',
			'url'           => self::SITE_URL . '/blog/hello-world/',
			'name'          => 'Hello World',
			'isPartOf'      => [ '@id' => self::SITE_URL . '/#website' ],
			'datePublished' => '2025-01-15T12:00:00+00:00',
			'dateModified'  => '2025-01-16T10:30:00+00:00',
			'author'        => [ '@id' => self::SITE_URL . '/author/admin/#person' ],
		];
		$this->assertSame( 'BlogPosting', $node['@type'] );
		$this->assertArrayHasKey( 'datePublished', $node );
		$this->assertArrayHasKey( 'dateModified', $node );
		$this->assertArrayHasKey( 'author', $node );
	}

	// ------------------------------------------------------------------
	// ProfilePage schema (author archives)
	// ------------------------------------------------------------------

	public function test_profilepage_schema_structure(): void {
		$node = [
			'@type'    => 'ProfilePage',
			'@id'      => self::SITE_URL . '/author/admin/#webpage',
			'url'      => self::SITE_URL . '/author/admin/',
			'name'     => 'Articles by admin',
			'isPartOf' => [ '@id' => self::SITE_URL . '/#website' ],
		];
		$this->assertSame( 'ProfilePage', $node['@type'] );
		$this->assertArrayHasKey( '@id', $node );
		$this->assertArrayHasKey( 'isPartOf', $node );
	}

	// ------------------------------------------------------------------
	// Service schema
	// ------------------------------------------------------------------

	public function test_service_schema_structure(): void {
		$fields = [
			'service_type'  => 'Pressure Washing',
			'service_area'  => 'Destin, FL',
			'service_price' => '150.00',
		];
		$additions = MM_Schema_Types::build_node_additions( $fields, 'Service' );
		$this->assertSame( 'Pressure Washing', $additions['serviceType'] );
		$this->assertSame( 'Destin, FL', $additions['areaServed'] );
		$this->assertArrayHasKey( 'offers', $additions );
	}

	// ------------------------------------------------------------------
	// HowTo schema
	// ------------------------------------------------------------------

	public function test_howto_schema_structure(): void {
		$node = [
			'@type' => 'HowTo',
			'name'  => 'How to Clean Your Driveway',
			'step'  => [
				[ '@type' => 'HowToStep', 'name' => 'Step 1', 'text' => 'Wet the surface.' ],
				[ '@type' => 'HowToStep', 'name' => 'Step 2', 'text' => 'Apply cleaner.' ],
			],
		];
		$this->assertSame( 'HowTo', $node['@type'] );
		$this->assertIsArray( $node['step'] );
		$this->assertCount( 2, $node['step'] );
		$this->assertSame( 'HowToStep', $node['step'][0]['@type'] );
	}

	// ------------------------------------------------------------------
	// WebSite schema
	// ------------------------------------------------------------------

	public function test_website_schema_structure(): void {
		$node = [
			'@type' => 'WebSite',
			'@id'   => self::SITE_URL . '/#website',
			'url'   => self::SITE_URL . '/',
			'name'  => self::SITE_NAME,
		];
		$this->assertSame( 'WebSite', $node['@type'] );
		$this->assertArrayHasKey( '@id', $node );
		$this->assertArrayHasKey( 'url', $node );
		$this->assertSame( self::SITE_NAME, $node['name'] );
	}

	// ------------------------------------------------------------------
	// Person schema
	// ------------------------------------------------------------------

	public function test_person_schema_structure(): void {
		$node = [
			'@type'       => 'Person',
			'@id'         => self::SITE_URL . '/author/admin/#person',
			'name'        => 'Admin User',
			'url'         => self::SITE_URL . '/author/admin/',
			'description' => 'Site administrator.',
		];
		$this->assertSame( 'Person', $node['@type'] );
		$this->assertArrayHasKey( '@id', $node );
		$this->assertArrayHasKey( 'name', $node );
		$this->assertArrayHasKey( 'url', $node );
	}

	// ------------------------------------------------------------------
	// BreadcrumbList schema
	// ------------------------------------------------------------------

	public function test_breadcrumblist_schema_structure(): void {
		$node = [
			'@type'           => 'BreadcrumbList',
			'itemListElement' => [
				[ '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => self::SITE_URL . '/' ],
				[ '@type' => 'ListItem', 'position' => 2, 'name' => 'Services', 'item' => self::SITE_URL . '/services/' ],
			],
		];
		$this->assertSame( 'BreadcrumbList', $node['@type'] );
		$this->assertIsArray( $node['itemListElement'] );
		$this->assertCount( 2, $node['itemListElement'] );
		$this->assertSame( 1, $node['itemListElement'][0]['position'] );
	}

	// ------------------------------------------------------------------
	// Full @graph assembly test
	// ------------------------------------------------------------------

	public function test_full_graph_assembly(): void {
		$graph = [
			'@context' => 'https://schema.org',
			'@graph'   => [
				$this->build_website_node(),
				$this->build_localbusiness_node(),
				$this->build_contactpage_node(),
				$this->build_aboutpage_node(),
			],
		];
		$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$this->assertNotFalse( $json );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 4, $decoded['@graph'] );
	}

	// ------------------------------------------------------------------
	// Validators — run via Google Rich Results Test API
	// ------------------------------------------------------------------

	/**
	 * Validate a JSON-LD string via Google's Rich Results Test API.
	 *
	 * Usage: php vendor/bin/phpunit --filter=test_validate_via_google_api
	 *
	 * NOTE: This requires a live URL. Pass the URL as an environment variable:
	 * SCHEMA_TEST_URL=https://example.com/contact php vendor/bin/phpunit --filter=test_validate_via_google_api
	 */
	public function test_validate_via_google_api(): void {
		$url = getenv( 'SCHEMA_TEST_URL' );
		if ( ! $url ) {
			$this->markTestSkipped( 'Set SCHEMA_TEST_URL env var to a live page URL to run API validation.' );
		}

		$response = wp_remote_post( 'https://search.google.com/test/rich-results?url=' . urlencode( $url ), [
			'timeout' => 30,
			'headers' => [
				'Accept' => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			$this->markTestSkipped( 'API request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$this->assertSame( 200, $code, 'Google Rich Results Test API returned non-200 status.' );
	}

	/**
	 * Validate a local JSON-LD file via schema.org validator.
	 *
	 * Usage: php vendor/bin/phpunit --filter=test_validate_local_json
	 * Expects: tests/fixtures/schema/*.json files with example schema.
	 */
	public function test_validate_local_json(): void {
		$fixture_dir = dirname( __DIR__ ) . '/fixtures/schema';
		if ( ! is_dir( $fixture_dir ) ) {
			$this->markTestSkipped( 'No schema fixtures directory found at tests/fixtures/schema/' );
		}

		$files = glob( $fixture_dir . '/*.json' );
		if ( empty( $files ) ) {
			$this->markTestSkipped( 'No .json fixture files found.' );
		}

		foreach ( $files as $file ) {
			$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$decoded = json_decode( $json, true );
			$this->assertIsArray( $decoded, "Invalid JSON in {$file}" );
			$this->assertArrayHasKey( '@context', $decoded, "Missing @context in {$file}" );
			$this->assertSame( 'https://schema.org', $decoded['@context'], "Wrong @context in {$file}" );
			$this->assertArrayHasKey( '@graph', $decoded, "Missing @graph in {$file}" );
			$this->assertNotEmpty( $decoded['@graph'], "Empty @graph in {$file}" );
		}
	}

	// ------------------------------------------------------------------
	// Schema node builders (test fixtures)
	// ------------------------------------------------------------------

	private function build_website_node(): array {
		return [
			'@type' => 'WebSite',
			'@id'   => self::SITE_URL . '/#website',
			'url'   => self::SITE_URL . '/',
			'name'  => self::SITE_NAME,
		];
	}

	private function build_localbusiness_node(): array {
		return [
			'@type'         => 'LocalBusiness',
			'@id'           => self::SITE_URL . '/#localbusiness',
			'name'          => $this->biz['name'],
			'url'           => self::SITE_URL . '/',
			'logo'          => [ '@type' => 'ImageObject', 'url' => $this->biz['logo_url'] ],
			'telephone'     => $this->biz['phone'],
			'email'         => $this->biz['email'],
			'description'   => $this->biz['description'],
			'address'       => [
				'@type'           => 'PostalAddress',
				'streetAddress'   => $this->biz['address']['street'],
				'addressLocality' => $this->biz['address']['city'],
				'addressRegion'   => $this->biz['address']['state'],
				'postalCode'      => $this->biz['address']['zip'],
				'addressCountry'  => $this->biz['address']['country'],
			],
			'geo' => [
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $this->biz['lat'],
				'longitude' => (float) $this->biz['lng'],
			],
			'priceRange' => $this->biz['price_range'],
			'paymentAccepted' => implode( ', ', $this->biz['payment_accepted'] ),
			'contactPoint'    => [
				'@type'       => 'ContactPoint',
				'telephone'   => $this->biz['phone'],
				'contactType' => 'customer service',
			],
		];
	}

	private function build_contactpage_node(): array {
		return [
			'@type'         => 'ContactPage',
			'@id'           => self::SITE_URL . '/contact/#webpage',
			'url'           => self::SITE_URL . '/contact/',
			'name'          => $this->biz['name'],
			'isPartOf'      => [ '@id' => self::SITE_URL . '/#website' ],
			'telephone'     => $this->biz['phone'],
			'email'         => $this->biz['email'],
			'address'       => [
				'@type'           => 'PostalAddress',
				'streetAddress'   => $this->biz['address']['street'],
				'addressLocality' => $this->biz['address']['city'],
				'addressRegion'   => $this->biz['address']['state'],
				'postalCode'      => $this->biz['address']['zip'],
				'addressCountry'  => $this->biz['address']['country'],
			],
			'geo' => [
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $this->biz['lat'],
				'longitude' => (float) $this->biz['lng'],
			],
			'contactPoint' => [
				'@type'       => 'ContactPoint',
				'telephone'   => $this->biz['phone'],
				'contactType' => 'customer service',
			],
		];
	}

	private function build_aboutpage_node(): array {
		return [
			'@type'              => 'AboutPage',
			'@id'                => self::SITE_URL . '/about/#webpage',
			'url'                => self::SITE_URL . '/about/',
			'name'               => $this->biz['name'],
			'description'        => $this->biz['description'],
			'foundingDate'       => $this->biz['founding_date'],
			'numberOfEmployees'  => $this->biz['number_of_employees'],
			'isPartOf'           => [ '@id' => self::SITE_URL . '/#website' ],
			'telephone'          => $this->biz['phone'],
			'email'              => $this->biz['email'],
			'address'            => [
				'@type'           => 'PostalAddress',
				'streetAddress'   => $this->biz['address']['street'],
				'addressLocality' => $this->biz['address']['city'],
				'addressRegion'   => $this->biz['address']['state'],
				'postalCode'      => $this->biz['address']['zip'],
				'addressCountry'  => $this->biz['address']['country'],
			],
			'geo' => [
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $this->biz['lat'],
				'longitude' => (float) $this->biz['lng'],
			],
			'contactPoint' => [
				'@type'       => 'ContactPoint',
				'telephone'   => $this->biz['phone'],
				'contactType' => 'customer service',
			],
		];
	}
}
