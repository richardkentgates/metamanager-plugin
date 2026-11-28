<?php
/**
 * MM_Mod_Html_Sitemap — [mm_sitemap] shortcode.
 *
 * Renders a hierarchical HTML sitemap respecting noindex flags and
 * page exclusions. Parameters:
 *
 *   post_types  (comma-separated, default: page,post)
 *   taxonomies  (comma-separated, default: empty)
 *   depth       (int, default: 3, 0 = unlimited)
 *   exclude     (comma-separated post IDs)
 *   columns     (1–3, default: 1)
 *   show_date   (yes|no, default: no)
 *   order_by    (menu_order|title|date, default: menu_order)
 *   limit       (int, default: 500, max posts per flat query)
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Html_Sitemap extends MM_Mod_Base {

	/** Nothing to add to HTML head. */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {}

	public function register_hooks(): void {
		if ( $this->settings->get( 'sitemap.html_sitemap.enabled', true ) ) {
			add_shortcode( 'mm_sitemap', array( $this, 'render_shortcode' ) );
			add_action( 'init', array( $this, 'register_block' ) );
			add_action( 'widgets_init', array( $this, 'register_widget' ) );
		}
	}

	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'mm-block-sitemap',
			MM_META_URL . 'assets/js/block-sitemap.js',
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
			MM_META_VERSION,
			true
		);
		register_block_type(
			'metamanager/sitemap',
			array(
				'editor_script'   => 'mm-block-sitemap',
				'render_callback' => array( $this, 'render_shortcode' ),
				'attributes'      => array(
					'columns'  => array(
						'type'    => 'number',
						'default' => 1,
					),
					'orderBy'  => array(
						'type'    => 'string',
						'default' => 'menu_order',
					),
					'depth'    => array(
						'type'    => 'number',
						'default' => 3,
					),
					'showDate' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	public function register_widget(): void {
		register_widget( 'MM_Sitemap_Widget' );
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	public function render_shortcode( $atts ): string {
		wp_enqueue_style( 'mm-html-sitemap', MM_META_URL . 'assets/css/html-sitemap.css', array(), MM_META_VERSION );

		$cfg = $this->settings->get( 'sitemap.html_sitemap', array() );

		$flat_limit = max( 1, min( 5000, (int) ( $cfg['flat_limit'] ?? 500 ) ) );

		$atts = shortcode_atts(
			array(
				'post_types' => implode(
					',',
					array_merge(
						array( 'page', 'post' ),
						array_keys( MM_Schema_Post_Types::TYPES )
					)
				),
				'taxonomies' => 'category,post_tag',
				'depth'      => (int) ( $cfg['depth'] ?? 3 ),
				'exclude'    => '',
				'columns'    => (int) ( $cfg['columns'] ?? 1 ),
				'show_date'  => 'no',
				'order_by'   => $cfg['order_by'] ?? 'menu_order',
				'limit'      => $flat_limit,
			),
			$atts,
			'mm_sitemap'
		);

		$post_types = array_filter( array_map( 'trim', explode( ',', $atts['post_types'] ) ) );
		$taxonomies = array_filter( array_map( 'trim', explode( ',', $atts['taxonomies'] ) ) );
		$exclude    = array_filter( array_map( 'absint', explode( ',', $atts['exclude'] ) ) );
		$global_ex  = array_filter( array_map( 'absint', (array) ( $cfg['exclude_ids'] ?? array() ) ) );
		$exclude    = array_unique( array_merge( $exclude, $global_ex ) );
		$columns    = max( 1, min( 3, (int) $atts['columns'] ) );
		$show_date  = 'yes' === $atts['show_date'];
		$depth      = (int) $atts['depth'];
		$order_by   = in_array( $atts['order_by'], array( 'menu_order', 'title', 'date' ), true ) ? $atts['order_by'] : 'menu_order';
		$limit      = max( 1, min( 5000, (int) $atts['limit'] ) );

		$out = '<div class="gcm-html-sitemap gcm-html-sitemap--cols-' . $columns . '">';

		// Section order: pages → CPTs → posts → categories → tags.
		// Pages first (site structure), then custom post types, then core
		// posts, then category taxonomy, then remaining taxonomies (tags).
		$core_pts  = array( 'page' );
		$cpt_list  = array_diff( $post_types, array( 'page', 'post' ) );
		$post_only = in_array( 'post', $post_types, true ) ? array( 'post' ) : array();
		$core_tax  = array( 'category' );
		$other_tax = array_diff( $taxonomies, $core_tax );

		// 1. Pages
		foreach ( $core_pts as $pt ) {
			if ( in_array( $pt, $post_types, true ) ) {
				$out .= $this->render_post_type( $pt, $depth, $exclude, $show_date, $order_by, $limit );
			}
		}

		// 2. Custom post types (mm_service, mm_event, etc.)
		foreach ( $cpt_list as $pt ) {
			$out .= $this->render_post_type( $pt, $depth, $exclude, $show_date, $order_by, $limit );
		}

		// 3. Posts
		foreach ( $post_only as $pt ) {
			$out .= $this->render_post_type( $pt, $depth, $exclude, $show_date, $order_by, $limit );
		}

		// 4. Categories
		foreach ( $core_tax as $tax ) {
			if ( in_array( $tax, $taxonomies, true ) ) {
				$out .= $this->render_taxonomy( $tax );
			}
		}

		// 5. Remaining taxonomies (tags, custom taxonomies)
		foreach ( $other_tax as $taxonomy ) {
			$out .= $this->render_taxonomy( $taxonomy );
		}

		$out .= '</div>';

		return $out;
	}

	// -------------------------------------------------------------------------
	// Post-type section
	// -------------------------------------------------------------------------

	private function render_post_type( string $pt, int $depth, array $exclude, bool $show_date, string $order_by, int $limit ): string {
		$obj = get_post_type_object( $pt );
		if ( ! $obj ) {
			return '';
		}

		$is_hierarchical = $obj->hierarchical;

		if ( $is_hierarchical ) {
			$html = $this->render_hierarchical( $pt, 0, $depth, $exclude, $show_date, $order_by, 0, $limit );
		} else {
			$html = $this->render_flat( $pt, $exclude, $show_date, $order_by, $limit );
		}

		if ( ! $html ) {
			return '';
		}

		$label = esc_html( $obj->labels->name );
		$out   = '<section class="gcm-sitemap-section gcm-sitemap-section--' . esc_attr( $pt ) . '">';
		$out  .= '<h2 class="gcm-sitemap-heading">' . $label . '</h2>';
		$out  .= $html;
		$out  .= '</section>';
		return $out;
	}

	private function render_hierarchical( string $pt, int $parent, int $max_depth, array $exclude, bool $show_date, string $order_by, int $current_depth = 0, int $limit = 500 ): string {
		if ( $max_depth > 0 && $current_depth >= $max_depth ) {
			return '';
		}

		$query_args = array(
			'post_type'      => $pt,
			'post_status'    => 'publish',
			'post_parent'    => $parent,
			'posts_per_page' => $limit,
			'no_found_rows'  => true,
			'has_password'   => false,
			'post__not_in'   => $exclude ?: array( 0 ),
		);

		$query_args = $this->apply_order( $query_args, $order_by );
		$posts      = get_posts( $query_args );
		$posts      = $this->filter_noindex( $posts );

		if ( ! $posts ) {
			return '';
		}

		$out = '<ul class="gcm-sitemap-list">';
		foreach ( $posts as $post ) {
			$out .= '<li class="gcm-sitemap-item">';
			$out .= '<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
			if ( $show_date ) {
				$out .= ' <span class="gcm-sitemap-date">(' . esc_html( get_the_date( '', $post ) ) . ')</span>';
			}
			// Recurse into children.
			$children = $this->render_hierarchical( $pt, $post->ID, $max_depth, $exclude, $show_date, $order_by, $current_depth + 1, $limit );
			$out     .= $children;
			$out     .= '</li>';
		}
		$out .= '</ul>';
		return $out;
	}

	private function render_flat( string $pt, array $exclude, bool $show_date, string $order_by, int $limit = 500 ): string {
		$query_args = array(
			'post_type'      => $pt,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'no_found_rows'  => true,
			'has_password'   => false,
			'post__not_in'   => $exclude ?: array( 0 ),
		);

		$query_args = $this->apply_order( $query_args, $order_by );
		$posts      = get_posts( $query_args );
		$posts      = $this->filter_noindex( $posts );

		if ( ! $posts ) {
			return '';
		}

		$out = '<ul class="gcm-sitemap-list">';
		foreach ( $posts as $post ) {
			$out .= '<li class="gcm-sitemap-item">';
			$out .= '<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
			if ( $show_date ) {
				$out .= ' <span class="gcm-sitemap-date">(' . esc_html( get_the_date( '', $post ) ) . ')</span>';
			}
			$out .= '</li>';
		}
		$out .= '</ul>';
		return $out;
	}

	// -------------------------------------------------------------------------
	// Taxonomy section
	// -------------------------------------------------------------------------

	private function render_taxonomy( string $taxonomy ): string {
		$obj = get_taxonomy( $taxonomy );
		if ( ! $obj ) {
			return '';
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}

		$out  = '<section class="gcm-sitemap-section gcm-sitemap-section--tax-' . esc_attr( $taxonomy ) . '">';
		$out .= '<h2 class="gcm-sitemap-heading">' . esc_html( $obj->labels->name ) . '</h2>';
		$out .= '<ul class="gcm-sitemap-list">';

		foreach ( $terms as $term ) {
			// Skip noindexed terms.
			$meta = $this->settings->get_term_meta( $term->term_id );
			if ( ! empty( $meta['noindex'] ) ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( ! is_string( $link ) ) {
				continue;
			}
			$out .= '<li class="gcm-sitemap-item">';
			$out .= '<a href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . '</a>';
			$out .= ' <span class="gcm-sitemap-count">(' . (int) $term->count . ')</span>';
			$out .= '</li>';
		}

		$out .= '</ul>';
		$out .= '</section>';
		return $out;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function filter_noindex( array $posts ): array {
		return array_filter(
			$posts,
			function ( $post ) {
				// Exclude posts explicitly set to noindex or exclude_sitemap.
				$meta = $this->settings->get_post_meta( $post->ID );
				if ( ! empty( $meta['noindex'] ) ) {
					return false;
				}
				if ( ! empty( $meta['exclude_sitemap'] ) ) {
					return false;
				}
				// Exclude password-protected.
				if ( post_password_required( $post ) ) {
					return false;
				}
				return true;
			}
		);
	}

	private function apply_order( array $args, string $order_by ): array {
		switch ( $order_by ) {
			case 'title':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;
			case 'date':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			default: // menu_order
				$args['orderby'] = 'menu_order title';
				$args['order']   = 'ASC';
				break;
		}
		return $args;
	}
}

/**
 * Sitemap Widget — renders the HTML sitemap in a sidebar/widget area.
 */
class MM_Sitemap_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'mm_meta_sitemap',
			__( 'GCM Sitemap', 'metamanager' ),
			array(
				'classname'   => 'mm-sitemap-widget',
				'description' => __( 'Displays the HTML sitemap in a widget area.', 'metamanager' ),
			)
		);
	}

	public function widget( $args, $instance ): void {
		$module = new MM_Mod_Html_Sitemap( MM_Site_Settings::get_instance() );
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. esc_html( apply_filters( 'widget_title', $instance['title'] ) )
				. $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo $module->render_shortcode( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ): void {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">Title (optional):</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
					type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p class="description">Sitemap content is configured in Metamanager → Sitemaps settings.</p>
		<?php
	}

	public function update( $new_instance, $old_instance ): array {
		return array( 'title' => sanitize_text_field( $new_instance['title'] ?? '' ) );
	}
}