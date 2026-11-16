<?php
/**
 * MM_Term_Meta_Panel — per-term SEO controls.
 *
 * Adds SEO fields to taxonomy term edit screens.
 * Stores data under term-meta key MM_META_KEY.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Term meta panel.
 *
 * @package Metamanager
 */
class MM_Term_Meta_Panel {

	/**
	 * Site settings instance.
	 *
	 * @var MM_Site_Settings
	 */
	private MM_Site_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param MM_Site_Settings $settings Settings.
	 */
	public function __construct( MM_Site_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		// Add fields to all public taxonomy forms.
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_edit_fields' ), 10, 2 );
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'render_add_fields' ), 10, 1 );
			add_action( "edited_{$taxonomy}", array( $this, 'save_meta' ), 10, 1 );
			add_action( "created_{$taxonomy}", array( $this, 'save_meta' ), 10, 1 );
		}
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Render edit fields.
	 *
	 * @param \WP_Term $term Term object.
	 * @param string   $taxonomy Taxonomy.
	 */
	public function render_edit_fields( \WP_Term $term, string $taxonomy ): void {
		$meta = $this->settings->get_term_meta( $term->term_id );
		wp_nonce_field( 'mm_meta_term_meta_save', 'mm_meta_term_nonce' );
		include MM_META_DIR . 'templates/metabox/term-metadata.php';
	}

	/**
	 * Render add fields.
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public function render_add_fields( string $taxonomy ): void {
		$meta = array();
		$term = null;
		wp_nonce_field( 'mm_meta_term_meta_save', 'mm_meta_term_nonce' );
		include MM_META_DIR . 'templates/metabox/term-metadata-add.php';
	}

	// -------------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------------

	/**
	 * Save meta.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_meta( int $term_id ): void {
		if ( ! isset( $_POST['mm_meta_term_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['mm_meta_term_nonce'] ), 'mm_meta_term_meta_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$clean            = array();
		$og_t             = sanitize_text_field( wp_unslash( $_POST['mm_meta_og_title'] ?? '' ) );
		$og_d             = sanitize_textarea_field( wp_unslash( $_POST['mm_meta_og_description'] ?? '' ) );
		$og_id            = absint( wp_unslash( $_POST['mm_meta_og_image_id'] ?? 0 ) );
		$og_url           = esc_url_raw( wp_unslash( $_POST['mm_meta_og_image_url'] ?? '' ) );
		$noindex          = $this->sanitize_tristate( wp_unslash( $_POST['mm_meta_noindex'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_tristate() is a custom sanitizer
		$nofollow         = $this->sanitize_tristate( wp_unslash( $_POST['mm_meta_nofollow'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$breadcrumb_label = sanitize_text_field( wp_unslash( $_POST['mm_meta_breadcrumb_label'] ?? '' ) );
		$exclude_sitemap  = ! empty( $_POST['mm_meta_exclude_sitemap'] );
		// phpcs:enable

		if ( $og_t ) {
			$clean['og_title'] = $og_t; }
		if ( $og_d ) {
			$clean['og_description'] = $og_d; }
		if ( $og_id ) {
			$clean['og_image_id'] = $og_id; }
		if ( $og_url ) {
			$clean['og_image_url'] = $og_url; }
		if ( null !== $noindex ) {
			$clean['noindex'] = $noindex; }
		if ( null !== $nofollow ) {
			$clean['nofollow'] = $nofollow; }
		if ( $breadcrumb_label ) {
			$clean['breadcrumb_label'] = $breadcrumb_label; }
		if ( $exclude_sitemap ) {
			$clean['exclude_sitemap'] = true; }

		if ( empty( $clean ) ) {
			delete_term_meta( $term_id, MM_META_KEY );
		} else {
			update_term_meta( $term_id, MM_META_KEY, wp_json_encode( $clean ) );
		}
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'term.php', 'edit-tags.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'mm-meta-term-panel', MM_META_URL . 'assets/css/metadata-admin.css', array(), MM_META_VERSION );
		wp_enqueue_script( 'mm-meta-admin-metabox', MM_META_URL . 'assets/js/admin-metabox.js', array( 'jquery' ), MM_META_VERSION, true );
		wp_enqueue_script( 'mm-meta-admin-media', MM_META_URL . 'assets/js/admin-media.js', array( 'jquery' ), MM_META_VERSION, true );
	}

	// -------------------------------------------------------------------------

	/**
	 * Sanitize tristate.
	 *
	 * @param mixed $value Value.
	 * @return bool|null Return value.
	 */
	private function sanitize_tristate( $value ): ?bool {
		if ( '' === $value || null === $value ) {
			return null;
		}
		return (bool) (int) $value;
	}
}
