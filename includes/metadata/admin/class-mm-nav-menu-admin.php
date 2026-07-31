<?php
/**
 * MM_Nav_Menu_Admin — adds a "primary navigation" checkbox to the
 * Appearance → Menus edit screen so the user can designate which menu
 * the Schema module should emit as SiteNavigationElement.
 */

defined( 'ABSPATH' ) || exit;

class MM_Nav_Menu_Admin {

	public function register_hooks(): void {
		add_action( 'admin_head-nav-menus.php', [ $this, 'add_meta_box' ] );
		add_action( 'wp_update_nav_menu_item', [ $this, 'save_checkbox' ], 10, 3 );
	}

	/**
	 * Add a meta box to every nav-menu edit screen.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'mm-nav-menu-primary',
			'Metamanager — Schema Navigation',
			[ $this, 'render_meta_box' ],
			'nav_menu',
			'side',
			'high'
		);
	}

	/**
	 * Render the checkbox inside the meta box.
	 *
	 * @param object $menu The nav_menu term object passed by WordPress.
	 */
	public function render_meta_box( $menu ): void {
		$term_id = is_object( $menu ) ? ( $menu->term_id ?? $menu->ID ?? 0 ) : 0;
		$checked = get_term_meta( $term_id, '_mm_nav_menu_primary', true );
		wp_nonce_field( 'mm_nav_menu_primary_' . $term_id, '_mm_nav_menu_primary_nonce' );
		?>
		<p>
			<label>
				<input type="checkbox" name="_mm_nav_menu_primary" value="1"
					<?php checked( $checked, '1' ); ?>>
				Use as primary navigation for schema
			</label>
		</p>
		<p class="description">
			When checked, only this menu is emitted as <code>SiteNavigationElement</code> JSON-LD.
			If no menu is checked, no navigation schema is emitted.
		</p>
		<?php
	}

	/**
	 * Save the checkbox value when a menu is updated.
	 *
	 * @param int      $menu_id  The menu term ID.
	 * @param int|null $menu_id_from_db Unused.
	 * @param array    $menu_data Parsed menu data from the form.
	 */
	public function save_checkbox( int $menu_id, ?int $menu_id_from_db, array $menu_data ): void {
		if ( ! isset( $_POST['_mm_nav_menu_primary_nonce'] ) ||
			! wp_verify_nonce( $_POST['_mm_nav_menu_primary_nonce'], 'mm_nav_menu_primary_' . $menu_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$is_primary = isset( $_POST['_mm_nav_menu_primary'] ) && '1' === $_POST['_mm_nav_menu_primary'];

		if ( $is_primary ) {
			// Uncheck any other menu that was previously primary.
			$old_menus = get_terms( [
				'taxonomy'   => 'nav_menu',
				'meta_query' => [
					[
						'key'   => '_mm_nav_menu_primary',
						'value' => '1',
					],
				],
				'number'     => 0,
				'hide_empty' => false,
			] );
			if ( ! is_wp_error( $old_menus ) ) {
				foreach ( $old_menus as $old ) {
					if ( (int) $old->term_id !== $menu_id ) {
						delete_term_meta( $old->term_id, '_mm_nav_menu_primary' );
					}
				}
			}

			update_term_meta( $menu_id, '_mm_nav_menu_primary', '1' );
		} else {
			delete_term_meta( $menu_id, '_mm_nav_menu_primary' );
		}
	}
}
