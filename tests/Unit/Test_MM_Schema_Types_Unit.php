<?php
/**
 * Unit tests for MM_Schema_Types.
 *
 * Pure logic tests — no database required.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Schema_Types_Unit extends WP_UnitTestCase {

	// ------------------------------------------------------------------
	// get_schema_types()
	// ------------------------------------------------------------------

	public function test_get_schema_types_returns_array(): void {
		$types = MM_Schema_Types::get_schema_types();
		$this->assertIsArray( $types );
		$this->assertNotEmpty( $types );
	}

	public function test_get_schema_types_has_required_types(): void {
		$types = MM_Schema_Types::get_schema_types();
		$expected = [ 'WebPage', 'BlogPosting', 'Product', 'Event', 'Service' ];
		foreach ( $expected as $type ) {
			$this->assertArrayHasKey( $type, $types, "Schema type {$type} should exist" );
		}
	}

	public function test_get_schema_types_include_empty_prepends_empty_key(): void {
		$types = MM_Schema_Types::get_schema_types( true );
		$keys  = array_keys( $types );
		$this->assertSame( '', $keys[0] );
	}

	public function test_get_schema_types_exclude_empty_by_default(): void {
		$types = MM_Schema_Types::get_schema_types( false );
		$keys  = array_keys( $types );
		$this->assertNotContains( '', $keys );
	}

	// ------------------------------------------------------------------
	// get_fields_by_type()
	// ------------------------------------------------------------------

	public function test_get_fields_by_type_returns_array(): void {
		$fields = MM_Schema_Types::get_fields_by_type();
		$this->assertIsArray( $fields );
	}

	public function test_event_type_has_start_date_field(): void {
		$fields = MM_Schema_Types::get_fields_by_type();
		$this->assertArrayHasKey( 'Event', $fields );
		$event_fields = array_column( $fields['Event'], 'key' );
		$this->assertContains( 'event_start_date', $event_fields );
		$this->assertContains( 'event_end_date', $event_fields );
		$this->assertContains( 'event_price', $event_fields );
		$this->assertContains( 'event_attendance_mode', $event_fields );
		$this->assertContains( 'event_type', $event_fields );
	}

	public function test_service_type_has_provider_field(): void {
		$fields = MM_Schema_Types::get_fields_by_type();
		$this->assertArrayHasKey( 'Service', $fields );
		$service_fields = array_column( $fields['Service'], 'key' );
		$this->assertContains( 'service_provider_name', $service_fields );
		$this->assertContains( 'service_type', $service_fields );
		$this->assertContains( 'service_area', $service_fields );
	}

	public function test_howto_type_has_time_and_cost_fields(): void {
		$fields = MM_Schema_Types::get_fields_by_type();
		$this->assertArrayHasKey( 'HowTo', $fields );
		$howto_fields = array_column( $fields['HowTo'], 'key' );
		$this->assertContains( 'howto_total_time', $howto_fields );
		$this->assertContains( 'howto_cost_amount', $howto_fields );
		$this->assertContains( 'howto_cost_currency', $howto_fields );
	}

	public function test_product_type_has_brand_field(): void {
		$fields = MM_Schema_Types::get_fields_by_type();
		$this->assertArrayHasKey( 'Product', $fields );
		$product_fields = array_column( $fields['Product'], 'key' );
		$this->assertContains( 'product_brand', $product_fields );
		$this->assertContains( 'product_price', $product_fields );
		$this->assertContains( 'product_availability', $product_fields );
	}

	// ------------------------------------------------------------------
	// build_node_additions()
	// ------------------------------------------------------------------

	public function test_build_node_additions_empty_fields(): void {
		$result = MM_Schema_Types::build_node_additions( [], 'WebPage' );
		$this->assertSame( [], $result );
	}

	public function test_build_node_additions_unknown_type(): void {
		$result = MM_Schema_Types::build_node_additions( [ 'some_field' => 'value' ], 'UnknownType' );
		$this->assertSame( [], $result );
	}

	public function test_build_node_additions_event_with_dates_and_price(): void {
		$fields = [
			'event_start_date' => '2025-06-15T10:00',
			'event_end_date'   => '2025-06-15T18:00',
			'event_price'      => '25.00',
			'event_currency'   => 'USD',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'Event' );

		$this->assertSame( '2025-06-15T10:00', $result['startDate'] );
		$this->assertSame( '2025-06-15T18:00', $result['endDate'] );
		$this->assertArrayHasKey( 'offers', $result );
		$this->assertSame( '25.00', $result['offers']['price'] );
		$this->assertSame( 'USD', $result['offers']['priceCurrency'] );
	}

	public function test_build_node_additions_event_without_price_omits_offers(): void {
		$fields = [
			'event_start_date' => '2025-06-15T10:00',
			'event_end_date'   => '2025-06-15T18:00',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'Event' );

		$this->assertArrayNotHasKey( 'offers', $result );
	}

	public function test_build_node_additions_product_with_brand(): void {
		$fields = [
			'product_brand'   => 'Acme Corp',
			'product_price'   => '99.99',
			'product_currency' => 'EUR',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'Product' );

		$this->assertArrayHasKey( 'brand', $result );
		$this->assertSame( 'Acme Corp', $result['brand']['name'] );
		$this->assertArrayHasKey( 'offers', $result );
		$this->assertSame( '99.99', $result['offers']['price'] );
	}

	public function test_build_node_additions_product_availability(): void {
		$fields = [
			'product_price'        => '10.00',
			'product_availability' => 'InStock',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'Product' );

		$this->assertStringContainsString( 'InStock', $result['offers']['availability'] );
	}

	public function test_build_node_additions_product_invalid_availability_omitted(): void {
		$fields = [
			'product_price'        => '10.00',
			'product_availability' => 'InvalidValue',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'Product' );

		$this->assertArrayNotHasKey( 'availability', $result['offers'] ?? [] );
	}

	public function test_build_node_additions_service(): void {
		$fields = [
			'service_type' => 'Plumbing',
			'service_area' => 'Greater Portland',
			'service_price' => '75.00',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'Service' );

		$this->assertSame( 'Plumbing', $result['serviceType'] );
		$this->assertSame( 'Greater Portland', $result['areaServed'] );
		$this->assertArrayHasKey( 'offers', $result );
	}

	public function test_build_node_additions_event_attendance_mode(): void {
		$fields = [
			'event_start_date'       => '2025-06-15T10:00',
			'event_attendance_mode'  => 'OnlineEventAttendanceMode',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'Event' );

		$this->assertSame( 'https://schema.org/OnlineEventAttendanceMode', $result['eventAttendanceMode'] );
	}

	public function test_build_node_additions_event_type(): void {
		$fields = [
			'event_start_date' => '2025-06-15T10:00',
			'event_type'       => 'MusicEvent',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'Event' );

		$this->assertSame( 'https://schema.org/MusicEvent', $result['eventType'] );
	}

	public function test_build_node_additions_service_provider(): void {
		$fields = [
			'service_type'          => 'Plumbing',
			'service_provider_name' => 'Acme Plumbing Co',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'Service' );

		$this->assertArrayHasKey( 'provider', $result );
		$this->assertSame( 'Acme Plumbing Co', $result['provider']['name'] );
		$this->assertSame( 'Organization', $result['provider']['@type'] );
	}

	public function test_build_node_additions_howto_total_time(): void {
		$fields = [
			'howto_total_time' => 'PT30M',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'HowTo' );

		$this->assertSame( 'PT30M', $result['totalTime'] );
	}

	public function test_build_node_additions_howto_estimated_cost(): void {
		$fields = [
			'howto_cost_amount'   => '25.00',
			'howto_cost_currency' => 'USD',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'HowTo' );

		$this->assertArrayHasKey( 'estimatedCost', $result );
		$this->assertSame( 'MonetaryAmount', $result['estimatedCost']['@type'] );
		$this->assertSame( '25.00', $result['estimatedCost']['value'] );
		$this->assertSame( 'USD', $result['estimatedCost']['currency'] );
	}

	public function test_build_node_additions_howto_supply(): void {
		$fields = [
			'howto_supply_1' => '2 cups flour',
			'howto_supply_2' => '1 egg',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'HowTo' );

		$this->assertCount( 2, $result['supply'] );
		$this->assertSame( 'HowToSupply', $result['supply'][0]['@type'] );
		$this->assertSame( '2 cups flour', $result['supply'][0]['name'] );
		$this->assertSame( '1 egg', $result['supply'][1]['name'] );
	}

	public function test_build_node_additions_howto_tool(): void {
		$fields = [
			'howto_tool_1' => 'Screwdriver',
			'howto_tool_2' => 'Hammer',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'HowTo' );

		$this->assertCount( 2, $result['tool'] );
		$this->assertSame( 'HowToTool', $result['tool'][0]['@type'] );
		$this->assertSame( 'Screwdriver', $result['tool'][0]['name'] );
		$this->assertSame( 'Hammer', $result['tool'][1]['name'] );
	}

	// ------------------------------------------------------------------
	// TouristTrip
	// ------------------------------------------------------------------

	public function test_get_schema_types_has_tourist_trip(): void {
		$types = MM_Schema_Types::get_schema_types();
		$this->assertArrayHasKey( 'TouristTrip', $types );
	}

	public function test_get_fields_by_type_has_tourist_trip(): void {
		$fields = MM_Schema_Types::get_fields_by_type();
		$this->assertArrayHasKey( 'TouristTrip', $fields );
		$keys = array_column( $fields['TouristTrip'], 'key' );
		$this->assertContains( 'trip_departure_name', $keys );
		$this->assertContains( 'trip_price', $keys );
		$this->assertContains( 'trip_booking_url', $keys );
		$this->assertContains( 'trip_tourist_type', $keys );
	}

	public function test_build_node_additions_tourist_trip_with_departure(): void {
		$fields = [
			'trip_departure_name'    => 'Harborwalk Village',
			'trip_departure_address' => '10 Harbor Blvd, Destin FL',
			'trip_departure_lat'     => '30.3935',
			'trip_departure_lng'     => '-86.5085',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'TouristTrip' );

		$this->assertArrayHasKey( 'tripOrigin', $result );
		$this->assertSame( 'Place', $result['tripOrigin']['@type'] );
		$this->assertSame( 'Harborwalk Village', $result['tripOrigin']['name'] );
		$this->assertSame( '10 Harbor Blvd, Destin FL', $result['tripOrigin']['address'] );
		$this->assertSame( 30.3935, $result['tripOrigin']['geo']['latitude'] );
		$this->assertSame( -86.5085, $result['tripOrigin']['geo']['longitude'] );
	}

	public function test_build_node_additions_tourist_trip_with_price(): void {
		$fields = [
			'trip_departure_name' => 'Harborwalk',
			'trip_price'          => '350',
			'trip_currency'       => 'USD',
			'trip_booking_url'    => 'https://example.com/book/',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'TouristTrip' );

		$this->assertArrayHasKey( 'offers', $result );
		$this->assertSame( '350', $result['offers']['price'] );
		$this->assertSame( 'USD', $result['offers']['priceCurrency'] );
		$this->assertSame( 'https://example.com/book/', $result['offers']['url'] );
	}

	public function test_build_node_additions_tourist_trip_with_tourist_types(): void {
		$fields = [
			'trip_departure_name' => 'Harborwalk',
			'trip_tourist_type'   => 'Family tourism, Adventure tourism',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'TouristTrip' );

		$this->assertIsArray( $result['touristType'] );
		$this->assertCount( 2, $result['touristType'] );
		$this->assertSame( 'Family tourism', $result['touristType'][0] );
	}

	public function test_build_node_additions_tourist_trip_single_tourist_type(): void {
		$fields = [
			'trip_departure_name' => 'Harborwalk',
			'trip_tourist_type'   => 'Boat rental',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'TouristTrip' );

		$this->assertSame( 'Boat rental', $result['touristType'] );
	}

	public function test_build_node_additions_tourist_trip_without_price_omits_offers(): void {
		$fields = [
			'trip_departure_name' => 'Harborwalk',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'TouristTrip' );

		$this->assertArrayNotHasKey( 'offers', $result );
	}

	public function test_build_node_additions_tourist_trip_capacity(): void {
		$fields = [
			'trip_departure_name' => 'Harborwalk',
			'trip_max_passengers' => '13',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'TouristTrip' );

		$this->assertSame( 13, $result['maximumAttendeeCapacity'] );
	}

	// ------------------------------------------------------------------
	// TouristDestination
	// ------------------------------------------------------------------

	public function test_get_schema_types_has_tourist_destination(): void {
		$types = MM_Schema_Types::get_schema_types();
		$this->assertArrayHasKey( 'TouristDestination', $types );
	}

	public function test_build_node_additions_tourist_destination_with_geo(): void {
		$fields = [
			'destination_address' => 'Destin, FL 32541',
			'destination_lat'     => '30.3935',
			'destination_lng'     => '-86.5085',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'TouristDestination' );

		$this->assertSame( 'Destin, FL 32541', $result['address'] );
		$this->assertSame( 30.3935, $result['geo']['latitude'] );
	}

	public function test_build_node_additions_tourist_destination_with_attractions(): void {
		$fields = [
			'destination_attractions' => 'Crab Island Sandbar, Destin Harbor',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'TouristDestination' );

		$this->assertArrayHasKey( 'includesAttraction', $result );
		$this->assertCount( 2, $result['includesAttraction'] );
		$this->assertSame( 'TouristAttraction', $result['includesAttraction'][0]['@type'] );
		$this->assertSame( 'Crab Island Sandbar', $result['includesAttraction'][0]['name'] );
	}

	// ------------------------------------------------------------------
	// Vehicle
	// ------------------------------------------------------------------

	public function test_get_schema_types_has_vehicle(): void {
		$types = MM_Schema_Types::get_schema_types();
		$this->assertArrayHasKey( 'Vehicle', $types );
	}

	public function test_build_node_additions_vehicle_with_specs(): void {
		$fields = [
			'vessel_year'        => '2022',
			'vessel_type'        => 'Tritoon',
			'vessel_passengers'  => '13',
			'vessel_manufacturer'=> 'Barletta',
			'vessel_length'      => '24 ft',
			'vessel_engine'      => '200HP Mercury Verado',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'Vehicle' );

		$this->assertSame( '2022', $result['modelDate'] );
		$this->assertSame( 'Tritoon', $result['bodyType'] );
		$this->assertSame( 13, $result['seatingCapacity'] );
		$this->assertSame( 'Barletta', $result['manufacturer']['name'] );
		$this->assertSame( '24 ft, 200HP Mercury Verado', $result['vehicleConfiguration'] );
	}

	// ------------------------------------------------------------------
	// Course
	// ------------------------------------------------------------------

	public function test_get_schema_types_has_course(): void {
		$types = MM_Schema_Types::get_schema_types();
		$this->assertArrayHasKey( 'Course', $types );
	}

	public function test_get_fields_by_type_has_course(): void {
		$fields = MM_Schema_Types::get_fields_by_type();
		$this->assertArrayHasKey( 'Course', $fields );
		$keys = array_column( $fields['Course'], 'key' );
		$this->assertContains( 'course_credential', $keys );
		$this->assertContains( 'course_mode', $keys );
		$this->assertContains( 'course_price', $keys );
	}

	public function test_build_node_additions_course_with_credential(): void {
		$fields = [
			'course_credential' => 'CPR Certification',
			'course_mode'       => 'In-person',
			'course_duration'   => 'PT4H',
			'course_code'       => 'CPR-101',
			'course_provider'   => 'Express Training Services',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'Course' );

		$this->assertSame( 'CPR Certification', $result['educationalCredentialAwarded'] );
		$this->assertSame( 'In-person', $result['courseMode'] );
		$this->assertSame( 'PT4H', $result['timeToComplete'] );
		$this->assertSame( 'CPR-101', $result['courseCode'] );
		$this->assertSame( 'Express Training Services', $result['provider']['name'] );
	}

	public function test_build_node_additions_course_with_price(): void {
		$fields = [
			'course_credential'    => 'CPR',
			'course_price'         => '75',
			'course_currency'      => 'USD',
			'course_enrollment_url'=> 'https://example.com/enroll/',
		];
		$result = MM_Schema_Types::build_node_additions( $fields, 'Course' );

		$this->assertArrayHasKey( 'offers', $result );
		$this->assertSame( '75', $result['offers']['price'] );
		$this->assertSame( 'https://example.com/enroll/', $result['offers']['url'] );
	}
}
