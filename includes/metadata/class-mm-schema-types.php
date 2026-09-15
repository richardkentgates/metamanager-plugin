<?php
/**
 * MM_Schema_Types — central registry for schema type field definitions.
 *
 * Provides:
 *   - The narrowed list of schema types relevant to GCM clients
 *   - Per-type field definitions for the admin UI (metabox + settings tab)
 *   - A builder that converts flat stored overrides into nested JSON-LD properties
 */

defined( 'ABSPATH' ) || exit;

class MM_Schema_Types {

	// -------------------------------------------------------------------------
	// Schema type list
	// -------------------------------------------------------------------------

	/**
	 * Returns the narrowed set of schema types relevant to GCM client sites.
	 * Used by both the post metabox and the Schema settings tab.
	 *
	 * @param bool $include_empty Whether to prepend the "use default" empty option.
	 * @return array<string, string>
	 */
	public static function get_schema_types( bool $include_empty = false ): array {
		$types = [
			// ── Page types ────────────────────────────────────────────────────
			'WebPage'            => 'WebPage — Generic page',
			'AboutPage'          => 'AboutPage',
			'ContactPage'        => 'ContactPage',
			'ProfilePage'        => 'ProfilePage',
			'Calendar'           => 'Calendar',
			'FAQPage'            => 'FAQPage',
			// ── Articles ─────────────────────────────────────────────────────
			'BlogPosting'        => 'BlogPosting',
			'HowTo'              => 'HowTo',
			// ── Products & services ───────────────────────────────────────────
			'Event'              => 'Event',
			'Product'            => 'Product',
			'Service'            => 'Service',
			// ── Tourism ─────────────────────────────────────────────────────
			'TouristTrip'        => 'TouristTrip — Bookable tour/experience',
			'TouristDestination' => 'TouristDestination — Place/area being promoted',
			// ── Vehicles ────────────────────────────────────────────────────
			'Vehicle'            => 'Vehicle — Boat, car, or other vehicle',
			// ── Education ───────────────────────────────────────────────────
			'Course'             => 'Course — Educational course or certification',
		];

		if ( $include_empty ) {
			return array_merge( [ '' => '— Use post type default —' ], $types );
		}

		return $types;
	}

	// -------------------------------------------------------------------------
	// Field definitions
	// -------------------------------------------------------------------------

	/**
	 * Returns field definitions for all types that have expandable fields.
	 * Types not in this map (WebPage, Article, BlogPosting, etc.) are fully
	 * auto-populated from WP data and need no extra fields.
	 *
	 * Each field definition:
	 *   key         string   flat meta key stored under schema_fields
	 *   label       string   UI label
	 *   type        string   input type: text|email|tel|url|number|datetime-local|select
	 *   required    bool     marks as required for valid schema
	 *   auto_label  string|null  shown as "Auto: …" when value comes from WP data
	 *   placeholder string   input placeholder
	 *   description string   help text shown beneath input
	 *   options     array    key=>label pairs, for 'select' type only
	 *
	 * @param bool $wc_active Whether WooCommerce is active (adds auto_label hints to Product fields).
	 * @return array<string, array>
	 */
	public static function get_fields_by_type( bool $wc_active = false ): array {
		return [
			// ── Event ─────────────────────────────────────────────────────────
			'Event' => [
				[
					'key'         => 'event_start_date',
					'label'       => 'Start Date & Time',
					'type'        => 'datetime-local',
					'required'    => true,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Required for Google rich results. Format: YYYY-MM-DDTHH:MM.',
				],
				[
					'key'         => 'event_end_date',
					'label'       => 'End Date & Time',
					'type'        => 'datetime-local',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => '',
				],
				[
					'key'         => 'event_location_name',
					'label'       => 'Venue / Location Name',
					'type'        => 'text',
					'required'    => true,
					'auto_label'  => null,
					'placeholder' => 'e.g. Crab Island, Destin Harbor',
					'description' => '',
				],
				[
					'key'         => 'event_location_address',
					'label'       => 'Location Address',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Destin, FL 32541',
					'description' => '',
				],
				[
					'key'         => 'event_organizer_name',
					'label'       => 'Organizer Name',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Auto-populated from business profile if empty.',
				],
				[
					'key'         => 'event_organizer_email',
					'label'       => 'Organizer Email',
					'type'        => 'email',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Auto-populated from business profile if empty.',
				],
				[
					'key'         => 'event_organizer_phone',
					'label'       => 'Organizer Phone',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Auto-populated from business profile if empty.',
				],
				[
					'key'         => 'event_organizer_url',
					'label'       => 'Organizer URL',
					'type'        => 'url',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => '',
				],
				[
					'key'         => 'event_price',
					'label'       => 'Price',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 150 or 75-200',
					'description' => 'Numeric or range. Leave blank to omit offers from schema.',
				],
				[
					'key'         => 'event_currency',
					'label'       => 'Currency',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'USD',
					'description' => 'ISO 4217 currency code. Defaults to USD.',
				],
				[
					'key'         => 'event_ticket_url',
					'label'       => 'Ticket URL',
					'type'        => 'url',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Link to purchase tickets.',
				],
				[
					'key'         => 'event_status',
					'label'       => 'Event Status',
					'type'        => 'select',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => '',
					'options'     => [
						'EventScheduled'   => 'Scheduled',
						'EventCancelled'   => 'Cancelled',
						'EventPostponed'   => 'Postponed',
						'EventRescheduled' => 'Rescheduled',
					],
				],
				[
					'key'         => 'event_offers',
					'label'       => 'Offers Description',
					'type'        => 'textarea',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Optional description for offers (e.g. "Early bird pricing available").',
				],
				[
					'key'         => 'event_attendance_mode',
					'label'       => 'Attendance Mode',
					'type'        => 'select',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Required for Google rich results if event is online or mixed.',
					'options'     => [
						''                                    => '— Not specified —',
						'OfflineEventAttendanceMode'         => 'In-Person Only',
						'OnlineEventAttendanceMode'          => 'Online Only',
						'MixedEventAttendanceMode'           => 'Mixed (In-Person + Online)',
					],
				],
				[
					'key'         => 'event_type',
					'label'       => 'Event Type',
					'type'        => 'select',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Category of event (used by Google for rich results filtering).',
					'options'     => [
						''                  => '— Not specified —',
						'BusinessEvent'     => 'Business Event',
						'ChildrensEvent'    => "Children's Event",
						'ComedyEvent'       => 'Comedy Event',
						'DanceEvent'        => 'Dance Event',
						'ExhibitionEvent'   => 'Exhibition Event',
						'FestivalEvent'     => 'Festival Event',
						'FoodEvent'         => 'Food Event',
						'LiteraryEvent'     => 'Literary Event',
						'MusicEvent'        => 'Music Event',
						'PoliticalEvent'    => 'Political Event',
						'SaleEvent'         => 'Sale Event',
						'SocialEvent'       => 'Social Event',
						'SportsEvent'       => 'Sports Event',
						'TheaterEvent'      => 'Theater Event',
						'VisualArtsEvent'   => 'Visual Arts Event',
					],
				],
			],

			// ── Service ───────────────────────────────────────────────────────
			'Service' => [
				[
					'key'         => 'service_type',
					'label'       => 'Service Type',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Pontoon Charter, Canvas Repair',
					'description' => '',
				],
				[
					'key'         => 'service_price',
					'label'       => 'Price / Range',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 150 or Starting at $75',
					'description' => '',
				],
				[
					'key'         => 'service_currency',
					'label'       => 'Currency',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'USD',
					'description' => 'ISO 4217. Used only when Price is a number.',
				],
				[
					'key'         => 'service_booking_url',
					'label'       => 'Booking URL',
					'type'        => 'url',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Link to book/purchase this service.',
				],
				[
					'key'         => 'service_duration',
					'label'       => 'Duration',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 2 hours, Half day',
					'description' => 'Estimated service duration.',
				],
				[
					'key'         => 'service_includes',
					'label'       => 'What\'s Included',
					'type'        => 'textarea',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'List what the service includes.',
				],
				[
					'key'         => 'service_provider_name',
					'label'       => 'Provider Name',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Auto-populated from business profile if empty.',
				],
			],

			// ── FAQPage ──────────────────────────────────────────────────────
			// Fields are dynamically rendered in the metabox (not static field defs).
			'FAQPage' => [],

			// ── HowTo ──────────────────────────────────────────────────────
			// Steps are dynamically rendered; these are HowTo-level fields.
			'HowTo' => [
				[
					'key'         => 'howto_total_time',
					'label'       => 'Total Time',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. PT30M, PT1H, P1D',
					'description' => 'ISO 8601 duration. PT30M = 30 minutes, PT1H = 1 hour, P1D = 1 day.',
				],
				[
					'key'         => 'howto_cost_amount',
					'label'       => 'Estimated Cost',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 25.00',
					'description' => 'Numeric cost value. Leave blank to omit.',
				],
				[
					'key'         => 'howto_cost_currency',
					'label'       => 'Cost Currency',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'USD',
					'description' => 'ISO 4217 currency code.',
				],
			],

			// ── Product ───────────────────────────────────────────────────────
			'Product' => [
				[
					'key'         => 'product_brand',
					'label'       => 'Brand',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => $wc_active ? 'from WooCommerce product data' : null,
					'placeholder' => '',
					'description' => '',
				],
				[
					'key'         => 'product_price',
					'label'       => 'Price',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => $wc_active ? 'from WooCommerce product data' : null,
					'placeholder' => 'e.g. 49.99',
					'description' => 'Numeric price.',
				],
				[
					'key'         => 'product_currency',
					'label'       => 'Currency',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => $wc_active ? 'from WooCommerce settings' : null,
					'placeholder' => 'USD',
					'description' => 'ISO 4217 currency code.',
				],
				[
					'key'         => 'product_availability',
					'label'       => 'Availability',
					'type'        => 'select',
					'required'    => false,
					'auto_label'  => $wc_active ? 'from WooCommerce stock status' : null,
					'placeholder' => '',
					'description' => '',
					'options'     => [
						''           => '— Not specified —',
						'InStock'    => 'In Stock',
						'OutOfStock' => 'Out of Stock',
						'PreOrder'   => 'Pre-Order',
					],
				],
			],

			// ── TouristTrip ───────────────────────────────────────────────────
			'TouristTrip' => [
				[
					'key'         => 'trip_departure_name',
					'label'       => 'Departure Location',
					'type'        => 'text',
					'required'    => true,
					'auto_label'  => null,
					'placeholder' => 'e.g. Harborwalk Village, Destin',
					'description' => 'Boarding/departure location name.',
				],
				[
					'key'         => 'trip_departure_address',
					'label'       => 'Departure Address',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 10 Harbor Blvd, Destin FL 32541',
					'description' => '',
				],
				[
					'key'         => 'trip_departure_lat',
					'label'       => 'Departure Latitude',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '30.3935',
					'description' => '',
				],
				[
					'key'         => 'trip_departure_lng',
					'label'       => 'Departure Longitude',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '-86.5085',
					'description' => '',
				],
				[
					'key'         => 'trip_destination_name',
					'label'       => 'Destination',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Crab Island, Gulf of Mexico',
					'description' => 'Primary destination for this trip.',
				],
				[
					'key'         => 'trip_duration',
					'label'       => 'Duration (display)',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 4 hours, Half day',
					'description' => 'Human-readable duration.',
				],
				[
					'key'         => 'trip_duration_iso',
					'label'       => 'Duration (ISO 8601)',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. PT4H, PT30M',
					'description' => 'ISO 8601 duration for schema.org.',
				],
				[
					'key'         => 'trip_max_passengers',
					'label'       => 'Max Passengers',
					'type'        => 'number',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '13',
					'description' => '',
				],
				[
					'key'         => 'trip_price',
					'label'       => 'Price',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 350 or 75-200',
					'description' => 'Numeric or range.',
				],
				[
					'key'         => 'trip_currency',
					'label'       => 'Currency',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'USD',
					'description' => 'ISO 4217 currency code. Defaults to USD.',
				],
				[
					'key'         => 'trip_booking_url',
					'label'       => 'Booking URL',
					'type'        => 'url',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Link to book this trip.',
				],
				[
					'key'         => 'trip_departure_time',
					'label'       => 'Departure Time',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 09:00',
					'description' => 'Typical departure time.',
				],
				[
					'key'         => 'trip_arrival_time',
					'label'       => 'Arrival Time',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 13:00',
					'description' => 'Typical return/arrival time.',
				],
				[
					'key'         => 'trip_tourist_type',
					'label'       => 'Tourist Type',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Family tourism, Adventure tourism',
					'description' => 'Comma-separated audience types.',
				],
				[
					'key'         => 'trip_includes',
					'label'       => 'What\'s Included',
					'type'        => 'textarea',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'What the trip includes (cooler, lily pad, stereo, etc.).',
				],
			],

			// ── TouristDestination ────────────────────────────────────────────
			'TouristDestination' => [
				[
					'key'         => 'destination_address',
					'label'       => 'Address',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Destin, FL 32541',
					'description' => '',
				],
				[
					'key'         => 'destination_lat',
					'label'       => 'Latitude',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '30.3935',
					'description' => '',
				],
				[
					'key'         => 'destination_lng',
					'label'       => 'Longitude',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '-86.5085',
					'description' => '',
				],
				[
					'key'         => 'destination_tourist_type',
					'label'       => 'Tourist Type',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Water tourism, Cultural tourism',
					'description' => 'Comma-separated types.',
				],
				[
					'key'         => 'destination_map_url',
					'label'       => 'Map URL',
					'type'        => 'url',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Google Maps or similar URL.',
				],
				[
					'key'         => 'destination_attractions',
					'label'       => 'Attractions',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Crab Island Sandbar, Destin Harbor',
					'description' => 'Comma-separated attraction names.',
				],
				[
					'key'         => 'destination_booking_url',
					'label'       => 'Booking URL',
					'type'        => 'url',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'URL to book tours of this destination.',
				],
			],

			// ── Vehicle ───────────────────────────────────────────────────────
			'Vehicle' => [
				[
					'key'         => 'vessel_year',
					'label'       => 'Year',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '2022',
					'description' => '',
				],
				[
					'key'         => 'vessel_length',
					'label'       => 'Length',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 24 ft',
					'description' => '',
				],
				[
					'key'         => 'vessel_engine',
					'label'       => 'Engine',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 200HP Mercury Verado',
					'description' => '',
				],
				[
					'key'         => 'vessel_passengers',
					'label'       => 'Max Passengers',
					'type'        => 'number',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '13',
					'description' => '',
				],
				[
					'key'         => 'vessel_type',
					'label'       => 'Vessel Type',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Tritoon, Sportfish, Parasail boat',
					'description' => '',
				],
				[
					'key'         => 'vessel_manufacturer',
					'label'       => 'Manufacturer',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Barletta, Bertram',
					'description' => '',
				],
				[
					'key'         => 'vessel_amenities',
					'label'       => 'Amenities',
					'type'        => 'textarea',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Cooler with ice, Bluetooth stereo, Lily pad',
					'description' => 'One amenity per line.',
				],
			],

			// ── Course ────────────────────────────────────────────────────────
			'Course' => [
				[
					'key'         => 'course_credential',
					'label'       => 'Credential Awarded',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. CPR Certification, Phlebotomy Technician',
					'description' => 'Certificate or credential awarded on completion.',
				],
				[
					'key'         => 'course_mode',
					'label'       => 'Delivery Mode',
					'type'        => 'select',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => '',
					'options'     => [
						''          => '— Not specified —',
						'In-person' => 'In-person',
						'Online'    => 'Online',
						'Hybrid'    => 'Hybrid',
					],
				],
				[
					'key'         => 'course_duration',
					'label'       => 'Duration',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. PT8H, P2D',
					'description' => 'ISO 8601 duration.',
				],
				[
					'key'         => 'course_prerequisites',
					'label'       => 'Prerequisites',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. High school diploma',
					'description' => '',
				],
				[
					'key'         => 'course_price',
					'label'       => 'Price',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 150',
					'description' => '',
				],
				[
					'key'         => 'course_currency',
					'label'       => 'Currency',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'USD',
					'description' => 'ISO 4217 currency code.',
				],
				[
					'key'         => 'course_enrollment_url',
					'label'       => 'Enrollment URL',
					'type'        => 'url',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Link to enroll in this course.',
				],
				[
					'key'         => 'course_provider',
					'label'       => 'Provider Name',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Auto-populated from business profile if empty.',
				],
				[
					'key'         => 'course_code',
					'label'       => 'Course Code',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. CPR-101',
					'description' => 'Internal course code/ID.',
				],
			],
		];
	}

	// -------------------------------------------------------------------------
	// JSON-LD node builder
	// -------------------------------------------------------------------------

	/**
	 * Converts flat stored schema_fields overrides into nested JSON-LD properties
	 * ready to merge into the schema node.
	 *
	 * @param array  $fields Flat key→value array from post meta schema_fields.
	 * @param string $type   The resolved schema @type for this post.
	 * @return array JSON-LD properties to merge.
	 */
	public static function build_node_additions( array $fields, string $type ): array {
		/** @var array<string, mixed> $out */
		$out = [];

		// ── Helpers ──────────────────────────────────────────────────────────

		$str = static function ( string $key ) use ( $fields ): string {
			return trim( $fields[ $key ] ?? '' );
		};

		// Build an Offer node from price/currency fields (key prefix passed in).
		$make_offer = function ( string $price_key, string $currency_key ) use ( $str ): ?array {
			$price = $str( $price_key );
			if ( $price === '' ) {
				return null;
			}
			$currency = $str( $currency_key ) ?: 'USD';
			return [
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => $currency,
			];
		};

		// ── Event ─────────────────────────────────────────────────────────────
		if ( 'Event' === $type ) {
			if ( $str( 'event_start_date' ) ) {
				$out['startDate'] = $str( 'event_start_date' );
			}
			if ( $str( 'event_end_date' ) ) {
				$out['endDate'] = $str( 'event_end_date' );
			}
			$attendance = $str( 'event_attendance_mode' );
			if ( $attendance ) {
				$out['eventAttendanceMode'] = 'https://schema.org/' . $attendance;
			}
			$event_type = $str( 'event_type' );
			if ( $event_type ) {
				$out['eventType'] = 'https://schema.org/' . $event_type;
			}
			$loc_name = $str( 'event_location_name' );
			$loc_addr = $str( 'event_location_address' );
			if ( $loc_name || $loc_addr ) {
				$place = [ '@type' => 'Place' ];
				if ( $loc_name ) {
					$place['name'] = $loc_name;
				}
				if ( $loc_addr ) {
					$place['address'] = $loc_addr;
				}
				$out['location'] = $place;
			}
			$status = $str( 'event_status' );
			if ( $status ) {
				$out['eventStatus'] = $status;
			}
			$offer = $make_offer( 'event_price', 'event_currency' );
			if ( $offer ) {
				$out['offers'] = $offer;
			}
			$tickets = esc_url_raw( $str( 'event_ticket_url' ) );
			if ( $tickets ) {
				if ( isset( $out['offers'] ) ) {
					$out['offers']['url'] = $tickets;
				} else {
					$out['offers'] = [
						'@type' => 'Offer',
						'url'   => $tickets,
					];
				}
			}
			$org_name = $str( 'event_organizer_name' );
			if ( $org_name ) {
				$organizer = [
					'@type' => 'Organization',
					'name'  => $org_name,
				];
				if ( $str( 'event_organizer_phone' ) ) {
					$organizer['telephone'] = $str( 'event_organizer_phone' );
				}
				if ( $str( 'event_organizer_email' ) ) {
					$organizer['email'] = $str( 'event_organizer_email' );
				}
				$org_url = esc_url_raw( $str( 'event_organizer_url' ) );
				if ( $org_url ) {
					$organizer['url'] = $org_url;
				}
				$out['organizer'] = $organizer;
			}
		}

		// ── Service ───────────────────────────────────────────────────────────
		if ( 'Service' === $type ) {
			if ( $str( 'service_type' ) ) {
				$out['serviceType'] = $str( 'service_type' );
			}
			$offer = $make_offer( 'service_price', 'service_currency' );
			if ( $offer ) {
				$out['offers'] = $offer;
			}
			$provider = $str( 'service_provider_name' );
			if ( $provider ) {
				$out['provider'] = [
					'@type' => 'Organization',
					'name'  => $provider,
				];
			}
		}

		// ── FAQPage ──────────────────────────────────────────────────────────
		if ( 'FAQPage' === $type ) {
			$main_entity = [];
			for ( $i = 1; $i <= 20; $i++ ) {
				$question = $str( "faq_question_{$i}" );
				$answer   = $str( "faq_answer_{$i}" );
				if ( $question && $answer ) {
					$main_entity[] = [
						'@type'          => 'Question',
						'name'           => $question,
						'acceptedAnswer' => [
							'@type' => 'Answer',
							'text'  => $answer,
						],
					];
				}
			}
			if ( ! empty( $main_entity ) ) {
				$out['mainEntity'] = $main_entity;
			}
		}

		// ── HowTo ──────────────────────────────────────────────────────────
		if ( 'HowTo' === $type ) {
			$total_time = $str( 'howto_total_time' );
			if ( $total_time ) {
				$out['totalTime'] = $total_time;
			}
			$cost_amount = $str( 'howto_cost_amount' );
			if ( $cost_amount ) {
				$out['estimatedCost'] = [
					'@type'         => 'MonetaryAmount',
					'currency'      => $str( 'howto_cost_currency' ) ?: 'USD',
					'value'         => $cost_amount,
				];
			}
			$supply = [];
			for ( $i = 1; $i <= 20; $i++ ) {
				$item = $str( "howto_supply_{$i}" );
				if ( $item ) {
					$supply[] = [
						'@type' => 'HowToSupply',
						'name'  => $item,
					];
				}
			}
			if ( ! empty( $supply ) ) {
				$out['supply'] = $supply;
			}
			$tool = [];
			for ( $i = 1; $i <= 20; $i++ ) {
				$item = $str( "howto_tool_{$i}" );
				if ( $item ) {
					$tool[] = [
						'@type' => 'HowToTool',
						'name'  => $item,
					];
				}
			}
			if ( ! empty( $tool ) ) {
				$out['tool'] = $tool;
			}
			$steps = [];
			for ( $i = 1; $i <= 20; $i++ ) {
				$step_name  = $str( "howto_step_name_{$i}" );
				$step_text  = $str( "howto_step_text_{$i}" );
				$step_image = $str( "howto_step_image_{$i}" );
				if ( $step_name || $step_text ) {
					$step = [
						'@type'          => 'HowToStep',
						'name'           => $step_name,
						'text'           => $step_text,
					];
					if ( $step_image ) {
						$step['image'] = $step_image;
					}
					$steps[] = $step;
				}
			}
			if ( ! empty( $steps ) ) {
				$out['step'] = $steps;
			}
		}

		// ── Product ───────────────────────────────────────────────────────────
		if ( 'Product' === $type ) {
			if ( $str( 'product_brand' ) ) {
				$out['brand'] = [ '@type' => 'Brand', 'name' => $str( 'product_brand' ) ];
			}
			$offer = $make_offer( 'product_price', 'product_currency' );
			if ( $offer ) {
				$avail = $str( 'product_availability' );
			if ( in_array( $avail, [ 'InStock', 'OutOfStock', 'PreOrder' ], true ) ) {
				$offer['availability'] = 'https://schema.org/' . $avail;
			}
				$out['offers'] = $offer;
			}
		}

		// ── TouristTrip ──────────────────────────────────────────────────────
		if ( 'TouristTrip' === $type ) {
			$dep_name = $str( 'trip_departure_name' );
			if ( $dep_name ) {
				$place = [ '@type' => 'Place', 'name' => $dep_name ];
				if ( $str( 'trip_departure_address' ) ) {
					$place['address'] = $str( 'trip_departure_address' );
				}
				$lat = $str( 'trip_departure_lat' );
				$lng = $str( 'trip_departure_lng' );
				if ( $lat && $lng && is_numeric( $lat ) && is_numeric( $lng ) ) {
					$place['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng ];
				}
				$out['tripOrigin'] = $place;
			}
			$dest_name = $str( 'trip_destination_name' );
			if ( $dest_name ) {
				$out['itinerary'] = [
					'@type'           => 'ItemList',
					'itemListElement' => [
						[ '@type' => 'ListItem', 'position' => 1, 'item' => [ '@type' => 'TouristAttraction', 'name' => $dest_name ] ],
					],
				];
			}
			if ( $str( 'trip_duration_iso' ) ) {
				$out['estimatedDuration'] = $str( 'trip_duration_iso' );
			}
			$passengers = $str( 'trip_max_passengers' );
			if ( $passengers && is_numeric( $passengers ) ) {
				$out['maximumAttendeeCapacity'] = (int) $passengers;
			}
			if ( $str( 'trip_tourist_type' ) ) {
				$ttypes = array_map( 'trim', explode( ',', $str( 'trip_tourist_type' ) ) );
				$out['touristType'] = count( $ttypes ) === 1 ? $ttypes[0] : array_values( $ttypes );
			}
			if ( $str( 'trip_departure_time' ) ) {
				$out['departureTime'] = $str( 'trip_departure_time' );
			}
			if ( $str( 'trip_arrival_time' ) ) {
				$out['arrivalTime'] = $str( 'trip_arrival_time' );
			}
			$offer = $make_offer( 'trip_price', 'trip_currency' );
			if ( $offer ) {
				$booking = esc_url_raw( $str( 'trip_booking_url' ) );
				if ( $booking ) {
					$offer['url'] = $booking;
				}
				$out['offers'] = $offer;
			}
		}

		// ── TouristDestination ───────────────────────────────────────────────
		if ( 'TouristDestination' === $type ) {
			if ( $str( 'destination_address' ) ) {
				$out['address'] = $str( 'destination_address' );
			}
			$lat = $str( 'destination_lat' );
			$lng = $str( 'destination_lng' );
			if ( $lat && $lng && is_numeric( $lat ) && is_numeric( $lng ) ) {
				$out['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng ];
			}
			if ( $str( 'destination_tourist_type' ) ) {
				$ttypes = array_map( 'trim', explode( ',', $str( 'destination_tourist_type' ) ) );
				$out['touristType'] = count( $ttypes ) === 1 ? $ttypes[0] : array_values( $ttypes );
			}
			if ( $str( 'destination_map_url' ) ) {
				$out['hasMap'] = esc_url_raw( $str( 'destination_map_url' ) );
			}
			if ( $str( 'destination_booking_url' ) ) {
				$out['tourBookingPage'] = esc_url_raw( $str( 'destination_booking_url' ) );
			}
			$attractions = $str( 'destination_attractions' );
			if ( $attractions ) {
				$names = array_map( 'trim', explode( ',', $attractions ) );
				$includes = [];
				foreach ( $names as $aname ) {
					if ( $aname ) {
						$includes[] = [ '@type' => 'TouristAttraction', 'name' => $aname ];
					}
				}
				if ( ! empty( $includes ) ) {
					$out['includesAttraction'] = $includes;
				}
			}
		}

		// ── Vehicle ──────────────────────────────────────────────────────────
		if ( 'Vehicle' === $type ) {
			if ( $str( 'vessel_year' ) ) {
				$out['modelDate'] = $str( 'vessel_year' );
			}
			if ( $str( 'vessel_type' ) ) {
				$out['bodyType'] = $str( 'vessel_type' );
			}
			if ( $str( 'vessel_manufacturer' ) ) {
				$out['manufacturer'] = [ '@type' => 'Organization', 'name' => $str( 'vessel_manufacturer' ) ];
			}
			$passengers = $str( 'vessel_passengers' );
			if ( $passengers && is_numeric( $passengers ) ) {
				$out['seatingCapacity'] = (int) $passengers;
			}
			$length = $str( 'vessel_length' );
			$engine = $str( 'vessel_engine' );
			if ( $length || $engine ) {
				$parts = array_filter( [ $length, $engine ] );
				$out['vehicleConfiguration'] = implode( ', ', $parts );
			}
			$amenities = $str( 'vessel_amenities' );
			if ( $amenities ) {
				$items = array_filter( array_map( 'trim', explode( "\n", $amenities ) ) );
				$props = [];
				foreach ( $items as $item ) {
					$props[] = [ '@type' => 'PropertyValue', 'name' => 'Amenity', 'value' => $item ];
				}
				if ( ! empty( $props ) ) {
					$out['additionalProperty'] = $props;
				}
			}
		}

		// ── Course ───────────────────────────────────────────────────────────
		if ( 'Course' === $type ) {
			if ( $str( 'course_credential' ) ) {
				$out['educationalCredentialAwarded'] = $str( 'course_credential' );
			}
			if ( $str( 'course_mode' ) ) {
				$out['courseMode'] = $str( 'course_mode' );
			}
			if ( $str( 'course_duration' ) ) {
				$out['timeToComplete'] = $str( 'course_duration' );
			}
			if ( $str( 'course_prerequisites' ) ) {
				$out['coursePrerequisites'] = $str( 'course_prerequisites' );
			}
			if ( $str( 'course_code' ) ) {
				$out['courseCode'] = $str( 'course_code' );
			}
			if ( $str( 'course_provider' ) ) {
				$out['provider'] = [ '@type' => 'Organization', 'name' => $str( 'course_provider' ) ];
			}
			$offer = $make_offer( 'course_price', 'course_currency' );
			if ( $offer ) {
				$enroll = esc_url_raw( $str( 'course_enrollment_url' ) );
				if ( $enroll ) {
					$offer['url'] = $enroll;
				}
				$out['offers'] = $offer;
			}
		}

		return $out;
	}
}
