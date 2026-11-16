<?php
/**
 * MM_Mod_Business_Contact — Business contact card (widget, block, shortcode).
 *
 * Renders a stylable business contact card sourced entirely from the business
 * profile stored by this plugin. Provides:
 *   - WordPress Widget  (classic widget areas)
 *   - Gutenberg Block   (metamanager/business-contact)
 *   - Secure server-side download endpoints for vCard (.vcf), JSON, and CSV
 *   - Inline schema.org JSON-LD scoped to each rendered card instance
 *
 * Style and visibility settings are sitewide, managed at SEO → Contact Card.
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Business_Contact extends MM_Mod_Base {

	/** WordPress option key for sitewide contact-card style settings. */
	const OPT_STYLE = 'mm_meta_contact_style';

	/** Settings API option group for the Contact Card admin page. */
	const OPT_GROUP = 'mm_meta_contact_group';

	/** Nothing to write into the <head> document graph. */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {}

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
				add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_download' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	// -------------------------------------------------------------------------
	// Download endpoints
	// -------------------------------------------------------------------------

	public function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^gcm-biz-export/(vcard|json|csv)/?$',
			'index.php?gcm_biz_export=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^gcm-event/(\d+)/ical/?$',
			'index.php?gcm_event_ical=$matches[1]',
			'top'
		);
	}

	public function add_query_var( array $vars ): array {
		$vars[] = 'gcm_biz_export';
		$vars[] = 'gcm_event_ical';
		return $vars;
	}

	public function maybe_serve_download(): void {
		// Business card export.
		$format = get_query_var( 'gcm_biz_export' );
		if ( $format ) {
			if ( ! in_array( $format, array( 'vcard', 'json', 'csv' ), true ) ) {
				wp_die( esc_html__( 'Invalid export format.', 'metamanager' ), '', array( 'response' => 400 ) );
			}

			$biz = $this->settings->all_business();
			if ( empty( $biz['name'] ) ) {
				wp_die( esc_html__( 'No business profile configured.', 'metamanager' ), '', array( 'response' => 404 ) );
			}

			nocache_headers();

			switch ( $format ) {
				case 'vcard':
					$this->serve_vcard( $biz );
					break;
				case 'json':
					$this->serve_json( $biz );
					break;
				case 'csv':
					$this->serve_csv( $biz );
					break;
			}
			exit;
		}

		// Event .ical export.
		$event_id = get_query_var( 'gcm_event_ical' );
		if ( $event_id ) {
			$this->serve_event_ical( (int) $event_id );
			exit;
		}
	}

	private function serve_vcard( array $biz ): void {
		$name  = sanitize_text_field( $biz['name'] ?? '' );
		$phone = sanitize_text_field( $biz['phone'] ?? '' );
		$email = sanitize_email( $biz['email'] ?? '' );
		$url   = trailingslashit( home_url() );
		$addr  = $biz['address'] ?? array();
		$lat   = isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : null;
		$lng   = isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : null;
		$logo  = esc_url_raw( $biz['logo_url'] ?? '' );
		$slug  = sanitize_file_name( strtolower( str_replace( ' ', '-', $name ) ) ) ?: 'business';

		$lines = array(
			'BEGIN:VCARD',
			'VERSION:3.0',
			'FN:' . $name,
			'ORG:' . $name,
			'KIND:org',
		);

		if ( $phone ) {
			$lines[] = 'TEL;TYPE=WORK,VOICE:' . $phone; }
		if ( $email ) {
			$lines[] = 'EMAIL;TYPE=WORK:' . $email; }
		if ( $url ) {
			$lines[] = 'URL:' . $url; }

		if ( ! empty( $addr['street'] ) ) {
			$lines[] = 'ADR;TYPE=WORK:;;'
				. sanitize_text_field( $addr['street'] ?? '' ) . ';'
				. sanitize_text_field( $addr['city'] ?? '' ) . ';'
				. sanitize_text_field( $addr['state'] ?? '' ) . ';'
				. sanitize_text_field( $addr['zip'] ?? '' ) . ';'
				. sanitize_text_field( $addr['country'] ?? 'US' );
		}

		if ( $lat !== null && $lng !== null ) {
			$lines[] = 'GEO:' . $lat . ';' . $lng;
		}

		if ( $logo ) {
			$lines[] = 'PHOTO;VALUE=URI:' . $logo; }

		// Social URLs
		$accounts    = $biz['accounts'] ?? array();
		$social_urls = array();
		foreach ( $accounts as $platform => $url ) {
			if ( ! empty( $url ) ) {
				$social_urls[] = $url;
			}
		}
		if ( $social_urls ) {
			foreach ( $social_urls as $surl ) {
				$lines[] = 'URL;TYPE=WORK:' . $surl;
			}
		}

		// Hours
		$hours = $biz['hours'] ?? array();
		if ( ! empty( $hours ) && is_array( $hours ) ) {
			foreach ( $hours as $group ) {
				if ( empty( $group['days'] ) ) {
					continue;
				}
				$days    = array_map(
					function ( $d ) {
						return strtoupper( substr( $d, 0, 2 ) );
					},
					(array) $group['days']
				);
				$day_str = implode( ',', $days );
				$open    = ! empty( $group['closed'] ) ? '' : ( $group['open'] ?? '' );
				$close   = ! empty( $group['closed'] ) ? '' : ( $group['close'] ?? '' );
				if ( $open && $close ) {
					$lines[] = 'X-HOURS:' . $day_str . ' ' . $open . '-' . $close;
				}
			}
		}

		$lines[] = 'END:VCARD';

		header( 'Content-Type: text/vcard; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.vcf"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo implode( "\r\n", $lines ) . "\r\n";
		exit;
	}

	private function serve_json( array $biz ): void {
		$name = sanitize_text_field( $biz['name'] ?? '' );
		$addr = $biz['address'] ?? array();
		$slug = sanitize_file_name( strtolower( str_replace( ' ', '-', $name ) ) ) ?: 'business';

		$lat = isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : null;
		$lng = isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : null;

		$data = array(
			'name'    => $name,
			'phone'   => sanitize_text_field( $biz['phone'] ?? '' ),
			'email'   => sanitize_email( $biz['email'] ?? '' ),
			'url'     => trailingslashit( home_url() ),
			'address' => array_filter(
				array(
					'street'  => sanitize_text_field( $addr['street'] ?? '' ),
					'city'    => sanitize_text_field( $addr['city'] ?? '' ),
					'state'   => sanitize_text_field( $addr['state'] ?? '' ),
					'zip'     => sanitize_text_field( $addr['zip'] ?? '' ),
					'country' => sanitize_text_field( $addr['country'] ?? '' ),
				)
			),
		);
		if ( null !== $lat ) {
			$data['lat'] = $lat; }
		if ( null !== $lng ) {
			$data['lng'] = $lng; }

		// Hours
		$hours = $biz['hours'] ?? array();
		if ( ! empty( $hours ) && is_array( $hours ) ) {
			$data['hours'] = array_map(
				function ( $g ) {
					return array(
						'days'   => array_values( (array) ( $g['days'] ?? array() ) ),
						'open'   => $g['open'] ?? '',
						'close'  => $g['close'] ?? '',
						'closed' => ! empty( $g['closed'] ),
					);
				},
				$hours
			);
		}

		// Social links
		$accounts = $biz['accounts'] ?? array();
		$social   = array_filter( $accounts );
		if ( $social ) {
			$data['social'] = $social;
		}

		// Service areas
		$areas = $biz['service_areas'] ?? array();
		if ( ! empty( $areas ) ) {
			$data['service_areas'] = array_values( $areas );
		}

		// Payment accepted
		$payment = $biz['payment_accepted'] ?? array();
		if ( ! empty( $payment ) ) {
			$data['payment_accepted'] = array_values( $payment );
		}

		// Price range
		if ( ! empty( $biz['price_range'] ) ) {
			$data['price_range'] = $biz['price_range'];
		}

		// Description
		if ( ! empty( $biz['description'] ) ) {
			$data['description'] = wp_strip_all_tags( $biz['description'] );
		}

		// Location type
		if ( ! empty( $biz['location_type'] ) ) {
			$data['location_type'] = $biz['location_type'];
		}

		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.json"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	private function serve_csv( array $biz ): void {
		$name = sanitize_text_field( $biz['name'] ?? '' );
		$addr = $biz['address'] ?? array();
		$slug = sanitize_file_name( strtolower( str_replace( ' ', '-', $name ) ) ) ?: 'business';

		$accounts     = $biz['accounts'] ?? array();
		$social_str   = '';
		$social_parts = array();
		foreach ( $accounts as $platform => $url ) {
			if ( ! empty( $url ) ) {
				$social_parts[] = "$platform:$url"; }
		}
		$social_str = implode( '; ', $social_parts );

		$hours       = $biz['hours'] ?? array();
		$hours_str   = '';
		$hours_parts = array();
		if ( ! empty( $hours ) && is_array( $hours ) ) {
			foreach ( $hours as $g ) {
				if ( empty( $g['days'] ) ) {
					continue;
				}
				$days_label    = implode( ',', (array) $g['days'] );
				$time_label    = ( ! empty( $g['closed'] ) || ( ( $g['open'] ?? '' ) === '00:00' && ( $g['close'] ?? '' ) === '00:00' ) ) ? 'Closed' : ( $g['open'] ?? '' ) . '-' . ( $g['close'] ?? '' );
				$hours_parts[] = "$days_label $time_label";
			}
		}
		$hours_str = implode( '; ', $hours_parts );

		$headers = array( 'Name', 'Phone', 'Email', 'Website', 'Street', 'City', 'State', 'ZIP', 'Country', 'Latitude', 'Longitude', 'Hours', 'Social', 'Description', 'Price Range', 'Service Areas' );
		$row     = array(
			$name,
			sanitize_text_field( $biz['phone'] ?? '' ),
			sanitize_email( $biz['email'] ?? '' ),
			trailingslashit( home_url() ),
			sanitize_text_field( $addr['street'] ?? '' ),
			sanitize_text_field( $addr['city'] ?? '' ),
			sanitize_text_field( $addr['state'] ?? '' ),
			sanitize_text_field( $addr['zip'] ?? '' ),
			sanitize_text_field( $addr['country'] ?? '' ),
			isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : '',
			isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : '',
			$hours_str,
			$social_str,
			wp_strip_all_tags( $biz['description'] ?? '' ),
			sanitize_text_field( $biz['price_range'] ?? '' ),
			implode( ', ', (array) ( $biz['service_areas'] ?? array() ) ),
		);

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.csv"' );

		ob_start();
		$fp = fopen( 'php://output', 'wb' );
		fputcsv( $fp, $headers );
		fputcsv( $fp, $row );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream; WP_Filesystem has no equivalent
		fclose( $fp );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ob_get_clean();
		exit;
	}

	// -------------------------------------------------------------------------
	// Event .ical export
	// -------------------------------------------------------------------------

	/**
	 * Serve an .ical file for a given event post.
	 */
	private function serve_event_ical( int $event_id ): void {
		$post = get_post( $event_id );
		if ( ! $post || 'mm_event' !== $post->post_type ) {
			wp_die( esc_html__( 'Event not found.', 'metamanager' ), '', array( 'response' => 404 ) );
		}

		$saved = get_post_meta( $post->ID, 'mm_schema_fields', true );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$start    = $saved['event_start_date'] ?? '';
		$end      = $saved['event_end_date'] ?? '';
		$location = $saved['event_location_name'] ?? '';
		$addr     = $saved['event_location_address'] ?? '';
		$price    = $saved['event_price'] ?? '';
		$url      = get_permalink( $post->ID );
		$uid      = 'event-' . $post->ID . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
		$now      = gmdate( 'Ymd\THis\Z' );
		$slug     = sanitize_file_name( strtolower( str_replace( ' ', '-', get_the_title( $post ) ) ) ) ?: 'event';

		// Format dates for iCal (DTSTART/DTEND).
		$dtstart = $this->format_ical_date( $start );
		$dtend   = $end ? $this->format_ical_date( $end ) : '';

		$lines   = array();
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-' . get_bloginfo( 'name' ) . '-Metamanager';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'METHOD:PUBLISH';
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $uid;
		$lines[] = 'DTSTAMP:' . $now;
		$lines[] = 'DTSTART:' . $dtstart;
		if ( $dtend ) {
			$lines[] = 'DTEND:' . $dtend;
		}
		$lines[] = 'SUMMARY:' . $this->escape_ical_text( get_the_title( $post ) );
		if ( $post->post_excerpt ) {
			$lines[] = 'DESCRIPTION:' . $this->escape_ical_text( wp_strip_all_tags( $post->post_excerpt ) );
		}
		$lines[] = 'URL:' . $url;
		$loc_str = $location;
		if ( $addr ) {
			$loc_str .= ( $loc_str ? ' ' : '' ) . $addr;
		}
		if ( $loc_str ) {
			$lines[] = 'LOCATION:' . $this->escape_ical_text( $loc_str );
		}
		if ( $price ) {
			$lines[] = 'X-PRICE:' . $this->escape_ical_text( $price );
		}
		$lines[] = 'STATUS:CONFIRMED';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.ics"' );

		echo implode( "\r\n", $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Format a datetime-local value for iCal (DTSTART/DTEND).
	 *
	 * Accepts YYYY-MM-DDTHH:MM or YYYY-MM-DDTHH:MM:SS. Returns YYYYMMDDTHHMMSS or YYYYMMDD.
	 */
	private function format_ical_date( string $datetime ): string {
		$datetime = trim( $datetime );
		// Already in iCal format?
		if ( preg_match( '/^\d{8}T\d{6}$/', $datetime ) ) {
			return $datetime;
		}
		$ts = strtotime( $datetime );
		if ( ! $ts ) {
			return '';
		}
		// If the time is midnight and no time was explicitly set, use date only.
		if ( preg_match( '/T00:00(:00)?$/', $datetime ) && ! preg_match( '/T00:00:\d{2}$/', $datetime ) ) {
			return gmdate( 'Ymd', $ts );
		}
		return gmdate( 'Ymd\THis', $ts );
	}

	/**
	 * Escape text for iCal (fold lines > 75 octets, escape special chars).
	 */
	private function escape_ical_text( string $text ): string {
		$text = str_replace( array( '\\', ',', ';' ), array( '\\\\', '\\,', '\\;' ), $text );
		$text = str_replace( "\n", '\\n', $text );
		$text = str_replace( "\r", '', $text );
		return $text;
	}

	// -------------------------------------------------------------------------
	// Widget
	// -------------------------------------------------------------------------

	public function register_widget(): void {
	}

	// -------------------------------------------------------------------------
	// Frontend assets
	// -------------------------------------------------------------------------

	public function enqueue_frontend_assets(): void {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(
			'mm-meta-biz-contact',
			MM_META_URL . 'assets/css/biz-contact.css',
			array( 'dashicons' ),
			MM_META_VERSION
		);
		// Attach the generated sitewide card CSS as a single inline block so it
		// is emitted once in <head> regardless of how many cards are on the page.
		$css = MM_Biz_Card_CSS::generate( self::get_style_settings() );
		if ( $css ) {
			wp_add_inline_style( 'mm-meta-biz-contact', $css );
		}
	}

	// -------------------------------------------------------------------------
	// Card rendering — shared by widget, block, and shortcode
	// -------------------------------------------------------------------------

	/**
	 * Build and return the complete contact card HTML with inline CSS and schema.
	 *
	 * @param array $attributes Block attributes (unused — all settings are sitewide).
	 * @return string
	 */


	// -------------------------------------------------------------------------
	// ContactPage rendering — auto-generated from business info
	// -------------------------------------------------------------------------

	/**
	 * Build the ContactPage content for display on the frontend.
	 *
	 * Uses HTML5 <address> element with geo: protocol links for maps.
	 * The content is auto-generated from business info settings.
	 *
	 * @return string HTML content for the ContactPage.
	 */
	/**
	 * Derive coordinates from business address using Google Maps Geocoding API.
	 */
	public static function geocode_address(): array|false {
		$s    = MM_Site_Settings::get_instance();
		$biz  = $s->all_business();
		$key  = sanitize_text_field( $biz['google_maps_api_key'] ?? '' );
		$addr = $biz['address'] ?? array();

		if ( empty( $key ) || empty( $addr['street'] ) ) {
			return false;
		}

		$parts   = array_filter(
			array(
				$addr['street'] ?? '',
				$addr['city'] ?? '',
				$addr['state'] ?? '',
				$addr['zip'] ?? '',
				$addr['country'] ?? 'US',
			)
		);
		$address = implode( ', ', $parts );

		$url = add_query_arg(
			array(
				'address' => rawurlencode( $address ),
				'key'     => $key,
			),
			'https://maps.googleapis.com/maps/api/geocode/json'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['results'][0]['geometry']['location'] ) ) {
			return false;
		}

		$loc = $body['results'][0]['geometry']['location'];
		return array(
			'lat' => (float) $loc['lat'],
			'lng' => (float) $loc['lng'],
		);
	}

	/**
	 * Build map links HTML based on enabled map_links settings.
	 */
	public function render_map_links( array $biz ): string {
		$lat           = isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : null;
		$lng           = isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : null;
		$addr          = $biz['address'] ?? array();
		$addr_str      = trim(
			implode(
				' ',
				array_filter(
					array(
						$addr['street'] ?? '',
						$addr['city'] ?? '',
						$addr['state'] ?? '',
						$addr['zip'] ?? '',
					)
				)
			)
		);
		$map_links     = $biz['map_links'] ?? array();
		$location_type = $biz['location_type'] ?? 'brick_and_mortar';

		if ( 'service_area' === $location_type ) {
			return '';
		}

		if ( null === $lat || null === $lng ) {
			return '';
		}

		$links = array();

		if ( ! empty( $map_links['google'] ) ) {
			$links[] = array(
				'class' => 'gcm-map-link--google',
				'label' => 'Google Maps',
				'url'   => 'https://www.google.com/maps/search/?api=1&query=' . $lat . ',' . $lng,
			);
		}
		if ( ! empty( $map_links['bing'] ) ) {
			$links[] = array(
				'class' => 'gcm-map-link--bing',
				'label' => 'Bing Maps',
				'url'   => 'https://www.bing.com/maps?cp=' . $lat . '~' . $lng . '&lvl=17',
			);
		}
		if ( ! empty( $map_links['apple'] ) ) {
			$links[] = array(
				'class' => 'gcm-map-link--apple',
				'label' => 'Apple Maps',
				'url'   => 'https://maps.apple.com/?q=' . rawurlencode( $addr_str ) . '&ll=' . $lat . ',' . $lng . '&z=17',
			);
		}
		if ( ! empty( $map_links['geo'] ) ) {
			$links[] = array(
				'class' => 'gcm-map-link--geo',
				'label' => 'Long + Lat',
				'url'   => 'geo:' . $lat . ',' . $lng . '?z=17',
			);
		}

		if ( empty( $links ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="gcm-map-links">
			<?php foreach ( $links as $link ) : ?>
			<a href="<?php echo esc_attr( $link['url'] ); ?>"
				class="gcm-map-link <?php echo esc_attr( $link['class'] ); ?>"
				target="_blank" rel="noopener noreferrer">
				<span class="dashicons dashicons-location" aria-hidden="true"></span>
				<span><?php echo esc_html( $link['label'] ); ?></span>
			</a>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public function render_contact_page(): string {
		$biz   = $this->settings->all_business();
		$style = self::get_style_settings();

		if ( empty( $biz['name'] ) ) {
			return '';
		}

		$name   = $biz['name'];
		$phone  = $biz['phone'] ?? '';
		$email  = sanitize_email( $biz['email'] ?? '' );
		$addr   = $biz['address'] ?? array();
		$lat    = $biz['lat'] ?? '';
		$lng    = $biz['lng'] ?? '';
		$hours  = $biz['hours'] ?? array();
		$type   = sanitize_text_field( $biz['type'] ?? 'LocalBusiness' );
		$schema = wp_json_encode( $this->build_schema( $biz, $style ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '{}';

		$has_address = ! empty( $addr['street'] );
		$show_phone  = ! empty( $biz['show_phone'] ?? true ) && $phone;
		$show_sms    = ! empty( $biz['show_sms'] ) && $phone;
		$show_email  = ! empty( $biz['show_email'] ?? true ) && $email;
		$show_vcard  = ! empty( $biz['show_vcard'] ?? true );
		$show_json   = ! empty( $biz['show_json'] ?? true );
		$show_csv    = ! empty( $biz['show_csv'] ?? true );

		ob_start();
		?>
		<div class="gcm-contact-page" itemscope itemtype="https://schema.org/<?php echo esc_attr( $type ); ?>">

			<div class="gcm-contact-page__name" itemprop="name"><?php echo esc_html( $name ); ?></div>

			<div class="gcm-contact-page__actions">
				<?php if ( $show_phone ) : ?>
				<meta itemprop="telephone" content="<?php echo esc_attr( $phone ); ?>">
				<a href="tel:<?php echo esc_attr( $phone ); ?>" class="gcm-contact-page__btn gcm-contact-page__btn--call">
					<span class="dashicons dashicons-phone" aria-hidden="true"></span>
					<span><?php echo esc_html( $phone ); ?></span>
				</a>
				<?php endif; ?>
				<?php
				$booking_link  = $biz['booking_link'] ?? '';
				$booking_label = sanitize_text_field( $biz['booking_link_label'] ?? 'Book Now' );
				if ( ! empty( $booking_link ) ) :
					?>
				<a href="<?php echo esc_url( $booking_link ); ?>" class="gcm-contact-page__btn gcm-contact-page__btn--booking"<?php echo ! empty( $biz['booking_link_new_tab'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
					<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
					<span><?php echo esc_html( $booking_label ); ?></span>
				</a>
				<?php endif; ?>
			</div>

			<div class="gcm-contact-page__downloads">
				<?php if ( $show_vcard ) : ?>
				<a href="<?php echo esc_url( home_url( '/gcm-biz-export/vcard/' ) ); ?>" class="gcm-contact-page__download" download>
					<span class="dashicons dashicons-id-alt" aria-hidden="true"></span> <?php esc_html_e( 'Save Contact', 'metamanager' ); ?>
				</a>
				<?php endif; ?>
				<?php if ( $show_json ) : ?>
				<a href="<?php echo esc_url( home_url( '/gcm-biz-export/json/' ) ); ?>" class="gcm-contact-page__download" download>
					<span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Download JSON', 'metamanager' ); ?>
				</a>
				<?php endif; ?>
				<?php if ( $show_csv ) : ?>
				<a href="<?php echo esc_url( home_url( '/gcm-biz-export/csv/' ) ); ?>" class="gcm-contact-page__download" download>
					<span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span> <?php esc_html_e( 'Download CSV', 'metamanager' ); ?>
				</a>
				<?php endif; ?>
			</div>

			<?php
			$accounts = $biz['accounts'] ?? array();
			?>
			<?php if ( ! empty( $accounts ) ) : ?>
			<div class="gcm-contact-page__social">
				<h3>Connect With Us</h3>
				<div class="gcm-contact-page__social-links">
					<?php
					$icons = array(
						'facebook'  => 'dashicons-facebook',
						'instagram' => 'dashicons-instagram',
						'twitter'   => 'dashicons-twitter',
						'linkedin'  => 'dashicons-linkedin',
						'youtube'   => 'dashicons-youtube',
						'pinterest' => 'dashicons-pinterest',
					);
					foreach ( $accounts as $platform => $surl ) :
						if ( empty( $surl ) ) {
							continue;
						}
						$icon  = $icons[ $platform ] ?? 'dashicons-share';
						$label = ucfirst( $platform );
						?>
					<a href="<?php echo esc_url( $surl ); ?>" class="gcm-contact-page__social-link" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $label ); ?>">
						<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
						<span class="gcm-contact-page__social-label"><?php echo esc_html( $label ); ?></span>
					</a>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( $has_address ) : ?>
				<?php $is_service_area = ( ( $biz['location_type'] ?? 'brick_and_mortar' ) === 'service_area' ); ?>
			<address class="gcm-contact-page__address"
					itemprop="address"
					itemscope itemtype="https://schema.org/PostalAddress">
				<?php if ( $is_service_area ) : ?>
					<span class="gcm-contact-page__mailing-label"><?php esc_html_e( 'Mailing Address', 'metamanager' ); ?></span><br>
				<?php endif; ?>
				<?php if ( ! empty( $addr['street'] ) ) : ?>
					<span itemprop="streetAddress"><?php echo esc_html( $addr['street'] ); ?></span><br>
				<?php endif; ?>
				<?php
				$city  = $addr['city'] ?? '';
				$state = $addr['state'] ?? '';
				$zip   = $addr['zip'] ?? '';
				if ( $city || $state || $zip ) :
					?>
					<span itemprop="addressLocality"><?php echo esc_html( $city ); ?></span><?php echo ( $city && $state ) ? ', ' : ''; ?><span itemprop="addressRegion"><?php echo esc_html( $state ); ?></span><?php echo $zip ? ' ' . esc_html( $zip ) : ''; ?>
				<?php endif; ?>
				<?php if ( ! empty( $addr['country'] ) ) : ?>
					<br><span itemprop="addressCountry"><?php echo esc_html( $addr['country'] ); ?></span>
				<?php endif; ?>

				<?php if ( $lat && $lng && ! $is_service_area ) : ?>
				<meta itemprop="latitude" content="<?php echo esc_attr( $lat ); ?>">
				<meta itemprop="longitude" content="<?php echo esc_attr( $lng ); ?>">
				<?php endif; ?>
				<?php if ( $is_service_area ) : ?>
					<br><span class="gcm-contact-page__mailing-note"><?php esc_html_e( 'This is a mailing address only. We come to you.', 'metamanager' ); ?></span>
				<?php endif; ?>
			</address>
				<?php echo $this->render_map_links( $biz ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>

			<?php if ( ! empty( $hours ) && is_array( $hours ) ) : ?>
			<div class="gcm-contact-page__hours" itemprop="openingHoursSpecification">
				<?php
				foreach ( $hours as $group ) :
					if ( empty( $group['days'] ) ) {
						continue;
					}
					$days_label = implode( ', ', (array) $group['days'] );
					$time_label = ( ! empty( $group['closed'] ) || ( ( $group['open'] ?? '' ) === '00:00' && ( $group['close'] ?? '' ) === '00:00' ) ) ? 'Closed' : ( $group['open'] ?? '' ) . ' – ' . ( $group['close'] ?? '' );
					?>
					<div class="gcm-contact-page__hours-row">
						<span class="gcm-contact-page__hours-day"><?php echo esc_html( $days_label ); ?></span>
						<span class="gcm-contact-page__hours-time"><?php echo esc_html( $time_label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php
			$areas   = $biz['service_areas'] ?? array();
			$payment = $biz['payment_accepted'] ?? array();
			?>
			<?php if ( ! empty( $areas ) ) : ?>
			<div class="gcm-contact-page__areas">
				<h3>Service Areas</h3>
				<p><?php echo esc_html( implode( ', ', $areas ) ); ?></p>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $payment ) ) : ?>
			<div class="gcm-contact-page__payment">
				<h3>Payment Accepted</h3>
				<p><?php echo esc_html( implode( ', ', $payment ) ); ?></p>
			</div>
			<?php endif; ?>

		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// AboutPage rendering — auto-generated from business info
	// -------------------------------------------------------------------------

	/**
	 * Build the AboutPage content for display on the frontend.
	 *
	 * The content is auto-generated from business info settings.
	 *
	 * @return string HTML content for the AboutPage.
	 */
	public function render_about_page(): string {
		$biz = $this->settings->all_business();

		if ( empty( $biz['name'] ) ) {
			return '';
		}

		$name      = $biz['name'];
		$desc      = $biz['description'] ?? '';
		$founding  = $biz['founding_date'] ?? '';
		$employees = $biz['number_of_employees'] ?? '';

		ob_start();
		?>
		<div class="gcm-about-page">

			<?php if ( ! empty( $biz['logo_url'] ) ) : ?>
			<div class="gcm-about-page__logo">
				<img src="<?php echo esc_url( $biz['logo_url'] ); ?>"
					alt="<?php echo esc_attr( $name ); ?>">
			</div>
			<?php endif; ?>

			<div class="gcm-about-page__name"><?php echo esc_html( $name ); ?></div>

			<?php if ( $desc ) : ?>
			<div class="gcm-about-page__description">
				<?php echo wp_kses_post( nl2br( esc_html( $desc ) ) ); ?>
			</div>
			<?php endif; ?>

			<?php if ( $founding || $employees ) : ?>
			<div class="gcm-about-page__details">
				<?php if ( $founding ) : ?>
				<div class="gcm-about-page__founding">
					<strong>Founded:</strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $founding ) ) ); ?>
				</div>
				<?php endif; ?>
				<?php if ( $employees ) : ?>
				<div class="gcm-about-page__employees">
					<strong>Employees:</strong> <?php echo esc_html( $employees ); ?>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Calendar rendering — auto-generated event calendar
	// -------------------------------------------------------------------------

	/**
	 * Build the Calendar content for display on the frontend.
	 *
	 * Shows a navigable month-by-month calendar of events with full
	 * forward/backward navigation. Auto-generated from Event posts.
	 *
	 * @return string HTML content for the Calendar page.
	 */
	public function render_calendar(): string {
		// Determine the month/year to display.
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only calendar display; no state change.
		$month = isset( $_GET['cal_month'] ) ? absint( wp_unslash( $_GET['cal_month'] ) ) : (int) current_time( 'n' );
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only calendar display; no state change.
		$year = isset( $_GET['cal_year'] ) ? absint( wp_unslash( $_GET['cal_year'] ) ) : (int) current_time( 'Y' );

		// Clamp values.
		if ( $month < 1 || $month > 12 ) {
			$month = (int) current_time( 'n' );
		}
		if ( $year < 2000 || $year > 2100 ) {
			$year = (int) current_time( 'Y' );
		}

		$month_name        = date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) );
		$days_in_month     = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
		$first_day_of_week = (int) date( 'w', mktime( 0, 0, 0, $month, 1, $year ) );

		// Get events for this month.
		$events = $this->get_events_for_month( $year, $month );

		// Build day => events map.
		$day_events = array();
		foreach ( $events as $event ) {
			$saved = get_post_meta( $event->ID, 'mm_schema_fields', true );
			$start = $saved['event_start_date'] ?? '';
			if ( $start ) {
				$day                  = (int) date( 'j', strtotime( $start ) );
				$day_events[ $day ][] = $event;
			}
		}

		// Navigation URLs.
		$prev_month = $month - 1;
		$prev_year  = $year;
		if ( $prev_month < 1 ) {
			$prev_month = 12;
			--$prev_year;
		}
		$next_month = $month + 1;
		$next_year  = $year;
		if ( $next_month > 12 ) {
			$next_month = 1;
			++$next_year;
		}
		$base_url  = get_permalink();
		$prev_url  = add_query_arg(
			array(
				'cal_month' => $prev_month,
				'cal_year'  => $prev_year,
			),
			$base_url
		);
		$next_url  = add_query_arg(
			array(
				'cal_month' => $next_month,
				'cal_year'  => $next_year,
			),
			$base_url
		);
		$today_url = add_query_arg( array(), $base_url );

		ob_start();
		?>
		<div class="gcm-calendar">

			<div class="gcm-calendar__nav">
				<a href="<?php echo esc_url( $prev_url ); ?>" class="gcm-calendar__nav-link">&larr; <?php echo esc_html( date_i18n( 'F', mktime( 0, 0, 0, $prev_month, 1, $prev_year ) ) ); ?></a>
				<span class="gcm-calendar__month-title"><?php echo esc_html( $month_name ); ?></span>
				<a href="<?php echo esc_url( $next_url ); ?>" class="gcm-calendar__nav-link"><?php echo esc_html( date_i18n( 'F', mktime( 0, 0, 0, $next_month, 1, $next_year ) ) ); ?> &rarr;</a>
			</div>

			<table class="gcm-calendar__table">
				<thead>
					<tr>
						<?php foreach ( array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ) as $d ) : ?>
						<th><?php echo esc_html( $d ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php
					$day     = 1;
					$started = false;
					for ( $row = 0; $row < 6; $row++ ) {
						if ( $day > $days_in_month ) {
							break;
						}
						echo '<tr>';
						for ( $col = 0; $col < 7; $col++ ) {
							if ( ( 0 === $row && $col < $first_day_of_week ) || $day > $days_in_month ) {
								echo '<td class="gcm-calendar__cell gcm-calendar__cell--empty"></td>';
							} else {
								$is_today   = ( $day === (int) current_time( 'j' ) && $month === (int) current_time( 'n' ) && $year === (int) current_time( 'Y' ) );
								$has_events = ! empty( $day_events[ $day ] );
								$class      = 'gcm-calendar__cell';
								if ( $is_today ) {
									$class .= ' gcm-calendar__cell--today';
								}
								if ( $has_events ) {
									$class .= ' gcm-calendar__cell--has-events';
								}
								printf( '<td class="%s">', esc_attr( $class ) );
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %d placeholder receives the loop day integer.
								printf( '<span class="gcm-calendar__day-num">%d</span>', $day );
								if ( $has_events ) {
									echo '<div class="gcm-calendar__events">';
									foreach ( $day_events[ $day ] as $ev ) {
										$saved      = get_post_meta( $ev->ID, 'mm_schema_fields', true );
										$start_time = $saved['event_start_date'] ?? '';
										$time_str   = $start_time ? date_i18n( 'g:ia', strtotime( $start_time ) ) : '';
										printf(
											'<a href="%s" class="gcm-calendar__event-link">%s%s</a>',
											esc_url( get_permalink( $ev->ID ) ),
											$time_str ? esc_html( $time_str . ' — ' ) : '',
											esc_html( get_the_title( $ev ) )
										);
									}
									echo '</div>';
								}
								echo '</td>';
								++$day;
							}
						}
						echo '</tr>';
					}
					?>
				</tbody>
			</table>

			<div class="gcm-calendar__legend">
				<a href="<?php echo esc_url( $today_url ); ?>" class="button button-secondary">Today</a>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get events that fall within a given month.
	 */
	private function get_events_for_month( int $year, int $month ): array {
		$start_date = sprintf( '%04d-%02d-01', $year, $month );
		$end_date   = sprintf( '%04d-%02d-%02d', $year, $month, (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) ) );

		$query = new \WP_Query(
			array(
				'post_type'      => 'mm_event',
				'posts_per_page' => 50,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'mm_schema_fields',
						'compare' => 'EXISTS',
					),
				),
				'date_query'     => array(
					array(
						'after'     => $start_date,
						'before'    => $end_date . ' 23:59:59',
						'inclusive' => true,
					),
				),
			)
		);

		return $query->posts;
	}

	// -------------------------------------------------------------------------
	// Schema builder
	// -------------------------------------------------------------------------

	private function build_schema( array $biz, array $style ): array {
		$type   = sanitize_text_field( $biz['type'] ?? 'LocalBusiness' );
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
			'name'     => sanitize_text_field( $biz['name'] ),
			'url'      => trailingslashit( home_url() ),
		);

		if ( ! empty( $biz['logo_url'] ) ) {
			$schema['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => esc_url_raw( $biz['logo_url'] ),
			);
		}

		if ( ! empty( $style['show_phone'] ) && ! empty( $biz['phone'] ) ) {
			$schema['telephone'] = sanitize_text_field( $biz['phone'] );
		}
		if ( ! empty( $style['show_email'] ) && ! empty( $biz['email'] ) ) {
			$schema['email'] = sanitize_email( $biz['email'] );
		}

		$addr = MM_Mod_Base::postal_address_node( $biz['address'] ?? array() );
		if ( $addr ) {
			$schema['address'] = $addr;
		}

		$geo_lat = isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : null;
		$geo_lng = isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : null;
		if ( null !== $geo_lat && null !== $geo_lng ) {
			$schema['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $geo_lat,
				'longitude' => $geo_lng,
			);
		}

		// ContactPoint nodes for rendered actions.
		$contact_points = array();
		if ( ! empty( $style['show_phone'] ) && ! empty( $biz['phone'] ) ) {
			$contact_points[] = array(
				'@type'       => 'ContactPoint',
				'telephone'   => sanitize_text_field( $biz['phone'] ),
				'contactType' => 'customer service',
			);
		}
		if ( ! empty( $style['show_email'] ) && ! empty( $biz['email'] ) ) {
			$contact_points[] = array(
				'@type'       => 'ContactPoint',
				'email'       => sanitize_email( $biz['email'] ),
				'contactType' => 'customer service',
			);
		}
		if ( $contact_points ) {
			$schema['contactPoint'] = ( 1 === count( $contact_points ) ) ? $contact_points[0] : $contact_points;
		}

		return $schema;
	}

	// -------------------------------------------------------------------------
	// Style settings helpers
	// -------------------------------------------------------------------------

	/** Return merged (defaults + saved) sitewide style settings. */
	public static function get_style_settings(): array {
		$saved  = get_option( self::OPT_STYLE, array() );
		$merged = array_merge( self::style_defaults(), is_array( $saved ) ? $saved : array() );
		// Button styles come from the Button Styles page (listing settings).
		static $listing_cache = null;
		if ( null === $listing_cache ) {
			$listing_cache = get_option( 'mm_meta_settings', array() )['listing'] ?? array();
		}
		$listing = $listing_cache;
		if ( is_array( $listing ) ) {
			$merged['btn_bg']                = $listing['btn_bg'] ?? $merged['btn_bg'];
			$merged['btn_text']              = $listing['btn_text_color'] ?? $merged['btn_text'];
			$merged['btn_radius']            = $listing['btn_radius'] ?? $merged['btn_radius'];
			$merged['btn_padding']           = $listing['btn_padding'] ?? $merged['btn_padding'];
			$merged['btn_font_size']         = $listing['btn_font_size'] ?? $merged['btn_font_size'];
			$merged['btn_font_weight']       = $listing['btn_font_weight'] ?? $merged['btn_font_weight'];
			$merged['btn_border_thickness']  = $listing['btn_border_thickness'] ?? $merged['btn_border_thickness'];
			$merged['btn_border_color']      = $listing['btn_border_color'] ?? $merged['btn_border_color'];
			$merged['btn_box_shadow']        = $listing['btn_box_shadow'] ?? $merged['btn_box_shadow'];
			$merged['btn_box_shadow_color']  = $listing['btn_box_shadow_color'] ?? $merged['btn_box_shadow_color'];
			$merged['btn_box_shadow_x']      = $listing['btn_box_shadow_x'] ?? $merged['btn_box_shadow_x'];
			$merged['btn_box_shadow_y']      = $listing['btn_box_shadow_y'] ?? $merged['btn_box_shadow_y'];
			$merged['btn_box_shadow_blur']   = $listing['btn_box_shadow_blur'] ?? $merged['btn_box_shadow_blur'];
			$merged['btn_box_shadow_spread'] = $listing['btn_box_shadow_spread'] ?? $merged['btn_box_shadow_spread'];
			$merged['btn_hover_enabled']     = $listing['btn_hover_enabled'] ?? $merged['btn_hover_enabled'];
			$merged['btn_hover_bg']          = $listing['btn_hover_bg'] ?? $merged['btn_hover_bg'];
			$merged['btn_hover_text']        = $listing['btn_hover_text'] ?? $merged['btn_hover_text'];
		}
		return $merged;
	}

	/** Full set of style defaults. */
	public static function style_defaults(): array {
		return array(
			// Visibility toggles
			'show_phone'            => true,
			'show_sms'              => true,
			'show_email'            => true,
			'show_vcard'            => true,
			'show_json'             => false,
			'show_csv'              => false,
			// Card appearance
			'card_bg'               => '#ffffff',
			'card_text'             => '#333333',
			'card_border'           => '#e2e2e2',
			'card_border_width'     => '1px',
			'card_radius'           => '8px',
			'card_padding'          => '24px',
			'card_max_width'        => '420px',
			'card_shadow'           => '0 2px 8px rgba(0,0,0,0.08)',
			// Button appearance
			'btn_bg'                => '#0073aa',
			'btn_text'              => '#ffffff',
			'btn_radius'            => '4px',
			'btn_padding'           => '10px 16px',
			'btn_font_size'         => '14px',
			'btn_font_weight'       => '500',
			'btn_border_thickness'  => '0px',
			'btn_border_color'      => '#0073aa',
			'btn_box_shadow'        => false,
			'btn_box_shadow_color'  => 'rgba(0,0,0,0.3)',
			'btn_box_shadow_x'      => '0px',
			'btn_box_shadow_y'      => '2px',
			'btn_box_shadow_blur'   => '4px',
			'btn_box_shadow_spread' => '0px',
			'btn_hover_enabled'     => true,
			'btn_hover_bg'          => '',
			'btn_hover_text'        => '',
			// Typography
			'name_font_size'        => '20px',
			'body_font_size'        => '14px',
		);
	}
}

// =============================================================================
// =============================================================================


