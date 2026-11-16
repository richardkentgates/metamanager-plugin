<?php
/**
 * MM_Mod_Local — LocalBusiness / Organization schema node.
 *
 * Adds the knowledge-graph entity node to the @graph on every page.
 * On the homepage also emits og:type="business.business" and
 * business:contact_data:* tags for Facebook's business card format.
 *
 * Supports 45 schema.org LocalBusiness subtypes grouped by industry.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mod local.
 *
 * @package Metamanager
 */
class MM_Mod_Local extends MM_Mod_Base {

	/**
	 * Populate.
	 *
	 * @param array            $data Data.
	 * @param MM_Page_Context  $context Context.
	 * @param MM_Site_Settings $settings Settings.
	 */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		// A publication/content site (knowledge entity = WebSite) has no
		// organization node: the WebSite node is the graph root and every
		// #organization reference resolves to #website (see MM_Mod_Base).
		if ( 'WebSite' === $settings->get( 'schema.knowledge_entity', 'LocalBusiness' ) ) {
			return;
		}

		$biz = $settings->all_business();

		if ( empty( $biz['name'] ) ) {
			// No business profile configured yet — add minimal Organization node.
			$this->add_node(
				$data,
				array(
					'@type' => 'Organization',
					'@id'   => $this->site_id( 'organization' ),
					'name'  => get_bloginfo( 'name' ),
					'url'   => $this->site_url(),
				)
			);
			return;
		}

		$type = $biz['type'] ? $biz['type'] : 'LocalBusiness';

		// Validate against known schema.org types — silently fall back to
		// LocalBusiness if the stored type is not in the recognised list.
		$valid_types = array();
		foreach ( self::get_business_types() as $group ) {
			$valid_types += $group;
		}
		if ( ! isset( $valid_types[ $type ] ) ) {
			$type = 'LocalBusiness';
		}
		$node = array(
			'@type' => $type,
			'@id'   => $this->site_id( 'organization' ),
			'name'  => $biz['name'],
			'url'   => $this->site_url(),
		);

		// Logo.
		if ( ! empty( $biz['logo_url'] ) || ! empty( $biz['logo_id'] ) ) {
			$logo = $this->image_data( (int) ( $biz['logo_id'] ?? 0 ), $biz['logo_url'] ?? '' );
			if ( $logo['url'] ) {
				$node['logo']  = array(
					'@type'  => 'ImageObject',
					'url'    => $logo['url'],
					'width'  => $logo['width'],
					'height' => $logo['height'],
				);
				$node['image'] = $node['logo'];
			}
		}

		// Description.
		if ( ! empty( $biz['description'] ) ) {
			$node['description'] = sanitize_text_field( $biz['description'] );
		}

		// Contact.
		if ( ! empty( $biz['phone'] ) ) {
			$node['telephone'] = sanitize_text_field( $biz['phone'] );
		}
		if ( ! empty( $biz['email'] ) ) {
			$node['email'] = sanitize_email( $biz['email'] );
		}

		// Address & geo — always in schema (mailing address is valid structured data).
		$addr = MM_Mod_Base::postal_address_node( $biz['address'] ?? array() );
		if ( $addr ) {
			$node['address'] = $addr;
		}

		// Geo coordinates: Organization has no 'geo'; use 'location' (Place) which does.
		// Suppressed for service_area businesses (home address — don't publish exact coordinates).
		if ( ! empty( $biz['lat'] ) && ! empty( $biz['lng'] ) && ( ( $biz['location_type'] ?? 'brick_and_mortar' ) !== 'service_area' ) ) {
			$node['location'] = array(
				'@type' => 'Place',
				'geo'   => array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => (float) $biz['lat'],
					'longitude' => (float) $biz['lng'],
				),
			);
		}

		// Price range.
		if ( ! empty( $biz['price_range'] ) ) {
			$node['priceRange'] = sanitize_text_field( $biz['price_range'] );
		}

		// Founding date / employees (Organization properties).
		if ( ! empty( $biz['founding_date'] ) ) {
			$node['foundingDate'] = sanitize_text_field( $biz['founding_date'] );
		}
		if ( ! empty( $biz['number_of_employees'] ) ) {
			$node['numberOfEmployees'] = sanitize_text_field( $biz['number_of_employees'] );
		}

		// ContactPoint nodes.
		$contact_points = array();
		if ( ! empty( $biz['phone'] ) ) {
			$contact_points[] = array(
				'@type'       => 'ContactPoint',
				'telephone'   => sanitize_text_field( $biz['phone'] ),
				'contactType' => 'customer service',
			);
		}
		if ( ! empty( $biz['email'] ) ) {
			$contact_points[] = array(
				'@type'       => 'ContactPoint',
				'email'       => sanitize_email( $biz['email'] ),
				'contactType' => 'customer service',
			);
		}
		if ( $contact_points ) {
			$node['contactPoint'] = ( 1 === count( $contact_points ) ) ? $contact_points[0] : $contact_points;
		}

		// Payment accepted.
		if ( ! empty( $biz['payment_accepted'] ) ) {
			$node['paymentAccepted'] = implode( ', ', array_map( 'sanitize_text_field', $biz['payment_accepted'] ) );
		}

		// Opening hours.
		$hours = $this->build_opening_hours( $biz['hours'] ?? array() );
		if ( $hours ) {
			$node['openingHoursSpecification'] = $hours;
		}

		// Service areas (areaServed).
		if ( ! empty( $biz['service_areas'] ) ) {
			$service_areas      = array_map(
				function ( $area ) {
					return array(
						'@type' => 'City',
						'name'  => sanitize_text_field( $area ),
					);
				},
				$biz['service_areas']
			);
			$node['areaServed'] = $service_areas;
		}

		// Social profiles → sameAs (merge both Social settings and Business Profile accounts).
		// Filter empty values first so non-empty social URLs aren't overwritten by empty business defaults.
		$social_accounts = array_filter( $settings->get( 'social.accounts', array() ) );
		$biz_accounts    = array_filter( $biz['accounts'] ?? array() );
		$all_accounts    = array_merge( $social_accounts, $biz_accounts );
		$same_as         = array_values( array_filter( array_map( 'esc_url_raw', $all_accounts ) ) );
		if ( $same_as ) {
			$node['sameAs'] = $same_as;
		}

		// Booking link → ReserveAction potentialAction.
		if ( ! empty( $biz['booking_link'] ) ) {
			$node['potentialAction'] = array(
				'@type'          => 'ReserveAction',
				'target'         => array(
					'@type'          => 'EntryPoint',
					'urlTemplate'    => esc_url_raw( $biz['booking_link'] ),
					'actionPlatform' => array(
						'http://schema.org/DesktopWebPlatform',
						'http://schema.org/MobileWebPlatform',
					),
				),
				'reservationFor' => array(
					'@type' => 'Service',
					'name'  => sanitize_text_field( $biz['booking_link_label'] ?? 'Book Now' ),
				),
			);
		}

		$this->add_node( $data, $node );

		// Multiple locations — each as a separate node.
		if ( ! empty( $biz['locations'] ) ) {
			foreach ( $biz['locations'] as $index => $loc ) {
				$this->add_node( $data, $this->build_location_node( $loc, $node, $index ) );
			}
		}

		// Homepage: OG business.business type + contact tags.
		if ( $context->is_front_page() && $settings->get( 'social.og_enabled', true ) ) {
			$this->emit_og_business( $data, $biz, $node );
		}
	}

	// -------------------------------------------------------------------------
	// Opening hours
	// -------------------------------------------------------------------------

	/**
	 * Build opening hours.
	 *
	 * @param array $hours Hours.
	 * @return array Resulting array.
	 */
	private function build_opening_hours( array $hours ): array {
		$specs   = array();
		$day_map = array(
			'monday'    => 'Monday',
			'tuesday'   => 'Tuesday',
			'wednesday' => 'Wednesday',
			'thursday'  => 'Thursday',
			'friday'    => 'Friday',
			'saturday'  => 'Saturday',
			'sunday'    => 'Sunday',
		);

		foreach ( $hours as $entry ) {
			if ( empty( $entry['days'] ) || empty( $entry['open'] ) || empty( $entry['close'] ) ) {
				continue;
			}
			foreach ( $entry['days'] as $day ) {
				$day_name = $day_map[ strtolower( $day ) ] ?? null;
				if ( $day_name ) {
					$specs[] = array(
						'@type'     => 'OpeningHoursSpecification',
						'dayOfWeek' => 'https://schema.org/' . $day_name,
						'opens'     => sanitize_text_field( $entry['open'] ),
						'closes'    => sanitize_text_field( $entry['close'] ),
					);
				}
			}
		}
		return $specs;
	}

	// -------------------------------------------------------------------------
	// Multiple locations
	// -------------------------------------------------------------------------

	/**
	 * Build location node.
	 *
	 * @param array $loc Loc.
	 * @param array $parent Parent.
	 * @param int   $index Index.
	 * @return array Resulting array.
	 */
	private function build_location_node( array $loc, array $parent, int $index ): array {
		$node = array(
			'@type'              => $parent['@type'],
			'@id'                => $this->site_id( 'location-' . ( $index + 1 ) ),
			'name'               => sanitize_text_field( $loc['name'] ?? $parent['name'] ),
			'url'                => $parent['url'],
			'parentOrganization' => array( '@id' => $parent['@id'] ),
		);
		$addr = MM_Mod_Base::postal_address_node( $loc['address'] ?? array() );
		if ( $addr ) {
			$node['address'] = $addr;
		}
		if ( ! empty( $loc['phone'] ) ) {
			$node['telephone'] = sanitize_text_field( $loc['phone'] );
		}
		$hours = $this->build_opening_hours( $loc['hours'] ?? array() );
		if ( $hours ) {
			$node['openingHoursSpecification'] = $hours;
		}
		return $node;
	}

	// -------------------------------------------------------------------------
	// OG business.business (homepage Facebook card)
	// -------------------------------------------------------------------------

	/**
	 * Emit OG business.
	 *
	 * @param array $data Data.
	 * @param array $biz Biz.
	 * @param array $node Node.
	 */
	private function emit_og_business( array &$data, array $biz, array $node ): void {
		// Override og:type to business.business.
		foreach ( $data['meta'] as &$mt ) {
			if ( ( $mt['property'] ?? '' ) === 'og:type' ) {
				$mt['content'] = 'business.business';
				break;
			}
		}
		unset( $mt );

		$addr           = $biz['address'] ?? array();
		$contact_fields = array_filter(
			array(
				'business:contact_data:street_address' => $addr['street'] ?? '',
				'business:contact_data:locality'       => $addr['city'] ?? '',
				'business:contact_data:region'         => $addr['state'] ?? '',
				'business:contact_data:postal_code'    => $addr['zip'] ?? '',
				'business:contact_data:country_name'   => $addr['country'] ?? '',
				'business:contact_data:phone_number'   => $biz['phone'] ?? '',
				'business:contact_data:email'          => $biz['email'] ?? '',
			)
		);

		foreach ( $contact_fields as $prop => $val ) {
			$data['meta'][] = array(
				'property' => $prop,
				'content'  => esc_attr( sanitize_text_field( $val ) ),
			);
		}
	}

	// -------------------------------------------------------------------------
	// Static: known LocalBusiness subtypes grouped by industry.
	// Used by admin settings UI.
	// -------------------------------------------------------------------------

	/**
	 * Get business types.
	 *
	 * @return array Resulting array.
	 */
	public static function get_business_types(): array {
		return array(
			'General'                => array(
				'LocalBusiness' => 'Local Business (generic)',
				'Organization'  => 'Organization',
				'Corporation'   => 'Corporation',
				'NGO'           => 'Non-Profit / NGO',
			),
			'Professional Services'  => array(
				'LegalService'      => 'Legal Service',
				'Attorney'          => 'Attorney / Law Firm',
				'AccountingService' => 'Accounting Service / CPA',
				'FinancialService'  => 'Financial Services',
				'InsuranceAgency'   => 'Insurance Agency',
				'RealEstateAgent'   => 'Real Estate Agent',
				'NotaryService'     => 'Notary Service',
				'EmploymentAgency'  => 'Employment / Staffing Agency',
				'ITService'         => 'IT Service / Tech Consulting',
				'MarketingAgency'   => 'Marketing Agency',
			),
			'Medical & Wellness'     => array(
				'MedicalBusiness'  => 'Medical Business (generic)',
				'Dentist'          => 'Dentist',
				'Physician'        => 'Physician / Doctor',
				'Optician'         => 'Optician',
				'Optometrist'      => 'Optometrist',
				'Pharmacy'         => 'Pharmacy',
				'AnimalShelter'    => 'Veterinarian / Animal Hospital',
				'Chiropractor'     => 'Chiropractor',
				'MassageTherapist' => 'Massage Therapist',
				'Therapist'        => 'Therapist / Counselor',
			),
			'Home & Construction'    => array(
				'HomeAndConstructionBusiness' => 'Home & Construction (generic)',
				'Electrician'                 => 'Electrician',
				'GeneralContractor'           => 'General Contractor',
				'HousePainter'                => 'Painter',
				'HVACBusiness'                => 'HVAC',
				'Locksmith'                   => 'Locksmith',
				'MovingCompany'               => 'Moving Company',
				'Plumber'                     => 'Plumber',
				'RoofingContractor'           => 'Roofing Contractor',
				'LandscapingBusiness'         => 'Landscaping',
				'HouseCleaning'               => 'House Cleaning / Maid Service',
			),
			'Food & Dining'          => array(
				'FoodEstablishment'  => 'Food Establishment (generic)',
				'Bakery'             => 'Bakery',
				'BarOrPub'           => 'Bar / Pub',
				'Brewery'            => 'Brewery / Winery',
				'CafeOrCoffeeShop'   => 'Café / Coffee Shop',
				'FastFoodRestaurant' => 'Fast Food',
				'IceCreamShop'       => 'Ice Cream Shop',
				'Restaurant'         => 'Restaurant',
				'FoodTruck'          => 'Food Truck',
			),
			'Retail'                 => array(
				'Store'            => 'Retail Store (generic)',
				'AutoPartsStore'   => 'Auto Parts Store',
				'BookStore'        => 'Book Store',
				'ClothingStore'    => 'Clothing Store',
				'ConvenienceStore' => 'Convenience Store',
				'FlowerShop'       => 'Florist',
				'FurnitureStore'   => 'Furniture Store',
				'GroceryStore'     => 'Grocery Store',
				'HardwareStore'    => 'Hardware Store',
				'JewelryStore'     => 'Jewelry Store',
				'PetStore'         => 'Pet Store',
			),
			'Automotive'             => array(
				'AutoDealer'   => 'Auto Dealer',
				'AutoBodyShop' => 'Auto Body Shop',
				'AutoRepair'   => 'Auto Repair',
				'AutoWash'     => 'Car Wash',
			),
			'Beauty & Personal Care' => array(
				'BeautySalon'  => 'Beauty Salon',
				'HairSalon'    => 'Hair Salon',
				'NailSalon'    => 'Nail Salon',
				'TattooParlor' => 'Tattoo Parlor',
				'SpaService'   => 'Spa',
			),
			'Education & Fitness'    => array(
				'EducationalOrganization' => 'School / Educational Org',
				'SportsActivityLocation'  => 'Gym / Fitness Center',
				'DanceSchool'             => 'Dance School',
				'MusicSchool'             => 'Music School',
				'TutoringService'         => 'Tutoring Service',
			),
			'Lodging & Travel'       => array(
				'Hotel'             => 'Hotel',
				'BedAndBreakfast'   => 'Bed & Breakfast',
				'TouristAttraction' => 'Tourist Attraction',
				'TravelAgency'      => 'Travel Agency',
			),
			'Tourism & Recreation'   => array(
				'TourismBusiness' => 'Tourism Business (generic)',
				'BoatRental'      => 'Boat Rental',
				'BoatTour'        => 'Boat Tour',
				'FishingCharter'  => 'Fishing Charter',
				'AmusementPark'   => 'Amusement Park',
				'Museum'          => 'Museum',
			),
		);
	}
}
