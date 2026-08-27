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
			'WebPage'       => 'WebPage — Generic page',
			'AboutPage'     => 'AboutPage',
			'ContactPage'   => 'ContactPage',
			'ProfilePage'   => 'ProfilePage',
			'Calendar'      => 'Calendar',
			'FAQPage'       => 'FAQPage',
			// ── Articles ─────────────────────────────────────────────────────
			'BlogPosting'   => 'BlogPosting',
			'HowTo'         => 'HowTo',
			// ── Products & services ───────────────────────────────────────────
			'Event'         => 'Event',
			'Product'       => 'Product',
			'Service'       => 'Service',
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
					'key'         => 'service_area',
					'label'       => 'Area Served',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Destin, Fort Walton Beach, FL',
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
			if ( $str( 'service_area' ) ) {
				$out['areaServed'] = $str( 'service_area' );
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

		return $out;
	}
}
