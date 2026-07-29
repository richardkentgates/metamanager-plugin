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
	 * @param WP_Post $menu The nav_menu post object.
	 */
	public function render_meta_box( WP_Post $menu ): void {
		$checked = get_post_meta( $menu->ID, '_mm_nav_menu_primary', true );
		wp_nonce_field( 'mm_nav_menu_primary_' . $menu->ID, '_mm_nav_menu_primary_nonce' );
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
	 * @param int      $menu_id  The menu term ID (post ID).
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
			$old_primary = get_posts( [
				'post_type'   => 'nav_menu',
				'meta_key'    => '_mm_nav_menu_primary',
				'meta_value'  => '1',
				'exclude'     => [ $menu_id ],
				'numberposts' => -1,
			] );
			foreach ( $old_primary as $old ) {
				delete_post_meta( $old->ID, '_mm_nav_menu_primary' );
			}

			update_post_meta( $menu_id, '_mm_nav_menu_primary', '1' );
		} else {
			delete_post_meta( $menu_id, '_mm_nav_menu_primary' );
		}
	}
}
