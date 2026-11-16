<?php
/**
 * MM_Nav_Menu_Admin — nav-menus.php enhancements using WordPress codex
 * add_meta_box() API. No JavaScript injection hacks.
 *
 * LEFT SIDEBAR ("Add menu items"):
 *   - Archives meta box: dropdown (Blog, CPT archives, Taxonomies) + checkboxes + Add to Menu
 *
 * LEFT SIDEBAR (below Archives):
 *   - Navigation Schema meta box: checkbox to designate menu for SiteNavigationElement
  *
  * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nav menu admin.
 *
 * @package Metamanager
 */
class MM_Nav_Menu_Admin {

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register_nav_meta_boxes' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'save_schema_checkbox' ), 10, 2 );
	}

	/**
	 * Register meta boxes on the nav-menus screen (WordPress codex: add_meta_box).
	 */
	public function register_nav_meta_boxes(): void {
		add_meta_box(
			'mm-archives',
			__( 'Archives', 'metamanager' ),
			array( $this, 'render_archives_box' ),
			'nav-menus',
			'side',
			'default'
		);
		add_meta_box(
			'mm-schema-nav',
			__( 'Navigation Schema', 'metamanager' ),
			array( $this, 'render_schema_box' ),
			'nav-menus',
			'side',
			'low'
		);
	}

	// -------------------------------------------------------------------------
	// Archives meta box — all archive types in a dropdown
	// -------------------------------------------------------------------------

	/**
	 * Render archives box.
	 */
	public function render_archives_box(): void {
		// Build archive items grouped by type.
		$groups = array();

		// Blog archive.
		$blog_url = get_post_type_archive_link( 'post' );
		if ( $blog_url ) {
			$groups['blog'] = array(
				'label' => __( 'Blog', 'metamanager' ),
				'items' => array(
					array(
						'title' => __( 'Blog Archive', 'metamanager' ),
						'url'   => $blog_url,
					),
				),
			);
		}

		// CPT archives.
		if ( class_exists( 'MM_Schema_Post_Types' ) ) {
			$types     = MM_Schema_Post_Types::TYPES;
			$cpt_items = array();
			foreach ( $types as $slug => $config ) {
				$label = $config[1];
				$url   = get_post_type_archive_link( $slug );
				if ( $url ) {
					$cpt_items[] = array(
						'title' => $label,
						'url'   => $url,
					);
				}
			}
			if ( $cpt_items ) {
				$groups['cpt'] = array(
					'label' => __( 'CPT Archives', 'metamanager' ),
					'items' => $cpt_items,
				);
			}
		}

		// Taxonomy archives.
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		$tax_items  = array();
		foreach ( $taxonomies as $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'number'     => 50,
				)
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$tax_obj = get_taxonomy( $tax );
			foreach ( $terms as $term ) {
				$url = get_term_link( $term );
				if ( ! is_wp_error( $url ) ) {
					$tax_items[] = array(
						'title' => $term->name,
						'url'   => $url,
						'group' => $tax_obj->labels->name ?? $tax,
					);
				}
			}
		}
		if ( $tax_items ) {
			$groups['tax'] = array(
				'label' => __( 'Taxonomy Archives', 'metamanager' ),
				'items' => $tax_items,
			);
		}

		?>
		<div id="mm-archives-panel" class="posttypediv">
			<div class="tabs-panel tabs-panel-active">
				<p>
					<select id="mm-archives-filter" style="width:100%;margin-bottom:8px;">
						<option value=""><?php esc_html_e( 'All Archives', 'metamanager' ); ?></option>
						<?php foreach ( $groups as $key => $group ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $group['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<ul class="categorychecklist form-no-clear" id="mm-archives-list">
					<?php foreach ( $groups as $key => $group ) : ?>
						<?php foreach ( $group['items'] as $item ) : ?>
					<li data-group="<?php echo esc_attr( $key ); ?>">
						<label class="menu-item-title">
							<input type="checkbox" class="mm-archive-check"
								data-title="<?php echo esc_attr( $item['title'] ); ?>"
								data-url="<?php echo esc_url( $item['url'] ); ?>">
							<?php echo esc_html( $item['title'] ); ?>
						</label>
					</li>
					<?php endforeach; ?>
					<?php endforeach; ?>
				</ul>
			</div>
			<p class="button-controls">
				<button type="button" class="button" id="mm-add-archives-to-menu"><?php esc_html_e( 'Add to Menu', 'metamanager' ); ?></button>
			</p>
		</div>
		<script>
		jQuery(function($) {
			// Filter dropdown
			$('#mm-archives-filter').on('change', function() {
				var val = $(this).val();
				$('#mm-archives-list li').each(function() {
					$(this).toggle(!val || $(this).data('group') === val);
				});
			});
			// Add to Menu — use WordPress's native wpNavMenu.addCustomLink
			$('#mm-add-archives-to-menu').on('click', function() {
				var $checked = $('#mm-archives-list .mm-archive-check:checked');
				if (!$checked.length) return;
				// Process one at a time to avoid race conditions
				var items = [];
				$checked.each(function() {
					items.push({ title: $(this).data('title'), url: $(this).data('url') });
					$(this).prop('checked', false);
				});
				$.each(items, function(i, item) {
					$('#custom-menu-item-name').val(item.title);
					$('#custom-menu-item-url').val(item.url);
					if (typeof wpNavMenu !== 'undefined' && wpNavMenu.addCustomLink) {
						wpNavMenu.addCustomLink(wpNavMenu.addMenuItemToBottom);
					} else {
						$('#submit-customlinkdiv').trigger('click');
					}
				});
			});
		});
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Navigation Schema meta box
	// -------------------------------------------------------------------------

	/**
	 * Render schema box.
	 */
	public function render_schema_box(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display
		$menu_param   = isset( $_GET['menu'] ) ? sanitize_text_field( wp_unslash( $_GET['menu'] ) ) : '';
		$current_menu = (int) $menu_param;
		$checked      = $current_menu ? get_term_meta( $current_menu, '_mm_nav_menu_primary', true ) : '';
		?>
		<div>
			<label>
				<input type="checkbox" name="_mm_nav_menu_primary" value="1" <?php checked( $checked, '1' ); ?>>
				<strong><?php esc_html_e( 'Use for navigation schema', 'metamanager' ); ?></strong>
			</label>
			<p class="description" style="margin:6px 0 0;">
				<?php esc_html_e( 'When checked, this menu is emitted as SiteNavigationElement JSON-LD. Only one menu can be designated. Schema outputs only when this menu is rendered on the page.', 'metamanager' ); ?>
			</p>
			<?php if ( $current_menu ) : ?>
			<input type="hidden" name="_mm_nav_menu_primary_nonce" value="<?php echo esc_attr( wp_create_nonce( 'mm_nav_menu_primary_' . $current_menu ) ); ?>">
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save the schema checkbox when a menu is updated.
	 *
	 * @param mixed $menu_id Menu ID.
	 * @param mixed $data Data.
	 */
	public function save_schema_checkbox( $menu_id, $data ): void {
		if ( ! isset( $_POST['_mm_nav_menu_primary_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_mm_nav_menu_primary_nonce'] ) ), 'mm_nav_menu_primary_' . $menu_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$is_primary = isset( $_POST['_mm_nav_menu_primary'] ) && '1' === $_POST['_mm_nav_menu_primary'];

		if ( $is_primary ) {
			$old = get_terms(
				array(
					'taxonomy'   => 'nav-menus',
					'meta_query' => array(
						array(
							'key'   => '_mm_nav_menu_primary',
							'value' => '1',
						),
					),
					'number'     => 0,
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $old ) ) {
				foreach ( $old as $term ) {
					if ( (int) $term->term_id !== (int) $menu_id ) {
						delete_term_meta( $term->term_id, '_mm_nav_menu_primary' );
					}
				}
			}
			update_term_meta( $menu_id, '_mm_nav_menu_primary', '1' );
		} else {
			delete_term_meta( $menu_id, '_mm_nav_menu_primary' );
		}
	}
}
