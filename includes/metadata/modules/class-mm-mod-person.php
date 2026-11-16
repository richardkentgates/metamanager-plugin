<?php
/**
 * MM_Mod_Person — Person/Professional card widget, block, and shortcode.
 *
 * Renders a staff member card with configurable image position and
 * emits Person schema. Designed for people who are not WordPress users
 * (e.g. salon stylists, contractors, team members).
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Person extends MM_Mod_Base {

	/**
	 * Image position options.
	 */
	public const POSITIONS = [
		'top'   => 'Top (stacked)',
		'left'  => 'Left (image + text side by side)',
		'right' => 'Right (text + image side by side)',
	];

	/**
	 * No-op — Person cards render inline via widget/block, not in the head.
	 */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_block' ] );
		add_action( 'widgets_init', [ $this, 'register_widget' ] );
		add_shortcode( 'mm_person', [ $this, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	// -------------------------------------------------------------------------
	// Block
	// -------------------------------------------------------------------------

	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'mm-meta-block-person',
			MM_META_URL . 'assets/js/block-person.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			MM_META_VERSION,
			true
		);

		register_block_type(
			'metamanager/person',
			[
				'editor_script'   => 'mm-meta-block-person',
				'render_callback' => [ $this, 'render_person' ],
				'attributes'      => [
					'name'          => [
						'type'    => 'string',
						'default' => '',
					],
					'jobTitle'      => [
						'type'    => 'string',
						'default' => '',
					],
					'description'   => [
						'type'    => 'string',
						'default' => '',
					],
					'imageId'       => [
						'type'    => 'number',
						'default' => 0,
					],
					'imagePosition' => [
						'type'    => 'string',
						'default' => 'top',
					],
					'schemaType'    => [
						'type'    => 'string',
						'default' => 'Person',
					],
					'sameAs'        => [
						'type'    => 'string',
						'default' => '',
					],
					'phone'         => [
						'type'    => 'string',
						'default' => '',
					],
					'email'         => [
						'type'    => 'string',
						'default' => '',
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Widget
	// -------------------------------------------------------------------------

	public function register_widget(): void {
		register_widget( 'MM_Person_Widget' );
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			[
				'name'           => '',
				'job_title'      => '',
				'description'    => '',
				'image_id'       => 0,
				'image_position' => 'top',
				'schema_type'    => 'Person',
				'same_as'        => '',
				'phone'          => '',
				'email'          => '',
			],
			$atts,
			'mm_person'
		);

		return $this->render_person(
			[
				'name'          => $atts['name'],
				'jobTitle'      => $atts['job_title'],
				'description'   => $atts['description'],
				'imageId'       => (int) $atts['image_id'],
				'imagePosition' => $atts['image_position'],
				'schemaType'    => $atts['schema_type'],
				'sameAs'        => $atts['same_as'],
				'phone'         => $atts['phone'],
				'email'         => $atts['email'],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets(): void {
		wp_enqueue_style(
			'mm-meta-person',
			MM_META_URL . 'assets/css/person-card.css',
			[],
			MM_META_VERSION
		);

		// Inject Button Styles from settings so Person buttons match sitewide styles.
		$s           = MM_Site_Settings::get_instance();
		$btn         = $s->get( 'listing', [] );
		$bg          = esc_attr( $btn['btn_bg'] ?? '#0073aa' );
		$color       = esc_attr( $btn['btn_text_color'] ?? '#ffffff' );
		$radius      = esc_attr( $btn['btn_radius'] ?? '4px' );
		$padding     = esc_attr( $btn['btn_padding'] ?? '10px 16px' );
		$font_size   = esc_attr( $btn['btn_font_size'] ?? '14px' );
		$font_weight = esc_attr( $btn['btn_font_weight'] ?? '500' );
		$css         = ".mm-person__phone-btn,.mm-person__email-btn{background:{$bg};color:{$color} !important;border-radius:{$radius};padding:{$padding};font-size:{$font_size};font-weight:{$font_weight};}";
		wp_add_inline_style( 'mm-meta-person', $css );
	}

	// -------------------------------------------------------------------------
	// Rendering — shared by block, widget, and shortcode
	// -------------------------------------------------------------------------

	/**
	 * Render a person card with schema.
	 *
	 * @param array $attributes Card data.
	 * @return string HTML.
	 */
	public function render_person( array $attributes = [] ): string {
		$name        = sanitize_text_field( $attributes['name'] ?? '' );
		$job_title   = sanitize_text_field( $attributes['jobTitle'] ?? $attributes['job_title'] ?? '' );
		$description = wp_kses_post( $attributes['description'] ?? '' );
		$image_id    = (int) ( $attributes['imageId'] ?? $attributes['image_id'] ?? 0 );
		$position    = sanitize_key( $attributes['imagePosition'] ?? $attributes['image_position'] ?? 'top' );
		$schema_type = sanitize_text_field( $attributes['schemaType'] ?? $attributes['schema_type'] ?? 'Person' );
		$same_as     = array_filter( array_map( 'esc_url_raw', explode( "\n", (string) ( $attributes['sameAs'] ?? $attributes['same_as'] ?? '' ) ) ) );
		$phone       = sanitize_text_field( $attributes['phone'] ?? '' );
		$email       = sanitize_email( $attributes['email'] ?? '' );

		if ( '' === $name ) {
			return '';
		}

		$position = isset( self::POSITIONS[ $position ] ) ? $position : 'top';
		$image    = $image_id ? wp_get_attachment_image( $image_id, 'medium', false, [ 'class' => 'mm-person__img' ] ) : '';

		// Schema node.
		$node = [
			'@type' => $schema_type,
			'name'  => $name,
		];
		if ( $job_title ) {
			$node['jobTitle'] = $job_title;
		}
		if ( $description ) {
			$node['description'] = wp_strip_all_tags( $description );
		}
		if ( $image_id ) {
			$src = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $src ) {
				$node['image'] = $src;
			}
		}
		if ( $same_as ) {
			$node['sameAs'] = count( $same_as ) === 1 ? $same_as[0] : array_values( $same_as );
		}
		if ( $phone ) {
			$node['telephone'] = $phone;
		}
		if ( $email ) {
			$node['email'] = $email;
		}

		// Organisation reference if set in business profile.
		$s = MM_Site_Settings::get_instance();
		if ( $s->get_business( 'name' ) ) {
			$node['worksFor'] = [ '@id' => $this->site_id( 'organization' ) ];
		}

		$schema_json = wp_json_encode(
			[
				'@context' => 'https://schema.org',
				'@graph'   => [ $node ],
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		ob_start();
		?>
		<div class="mm-person mm-person--<?php echo esc_attr( $position ); ?>">
			<?php if ( $image ) : ?>
			<div class="mm-person__image">
				<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image returns safe HTML. ?>
			</div>
			<?php endif; ?>
			<div class="mm-person__body">
				<h3 class="mm-person__name"><?php echo esc_html( $name ); ?></h3>
				<?php if ( $job_title ) : ?>
				<p class="mm-person__title"><?php echo esc_html( $job_title ); ?></p>
				<?php endif; ?>
				<?php if ( $description ) : ?>
				<div class="mm-person__bio"><?php echo wp_kses_post( $description ); ?></div>
				<?php endif; ?>
				<?php if ( $phone || $email ) : ?>
				<div class="mm-person__contact">
					<?php if ( $phone ) : ?>
					<a href="tel:<?php echo esc_attr( $phone ); ?>" class="mm-person__phone-btn">
						<span class="dashicons dashicons-phone" aria-hidden="true"></span>
						<span><?php echo esc_html( $phone ); ?></span>
					</a>
					<?php endif; ?>
					<?php if ( $email ) : ?>
					<a href="mailto:<?php echo esc_attr( $email ); ?>" class="mm-person__email-btn">
						<span class="dashicons dashicons-email" aria-hidden="true"></span>
						<span><?php echo esc_html( $email ); ?></span>
					</a>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<script type="application/ld+json"><?php echo $schema_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD. ?></script>
		<?php
		return ob_get_clean();
	}
}

/**
 * Person Widget — staff member card with schema.
 */
class MM_Person_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'mm_meta_person',
			__( 'GCM Person Card', 'metamanager' ),
			[
				'classname'   => 'mm-person-widget',
				'description' => __( 'Displays a person/professional card with schema markup. For staff who are not WordPress users.', 'metamanager' ),
			]
		);
	}

	public function widget( $args, $instance ): void {
		$module = new MM_Mod_Person( MM_Site_Settings::get_instance() );
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! empty( $instance['name'] ) ) {
			$html = $module->render_person( $instance );
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_person returns safe HTML.
		}

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ): void {
		$name        = ! empty( $instance['name'] ) ? $instance['name'] : '';
		$job_title   = ! empty( $instance['jobTitle'] ) ? $instance['jobTitle'] : '';
		$description = ! empty( $instance['description'] ) ? $instance['description'] : '';
		$image_id    = ! empty( $instance['imageId'] ) ? (int) $instance['imageId'] : 0;
		$position    = ! empty( $instance['imagePosition'] ) ? $instance['imagePosition'] : 'top';
		$schema_type = ! empty( $instance['schemaType'] ) ? $instance['schemaType'] : 'Person';
		$same_as     = ! empty( $instance['sameAs'] ) ? $instance['sameAs'] : '';
		$phone       = ! empty( $instance['phone'] ) ? $instance['phone'] : '';
		$email       = ! empty( $instance['email'] ) ? $instance['email'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'name' ) ); ?>">Name *</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'name' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'name' ) ); ?>"
					type="text" value="<?php echo esc_attr( $name ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'jobTitle' ) ); ?>">Job Title</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'jobTitle' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'jobTitle' ) ); ?>"
					type="text" value="<?php echo esc_attr( $job_title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'description' ) ); ?>">Bio / Description</label>
			<textarea class="widefat" rows="3"
						id="<?php echo esc_attr( $this->get_field_id( 'description' ) ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( 'description' ) ); ?>"><?php echo esc_textarea( $description ); ?></textarea>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'imageId' ) ); ?>">Image ID</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'imageId' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'imageId' ) ); ?>"
					type="number" value="<?php echo esc_attr( (string) $image_id ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'imagePosition' ) ); ?>">Image Position</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'imagePosition' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'imagePosition' ) ); ?>">
				<?php foreach ( MM_Mod_Person::POSITIONS as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $position, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'schemaType' ) ); ?>">Schema Type</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'schemaType' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'schemaType' ) ); ?>">
				<?php foreach ( [ 'Person', 'Physician', 'Dentist', 'Attorney', 'Accountant' ] as $t ) : ?>
				<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $schema_type, $t ); ?>><?php echo esc_html( $t ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'phone' ) ); ?>">Phone</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'phone' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'phone' ) ); ?>"
					type="text" value="<?php echo esc_attr( $phone ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'email' ) ); ?>">Email</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'email' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'email' ) ); ?>"
					type="text" value="<?php echo esc_attr( $email ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'sameAs' ) ); ?>">Social URLs (one per line)</label>
			<textarea class="widefat" rows="3"
						id="<?php echo esc_attr( $this->get_field_id( 'sameAs' ) ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( 'sameAs' ) ); ?>"><?php echo esc_textarea( $same_as ); ?></textarea>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ): array {
		return [
			'name'          => sanitize_text_field( $new_instance['name'] ?? '' ),
			'jobTitle'      => sanitize_text_field( $new_instance['jobTitle'] ?? '' ),
			'description'   => wp_kses_post( $new_instance['description'] ?? '' ),
			'imageId'       => (int) ( $new_instance['imageId'] ?? 0 ),
			'imagePosition' => sanitize_key( $new_instance['imagePosition'] ?? 'top' ),
			'schemaType'    => sanitize_text_field( $new_instance['schemaType'] ?? 'Person' ),
			'sameAs'        => esc_url_raw( $new_instance['sameAs'] ?? '' ),
			'phone'         => sanitize_text_field( $new_instance['phone'] ?? '' ),
			'email'         => sanitize_email( $new_instance['email'] ?? '' ),
		];
	}
}
