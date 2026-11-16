<?php
/**
 * MM_Customizer — WordPress Customizer integration for MetaManager.
 *
 * Adds a root-level "Schema" section with per-CPT archive layout selection.
 * Layouts: 'menu' (image left, text right) and 'grid' (image top, text below).
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customizer.
 *
 * @package Metamanager
 */
class MM_Customizer {

	/**
	 * Layout options available for CPT archives.
	 */
	public const LAYOUTS = array(
		'menu' => 'Menu Layout',
		'grid' => 'Grid Layout',
	);

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'customize_register', array( __CLASS__, 'customize_register' ) );
	}

	/**
	 * Register the Schema section and per-CPT layout controls.
	 *
	 * @param \WP_Customize_Manager $wp_customize Customizer manager.
	 */
	public static function customize_register( \WP_Customize_Manager $wp_customize ): void {
		// Root-level MM Archives section.
		$wp_customize->add_section(
			'mm_archives',
			array(
				'title'    => __( 'MM Archives', 'metamanager' ),
				'priority' => 35,
			)
		);

		// Per-CPT layout settings.
		$types = MM_Schema_Post_Types::TYPES;
		foreach ( $types as $slug => $config ) {
			$label = $config[1];

			$wp_customize->add_setting(
				"mm_meta_settings[schema][archive_layouts][{$slug}]",
				array(
					'default'           => 'menu',
					'type'              => 'option',
					'capability'        => 'manage_options',
					'sanitize_callback' => array( __CLASS__, 'sanitize_layout' ),
				)
			);

			$wp_customize->add_control(
				"mm_archives_layout_{$slug}",
				array(
					'label'       => sprintf( __( '%s Archive Layout', 'metamanager' ), $label ),
					'section'     => 'mm_archives',
					'setting'     => "mm_meta_settings[schema][archive_layouts][{$slug}]",
					'type'        => 'select',
					'choices'     => self::LAYOUTS,
					'description' => sprintf( __( 'Choose how %s archive pages display their listings.', 'metamanager' ), strtolower( $label ) ),
				)
			);
		}
	}

	/**
	 * Sanitize layout value.
	 *
	 * @param mixed $value Value.
	 */
	public static function sanitize_layout( $value ): string {
		return array_key_exists( $value, self::LAYOUTS ) ? $value : 'menu';
	}

	/**
	 * Get the layout for a given CPT slug.
	 *
	 * @param string $slug CPT slug.
	 * @return string 'menu' or 'grid'.
	 */
	public static function get_layout( string $slug ): string {
		$s      = MM_Site_Settings::get_instance();
		$layout = $s->get( "schema.archive_layouts.{$slug}", 'menu' );
		return array_key_exists( $layout, self::LAYOUTS ) ? $layout : 'menu';
	}
}
