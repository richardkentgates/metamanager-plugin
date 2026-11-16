<?php
/**
 * MM_Schema_Post_Types — registers a custom post type per schema type.
 *
 * Each schema type (Event, Product, Service, etc.) gets its own CPT so the
 * admin meta box shows only the fields relevant to that type.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema post types.
 *
 * @package Metamanager
 */
class MM_Schema_Post_Types {

	/** Map of post type slug => [schema_type_label, short_label, supports, icon]. */
	public const TYPES = array(
		'mm_event'        => array( 'Event', 'Events', array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ), 'calendar-alt' ),
		'mm_service'      => array( 'Service', 'Services', array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ), 'hammer' ),
		'mm_how_to'       => array( 'HowTo', 'How-To', array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ), 'list-view' ),
		'mm_faq_page'     => array( 'FAQPage', 'FAQ', array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ), 'editor' ),
		'mm_trip'         => array( 'TouristTrip', 'Trips', array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ), 'location-alt' ),
		'mm_destination'  => array( 'TouristDestination', 'Destinations', array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ), 'location' ),
		'mm_vessel'       => array( 'Vehicle', 'Vehicles', array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ), 'car' ),
		'mm_course'       => array( 'Course', 'Courses', array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ), 'welcome-learn-more' ),
		'mm_business'     => array( 'LocalBusiness', 'Businesses', array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ), 'store' ),
		'mm_organization' => array( 'Organization', 'Organizations', array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ), 'groups' ),
		// AboutPage, ContactPage, Calendar are WordPress Page Templates — not CPTs.
		// WebPage maps to default `page` type — not a separate CPT.
		// BlogPosting maps to default `post` type — not a separate CPT.
		// ProfilePage maps to author archive pages — not a separate CPT.
	);

	/** Map of page template slug => schema type. */
	public const TEMPLATES = array(
		'mm-about'    => 'AboutPage',
		'mm-contact'  => 'ContactPage',
		'mm-calendar' => 'CollectionPage',
	);

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_schema_menu' ) );
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta' ), 10, 2 );
		add_filter( 'template_include', array( __CLASS__, 'load_page_template' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'order_archives_by_menu_order' ) );
		add_action( 'wp_ajax_mm_search_vessels', array( __CLASS__, 'ajax_search_vessels' ) );
		add_action( 'wp_ajax_mm_search_destinations', array( __CLASS__, 'ajax_search_destinations' ) );
	}

	/**
	 * Order schema CPT archives by menu_order (like pages), then title.
	 *
	 * @param \WP_Query $query The query to modify.
	 */
	public static function order_archives_by_menu_order( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( ! $query->is_post_type_archive() && ! $query->is_singular() ) {
			return;
		}
		$pt = $query->get( 'post_type' );
		if ( ! is_string( $pt ) || ! isset( self::TYPES[ $pt ] ) ) {
			return;
		}
		$query->set(
			'orderby',
			array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			)
		);
		return;
	}

	/**
	 * Register the Schema parent menu and move CPTs under it.
	 */
	public static function register_schema_menu(): void {
		add_menu_page(
			'Schema Types',
			'Schema',
			'edit_posts',
			'mm-schema-dashboard',
			array( __CLASS__, 'render_schema_dashboard' ),
			'dashicons-editor-table',
			30
		);
	}

	/**
	 * Render the Schema dashboard page.
	 */
	public static function render_schema_dashboard(): void {
		$types = self::TYPES;
		echo '<div class="wrap">';
		echo '<h1>Schema Types</h1>';
		echo '<p>Manage your structured data content types. Each type has its own editor and schema output.</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Schema Type</th><th>Menu Label</th><th>Posts</th><th>Actions</th>';
		echo '</tr></thead><tbody>';

		foreach ( $types as $slug => $config ) {
			$schema_type = $config[0];
			$short_label = $config[1];
			$count       = wp_count_posts( $slug );
			$published   = $count->publish ?? 0;
			$edit_link   = admin_url( 'edit.php?post_type=' . $slug );
			$new_link    = admin_url( 'post-new.php?post_type=' . $slug );

			printf(
				'<tr><td><strong>%s</strong></td><td>%s</td><td>%d</td><td><a href="%s" class="button button-small">View All</a> <a href="%s" class="button button-small button-primary">Add New</a></td></tr>',
				esc_html( $schema_type ),
				esc_html( $short_label ),
				(int) $published,
				esc_url( $edit_link ),
				esc_url( $new_link )
			);
		}

		echo '</tbody></table>';

		// Page templates section.
		echo '<h2 style="margin-top:24px">Page Templates</h2>';
		echo '<p>These are WordPress page templates that output schema. Assign them to a page in the Page Attributes panel.</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Template</th><th>Schema Type</th><th>Pages Using</th>';
		echo '</tr></thead><tbody>';

		foreach ( self::TEMPLATES as $template_slug => $schema_type ) {
			$pages = get_pages(
				array(
					'meta_key'   => '_wp_page_template',
					'meta_value' => $template_slug,
				)
			);
			$count = count( $pages );
			$label = ucwords( str_replace( array( 'mm-', '-' ), array( '', ' ' ), $template_slug ) );

			printf(
				'<tr><td>%s</td><td>%s</td><td>%d</td></tr>',
				esc_html( $label ),
				esc_html( $schema_type ),
				(int) $count
			);
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Register all schema custom post types.
	 */
	public static function register_post_types(): void {
		foreach ( self::TYPES as $slug => $config ) {
			$schema_type = $config[0];
			$short_label = $config[1];
			$supports    = $config[2];
			$icon        = $config[3];

			register_post_type(
				$slug,
				array(
					'labels'       => array(
						'name'               => $short_label,
						'singular_name'      => $schema_type,
						'add_new_item'       => 'Add New ' . $schema_type,
						'edit_item'          => 'Edit ' . $schema_type,
						'view_item'          => 'View ' . $schema_type,
						'search_items'       => 'Search ' . $short_label,
						'not_found'          => 'No ' . $short_label . ' found',
						'not_found_in_trash' => 'No ' . $short_label . ' found in Trash',
					),
					'public'       => true,
					'has_archive'  => true,
					'rewrite'      => array( 'slug' => sanitize_title( strtolower( $short_label ) ) ),
					'supports'     => $supports,
					'menu_icon'    => 'dashicons-' . $icon,
					'show_in_rest' => true,
					'show_in_menu' => 'mm-schema-dashboard',
				)
			);
		}
	}

	/**
	 * Add meta boxes for schema CPTs and page templates.
	 */
	public static function add_meta_boxes(): void {
		foreach ( self::TYPES as $slug => $config ) {
			$schema_type = $config[0];
			add_meta_box(
				'mm_schema_fields',
				$schema_type . ' Details',
				array( __CLASS__, 'render_meta_box' ),
				$slug,
				'normal',
				'high'
			);
		}

		// Page template metaboxes — detect on the page screen.
		add_action( 'load-post.php', array( __CLASS__, 'add_page_template_meta_boxes' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'add_page_template_meta_boxes' ) );
	}

	/**
	 * Add metaboxes for pages using schema templates.
	 */
	public static function add_page_template_meta_boxes(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'page' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- read-only metabox rendering; no state change.
		$post_id  = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$template = $post_id ? get_page_template_slug( $post_id ) : '';

		if ( ! isset( self::TEMPLATES[ $template ] ) ) {
			return;
		}

		$schema_type = self::TEMPLATES[ $template ];
		add_meta_box(
			'mm_schema_fields',
			$schema_type . ' Details',
			array( __CLASS__, 'render_page_template_meta_box' ),
			'page',
			'normal',
			'high'
		);
	}

	/**
	 * Render the meta box for a given schema post type.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public static function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'mm_schema_meta', 'mm_schema_nonce' );

		$slug   = $post->post_type;
		$config = self::TYPES[ $slug ] ?? null;
		if ( ! $config ) {
			return;
		}

		$schema_type = $config[0];

		if ( 'mm_faq_page' === $slug ) {
			self::render_faq_page_meta_box( $post );
			return;
		}
		if ( 'mm_how_to' === $slug ) {
			self::render_how_to_meta_box( $post );
			return;
		}

		$wc_active   = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
		$fields      = MM_Schema_Types::get_fields_by_type( $wc_active );
		$type_fields = $fields[ $schema_type ] ?? array();
		$saved       = get_post_meta( $post->ID, 'mm_schema_fields', true );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		// WooCommerce product linking section for Event and Service.
		if ( $wc_active && in_array( $slug, array( 'mm_event', 'mm_service' ), true ) ) {
			self::render_wc_product_linking_section( $post, $slug );
		}

		// Vessel linking section for Trip.
		if ( 'mm_trip' === $slug ) {
			self::render_vessel_linking_section( $post );
			self::render_destination_linking_section( $post );
		}

		if ( empty( $type_fields ) ) {
			echo '<p>This schema type has no additional fields — all data is auto-populated from the post content.</p>';
			return;
		}

		echo '<table class="form-table"><tbody>';

		// Get business profile defaults for auto-population.
		$biz      = in_array( $slug, array( 'mm_event', 'mm_service', 'mm_trip', 'mm_course' ), true ) ? MM_Site_Settings::get_instance()->all_business() : array();
		$biz_addr = $biz['address'] ?? array();

		foreach ( $type_fields as $field ) {
			$key         = $field['key'];
			$value       = $saved[ $key ] ?? '';
			$label       = $field['label'];
			$type        = $field['type'] ?? 'text';
			$required    = ! empty( $field['required'] );
			$placeholder = $field['placeholder'] ?? '';
			$desc        = $field['description'] ?? '';
			$options     = $field['options'] ?? array();

			// Build data attribute for auto-population from business profile.
			$biz_attr = '';
			if ( 'mm_event' === $slug ) {
				switch ( $key ) {
					case 'event_location_name':
						$biz_attr = ! empty( $biz['name'] ) ? sprintf( ' data-biz-default="%s"', esc_attr( $biz['name'] ) ) : '';
						break;
					case 'event_location_address':
						$full_addr = trim(
							implode(
								', ',
								array_filter(
									array(
										$biz_addr['street'] ?? '',
										$biz_addr['city'] ?? '',
										$biz_addr['state'] ?? '',
										$biz_addr['zip'] ?? '',
									)
								)
							)
						);
						$biz_attr  = $full_addr ? sprintf( ' data-biz-default="%s"', esc_attr( $full_addr ) ) : '';
						break;
					case 'event_organizer_name':
						$biz_attr = ! empty( $biz['name'] ) ? sprintf( ' data-biz-default="%s"', esc_attr( $biz['name'] ) ) : '';
						break;
					case 'event_organizer_email':
						$biz_attr = ! empty( $biz['email'] ) ? sprintf( ' data-biz-default="%s"', esc_attr( $biz['email'] ) ) : '';
						break;
					case 'event_organizer_phone':
						$biz_attr = ! empty( $biz['phone'] ) ? sprintf( ' data-biz-default="%s"', esc_attr( $biz['phone'] ) ) : '';
						break;
				}
			}
			if ( 'mm_service' === $slug && 'service_provider_name' === $key ) {
				$biz_attr = ! empty( $biz['name'] ) ? sprintf( ' data-biz-default="%s"', esc_attr( $biz['name'] ) ) : '';
			}
			if ( 'mm_trip' === $slug && 'trip_departure_name' === $key ) {
				$biz_attr = ! empty( $biz['name'] ) ? sprintf( ' data-biz-default="%s"', esc_attr( $biz['name'] ) ) : '';
			}
			if ( 'mm_course' === $slug && 'course_provider' === $key ) {
				$biz_attr = ! empty( $biz['name'] ) ? sprintf( ' data-biz-default="%s"', esc_attr( $biz['name'] ) ) : '';
			}

			printf( '<tr><th><label for="mm_%s">%s%s</label></th><td>', esc_attr( $key ), esc_html( $label ), $required ? ' <span class="required">*</span>' : '' );

			if ( 'select' === $type && $options ) {
				printf( '<select id="mm_%s" name="mm_schema_fields[%s]">', esc_attr( $key ), esc_attr( $key ) );
				foreach ( $options as $opt_val => $opt_label ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $opt_val ), selected( $value, $opt_val, false ), esc_html( $opt_label ) );
				}
				echo '</select>';
			} else {
				printf(
					'<input type="%s" id="mm_%s" name="mm_schema_fields[%s]" value="%s" class="regular-text" placeholder="%s"%s>',
					esc_attr( $type ),
					esc_attr( $key ),
					esc_attr( $key ),
					esc_attr( $value ),
					esc_attr( $placeholder ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $biz_attr holds only esc_attr()-built fragments (see builders above).
					$biz_attr
				);
			}

			if ( $desc ) {
				printf( '<p class="description">%s</p>', esc_html( $desc ) );
			}

			// Show "Use business profile" link if field has a business default and is empty.
			if ( $biz_attr && empty( $value ) ) {
				printf(
					'<p class="description" style="color:#2271b1;margin-top:4px;"><a href="#" class="mm-use-biz-default" data-target="mm_%s">Use business profile value</a></p>',
					esc_attr( $key )
				);
			}

			echo '</td></tr>';
		}
		echo '</tbody></table>';

		// Event: show .ical download link.
		if ( 'mm_event' === $slug ) {
			$ical_url = home_url( '/gcm-event/' . $post->ID . '/ical/' );
			printf(
				'<p><a href="%s" class="button button-secondary" download>Download .ics (iCal)</a></p>',
				esc_url( $ical_url )
			);
		}

		// Event/Service/Trip/Course: add auto-populate JavaScript.
		if ( in_array( $slug, array( 'mm_event', 'mm_service', 'mm_trip', 'mm_course' ), true ) ) {
			?>
			<script>
			(function(){
				document.querySelectorAll('.mm-use-biz-default').forEach(function(link){
					link.addEventListener('click', function(e){
						e.preventDefault();
						var target = document.getElementById(this.dataset.target);
						if(target){
							target.value = target.dataset.bizDefault || '';
							target.focus();
						}
					});
				});
			})();
			</script>
			<?php
		}

		// Breadcrumb label (common to all types).
		$bc_label = get_post_meta( $post->ID, 'mm_breadcrumb_label', true );
		printf(
			'<tr><th><label for="mm_breadcrumb_label">Breadcrumb Label</label></th><td><input type="text" id="mm_breadcrumb_label" name="mm_breadcrumb_label" value="%s" class="regular-text" placeholder="Override post title for breadcrumbs"></td></tr>',
			esc_attr( $bc_label ?? '' )
		);
	}

	/**
	 * Render the meta box for pages using schema templates.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public static function render_page_template_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'mm_schema_meta', 'mm_schema_nonce' );

		$template = get_page_template_slug( $post->ID );
		if ( ! isset( self::TEMPLATES[ $template ] ) ) {
			return;
		}

		switch ( $template ) {
			case 'mm-contact':
				self::render_contact_page_meta_box( $post );
				break;
			case 'mm-about':
				self::render_about_page_meta_box( $post );
				break;
			case 'mm-calendar':
				self::render_calendar_meta_box( $post );
				break;
		}

		// Breadcrumb label.
		$bc_label = get_post_meta( $post->ID, 'mm_breadcrumb_label', true );
		printf(
			'<tr><th><label for="mm_breadcrumb_label">Breadcrumb Label</label></th><td><input type="text" id="mm_breadcrumb_label" name="mm_breadcrumb_label" value="%s" class="regular-text" placeholder="Override page title for breadcrumbs"></td></tr>',
			esc_attr( $bc_label ?? '' )
		);
	}

	/**
	 * Render the ContactPage meta box — auto-generated from business info.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private static function render_contact_page_meta_box( \WP_Post $post ): void {
		$settings = MM_Site_Settings::get_instance();
		$biz      = $settings->all_business();
		$name     = $biz['name'] ?? '';
		$phone    = $biz['phone'] ?? '';
		$email    = $biz['email'] ?? '';
		$addr     = $biz['address'] ?? array();
		$hours    = $biz['hours'] ?? array();
		$lat      = $biz['lat'] ?? '';
		$lng      = $biz['lng'] ?? '';

		echo '<div style="background:#f0f0f1;padding:16px;border-radius:4px;margin-bottom:16px;">';
		echo '<p style="margin:0 0 8px 0;"><strong>Contact Page</strong> is auto-generated from your Business Info settings.</p>';
		echo '<p style="margin:0;">Content and schema are built from your business profile. Edit business info to change what appears here.</p>';
		echo '</div>';

		echo '<h4>Current Business Info</h4>';
		echo '<table class="form-table"><tbody>';

		printf( '<tr><th>Name</th><td>%s</td></tr>', esc_html( $name ? $name : '<em>Not set</em>' ) );
		printf( '<tr><th>Phone</th><td>%s</td></tr>', esc_html( $phone ? $phone : '<em>Not set</em>' ) );
		printf( '<tr><th>Email</th><td>%s</td></tr>', esc_html( $email ? $email : '<em>Not set</em>' ) );

		$street    = $addr['street'] ?? '';
		$city      = $addr['city'] ?? '';
		$state     = $addr['state'] ?? '';
		$zip       = $addr['zip'] ?? '';
		$full_addr = trim( "$street, $city, $state $zip", ', ' );
		printf( '<tr><th>Address</th><td>%s</td></tr>', esc_html( $full_addr ? $full_addr : '<em>Not set</em>' ) );

		if ( $lat && $lng ) {
			printf( '<tr><th>Coordinates</th><td>%s, %s</td></tr>', esc_html( $lat ), esc_html( $lng ) );
		}

		if ( ! empty( $hours ) && is_array( $hours ) ) {
			$hours_str = implode( ', ', array_filter( $hours ) );
			printf( '<tr><th>Hours</th><td>%s</td></tr>', esc_html( $hours_str ? $hours_str : '<em>Not set</em>' ) );
		}

		echo '</tbody></table>';

		printf(
			'<p><a href="%s" class="button button-secondary">Edit Business Info</a></p>',
			esc_url( admin_url( 'admin.php?page=mm-settings&tab=mm_tab_biz' ) )
		);
	}

	/**
	 * Render the AboutPage meta box — auto-generated from business info.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private static function render_about_page_meta_box( \WP_Post $post ): void {
		$settings  = MM_Site_Settings::get_instance();
		$biz       = $settings->all_business();
		$name      = $biz['name'] ?? '';
		$desc      = $biz['description'] ?? '';
		$phone     = $biz['phone'] ?? '';
		$email     = $biz['email'] ?? '';
		$addr      = $biz['address'] ?? array();
		$founding  = $biz['founding_date'] ?? '';
		$employees = $biz['number_of_employees'] ?? '';
		$areas     = $biz['service_areas'] ?? array();

		echo '<div style="background:#f0f0f1;padding:16px;border-radius:4px;margin-bottom:16px;">';
		echo '<p style="margin:0 0 8px 0;"><strong>About Page</strong> is auto-generated from your Business Info settings.</p>';
		echo '<p style="margin:0;">Content and schema are built from your business profile. Edit business info to change what appears here.</p>';
		echo '</div>';

		echo '<h4>Current Business Info</h4>';
		echo '<table class="form-table"><tbody>';

		printf( '<tr><th>Name</th><td>%s</td></tr>', esc_html( $name ? $name : '<em>Not set</em>' ) );
		printf( '<tr><th>Description</th><td>%s</td></tr>', esc_html( $desc ? $desc : '<em>Not set</em>' ) );

		if ( $founding ) {
			printf( '<tr><th>Founded</th><td>%s</td></tr>', esc_html( date_i18n( get_option( 'date_format' ), strtotime( $founding ) ) ) );
		}
		if ( $employees ) {
			printf( '<tr><th>Employees</th><td>%s</td></tr>', esc_html( $employees ) );
		}

		$street    = $addr['street'] ?? '';
		$city      = $addr['city'] ?? '';
		$state     = $addr['state'] ?? '';
		$zip       = $addr['zip'] ?? '';
		$full_addr = trim( "$street, $city, $state $zip", ', ' );
		printf( '<tr><th>Address</th><td>%s</td></tr>', esc_html( $full_addr ? $full_addr : '<em>Not set</em>' ) );

		if ( ! empty( $areas ) ) {
			printf( '<tr><th>Service Areas</th><td>%s</td></tr>', esc_html( implode( ', ', $areas ) ) );
		}

		echo '</tbody></table>';

		printf(
			'<p><a href="%s" class="button button-secondary">Edit Business Info</a></p>',
			esc_url( admin_url( 'admin.php?page=mm-settings&tab=mm_tab_biz' ) )
		);
	}

	/**
	 * Render the Calendar meta box — auto-generated event calendar.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private static function render_calendar_meta_box( \WP_Post $post ): void {
		$events = self::get_upcoming_events();

		echo '<div style="background:#f0f0f1;padding:16px;border-radius:4px;margin-bottom:16px;">';
		echo '<p style="margin:0 0 8px 0;"><strong>Calendar</strong> is auto-generated from your Event posts.</p>';
		echo '<p style="margin:0;">Events are pulled dynamically — add or edit events to change what appears here.</p>';
		echo '</div>';

		if ( empty( $events ) ) {
			echo '<p><em>No upcoming events found.</em></p>';
			return;
		}

		echo '<h4>Upcoming Events</h4>';
		echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Date</th><th>Location</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			$saved = get_post_meta( $event->ID, 'mm_schema_fields', true );
			$start = $saved['event_start_date'] ?? '';
			$loc   = $saved['event_location_name'] ?? '';
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>',
				esc_url( get_permalink( $event->ID ) ),
				esc_html( get_the_title( $event ) ),
				esc_html( $start ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $start ) ) : '—' ),
				esc_html( $loc ? $loc : '—' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Get upcoming events (sorted by start date).
	 */
	private static function get_upcoming_events(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'mm_event',
				'posts_per_page' => 20,
				'meta_key'       => 'mm_schema_fields',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'mm_schema_fields',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return $query->posts;
	}

	/**
	 * Render the FAQPage meta box — dynamic Q&A pairs.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private static function render_faq_page_meta_box( \WP_Post $post ): void {
		$saved = get_post_meta( $post->ID, 'mm_schema_fields', true );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		// Count existing pairs.
		$pair_count = 0;
		for ( $i = 1; $i <= 20; $i++ ) {
			if ( ! empty( $saved[ "faq_question_{$i}" ] ) || ! empty( $saved[ "faq_answer_{$i}" ] ) ) {
				$pair_count = $i;
			}
		}
		if ( $pair_count < 1 ) {
			$pair_count = 1;
		}

		echo '<div id="mm-faq-pairs">';
		for ( $i = 1; $i <= $pair_count; $i++ ) {
			self::render_faq_pair( $i, $saved );
		}
		echo '</div>';
		printf(
			'<p><button type="button" id="mm-add-faq-pair" class="button button-secondary">+ Add Question</button></p>'
		);
		?>
		<script>
		(function(){
			var pairCount = <?php echo esc_js( (string) $pair_count ); ?>;
			var container = document.getElementById('mm-faq-pairs');
			document.getElementById('mm-add-faq-pair').addEventListener('click', function(){
				pairCount++;
				var div = document.createElement('div');
				div.className = 'mm-faq-pair';
				div.style.cssText = 'background:#f0f0f1;padding:12px;margin-bottom:12px;border-radius:4px;position:relative;';
				div.innerHTML =
					'<button type="button" class="button mm-remove-faq-pair" style="position:absolute;top:8px;right:8px;">&times;</button>' +
					'<p><strong>Q&amp;A Pair ' + pairCount + '</strong></p>' +
					'<table class="form-table"><tbody>' +
					'<tr><th><label for="mm_faq_question_' + pairCount + '">Question</label></th>' +
					'<td><input type="text" id="mm_faq_question_' + pairCount + '" name="mm_schema_fields[faq_question_' + pairCount + ']" value="" class="regular-text"></td></tr>' +
					'<tr><th><label for="mm_faq_answer_' + pairCount + '">Answer</label></th>' +
					'<td><textarea id="mm_faq_answer_' + pairCount + '" name="mm_schema_fields[faq_answer_' + pairCount + ']" rows="4" class="large-text"></textarea></td></tr>' +
					'</tbody></table>';
				container.appendChild(div);
				div.querySelector('.mm-remove-faq-pair').addEventListener('click', function(){
					div.remove();
				});
			});
			container.addEventListener('click', function(e){
				if(e.target.classList.contains('mm-remove-faq-pair')){
					e.target.closest('.mm-faq-pair').remove();
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render a single FAQ Q&A pair.
	 *
	 * @param int   $i I.
	 * @param array $saved Saved.
	 */
	private static function render_faq_pair( int $i, array $saved ): void {
		$question = $saved[ "faq_question_{$i}" ] ?? '';
		$answer   = $saved[ "faq_answer_{$i}" ] ?? '';
		$si       = (string) $i;
		?>
		<div class="mm-faq-pair" style="background:#f0f0f1;padding:12px;margin-bottom:12px;border-radius:4px;position:relative;">
			<button type="button" class="button mm-remove-faq-pair" style="position:absolute;top:8px;right:8px;">&times;</button>
			<p><strong>Q&amp;A Pair <?php echo esc_html( $si ); ?></strong></p>
			<table class="form-table"><tbody>
				<tr><th><label for="mm_faq_question_<?php echo esc_attr( $si ); ?>">Question</label></th>
				<td><input type="text" id="mm_faq_question_<?php echo esc_attr( $si ); ?>" name="mm_schema_fields[faq_question_<?php echo esc_attr( $si ); ?>]" value="<?php echo esc_attr( $question ); ?>" class="regular-text"></td></tr>
				<tr><th><label for="mm_faq_answer_<?php echo esc_attr( $si ); ?>">Answer</label></th>
				<td><textarea id="mm_faq_answer_<?php echo esc_attr( $si ); ?>" name="mm_schema_fields[faq_answer_<?php echo esc_attr( $si ); ?>]" rows="4" class="large-text"><?php echo esc_textarea( $answer ); ?></textarea></td></tr>
			</tbody></table>
		</div>
		<?php
	}

	/**
	 * Render the HowTo meta box — HowTo-level fields, dynamic supply/tools, dynamic steps.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private static function render_how_to_meta_box( \WP_Post $post ): void {
		$saved = get_post_meta( $post->ID, 'mm_schema_fields', true );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		// HowTo-level fields (totalTime, estimatedCost).
		echo '<h4>HowTo Details</h4>';
		echo '<table class="form-table"><tbody>';

		$total_time = $saved['howto_total_time'] ?? '';
		printf(
			'<tr><th><label for="mm_howto_total_time">Total Time</label></th><td>' .
			'<input type="text" id="mm_howto_total_time" name="mm_schema_fields[howto_total_time]" value="%s" class="regular-text" placeholder="e.g. PT30M, PT1H, P1D">' .
			'<p class="description">ISO 8601 duration. PT30M = 30 min, PT1H = 1 hour, P1D = 1 day.</p></td></tr>',
			esc_attr( $total_time )
		);

		$cost_amount   = $saved['howto_cost_amount'] ?? '';
		$cost_currency = $saved['howto_cost_currency'] ?? '';
		printf(
			'<tr><th><label for="mm_howto_cost_amount">Estimated Cost</label></th><td>' .
			'<input type="text" id="mm_howto_cost_amount" name="mm_schema_fields[howto_cost_amount]" value="%s" class="regular-text" placeholder="e.g. 25.00" style="width:120px;">' .
			'&nbsp;<input type="text" id="mm_howto_cost_currency" name="mm_schema_fields[howto_cost_currency]" value="%s" class="regular-text" placeholder="USD" style="width:80px;">' .
			'<p class="description">Cost value and ISO 4217 currency code. Leave blank to omit.</p></td></tr>',
			esc_attr( $cost_amount ),
			esc_attr( $cost_currency )
		);

		echo '</tbody></table>';

		// Dynamic supply list.
		$supply_count = 0;
		for ( $i = 1; $i <= 20; $i++ ) {
			if ( ! empty( $saved[ "howto_supply_{$i}" ] ) ) {
				$supply_count = $i;
			}
		}
		if ( $supply_count < 1 ) {
			$supply_count = 1;
		}

		echo '<h4>Materials (Supply)</h4>';
		echo '<div id="mm-howto-supplies">';
		for ( $i = 1; $i <= $supply_count; $i++ ) {
			$val = $saved[ "howto_supply_{$i}" ] ?? '';
			printf(
				'<div class="mm-howto-supply" style="display:flex;gap:8px;margin-bottom:6px;">' .
				'<input type="text" name="mm_schema_fields[howto_supply_%d]" value="%s" class="regular-text" placeholder="e.g. 2 cups flour" style="flex:1;">' .
				'<button type="button" class="button mm-remove-howto-supply">&times;</button>' .
				'</div>',
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %d placeholder receives the loop integer $i.
				$i,
				esc_attr( $val )
			);
		}
		echo '</div>';
		printf(
			'<p><button type="button" id="mm-add-howto-supply" class="button button-secondary">+ Add Material</button></p>'
		);

		// Dynamic tool list.
		$tool_count = 0;
		for ( $i = 1; $i <= 20; $i++ ) {
			if ( ! empty( $saved[ "howto_tool_{$i}" ] ) ) {
				$tool_count = $i;
			}
		}
		if ( $tool_count < 1 ) {
			$tool_count = 1;
		}

		echo '<h4>Tools</h4>';
		echo '<div id="mm-howto-tools">';
		for ( $i = 1; $i <= $tool_count; $i++ ) {
			$val = $saved[ "howto_tool_{$i}" ] ?? '';
			printf(
				'<div class="mm-howto-tool" style="display:flex;gap:8px;margin-bottom:6px;">' .
				'<input type="text" name="mm_schema_fields[howto_tool_%d]" value="%s" class="regular-text" placeholder="e.g. Screwdriver" style="flex:1;">' .
				'<button type="button" class="button mm-remove-howto-tool">&times;</button>' .
				'</div>',
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %d placeholder receives the loop integer $i.
				$i,
				esc_attr( $val )
			);
		}
		echo '</div>';
		printf(
			'<p><button type="button" id="mm-add-howto-tool" class="button button-secondary">+ Add Tool</button></p>'
		);

		// Dynamic steps.
		$step_count = 0;
		for ( $i = 1; $i <= 20; $i++ ) {
			if ( ! empty( $saved[ "howto_step_name_{$i}" ] ) || ! empty( $saved[ "howto_step_text_{$i}" ] ) ) {
				$step_count = $i;
			}
		}
		if ( $step_count < 1 ) {
			$step_count = 1;
		}

		echo '<h4>Steps</h4>';
		echo '<div id="mm-howto-steps">';
		for ( $i = 1; $i <= $step_count; $i++ ) {
			self::render_how_to_step( $i, $saved );
		}
		echo '</div>';
		printf(
			'<p><button type="button" id="mm-add-howto-step" class="button button-secondary">+ Add Step</button></p>'
		);
		?>
		<script>
		(function(){
			var supplyCount = <?php echo esc_js( (string) $supply_count ); ?>;
			var toolCount = <?php echo esc_js( (string) $tool_count ); ?>;
			var stepCount = <?php echo esc_js( (string) $step_count ); ?>;

			// Supply add/remove.
			document.getElementById('mm-add-howto-supply').addEventListener('click', function(){
				supplyCount++;
				var div = document.createElement('div');
				div.className = 'mm-howto-supply';
				div.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
				div.innerHTML = '<input type="text" name="mm_schema_fields[howto_supply_' + supplyCount + ']" value="" class="regular-text" placeholder="e.g. 2 cups flour" style="flex:1;"><button type="button" class="button mm-remove-howto-supply">&times;</button>';
				document.getElementById('mm-howto-supplies').appendChild(div);
			});
			document.getElementById('mm-howto-supplies').addEventListener('click', function(e){
				if(e.target.classList.contains('mm-remove-howto-supply')) e.target.closest('.mm-howto-supply').remove();
			});

			// Tool add/remove.
			document.getElementById('mm-add-howto-tool').addEventListener('click', function(){
				toolCount++;
				var div = document.createElement('div');
				div.className = 'mm-howto-tool';
				div.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
				div.innerHTML = '<input type="text" name="mm_schema_fields[howto_tool_' + toolCount + ']" value="" class="regular-text" placeholder="e.g. Screwdriver" style="flex:1;"><button type="button" class="button mm-remove-howto-tool">&times;</button>';
				document.getElementById('mm-howto-tools').appendChild(div);
			});
			document.getElementById('mm-howto-tools').addEventListener('click', function(e){
				if(e.target.classList.contains('mm-remove-howto-tool')) e.target.closest('.mm-howto-tool').remove();
			});

			// Step add/remove.
			document.getElementById('mm-add-howto-step').addEventListener('click', function(){
				stepCount++;
				var div = document.createElement('div');
				div.className = 'mm-howto-step';
				div.style.cssText = 'background:#f0f0f1;padding:12px;margin-bottom:12px;border-radius:4px;position:relative;';
				div.innerHTML =
					'<button type="button" class="button mm-remove-howto-step" style="position:absolute;top:8px;right:8px;">&times;</button>' +
					'<p><strong>Step ' + stepCount + '</strong></p>' +
					'<table class="form-table"><tbody>' +
					'<tr><th><label for="mm_howto_step_name_' + stepCount + '">Step Name</label></th>' +
					'<td><input type="text" id="mm_howto_step_name_' + stepCount + '" name="mm_schema_fields[howto_step_name_' + stepCount + ']" value="" class="regular-text" placeholder="e.g. Preheat the oven"></td></tr>' +
					'<tr><th><label for="mm_howto_step_text_' + stepCount + '">Step Instructions</label></th>' +
					'<td><textarea id="mm_howto_step_text_' + stepCount + '" name="mm_schema_fields[howto_step_text_' + stepCount + ']" rows="4" class="large-text" placeholder="Detailed instructions for this step..."></textarea></td></tr>' +
					'<tr><th><label for="mm_howto_step_image_' + stepCount + '">Step Image URL</label></th>' +
					'<td><input type="url" id="mm_howto_step_image_' + stepCount + '" name="mm_schema_fields[howto_step_image_' + stepCount + ']" value="" class="regular-text" placeholder="https://example.com/step-image.jpg"></td></tr>' +
					'</tbody></table>';
				document.getElementById('mm-howto-steps').appendChild(div);
			});
			document.getElementById('mm-howto-steps').addEventListener('click', function(e){
				if(e.target.classList.contains('mm-remove-howto-step')) e.target.closest('.mm-howto-step').remove();
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render a single HowTo step.
	 *
	 * @param int   $i I.
	 * @param array $saved Saved.
	 */
	private static function render_how_to_step( int $i, array $saved ): void {
		$name  = $saved[ "howto_step_name_{$i}" ] ?? '';
		$text  = $saved[ "howto_step_text_{$i}" ] ?? '';
		$image = $saved[ "howto_step_image_{$i}" ] ?? '';
		$si    = (string) $i;
		?>
		<div class="mm-howto-step" style="background:#f0f0f1;padding:12px;margin-bottom:12px;border-radius:4px;position:relative;">
			<button type="button" class="button mm-remove-howto-step" style="position:absolute;top:8px;right:8px;">&times;</button>
			<p><strong>Step <?php echo esc_html( $si ); ?></strong></p>
			<table class="form-table"><tbody>
				<tr><th><label for="mm_howto_step_name_<?php echo esc_attr( $si ); ?>">Step Name</label></th>
				<td><input type="text" id="mm_howto_step_name_<?php echo esc_attr( $si ); ?>" name="mm_schema_fields[howto_step_name_<?php echo esc_attr( $si ); ?>]" value="<?php echo esc_attr( $name ); ?>" class="regular-text" placeholder="e.g. Preheat the oven"></td></tr>
				<tr><th><label for="mm_howto_step_text_<?php echo esc_attr( $si ); ?>">Step Instructions</label></th>
				<td><textarea id="mm_howto_step_text_<?php echo esc_attr( $si ); ?>" name="mm_schema_fields[howto_step_text_<?php echo esc_attr( $si ); ?>]" rows="4" class="large-text" placeholder="Detailed instructions for this step..."><?php echo esc_textarea( $text ); ?></textarea></td></tr>
				<tr><th><label for="mm_howto_step_image_<?php echo esc_attr( $si ); ?>">Step Image URL</label></th>
				<td><input type="url" id="mm_howto_step_image_<?php echo esc_attr( $si ); ?>" name="mm_schema_fields[howto_step_image_<?php echo esc_attr( $si ); ?>]" value="<?php echo esc_attr( $image ); ?>" class="regular-text" placeholder="https://example.com/step-image.jpg"></td></tr>
			</tbody></table>
		</div>
		<?php
	}

	/**
	 * Render WooCommerce product linking section for Event/Service.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $slug Slug.
	 */
	private static function render_wc_product_linking_section( \WP_Post $post, string $slug ): void {
		$linked_product_id = (int) get_post_meta( $post->ID, '_mm_wc_product_id', true );
		$product_title     = $linked_product_id ? get_the_title( $linked_product_id ) : '';

		$nonce = wp_create_nonce( 'mm_wc_product_link' );
		?>
		<div class="mm-wc-product-linking" style="background:#f0f0f1;padding:15px;margin-bottom:20px;border-radius:4px;">
			<h3 style="margin-top:0;">WooCommerce Product</h3>
			<p class="description">Link this <?php echo esc_html( strtolower( 'mm_event' === $slug ? 'Event' : 'Service' ) ); ?> to a WooCommerce product for purchasing.</p>
			<table class="form-table">
				<tbody>
					<tr>
						<th><label for="mm_wc_product">Linked Product</label></th>
						<td>
							<input type="hidden" id="mm_wc_product_id" name="mm_wc_product_id" value="<?php echo esc_attr( (string) $linked_product_id ); ?>">
							<input type="text" id="mm_wc_product" value="<?php echo esc_attr( $product_title ); ?>" class="regular-text" placeholder="Search for a product...">
							<button type="button" id="mm_wc_product_search" class="button">Search</button>
							<?php if ( $linked_product_id ) : ?>
								<button type="button" id="mm_wc_product_clear" class="button">Remove</button>
								<p class="description">Linked to: <a href="<?php echo esc_url( get_edit_post_link( $linked_product_id ) ); ?>"><?php echo esc_html( $product_title ); ?></a></p>
							<?php endif; ?>
							<p class="description">Product data (price, availability) will be used in schema output.</p>
						</td>
					</tr>
				</tbody>
			</table>
			<script>
			jQuery(function($) {
				$('#mm_wc_product_search').on('click', function() {
					var search = $('#mm_wc_product').val();
					if (!search) return;
					$.ajax({
						url: ajaxurl,
						data: {
							action: 'mm_search_wc_products',
							search: search,
							nonce: '<?php echo esc_js( $nonce ); ?>'
						},
						success: function(response) {
							if (response.data && response.data.length) {
								var options = response.data.map(function(p) {
									return '<option value="' + p.id + '">' + p.title + '</option>';
								}).join('');
								// Show results in a simple prompt for now.
								var selected = prompt('Select a product:\n' + response.data.map(function(p, i) {
									return (i+1) + '. ' + p.title;
								}).join('\n'));
								if (selected) {
									var idx = parseInt(selected) - 1;
									if (response.data[idx]) {
										$('#mm_wc_product_id').val(response.data[idx].id);
										$('#mm_wc_product').val(response.data[idx].title);
									}
								}
							}
						}
					});
				});
				$('#mm_wc_product_clear').on('click', function() {
					$('#mm_wc_product_id').val('');
					$('#mm_wc_product').val('');
					$(this).hide();
				});
			});
			</script>
		</div>
		<?php
	}

	/**
	 * Render vessel linking section for Trip CPT.
	 *
	 * Allows linking a Trip to a Vessel post, similar to WC product linking.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private static function render_vessel_linking_section( \WP_Post $post ): void {
		$vessel_id    = (int) get_post_meta( $post->ID, '_mm_vessel_id', true );
		$vessel_title = $vessel_id ? get_the_title( $vessel_id ) : '';
		$nonce        = wp_create_nonce( 'mm_vessel_link' );
		?>
		<div class="mm-vessel-linking" style="background:#f0f0f1;padding:15px;margin-bottom:20px;border-radius:4px;">
			<h3 style="margin-top:0;">Vessel</h3>
			<p class="description">Link this trip to a vessel listing.</p>
			<table class="form-table">
				<tbody>
					<tr>
						<th><label for="mm_vessel">Linked Vessel</label></th>
						<td>
							<input type="hidden" id="mm_vessel_id" name="mm_vessel_id" value="<?php echo esc_attr( (string) $vessel_id ); ?>">
							<input type="text" id="mm_vessel" value="<?php echo esc_attr( $vessel_title ); ?>" class="regular-text" placeholder="Search for a vessel...">
							<button type="button" id="mm_vessel_search" class="button">Search</button>
							<?php if ( $vessel_id ) : ?>
								<button type="button" id="mm_vessel_clear" class="button">Remove</button>
								<p class="description">Linked to: <a href="<?php echo esc_url( get_edit_post_link( $vessel_id ) ); ?>"><?php echo esc_html( $vessel_title ); ?></a></p>
							<?php endif; ?>
							<p class="description">Vessel specs (capacity, type, amenities) will be used in schema output.</p>
						</td>
					</tr>
				</tbody>
			</table>
			<script>
			jQuery(function($) {
				$('#mm_vessel_search').on('click', function() {
					var search = $('#mm_vessel').val();
					if (!search) return;
					$.ajax({
						url: ajaxurl,
						data: {
							action: 'mm_search_vessels',
							search: search,
							nonce: '<?php echo esc_js( $nonce ); ?>'
						},
						success: function(response) {
							if (response.data && response.data.length) {
								var selected = prompt('Select a vessel:\n' + response.data.map(function(p, i) {
									return (i+1) + '. ' + p.title;
								}).join('\n'));
								if (selected) {
									var idx = parseInt(selected) - 1;
									if (response.data[idx]) {
										$('#mm_vessel_id').val(response.data[idx].id);
										$('#mm_vessel').val(response.data[idx].title);
									}
								}
							}
						}
					});
				});
				$('#mm_vessel_clear').on('click', function() {
					$('#mm_vessel_id').val('');
					$('#mm_vessel').val('');
					$(this).hide();
				});
			});
			</script>
		</div>
		<?php
	}

	/**
	 * Render destination linking section for Trip CPT.
	 *
	 * Allows linking a Trip to a Destination post.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private static function render_destination_linking_section( \WP_Post $post ): void {
		$dest_id    = (int) get_post_meta( $post->ID, '_mm_destination_id', true );
		$dest_title = $dest_id ? get_the_title( $dest_id ) : '';
		$nonce      = wp_create_nonce( 'mm_destination_link' );
		?>
		<div class="mm-destination-linking" style="background:#f0f0f1;padding:15px;margin-bottom:20px;border-radius:4px;">
			<h3 style="margin-top:0;">Destination</h3>
			<p class="description">Link this trip to a destination.</p>
			<table class="form-table">
				<tbody>
					<tr>
						<th><label for="mm_destination">Linked Destination</label></th>
						<td>
							<input type="hidden" id="mm_destination_id" name="mm_destination_id" value="<?php echo esc_attr( (string) $dest_id ); ?>">
							<input type="text" id="mm_destination" value="<?php echo esc_attr( $dest_title ); ?>" class="regular-text" placeholder="Search for a destination...">
							<button type="button" id="mm_destination_search" class="button">Search</button>
							<?php if ( $dest_id ) : ?>
								<button type="button" id="mm_destination_clear" class="button">Remove</button>
								<p class="description">Linked to: <a href="<?php echo esc_url( get_edit_post_link( $dest_id ) ); ?>"><?php echo esc_html( $dest_title ); ?></a></p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
			<script>
			jQuery(function($) {
				$('#mm_destination_search').on('click', function() {
					var search = $('#mm_destination').val();
					if (!search) return;
					$.ajax({
						url: ajaxurl,
						data: {
							action: 'mm_search_destinations',
							search: search,
							nonce: '<?php echo esc_js( $nonce ); ?>'
						},
						success: function(response) {
							if (response.data && response.data.length) {
								var selected = prompt('Select a destination:\n' + response.data.map(function(p, i) {
									return (i+1) + '. ' + p.title;
								}).join('\n'));
								if (selected) {
									var idx = parseInt(selected) - 1;
									if (response.data[idx]) {
										$('#mm_destination_id').val(response.data[idx].id);
										$('#mm_destination').val(response.data[idx].title);
									}
								}
							}
						}
					});
				});
				$('#mm_destination_clear').on('click', function() {
					$('#mm_destination_id').val('');
					$('#mm_destination').val('');
					$(this).hide();
				});
			});
			</script>
		</div>
		<?php
	}

	/**
	 * AJAX handler for destination search.
	 */
	public static function ajax_search_destinations(): void {
		check_ajax_referer( 'mm_destination_link', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$search = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
		if ( ! $search ) {
			wp_send_json_error( 'Empty search' );
		}
		$query   = new \WP_Query(
			array(
				'post_type'      => 'mm_destination',
				's'              => $search,
				'posts_per_page' => 10,
				'post_status'    => 'publish',
			)
		);
		$results = array();
		foreach ( $query->posts as $dest ) {
			$results[] = array(
				'id'    => $dest->ID,
				'title' => get_the_title( $dest ),
			);
		}
		wp_send_json_success( $results );
	}

	/**
	 * AJAX handler for vessel search.
	 */
	public static function ajax_search_vessels(): void {
		check_ajax_referer( 'mm_vessel_link', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$search = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
		if ( ! $search ) {
			wp_send_json_error( 'Empty search' );
		}
		$query   = new \WP_Query(
			array(
				'post_type'      => 'mm_vessel',
				's'              => $search,
				'posts_per_page' => 10,
				'post_status'    => 'publish',
			)
		);
		$results = array();
		foreach ( $query->posts as $vessel ) {
			$results[] = array(
				'id'    => $vessel->ID,
				'title' => get_the_title( $vessel ),
			);
		}
		wp_send_json_success( $results );
	}

	/**
	 * Save meta box data.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 */
	public static function save_meta( int $post_id, \WP_Post $post ): void {
		$is_schema_cpt  = isset( self::TYPES[ $post->post_type ] );
		$template       = get_page_template_slug( $post_id );
		$is_schema_page = 'page' === $post->post_type && isset( self::TEMPLATES[ $template ] );

		if ( ! $is_schema_cpt && ! $is_schema_page ) {
			return;
		}
		if ( ! isset( $_POST['mm_schema_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mm_schema_nonce'] ) ), 'mm_schema_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array read; every element is sanitized in the loop below.
		$raw_fields = (array) wp_unslash( $_POST['mm_schema_fields'] ?? array() );
		$fields     = array();
		foreach ( $raw_fields as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$fields[ sanitize_key( (string) $key ) ] = sanitize_text_field( $value );
		}
		update_post_meta( $post_id, 'mm_schema_fields', $fields );

		$bc_label = sanitize_text_field( wp_unslash( $_POST['mm_breadcrumb_label'] ?? '' ) );
		update_post_meta( $post_id, 'mm_breadcrumb_label', $bc_label );

		// WooCommerce product linking (Event/Service metabox section).
		$wc_product_id = isset( $_POST['mm_wc_product_id'] ) ? absint( $_POST['mm_wc_product_id'] ) : 0;
		if ( $wc_product_id > 0 ) {
			update_post_meta( $post_id, '_mm_wc_product_id', $wc_product_id );
		} else {
			delete_post_meta( $post_id, '_mm_wc_product_id' );
		}

		// Vessel linking (Trip metabox section).
		$vessel_id = isset( $_POST['mm_vessel_id'] ) ? absint( $_POST['mm_vessel_id'] ) : 0;
		if ( $vessel_id > 0 ) {
			update_post_meta( $post_id, '_mm_vessel_id', $vessel_id );
		} else {
			delete_post_meta( $post_id, '_mm_vessel_id' );
		}

		// Destination linking (Trip metabox section).
		$dest_id = isset( $_POST['mm_destination_id'] ) ? absint( $_POST['mm_destination_id'] ) : 0;
		if ( $dest_id > 0 ) {
			update_post_meta( $post_id, '_mm_destination_id', $dest_id );
		} else {
			delete_post_meta( $post_id, '_mm_destination_id' );
		}
	}

	// -------------------------------------------------------------------------
	// Archive rendering
	// -------------------------------------------------------------------------

	/**
	 * Render a CPT archive listing with the selected layout.
	 *
	 * @param string $slug CPT slug (e.g. 'mm_service').
	 */
	public static function render_archive( string $slug ): void {
		$layout = MM_Customizer::get_layout( $slug );
		?>
		<div class="mm-archive mm-archive--<?php echo esc_attr( $layout ); ?>" data-cpt="<?php echo esc_attr( $slug ); ?>">
		<?php if ( have_posts() ) : ?>
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<article id="post-<?php the_ID(); ?>" <?php post_class( 'mm-archive-card mm-archive-card--' . $layout ); ?>>
					<?php if ( has_post_thumbnail() ) : ?>
						<a class="mm-archive-card__image" href="<?php the_permalink(); ?>">
							<?php the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy' ) ); ?>
						</a>
					<?php endif; ?>
					<div class="mm-archive-card__body">
						<h2 class="mm-archive-card__title">
							<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
						</h2>
						<?php
						// Schema field details (price, etc.) after title.
						$saved_fields = get_post_meta( get_the_ID(), 'mm_schema_fields', true );
						if ( is_array( $saved_fields ) && ! empty( $saved_fields ) ) {
							$detail_items = array();
							$field_defs   = MM_Schema_Types::get_fields_by_type()[ self::slug_to_type( $slug ) ] ?? array();
							$label_map    = array();
							foreach ( $field_defs as $fdef ) {
								$label_map[ $fdef['key'] ?? '' ] = $fdef['label'] ?? ( $fdef['key'] ?? '' );
							}
							foreach ( $saved_fields as $fkey => $fval ) {
								$fval = trim( (string) $fval );
								if ( '' === $fval ) {
									continue;
								}
								$flabel         = $label_map[ $fkey ] ?? ucwords( str_replace( '_', ' ', (string) $fkey ) );
								$detail_items[] = array(
									'label' => $flabel,
									'value' => $fval,
								);
							}
							if ( $detail_items ) :
								?>
						<div class="mm-archive-card__details">
								<?php foreach ( $detail_items as $d ) : ?>
							<div class="mm-archive-card__detail-row">
								<span class="mm-archive-card__detail-label"><?php echo esc_html( $d['label'] ); ?>:</span>
								<span class="mm-archive-card__detail-value"><?php echo esc_html( $d['value'] ); ?></span>
							</div>
							<?php endforeach; ?>
						</div>
							<?php endif; } ?>
						<?php if ( has_excerpt() ) : ?>
							<div class="mm-archive-card__excerpt"><?php the_excerpt(); ?></div>
						<?php endif; ?>
						<a class="mm-archive-card__link" href="<?php the_permalink(); ?>"><?php echo esc_html( MM_Listing_Card_CSS::get_button_text() ); ?></a>
					</div>
				</article>
			<?php endwhile; ?>
		<?php else : ?>
			<p>No items are currently listed.</p>
		<?php endif; ?>
		</div>
		<?php
		the_posts_pagination();
	}

	/**
	 * Get archive CSS for both layouts.
	 */
	public static function get_archive_css(): string {
		return file_exists( MM_META_DIR . 'assets/css/archive-layouts.css' )
			? (string) file_get_contents( MM_META_DIR . 'assets/css/archive-layouts.css' )
			: '';
	}

	// -------------------------------------------------------------------------
	// Page templates
	// -------------------------------------------------------------------------

	/**
	 * Load plugin template files for schema CPTs and page templates.
	 *
	 * Handles both page templates (mm-about, mm-contact, etc.) and
	 * CPT single/archive templates (mm_trip, mm_destination, etc.).
	 * No theme dependency — plugin provides its own templates.
	 *
	 * @param string $template Template.
	 */
	public static function load_page_template( string $template ): string {
		// CPT single templates.
		$cpt_slugs = array_keys( self::TYPES );
		foreach ( $cpt_slugs as $slug ) {
			if ( is_singular( $slug ) ) {
				$tpl = MM_META_DIR . 'templates/single-' . $slug . '.php';
				if ( file_exists( $tpl ) ) {
					return $tpl;
				}
			}
		}

		// CPT archive templates.
		foreach ( $cpt_slugs as $slug ) {
			if ( is_post_type_archive( $slug ) ) {
				$tpl = MM_META_DIR . 'templates/archive-' . $slug . '.php';
				if ( file_exists( $tpl ) ) {
					return $tpl;
				}
			}
		}

		// Page templates (mm-about, mm-contact, mm-calendar, etc.).
		if ( ! is_singular( 'page' ) ) {
			return $template;
		}

		$post = get_post();
		if ( ! $post ) {
			return $template;
		}

		$page_template = get_page_template_slug( $post->ID );
		if ( ! isset( self::TEMPLATES[ $page_template ] ) ) {
			return $template;
		}

		$plugin_template = MM_META_DIR . 'templates/page-' . $page_template . '.php';
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $template;
	}

	/**
	 * Check if a page template slug is a schema template.
	 *
	 * @param string $template Template.
	 */
	public static function is_schema_template( string $template ): bool {
		return isset( self::TEMPLATES[ $template ] );
	}

	/**
	 * Convert a page template slug to its schema type name.
	 *
	 * @param string $template Template.
	 */
	public static function template_to_type( string $template ): ?string {
		return self::TEMPLATES[ $template ] ?? null;
	}

	/**
	 * Get the schema template slug for a post, or null if not using one.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public static function get_post_template( \WP_Post $post ): ?string {
		if ( 'page' !== $post->post_type ) {
			return null;
		}
		$template = get_page_template_slug( $post->ID );
		return isset( self::TEMPLATES[ $template ] ) ? $template : null;
	}

	// -------------------------------------------------------------------------
	// Migration (legacy)
	// -------------------------------------------------------------------------

	/**
	 * Migrate a post from its current type to the appropriate schema CPT.
	 *
	 * @param int    $post_id     Post ID to migrate.
	 * @param string $schema_type Target schema type (e.g. 'Event', 'Product').
	 * @return string|false New post type slug on success, false on failure.
	 */
	public static function migrate_post( int $post_id, string $schema_type ) {
		$slug = self::type_to_slug( $schema_type );
		if ( ! $slug ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		// Don't migrate if already the right type.
		if ( $post->post_type === $slug ) {
			return $slug;
		}

		// Don't migrate revisions or attachments.
		if ( in_array( $post->post_type, array( 'revision', 'attachment', 'nav_menu_item', 'custom_css', 'customize_changeset' ), true ) ) {
			return false;
		}

		// Move existing schema_fields meta to the new meta key.
		$old_meta = get_post_meta( $post_id, '_mm_meta', true );
		if ( is_array( $old_meta ) && ! empty( $old_meta['schema_fields'] ) ) {
			update_post_meta( $post_id, 'mm_schema_fields', $old_meta['schema_fields'] );
		}
		if ( is_array( $old_meta ) && ! empty( $old_meta['breadcrumb_label'] ) ) {
			update_post_meta( $post_id, 'mm_breadcrumb_label', $old_meta['breadcrumb_label'] );
		}

		// Change post type.
		$result = wp_update_post(
			array(
				'ID'        => $post_id,
				'post_type' => $slug,
			),
			true
		);

		return is_wp_error( $result ) ? false : $slug;
	}

	/**
	 * Convert a schema type name to its CPT slug.
	 *
	 * @param string $schema_type Schema type.
	 */
	public static function type_to_slug( string $schema_type ): ?string {
		foreach ( self::TYPES as $slug => $config ) {
			if ( $config[0] === $schema_type ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * Convert a CPT slug to its schema type name.
	 *
	 * @param string $slug Slug.
	 */
	public static function slug_to_type( string $slug ): ?string {
		return self::TYPES[ $slug ][0] ?? null;
	}

	/**
	 * Get all registered schema CPT slugs.
	 */
	public static function get_slugs(): array {
		return array_keys( self::TYPES );
	}

	/**
	 * Check if a post type is a schema CPT.
	 *
	 * @param string $post_type Post type.
	 */
	public static function is_schema_cpt( string $post_type ): bool {
		return isset( self::TYPES[ $post_type ] );
	}

	/**
	 * Render the schema fields as a consistent details table at the foot of
	 * a singular CPT page. Google expects schema.org metadata to be visible
	 * on the page it describes; this block is the built-in, uniform display
	 * for every schema CPT. The layout is deliberately plain — a definition
	 * list of the filled fields in field-definition order — because these
	 * are details, not decoration. URL fields render as links.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function render_details_table( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || ! self::is_schema_cpt( $post->post_type ) ) {
			return;
		}
		$type = self::slug_to_type( $post->post_type );
		if ( ! $type ) {
			return;
		}
		$saved = get_post_meta( $post_id, 'mm_schema_fields', true );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return;
		}
		$fields = MM_Schema_Types::get_fields_by_type()[ $type ] ?? array();
		$rows   = '';
		$seen   = array();
		foreach ( $fields as $field ) {
			$key          = $field['key'] ?? '';
			$value        = trim( (string) ( $saved[ $key ] ?? '' ) );
			$seen[ $key ] = true;
			if ( '' === $value ) {
				continue;
			}
			$label = $field['label'] ?? $key;
			if ( ( $field['type'] ?? '' ) === 'url' && filter_var( $value, FILTER_VALIDATE_URL ) ) {
				$display = '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener">' . esc_html( $value ) . '</a>';
			} else {
				$display = esc_html( $value );
			}
			$rows .= '<div class="mm-detail-row"><dt>' . esc_html( $label ) . '</dt><dd>' . $display . '</dd></div>';
		}
		// Dynamic keys (FAQ question/answer pairs, HowTo supplies, tools,
		// steps) have no static definitions; display them too so every
		// piece of schema data the post carries is visible on the page.
		foreach ( $saved as $key => $value ) {
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$label = ucwords( str_replace( '_', ' ', (string) $key ) );
			$rows .= '<div class="mm-detail-row"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		if ( '' === $rows ) {
			return;
		}
		echo '<style>.mm-schema-details{margin:48px 0 24px 0}.mm-schema-details h2{font-size:20px;border-bottom:1px solid #ccc;padding-bottom:6px;margin:0 0 8px 0}.mm-details-list{margin:0}.mm-detail-row{display:flex;border-bottom:1px solid #eee;padding:8px 0}.mm-detail-row dt{flex:0 0 220px;font-weight:700}.mm-detail-row dd{margin:0}</style>';
		echo '<section class="mm-schema-details">';
		echo '<h2>' . esc_html__( 'Details', 'metamanager' ) . '</h2>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $rows is assembled from esc_html()/esc_url() parts above.
		echo '<dl class="mm-details-list">' . $rows . '</dl>';
		echo '</section>';
	}
}
