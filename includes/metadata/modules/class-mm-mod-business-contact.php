<?php
/**
 * MM_Mod_Business_Contact — Business contact card (widget, block, shortcode).
 *
 * Renders a stylable business contact card sourced entirely from the business
 * profile stored by this plugin. Provides:
 *   - WordPress Widget  (classic widget areas)
 *   - Gutenberg Block   (metamanager/business-contact)
 *   - Shortcode         [gcm_business_contact]
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
		add_action( 'init',               [ $this, 'add_rewrite_rules' ] );
		add_action( 'init',               [ $this, 'register_block' ] );
		add_filter( 'query_vars',         [ $this, 'add_query_var' ] );
		add_action( 'template_redirect',  [ $this, 'maybe_serve_download' ] );
		add_action( 'widgets_init',       [ $this, 'register_widget' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		add_shortcode( 'gcm_business_contact', [ $this, 'render_shortcode' ] );
		add_filter( 'the_content',        [ $this, 'filter_auto_generated_page_content' ] );
	}

	/**
	 * Filter the_content for auto-generated CPTs (ContactPage, AboutPage, Calendar) — replace with generated content.
	 */
	public function filter_auto_generated_page_content( string $content ): string {
		if ( ! is_singular() || ! is_main_query() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post ) {
			return $content;
		}

		switch ( $post->post_type ) {
			case 'mm_contact_page':
				return $this->render_contact_page();
			case 'mm_about_page':
				return $this->render_about_page();
			case 'mm_calendar':
				return $this->render_calendar();
			default:
				return $content;
		}
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
			if ( ! in_array( $format, [ 'vcard', 'json', 'csv' ], true ) ) {
				wp_die( esc_html__( 'Invalid export format.', 'metamanager' ), '', [ 'response' => 400 ] );
			}

			$biz = $this->settings->all_business();
			if ( empty( $biz['name'] ) ) {
				wp_die( esc_html__( 'No business profile configured.', 'metamanager' ), '', [ 'response' => 404 ] );
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
		$name    = sanitize_text_field( $biz['name']     ?? '' );
		$phone   = sanitize_text_field( $biz['phone']    ?? '' );
		$email   = sanitize_email( $biz['email']         ?? '' );
		$url     = trailingslashit( home_url() );
		$addr    = $biz['address'] ?? [];
		$lat     = isset( $biz['lat'] ) && is_numeric( $biz['lat'] )  ? (float) $biz['lat']  : null;
		$lng     = isset( $biz['lng'] ) && is_numeric( $biz['lng'] )  ? (float) $biz['lng']  : null;
		$logo    = esc_url_raw( $biz['logo_url'] ?? '' );
		$slug    = sanitize_file_name( strtolower( str_replace( ' ', '-', $name ) ) ) ?: 'business';

		$lines = [
			'BEGIN:VCARD',
			'VERSION:3.0',
			'FN:' . $name,
			'ORG:' . $name,
			'KIND:org',
		];

		if ( $phone ) { $lines[] = 'TEL;TYPE=WORK,VOICE:' . $phone; }
		if ( $email ) { $lines[] = 'EMAIL;TYPE=WORK:' . $email; }
		if ( $url   ) { $lines[] = 'URL:' . $url; }

		if ( ! empty( $addr['street'] ) ) {
			$lines[] = 'ADR;TYPE=WORK:;;'
				. sanitize_text_field( $addr['street']  ?? '' ) . ';'
				. sanitize_text_field( $addr['city']    ?? '' ) . ';'
				. sanitize_text_field( $addr['state']   ?? '' ) . ';'
				. sanitize_text_field( $addr['zip']     ?? '' ) . ';'
				. sanitize_text_field( $addr['country'] ?? 'US' );
		}

		if ( $lat !== null && $lng !== null ) {
			$lines[] = 'GEO:' . $lat . ';' . $lng;
		}

		if ( $logo ) { $lines[] = 'PHOTO;VALUE=URI:' . $logo; }

		$lines[] = 'END:VCARD';

		header( 'Content-Type: text/vcard; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.vcf"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo implode( "\r\n", $lines ) . "\r\n";
		exit;
	}

	private function serve_json( array $biz ): void {
		$name = sanitize_text_field( $biz['name'] ?? '' );
		$addr = $biz['address'] ?? [];
		$slug = sanitize_file_name( strtolower( str_replace( ' ', '-', $name ) ) ) ?: 'business';

		$lat = isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : null;
		$lng = isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : null;

		$data = [
			'name'    => $name,
			'phone'   => sanitize_text_field( $biz['phone'] ?? '' ),
			'email'   => sanitize_email( $biz['email'] ?? '' ),
			'url'     => trailingslashit( home_url() ),
			'address' => array_filter( [
				'street'  => sanitize_text_field( $addr['street']  ?? '' ),
				'city'    => sanitize_text_field( $addr['city']    ?? '' ),
				'state'   => sanitize_text_field( $addr['state']   ?? '' ),
				'zip'     => sanitize_text_field( $addr['zip']     ?? '' ),
				'country' => sanitize_text_field( $addr['country'] ?? '' ),
			] ),
		];
		if ( null !== $lat ) { $data['lat'] = $lat; }
		if ( null !== $lng ) { $data['lng'] = $lng; }

		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.json"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	private function serve_csv( array $biz ): void {
		$name = sanitize_text_field( $biz['name'] ?? '' );
		$addr = $biz['address'] ?? [];
		$slug = sanitize_file_name( strtolower( str_replace( ' ', '-', $name ) ) ) ?: 'business';

		$headers = [ 'Name', 'Phone', 'Email', 'Website', 'Street', 'City', 'State', 'ZIP', 'Country', 'Latitude', 'Longitude' ];
		$row     = [
			$name,
			sanitize_text_field( $biz['phone'] ?? '' ),
			sanitize_email( $biz['email'] ?? '' ),
			trailingslashit( home_url() ),
			sanitize_text_field( $addr['street']  ?? '' ),
			sanitize_text_field( $addr['city']    ?? '' ),
			sanitize_text_field( $addr['state']   ?? '' ),
			sanitize_text_field( $addr['zip']     ?? '' ),
			sanitize_text_field( $addr['country'] ?? '' ),
			isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : '',
			isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : '',
		];

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
			wp_die( esc_html__( 'Event not found.', 'metamanager' ), '', [ 'response' => 404 ] );
		}

		$saved = get_post_meta( $post->ID, 'mm_schema_fields', true );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		$start      = $saved['event_start_date'] ?? '';
		$end        = $saved['event_end_date'] ?? '';
		$location   = $saved['event_location_name'] ?? '';
		$addr       = $saved['event_location_address'] ?? '';
		$price      = $saved['event_price'] ?? '';
		$url        = get_permalink( $post->ID );
		$uid        = 'event-' . $post->ID . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
		$now        = gmdate( 'Ymd\THis\Z' );
		$slug       = sanitize_file_name( strtolower( str_replace( ' ', '-', get_the_title( $post ) ) ) ) ?: 'event';

		// Format dates for iCal (DTSTART/DTEND).
		$dtstart = $this->format_ical_date( $start );
		$dtend   = $end ? $this->format_ical_date( $end ) : '';

		$lines   = [];
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
		$text = str_replace( [ '\\', ',', ';' ], [ '\\\\', '\\,', '\\;' ], $text );
		$text = str_replace( "\n", '\\n', $text );
		$text = str_replace( "\r", '', $text );
		return $text;
	}

	// -------------------------------------------------------------------------
	// Widget
	// -------------------------------------------------------------------------

	public function register_widget(): void {
		register_widget( 'MM_Business_Contact_Widget' );
	}

	// -------------------------------------------------------------------------
	// Gutenberg block
	// -------------------------------------------------------------------------

	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'mm-meta-block-business-contact',
			MM_META_URL . 'assets/js/block-business-contact.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n' ],
			MM_META_VERSION,
			true
		);
		wp_localize_script( 'mm-meta-block-business-contact', 'gcmBizBlock', [
			'settingsUrl' => admin_url( 'admin.php?page=mm-meta-contact' ),
		] );

		register_block_type( 'metamanager/business-contact', [
			'editor_script'   => 'mm-meta-block-business-contact',
			'render_callback' => [ $this, 'render_card' ],
			'attributes'      => [],
		] );
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	public function render_shortcode( $atts ): string {
		return $this->render_card( [] );
	}

	// -------------------------------------------------------------------------
	// Frontend assets
	// -------------------------------------------------------------------------

	public function enqueue_frontend_assets(): void {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(
			'mm-meta-biz-contact',
			MM_META_URL . 'assets/css/biz-contact.css',
			[ 'dashicons' ],
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
	public function render_card( array $attributes = [] ): string {
		$biz   = $this->settings->all_business();
		$style = self::get_style_settings();

		if ( empty( $biz['name'] ) ) {
			return '';
		}

		$name    = $biz['name'];
		$phone   = $biz['phone'] ?? '';
		$email   = sanitize_email( $biz['email'] ?? '' );
		$addr    = $biz['address'] ?? [];
		$type    = sanitize_text_field( $biz['type'] ?? 'LocalBusiness' );
		$schema  = wp_json_encode( $this->build_schema( $biz, $style ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '{}';

		$has_address = ! empty( $addr['street'] );
		$show_phone  = ! empty( $style['show_phone'] ) && $phone;
		$show_sms    = ! empty( $style['show_sms'] )   && $phone;
		$show_email  = ! empty( $style['show_email'] ) && $email;
		$show_vcard  = ! empty( $style['show_vcard'] );
		$show_json   = ! empty( $style['show_json'] );
		$show_csv    = ! empty( $style['show_csv'] );

		ob_start();
		?>
		<div class="gcm-biz-card" itemscope itemtype="https://schema.org/<?php echo esc_attr( $type ); ?>">

			<?php if ( ! empty( $biz['logo_url'] ) ) : ?>
			<div class="gcm-biz-card__logo">
				<img src="<?php echo esc_url( $biz['logo_url'] ); ?>"
				     alt="<?php echo esc_attr( $name ); ?>"
				     itemprop="logo">
			</div>
			<?php endif; ?>

			<div class="gcm-biz-card__name" itemprop="name"><?php echo esc_html( $name ); ?></div>

			<?php if ( $has_address ) : ?>
			<div class="gcm-biz-card__address"
			     itemprop="address"
			     itemscope itemtype="https://schema.org/PostalAddress">
				<?php if ( ! empty( $addr['street'] ) ) : ?>
					<span itemprop="streetAddress"><?php echo esc_html( $addr['street'] ); ?></span><br>
				<?php endif; ?>
				<?php
				$city  = $addr['city']  ?? '';
				$state = $addr['state'] ?? '';
				$zip   = $addr['zip']   ?? '';
				if ( $city || $state || $zip ) :
				?>
					<span itemprop="addressLocality"><?php echo esc_html( $city ); ?></span><?php echo ( $city && $state ) ? ', ' : ''; ?><span itemprop="addressRegion"><?php echo esc_html( $state ); ?></span><?php echo $zip ? ' ' . esc_html( $zip ) : ''; ?>
				<?php endif; ?>
				<?php if ( ! empty( $addr['country'] ) ) : ?>
					<br><span itemprop="addressCountry"><?php echo esc_html( $addr['country'] ); ?></span>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<div class="gcm-biz-card__actions">

				<?php if ( $show_phone ) : ?>
				<a href="tel:<?php echo esc_attr( $phone ); ?>"
				   class="gcm-biz-card__btn gcm-biz-card__btn--call"
				   itemprop="telephone">
					<span class="dashicons dashicons-phone" aria-hidden="true"></span>
					<span><?php echo esc_html( $phone ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_sms ) : ?>
				<a href="sms:<?php echo esc_attr( $phone ); ?>"
				   class="gcm-biz-card__btn gcm-biz-card__btn--sms">
					<span class="dashicons dashicons-smartphone" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Send SMS', 'metamanager' ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_email ) : ?>
				<a href="mailto:<?php echo esc_attr( $email ); ?>"
				   class="gcm-biz-card__btn gcm-biz-card__btn--email"
				   itemprop="email">
					<span class="dashicons dashicons-email" aria-hidden="true"></span>
					<span><?php echo esc_html( $email ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_vcard ) : ?>
				<a href="<?php echo esc_url( home_url( '/gcm-biz-export/vcard/' ) ); ?>"
				   class="gcm-biz-card__btn gcm-biz-card__btn--vcard"
				   download>
					<span class="dashicons dashicons-id-alt" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Save Contact', 'metamanager' ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_json ) : ?>
				<a href="<?php echo esc_url( home_url( '/gcm-biz-export/json/' ) ); ?>"
				   class="gcm-biz-card__btn gcm-biz-card__btn--json"
				   download>
					<span class="dashicons dashicons-download" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Download JSON', 'metamanager' ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_csv ) : ?>
				<a href="<?php echo esc_url( home_url( '/gcm-biz-export/csv/' ) ); ?>"
				   class="gcm-biz-card__btn gcm-biz-card__btn--csv"
				   download>
					<span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Download CSV', 'metamanager' ); ?></span>
				</a>
				<?php endif; ?>

			</div>
		</div>
		<script type="application/ld+json"><?php echo $schema; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		<?php
		return ob_get_clean();
	}

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
	public function render_contact_page(): string {
		$biz   = $this->settings->all_business();
		$style = self::get_style_settings();

		if ( empty( $biz['name'] ) ) {
			return '';
		}

		$name    = $biz['name'];
		$phone   = $biz['phone'] ?? '';
		$email   = sanitize_email( $biz['email'] ?? '' );
		$addr    = $biz['address'] ?? [];
		$lat     = $biz['lat'] ?? '';
		$lng     = $biz['lng'] ?? '';
		$hours   = $biz['hours'] ?? [];
		$type    = sanitize_text_field( $biz['type'] ?? 'LocalBusiness' );
		$schema  = wp_json_encode( $this->build_schema( $biz, $style ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '{}';

		$has_address = ! empty( $addr['street'] );
		$show_phone  = ! empty( $style['show_phone'] ) && $phone;
		$show_sms    = ! empty( $style['show_sms'] )   && $phone;
		$show_email  = ! empty( $style['show_email'] ) && $email;
		$show_vcard  = ! empty( $style['show_vcard'] );
		$show_json   = ! empty( $style['show_json'] );
		$show_csv    = ! empty( $style['show_csv'] );

		ob_start();
		?>
		<div class="gcm-contact-page" itemscope itemtype="https://schema.org/<?php echo esc_attr( $type ); ?>">

			<?php if ( ! empty( $biz['logo_url'] ) ) : ?>
			<div class="gcm-contact-page__logo">
				<img src="<?php echo esc_url( $biz['logo_url'] ); ?>"
				     alt="<?php echo esc_attr( $name ); ?>"
				     itemprop="logo">
			</div>
			<?php endif; ?>

			<div class="gcm-contact-page__name" itemprop="name"><?php echo esc_html( $name ); ?></div>

			<?php if ( $has_address ) : ?>
			<address class="gcm-contact-page__address"
			         itemprop="address"
			         itemscope itemtype="https://schema.org/PostalAddress">
				<?php if ( ! empty( $addr['street'] ) ) : ?>
					<span itemprop="streetAddress"><?php echo esc_html( $addr['street'] ); ?></span><br>
				<?php endif; ?>
				<?php
				$city  = $addr['city']  ?? '';
				$state = $addr['state'] ?? '';
				$zip   = $addr['zip']   ?? '';
				if ( $city || $state || $zip ) :
				?>
					<span itemprop="addressLocality"><?php echo esc_html( $city ); ?></span><?php echo ( $city && $state ) ? ', ' : ''; ?><span itemprop="addressRegion"><?php echo esc_html( $state ); ?></span><?php echo $zip ? ' ' . esc_html( $zip ) : ''; ?>
				<?php endif; ?>
				<?php if ( ! empty( $addr['country'] ) ) : ?>
					<br><span itemprop="addressCountry"><?php echo esc_html( $addr['country'] ); ?></span>
				<?php endif; ?>

				<?php if ( $lat && $lng ) : ?>
				<br>
				<a href="geo:<?php echo esc_attr( $lat ); ?>,<?php echo esc_attr( $lng ); ?>?z=16"
				   class="gcm-contact-page__map-link"
				   itemprop="geo"
				   itemscope itemtype="https://schema.org/GeoCoordinates">
					<meta itemprop="latitude" content="<?php echo esc_attr( $lat ); ?>">
					<meta itemprop="longitude" content="<?php echo esc_attr( $lng ); ?>">
					<?php esc_html_e( 'Open in Maps', 'metamanager' ); ?>
				</a>
				<?php endif; ?>
			</address>
			<?php endif; ?>

			<?php if ( ! empty( $hours ) && is_array( $hours ) ) : ?>
			<div class="gcm-contact-page__hours" itemprop="openingHoursSpecification">
				<?php foreach ( $hours as $day => $time ) : ?>
					<?php if ( ! empty( $time ) ) : ?>
					<div class="gcm-contact-page__hours-row">
						<span class="gcm-contact-page__hours-day"><?php echo esc_html( ucfirst( $day ) ); ?></span>
						<span class="gcm-contact-page__hours-time"><?php echo esc_html( $time ); ?></span>
					</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<div class="gcm-contact-page__actions">

				<?php if ( $show_phone ) : ?>
				<a href="tel:<?php echo esc_attr( $phone ); ?>"
				   class="gcm-contact-page__btn gcm-contact-page__btn--call"
				   itemprop="telephone">
					<span class="dashicons dashicons-phone" aria-hidden="true"></span>
					<span><?php echo esc_html( $phone ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_sms ) : ?>
				<a href="sms:<?php echo esc_attr( $phone ); ?>"
				   class="gcm-contact-page__btn gcm-contact-page__btn--sms">
					<span class="dashicons dashicons-smartphone" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Send SMS', 'metamanager' ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_email ) : ?>
				<a href="mailto:<?php echo esc_attr( $email ); ?>"
				   class="gcm-contact-page__btn gcm-contact-page__btn--email"
				   itemprop="email">
					<span class="dashicons dashicons-email" aria-hidden="true"></span>
					<span><?php echo esc_html( $email ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_vcard ) : ?>
				<a href="<?php echo esc_url( home_url( '/gcm-biz-export/vcard/' ) ); ?>"
				   class="gcm-contact-page__btn gcm-contact-page__btn--vcard"
				   download>
					<span class="dashicons dashicons-id-alt" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Save Contact', 'metamanager' ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_json ) : ?>
				<a href="<?php echo esc_url( home_url( '/gcm-biz-export/json/' ) ); ?>"
				   class="gcm-contact-page__btn gcm-contact-page__btn--json"
				   download>
					<span class="dashicons dashicons-download" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Download JSON', 'metamanager' ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_csv ) : ?>
				<a href="<?php echo esc_url( home_url( '/gcm-biz-export/csv/' ) ); ?>"
				   class="gcm-contact-page__btn gcm-contact-page__btn--csv"
				   download>
					<span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Download CSV', 'metamanager' ); ?></span>
				</a>
				<?php endif; ?>

			</div>
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

		$name     = $biz['name'];
		$desc     = $biz['description'] ?? '';
		$phone    = $biz['phone'] ?? '';
		$email    = sanitize_email( $biz['email'] ?? '' );
		$addr     = $biz['address'] ?? [];
		$lat      = $biz['lat'] ?? '';
		$lng      = $biz['lng'] ?? '';
		$hours    = $biz['hours'] ?? [];
		$founding = $biz['founding_date'] ?? '';
		$employees = $biz['number_of_employees'] ?? '';
		$areas    = $biz['service_areas'] ?? [];
		$accounts = $biz['accounts'] ?? [];
		$payment  = $biz['payment_accepted'] ?? [];
		$price    = $biz['price_range'] ?? '';

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

			<?php if ( $founding || $employees || $price ) : ?>
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
				<?php if ( $price ) : ?>
				<div class="gcm-about-page__price">
					<strong>Price Range:</strong> <?php echo esc_html( $price ); ?>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<address class="gcm-about-page__address">
				<?php if ( ! empty( $addr['street'] ) ) : ?>
					<span><?php echo esc_html( $addr['street'] ); ?></span><br>
				<?php endif; ?>
				<?php
				$city  = $addr['city']  ?? '';
				$state = $addr['state'] ?? '';
				$zip   = $addr['zip']   ?? '';
				if ( $city || $state || $zip ) :
				?>
					<span><?php echo esc_html( $city ); ?><?php echo ( $city && $state ) ? ', ' : ''; ?><?php echo esc_html( $state ); ?><?php echo $zip ? ' ' . esc_html( $zip ) : ''; ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $addr['country'] ) ) : ?>
					<br><span><?php echo esc_html( $addr['country'] ); ?></span>
				<?php endif; ?>
				<?php if ( $lat && $lng ) : ?>
				<br>
				<a href="geo:<?php echo esc_attr( $lat ); ?>,<?php echo esc_attr( $lng ); ?>?z=16"
				   class="gcm-about-page__map-link">
					<?php esc_html_e( 'Open in Maps', 'metamanager' ); ?>
				</a>
				<?php endif; ?>
			</address>

			<?php if ( $phone || $email ) : ?>
			<div class="gcm-about-page__contact">
				<?php if ( $phone ) : ?>
				<a href="tel:<?php echo esc_attr( $phone ); ?>" class="gcm-about-page__link">
					<span class="dashicons dashicons-phone" aria-hidden="true"></span>
					<span><?php echo esc_html( $phone ); ?></span>
				</a>
				<?php endif; ?>
				<?php if ( $email ) : ?>
				<a href="mailto:<?php echo esc_attr( $email ); ?>" class="gcm-about-page__link">
					<span class="dashicons dashicons-email" aria-hidden="true"></span>
					<span><?php echo esc_html( $email ); ?></span>
				</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $hours ) && is_array( $hours ) ) : ?>
			<div class="gcm-about-page__hours">
				<h3>Hours</h3>
				<?php foreach ( $hours as $day => $time ) : ?>
					<?php if ( ! empty( $time ) ) : ?>
					<div class="gcm-about-page__hours-row">
						<span class="gcm-about-page__hours-day"><?php echo esc_html( ucfirst( $day ) ); ?></span>
						<span class="gcm-about-page__hours-time"><?php echo esc_html( $time ); ?></span>
					</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $areas ) ) : ?>
			<div class="gcm-about-page__areas">
				<h3>Service Areas</h3>
				<p><?php echo esc_html( implode( ', ', $areas ) ); ?></p>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $payment ) ) : ?>
			<div class="gcm-about-page__payment">
				<h3>Payment Accepted</h3>
				<p><?php echo esc_html( implode( ', ', $payment ) ); ?></p>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $accounts ) ) : ?>
			<div class="gcm-about-page__social">
				<h3>Connect With Us</h3>
				<?php foreach ( $accounts as $platform => $url ) : ?>
					<?php if ( ! empty( $url ) ) : ?>
					<a href="<?php echo esc_url( $url ); ?>" class="gcm-about-page__social-link" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( ucfirst( $platform ) ); ?>
					</a>
					<?php endif; ?>
				<?php endforeach; ?>
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
		$month = isset( $_GET['cal_month'] ) ? absint( $_GET['cal_month'] ) : (int) current_time( 'n' );
		$year  = isset( $_GET['cal_year'] )  ? absint( $_GET['cal_year'] )  : (int) current_time( 'Y' );

		// Clamp values.
		if ( $month < 1 || $month > 12 ) {
			$month = (int) current_time( 'n' );
		}
		if ( $year < 2000 || $year > 2100 ) {
			$year = (int) current_time( 'Y' );
		}

		$month_name = date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) );
		$days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
		$first_day_of_week = (int) date( 'w', mktime( 0, 0, 0, $month, 1, $year ) );

		// Get events for this month.
		$events = $this->get_events_for_month( $year, $month );

		// Build day => events map.
		$day_events = [];
		foreach ( $events as $event ) {
			$saved = get_post_meta( $event->ID, 'mm_schema_fields', true );
			$start = $saved['event_start_date'] ?? '';
			if ( $start ) {
				$day = (int) date( 'j', strtotime( $start ) );
				$day_events[ $day ][] = $event;
			}
		}

		// Navigation URLs.
		$prev_month = $month - 1;
		$prev_year  = $year;
		if ( $prev_month < 1 ) {
			$prev_month = 12;
			$prev_year--;
		}
		$next_month = $month + 1;
		$next_year  = $year;
		if ( $next_month > 12 ) {
			$next_month = 1;
			$next_year++;
		}
		$base_url = get_permalink();
		$prev_url = add_query_arg( [ 'cal_month' => $prev_month, 'cal_year' => $prev_year ], $base_url );
		$next_url = add_query_arg( [ 'cal_month' => $next_month, 'cal_year' => $next_year ], $base_url );
		$today_url = add_query_arg( [], $base_url );

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
						<?php foreach ( [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ] as $d ) : ?>
						<th><?php echo esc_html( $d ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php
					$day = 1;
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
								$is_today = ( $day === (int) current_time( 'j' ) && $month === (int) current_time( 'n' ) && $year === (int) current_time( 'Y' ) );
								$has_events = ! empty( $day_events[ $day ] );
								$class = 'gcm-calendar__cell';
								if ( $is_today ) {
									$class .= ' gcm-calendar__cell--today';
								}
								if ( $has_events ) {
									$class .= ' gcm-calendar__cell--has-events';
								}
								printf( '<td class="%s">', esc_attr( $class ) );
								printf( '<span class="gcm-calendar__day-num">%d</span>', $day );
								if ( $has_events ) {
									echo '<div class="gcm-calendar__events">';
									foreach ( $day_events[ $day ] as $ev ) {
										$saved = get_post_meta( $ev->ID, 'mm_schema_fields', true );
										$start_time = $saved['event_start_date'] ?? '';
										$time_str = $start_time ? date_i18n( 'g:ia', strtotime( $start_time ) ) : '';
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
								$day++;
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

		$query = new \WP_Query( [
			'post_type'      => 'mm_event',
			'posts_per_page' => 50,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => 'mm_schema_fields',
					'compare' => 'EXISTS',
				],
			],
			'date_query'     => [
				[
					'after'     => $start_date,
					'before'    => $end_date . ' 23:59:59',
					'inclusive' => true,
				],
			],
		] );

		return $query->posts;
	}

	// -------------------------------------------------------------------------
	// Schema builder
	// -------------------------------------------------------------------------

	private function build_schema( array $biz, array $style ): array {
		$type   = sanitize_text_field( $biz['type'] ?? 'LocalBusiness' );
		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => $type,
			'name'     => sanitize_text_field( $biz['name'] ),
			'url'      => trailingslashit( home_url() ),
		];

		if ( ! empty( $biz['logo_url'] ) ) {
			$schema['logo'] = [
				'@type' => 'ImageObject',
				'url'   => esc_url_raw( $biz['logo_url'] ),
			];
		}

		if ( ! empty( $style['show_phone'] ) && ! empty( $biz['phone'] ) ) {
			$schema['telephone'] = sanitize_text_field( $biz['phone'] );
		}
		if ( ! empty( $style['show_email'] ) && ! empty( $biz['email'] ) ) {
			$schema['email'] = sanitize_email( $biz['email'] );
		}

		$addr = MM_Mod_Base::postal_address_node( $biz['address'] ?? [] );
		if ( $addr ) {
			$schema['address'] = $addr;
		}

		$geo_lat = isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : null;
		$geo_lng = isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : null;
		if ( null !== $geo_lat && null !== $geo_lng ) {
			$schema['geo'] = [
				'@type'     => 'GeoCoordinates',
				'latitude'  => $geo_lat,
				'longitude' => $geo_lng,
			];
		}

		// ContactPoint nodes for rendered actions.
		$contact_points = [];
		if ( ! empty( $style['show_phone'] ) && ! empty( $biz['phone'] ) ) {
			$contact_points[] = [
				'@type'       => 'ContactPoint',
				'telephone'   => sanitize_text_field( $biz['phone'] ),
				'contactType' => 'customer service',
			];
		}
		if ( ! empty( $style['show_email'] ) && ! empty( $biz['email'] ) ) {
			$contact_points[] = [
				'@type'       => 'ContactPoint',
				'email'       => sanitize_email( $biz['email'] ),
				'contactType' => 'customer service',
			];
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
		$saved = get_option( self::OPT_STYLE, [] );
		return array_merge( self::style_defaults(), is_array( $saved ) ? $saved : [] );
	}

	/** Full set of style defaults. */
	public static function style_defaults(): array {
		return [
			// Visibility toggles
			'show_phone' => true,
			'show_sms'   => true,
			'show_email' => true,
			'show_vcard' => true,
			'show_json'  => false,
			'show_csv'   => false,
			// Card appearance
			'card_bg'           => '#ffffff',
			'card_text'         => '#333333',
			'card_border'       => '#e2e2e2',
			'card_border_width' => '1px',
			'card_radius'       => '8px',
			'card_padding'      => '24px',
			'card_max_width'    => '420px',
			'card_shadow'       => '0 2px 8px rgba(0,0,0,0.08)',
			// Button appearance
			'btn_bg'        => '#0073aa',
			'btn_text'      => '#ffffff',
			'btn_radius'    => '4px',
			'btn_padding'   => '10px 16px',
			'btn_font_size' => '14px',
			// Typography
			'name_font_size' => '20px',
			'body_font_size' => '14px',
		];
	}
}

// =============================================================================
// Widget — thin wrapper that delegates to the module's render_card().
// =============================================================================

class MM_Business_Contact_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'mm_meta_business_contact',
			__( 'GCM Business Contact Card', 'metamanager' ),
			[ 'description' => __( 'Displays the business contact card. Styling is controlled via SEO → Contact Page.', 'metamanager' ) ]
		);
	}

	public function widget( $args, $instance ): void {
		$settings = MM_Site_Settings::get_instance();
		$module   = new MM_Mod_Business_Contact( $settings );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			     . esc_html( apply_filters( 'widget_title', $instance['title'] ) )
			     . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $module->render_card( [] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ): void {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title (optional):', 'metamanager' ); ?></label>
			<input class="widefat"
			       id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
			       name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
			       type="text"
			       value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to Contact Page settings page */
				esc_html__( 'Styling and action visibility are configured on the %s page.', 'metamanager' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=mm-meta-contact' ) ) . '">'
				. esc_html__( 'Contact Page settings', 'metamanager' )
				. '</a>'
			);
			?>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ): array {
		return [ 'title' => sanitize_text_field( $new_instance['title'] ?? '' ) ];
	}
}
