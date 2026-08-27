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
}
