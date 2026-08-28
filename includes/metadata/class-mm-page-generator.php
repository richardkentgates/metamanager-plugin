<?php
/**
 * MM_Page_Generator — generates and stores page content for schema page templates.
 *
 * About and Contact pages are generated from the business profile.
 * Calendar page is generated from Event posts.
 * Content is stored in post_content so WordPress renders it without runtime interception.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

class MM_Page_Generator {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		// Auto-regenerate when business profile is saved.
		add_action( 'mm_business_profile_saved', [ __CLASS__, 'regenerate_about_and_contact' ] );

		// Auto-regenerate Calendar when events are created, updated, or deleted.
		add_action( 'save_post_mm_event', [ __CLASS__, 'regenerate_calendar' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'on_before_delete_post' ] );
		add_action( 'transition_post_status', [ __CLASS__, 'on_transition_post_status' ], 10, 3 );

		// Admin AJAX for manual regeneration.
		add_action( 'wp_ajax_mm_regenerate_page', [ __CLASS__, 'ajax_regenerate' ] );
	}

	// -------------------------------------------------------------------------
	// Public regeneration methods
	// -------------------------------------------------------------------------

	/**
	 * Regenerate both About and Contact pages.
	 */
	public static function regenerate_about_and_contact(): void {
		self::regenerate_page( 'mm-about' );
		self::regenerate_page( 'mm-contact' );
	}

	/**
	 * Regenerate the Calendar page.
	 */
	public static function regenerate_calendar(): void {
		self::regenerate_page( 'mm-calendar' );
	}

	/**
	 * Regenerate a specific page by template slug.
	 */
	public static function regenerate_page( string $template ): void {
		$page_id = self::find_page_by_template( $template );
		if ( ! $page_id ) {
			return;
		}

		$html = self::generate_content( $template );
		if ( null === $html ) {
			return;
		}

		// Temporarily remove this hook to prevent infinite loop on wp_update_post.
		remove_action( 'save_post', [ 'MM_Schema_Post_Types', 'save_meta' ], 10 );

		wp_update_post( [
			'ID'           => $page_id,
			'post_content' => $html,
		] );

		add_action( 'save_post', [ 'MM_Schema_Post_Types', 'save_meta' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Content generation
	// -------------------------------------------------------------------------

	/**
	 * Generate HTML content for a given template.
	 *
	 * @param string $template Template slug (mm-about, mm-contact, mm-calendar).
	 * @return string|null Generated HTML, or null if generation not possible.
	 */
	private static function generate_content( string $template ): ?string {
		$settings = MM_Site_Settings::get_instance();

		switch ( $template ) {
			case 'mm-about':
				return self::generate_about( $settings );
			case 'mm-contact':
				return self::generate_contact( $settings );
			case 'mm-calendar':
				return self::generate_calendar_content();
			default:
				return null;
		}
	}

	/**
	 * Generate About page content from business profile.
	 */
	private static function generate_about( MM_Site_Settings $settings ): string {
		$module = new MM_Mod_Business_Contact( $settings );
		return $module->render_about_page();
	}

	/**
	 * Generate Contact page content from business profile.
	 */
	private static function generate_contact( MM_Site_Settings $settings ): string {
		$module = new MM_Mod_Business_Contact( $settings );
		return $module->render_contact_page();
	}

	/**
	 * Generate Calendar page content from Event posts.
	 */
	private static function generate_calendar_content(): string {
		$settings = MM_Site_Settings::get_instance();
		$module   = new MM_Mod_Business_Contact( $settings );
		return $module->render_calendar();
	}

	// -------------------------------------------------------------------------
	// Page lookup
	// -------------------------------------------------------------------------

	/**
	 * Find a page by its template slug.
	 *
	 * @param string $template Template slug (mm-about, mm-contact, mm-calendar).
	 * @return int|null Page ID, or null if not found.
	 */
	public static function find_page_by_template( string $template ): ?int {
		$pages = get_posts( [
			'post_type'   => 'page',
			'meta_key'    => '_wp_page_template',
			'meta_value'  => $template,
			'numberposts' => 1,
			'post_status' => 'publish',
		] );

		return ! empty( $pages ) ? $pages[0]->ID : null;
	}

	// -------------------------------------------------------------------------
	// Event hooks
	// -------------------------------------------------------------------------

	/**
	 * Handle before_delete_post for events.
	 */
	public static function on_before_delete_post( int $post_id ): void {
		if ( 'mm_event' === get_post_type( $post_id ) ) {
			// Defer regeneration to after the delete completes.
			add_action( 'deleted_post', [ __CLASS__, 'deferred_calendar_regenerate' ] );
		}
	}

	/**
	 * Deferred Calendar regeneration after event deletion.
	 */
	public static function deferred_calendar_regenerate(): void {
		remove_action( 'deleted_post', [ __CLASS__, 'deferred_calendar_regenerate' ] );
		self::regenerate_calendar();
	}

	/**
	 * Handle transition_post_status for events (trashing/untrashing).
	 */
	public static function on_transition_post_status( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'mm_event' !== $post->post_type ) {
			return;
		}
		if ( $new_status === $old_status ) {
			return;
		}
		// Only regenerate if status actually changed (publish → draft, etc.).
		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			self::regenerate_calendar();
		}
	}

	// -------------------------------------------------------------------------
	// AJAX handler
	// -------------------------------------------------------------------------

	/**
	 * Handle manual page regeneration from admin.
	 */
	public static function ajax_regenerate(): void {
		check_ajax_referer( 'mm_regenerate_page', 'nonce' );

		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$template = sanitize_text_field( wp_unslash( $_POST['template'] ?? '' ) );
		if ( ! isset( MM_Schema_Post_Types::TEMPLATES[ $template ] ) ) {
			wp_send_json_error( [ 'message' => 'Invalid template.' ] );
		}

		$page_id = self::find_page_by_template( $template );
		if ( ! $page_id ) {
			wp_send_json_error( [ 'message' => 'Page not found. Create a page and assign the "' . $template . '" template first.' ] );
		}

		self::regenerate_page( $template );

		wp_send_json_success( [
			'message' => 'Page content regenerated.',
			'edit_url' => get_edit_post_link( $page_id, 'raw' ),
			'view_url' => get_permalink( $page_id ),
		] );
	}
}
