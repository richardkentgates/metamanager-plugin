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

class MM_Schema_Post_Types {

	/** Map of post type slug => [schema_type_label, supports, icon]. */
	private const TYPES = [
		'mm_event'       => [ 'Event',       [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'calendar-alt' ],
		'mm_service'     => [ 'Service',     [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'hammer' ],
		'mm_how_to'      => [ 'HowTo',       [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'list-view' ],
		'mm_about_page'  => [ 'AboutPage',   [ 'title', 'thumbnail' ], 'info' ],  // Editor disabled — content auto-generated from business info.
		'mm_contact_page'=> [ 'ContactPage', [ 'title', 'thumbnail' ], 'email-alt' ],  // Editor disabled — content auto-generated from business info.
		'mm_calendar'    => [ 'Calendar',    [ 'title', 'thumbnail' ], 'calendar' ],  // Editor disabled — auto-generated event calendar.
		'mm_faq_page'    => [ 'FAQPage',     [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'editor' ],
		// WebPage maps to default `page` type — not a separate CPT.
		// BlogPosting maps to default `post` type — not a separate CPT.
		// ProfilePage maps to author archive pages — not a separate CPT.
		// Article removed — redundant with WebPage + BlogPosting.
	];

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_post_types' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
		add_action( 'save_post', [ __CLASS__, 'save_meta' ], 10, 2 );
	}

	/**
	 * Register all schema custom post types.
	 */
	public static function register_post_types(): void {
		foreach ( self::TYPES as $slug => $config ) {
			$schema_type = $config[0];
			$supports    = $config[1];
			$icon        = $config[2];

			register_post_type( $slug, [
				'labels'       => [
					'name'               => $schema_type . 's',
					'singular_name'      => $schema_type,
					'add_new_item'       => 'Add New ' . $schema_type,
					'edit_item'          => 'Edit ' . $schema_type,
					'view_item'          => 'View ' . $schema_type,
					'search_items'       => 'Search ' . $schema_type . 's',
					'not_found'          => 'No ' . $schema_type . 's found',
					'not_found_in_trash' => 'No ' . $schema_type . 's found in Trash',
				],
				'public'       => true,
				'has_archive'  => true,
				'rewrite'      => [ 'slug' => strtolower( str_replace( 'mm_', '', $slug ) ) ],
				'supports'     => $supports,
				'menu_icon'    => 'dashicons-' . $icon,
				'show_in_rest' => true,
			] );
		}
	}

	/**
	 * Add meta boxes for each schema post type.
	 */
	public static function add_meta_boxes(): void {
		foreach ( self::TYPES as $slug => $config ) {
			$schema_type = $config[0];
			add_meta_box(
				'mm_schema_fields',
				$schema_type . ' Details',
				[ __CLASS__, 'render_meta_box' ],
				$slug,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render the meta box for a given schema post type.
	 */
	public static function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'mm_schema_meta', 'mm_schema_nonce' );

		$slug   = $post->post_type;
		$config = self::TYPES[ $slug ] ?? null;
		if ( ! $config ) {
			return;
		}

		$schema_type = $config[0];

		// Auto-generated CPTs: show preview, not fields.
		if ( 'mm_contact_page' === $slug ) {
			self::render_contact_page_meta_box( $post );
			return;
		}
		if ( 'mm_about_page' === $slug ) {
			self::render_about_page_meta_box( $post );
			return;
		}
		if ( 'mm_calendar' === $slug ) {
			self::render_calendar_meta_box( $post );
			return;
		}
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
		$type_fields = $fields[ $schema_type ] ?? [];
		$saved       = get_post_meta( $post->ID, 'mm_schema_fields', true );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		// WooCommerce product linking section for Event and Service.
		if ( $wc_active && in_array( $slug, [ 'mm_event', 'mm_service' ], true ) ) {
			self::render_wc_product_linking_section( $post, $slug );
		}

		if ( empty( $type_fields ) ) {
			echo '<p>This schema type has no additional fields — all data is auto-populated from the post content.</p>';
			return;
		}

		echo '<table class="form-table"><tbody>';

		// Get business profile defaults for auto-population.
		$biz = ( 'mm_event' === $slug ) ? MM_Site_Settings::get_instance()->all_business() : [];
		$biz_addr = $biz['address'] ?? [];

		foreach ( $type_fields as $field ) {
			$key         = $field['key'];
			$value       = $saved[ $key ] ?? '';
			$label       = $field['label'];
			$type        = $field['type'] ?? 'text';
			$required    = ! empty( $field['required'] );
			$placeholder = $field['placeholder'] ?? '';
			$desc        = $field['description'] ?? '';
			$options     = $field['options'] ?? [];

			// Build data attribute for auto-population from business profile.
			$biz_attr = '';
			if ( 'mm_event' === $slug ) {
				switch ( $key ) {
					case 'event_location_name':
						$biz_attr = ! empty( $biz['name'] ) ? sprintf( ' data-biz-default="%s"', esc_attr( $biz['name'] ) ) : '';
						break;
					case 'event_location_address':
						$full_addr = trim( implode( ', ', array_filter( [
							$biz_addr['street'] ?? '',
							$biz_addr['city'] ?? '',
							$biz_addr['state'] ?? '',
							$biz_addr['zip'] ?? '',
						] ) ) );
						$biz_attr = $full_addr ? sprintf( ' data-biz-default="%s"', esc_attr( $full_addr ) ) : '';
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

		// Event: add auto-populate JavaScript.
		if ( 'mm_event' === $slug ) {
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
	 * Render the ContactPage meta box — auto-generated from business info.
	 */
	private static function render_contact_page_meta_box( \WP_Post $post ): void {
		$settings = MM_Site_Settings::get_instance();
		$biz      = $settings->all_business();
		$name     = $biz['name'] ?? '';
		$phone    = $biz['phone'] ?? '';
		$email    = $biz['email'] ?? '';
		$addr     = $biz['address'] ?? [];
		$hours    = $biz['hours'] ?? [];
		$lat      = $biz['lat'] ?? '';
		$lng      = $biz['lng'] ?? '';

		echo '<div style="background:#f0f0f1;padding:16px;border-radius:4px;margin-bottom:16px;">';
		echo '<p style="margin:0 0 8px 0;"><strong>Contact Page</strong> is auto-generated from your Business Info settings.</p>';
		echo '<p style="margin:0;">Content and schema are built dynamically — no manual editing needed.</p>';
		echo '</div>';

		// Preview of what will render.
		echo '<h4>Preview</h4>';
		echo '<table class="form-table"><tbody>';

		printf( '<tr><th>Name</th><td>%s</td></tr>', esc_html( $name ?: '<em>Not set</em>' ) );
		printf( '<tr><th>Phone</th><td>%s</td></tr>', esc_html( $phone ?: '<em>Not set</em>' ) );
		printf( '<tr><th>Email</th><td>%s</td></tr>', esc_html( $email ?: '<em>Not set</em>' ) );

		$street = $addr['street'] ?? '';
		$city   = $addr['city'] ?? '';
		$state  = $addr['state'] ?? '';
		$zip    = $addr['zip'] ?? '';
		$full_addr = trim( "$street, $city, $state $zip", ', ' );
		printf( '<tr><th>Address</th><td>%s</td></tr>', esc_html( $full_addr ?: '<em>Not set</em>' ) );

		if ( $lat && $lng ) {
			printf( '<tr><th>Coordinates</th><td>%s, %s</td></tr>', esc_html( $lat ), esc_html( $lng ) );
		}

		if ( ! empty( $hours ) && is_array( $hours ) ) {
			$hours_str = implode( ', ', array_filter( $hours ) );
			printf( '<tr><th>Hours</th><td>%s</td></tr>', esc_html( $hours_str ?: '<em>Not set</em>' ) );
		}

		echo '</tbody></table>';

		// Link to settings.
		printf(
			'<p><a href="%s" class="button button-secondary">Edit Business Info</a></p>',
			esc_url( admin_url( 'admin.php?page=mm-settings&tab=mm_tab_biz' ) )
		);
	}

	/**
	 * Render the AboutPage meta box — auto-generated from business info.
	 */
	private static function render_about_page_meta_box( \WP_Post $post ): void {
		$settings  = MM_Site_Settings::get_instance();
		$biz       = $settings->all_business();
		$name      = $biz['name'] ?? '';
		$desc      = $biz['description'] ?? '';
		$phone     = $biz['phone'] ?? '';
		$email     = $biz['email'] ?? '';
		$addr      = $biz['address'] ?? [];
		$founding  = $biz['founding_date'] ?? '';
		$employees = $biz['number_of_employees'] ?? '';
		$areas     = $biz['service_areas'] ?? [];

		echo '<div style="background:#f0f0f1;padding:16px;border-radius:4px;margin-bottom:16px;">';
		echo '<p style="margin:0 0 8px 0;"><strong>About Page</strong> is auto-generated from your Business Info settings.</p>';
		echo '<p style="margin:0;">Content and schema are built dynamically — no manual editing needed.</p>';
		echo '</div>';

		// Preview of what will render.
		echo '<h4>Preview</h4>';
		echo '<table class="form-table"><tbody>';

		printf( '<tr><th>Name</th><td>%s</td></tr>', esc_html( $name ?: '<em>Not set</em>' ) );
		printf( '<tr><th>Description</th><td>%s</td></tr>', esc_html( $desc ?: '<em>Not set</em>' ) );

		if ( $founding ) {
			printf( '<tr><th>Founded</th><td>%s</td></tr>', esc_html( date_i18n( get_option( 'date_format' ), strtotime( $founding ) ) ) );
		}
		if ( $employees ) {
			printf( '<tr><th>Employees</th><td>%s</td></tr>', esc_html( $employees ) );
		}

		$street = $addr['street'] ?? '';
		$city   = $addr['city'] ?? '';
		$state  = $addr['state'] ?? '';
		$zip    = $addr['zip'] ?? '';
		$full_addr = trim( "$street, $city, $state $zip", ', ' );
		printf( '<tr><th>Address</th><td>%s</td></tr>', esc_html( $full_addr ?: '<em>Not set</em>' ) );

		if ( ! empty( $areas ) ) {
			printf( '<tr><th>Service Areas</th><td>%s</td></tr>', esc_html( implode( ', ', $areas ) ) );
		}

		echo '</tbody></table>';

		// Link to settings.
		printf(
			'<p><a href="%s" class="button button-secondary">Edit Business Info</a></p>',
			esc_url( admin_url( 'admin.php?page=mm-settings&tab=mm_tab_biz' ) )
		);
	}

	/**
	 * Render the Calendar meta box — auto-generated event calendar.
	 */
	private static function render_calendar_meta_box( \WP_Post $post ): void {
		$events = self::get_upcoming_events();

		echo '<div style="background:#f0f0f1;padding:16px;border-radius:4px;margin-bottom:16px;">';
		echo '<p style="margin:0 0 8px 0;"><strong>Calendar</strong> is auto-generated from your Event posts.</p>';
		echo '<p style="margin:0;">Events are pulled dynamically — no manual editing needed.</p>';
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
				esc_html( $loc ?: '—' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Get upcoming events (sorted by start date).
	 */
	private static function get_upcoming_events(): array {
		$query = new \WP_Query( [
			'post_type'      => 'mm_event',
			'posts_per_page' => 20,
			'meta_key'       => 'mm_schema_fields',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => 'mm_schema_fields',
					'compare' => 'EXISTS',
				],
			],
		] );
		return $query->posts;
	}

	/**
	 * Render the FAQPage meta box — dynamic Q&A pairs.
	 */
	private static function render_faq_page_meta_box( \WP_Post $post ): void {
		$saved = get_post_meta( $post->ID, 'mm_schema_fields', true );
		if ( ! is_array( $saved ) ) {
			$saved = [];
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
	 * Render the HowTo meta box — dynamic steps.
	 */
	private static function render_how_to_meta_box( \WP_Post $post ): void {
		$saved = get_post_meta( $post->ID, 'mm_schema_fields', true );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		// Count existing steps.
		$step_count = 0;
		for ( $i = 1; $i <= 20; $i++ ) {
			if ( ! empty( $saved[ "howto_step_name_{$i}" ] ) || ! empty( $saved[ "howto_step_text_{$i}" ] ) ) {
				$step_count = $i;
			}
		}
		if ( $step_count < 1 ) {
			$step_count = 1;
		}

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
			var stepCount = <?php echo esc_js( (string) $step_count ); ?>;
			var container = document.getElementById('mm-howto-steps');
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
				container.appendChild(div);
				div.querySelector('.mm-remove-howto-step').addEventListener('click', function(){
					div.remove();
				});
			});
			container.addEventListener('click', function(e){
				if(e.target.classList.contains('mm-remove-howto-step')){
					e.target.closest('.mm-howto-step').remove();
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render a single HowTo step.
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
	 */
	private static function render_wc_product_linking_section( \WP_Post $post, string $slug ): void {
		$linked_product_id = (int) get_post_meta( $post->ID, '_mm_wc_product_id', true );
		$product_title     = $linked_product_id ? get_the_title( $linked_product_id ) : '';

		$nonce = wp_create_nonce( 'mm_wc_product_link' );
		?>
		<div class="mm-wc-product-linking" style="background:#f0f0f1;padding:15px;margin-bottom:20px;border-radius:4px;">
			<h3 style="margin-top:0;">WooCommerce Product</h3>
			<p class="description">Link this <?php echo esc_html( strtolower( $slug === 'mm_event' ? 'Event' : 'Service' ) ); ?> to a WooCommerce product for purchasing.</p>
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
	 * Save meta box data.
	 */
	public static function save_meta( int $post_id, \WP_Post $post ): void {
		if ( ! isset( self::TYPES[ $post->post_type ] ) ) {
			return;
		}
		if ( ! isset( $_POST['mm_schema_nonce'] ) || ! wp_verify_nonce( $_POST['mm_schema_nonce'], 'mm_schema_meta' ) ) {
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

		$raw_fields = (array) ( $_POST['mm_schema_fields'] ?? [] );
		$fields     = [];
		foreach ( $raw_fields as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$fields[ sanitize_key( (string) $key ) ] = sanitize_text_field( wp_unslash( $value ) );
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
	}

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
		if ( in_array( $post->post_type, [ 'revision', 'attachment', 'nav_menu_item', 'custom_css', 'customize_changeset' ], true ) ) {
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
		$result = wp_update_post( [
			'ID'        => $post_id,
			'post_type' => $slug,
		], true );

		return is_wp_error( $result ) ? false : $slug;
	}

	/**
	 * Convert a schema type name to its CPT slug.
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
	 */
	public static function is_schema_cpt( string $post_type ): bool {
		return isset( self::TYPES[ $post_type ] );
	}
}
