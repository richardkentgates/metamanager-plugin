<?php
/**
 * MM_Metadata_Admin — one WordPress sub-menu page per settings section.
 *
 * Navigation is handled by the WordPress admin sidebar (real URLs, not JS tabs).
 * Each section registers its own option group that points at the shared
 * mm_meta_settings option.  The sanitize callback loads the current full
 * option and merges only the submitted section so other sections are never
 * overwritten on a partial save.
 */

defined( 'ABSPATH' ) || exit;

class MM_Metadata_Admin {

/** @var MM_Site_Settings */
private MM_Site_Settings $settings;

/**
 * Sub-page definitions.
 * Structure: 'page-slug' => [
 *   'label'        => Human-readable name.
 *   'option_group' => Passed to settings_fields(). null = AJAX/no-save page.
 *   'section'      => Top-level key inside mm_meta_settings; null for Business/Tools.
 *   'template'     => Suffix of templates/admin/page-{suffix}.php.
 *   'inner_form'   => Template manages its own <form> tag (no outer wrapper).
 * ]
 */
private array $pages = [
'metamanager-business' => [ 'label' => 'Business Profile', 'option_group' => 'mm_meta_business_group',  'section' => null,      'template' => 'business',  'inner_form' => false ],
'mm-meta-titles'   => [ 'label' => 'Titles',     'option_group' => 'mm_meta_titles_group',    'section' => 'titles',  'template' => 'titles',    'inner_form' => false ],
'mm-meta-social'   => [ 'label' => 'Social',     'option_group' => 'mm_meta_social_group',    'section' => 'social',  'template' => 'social',    'inner_form' => false ],
'mm-meta-schema'   => [ 'label' => 'Schema',     'option_group' => 'mm_meta_schema_group',    'section' => 'schema',  'template' => 'schema',    'inner_form' => false ],
'mm-meta-sitemaps' => [ 'label' => 'Sitemaps',   'option_group' => 'mm_meta_sitemaps_group',  'section' => 'sitemap', 'template' => 'sitemaps',  'inner_form' => false ],
'mm-meta-robots'   => [ 'label' => 'Robots.txt', 'option_group' => 'mm_meta_robots_group',    'section' => 'robots',  'template' => 'robots',    'inner_form' => false ],
'mm-meta-authors'  => [ 'label' => 'Authors',    'option_group' => 'mm_meta_authors_group',   'section' => 'authors', 'template' => 'authors',   'inner_form' => false ],
'mm-meta-hygiene'  => [ 'label' => 'Hygiene',    'option_group' => 'mm_meta_hygiene_group',   'section' => 'hygiene', 'template' => 'hygiene',   'inner_form' => false ],
'mm-meta-feed'     => [ 'label' => 'RSS Feed',   'option_group' => 'mm_meta_feed_group',      'section' => 'feed',    'template' => 'feed',      'inner_form' => false ],
'mm-meta-links'    => [ 'label' => 'Links',      'option_group' => 'mm_meta_links_group',     'section' => 'links',   'template' => 'links',     'inner_form' => true  ],
'mm-meta-tools'    => [ 'label' => 'Tools',      'option_group' => null,                      'section' => null,      'template' => 'tools',     'inner_form' => true  ],
'mm-meta-contact'  => [ 'label' => 'Contact Card', 'option_group' => 'mm_meta_contact_group', 'section' => null, 'template' => 'contact', 'inner_form' => false ],
];

public function __construct( MM_Site_Settings $settings ) {
$this->settings = $settings;
}

public function register_hooks(): void {
add_action( 'admin_menu',            [ $this, 'register_menu' ] );
add_action( 'admin_init',            [ $this, 'register_settings' ] );
add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
add_action( 'current_screen',        [ $this, 'add_help_tabs' ] );
add_action( 'wp_ajax_mm_meta_tools_action', [ $this, 'ajax_tools_action' ] );
}

// -------------------------------------------------------------------------
// Menu
// -------------------------------------------------------------------------

public function register_menu(): void {
// Note: the top-level 'metamanager' menu is registered by MM_Admin::add_menu().
// This class only registers its submenu pages under that existing parent.

add_submenu_page( 'metamanager', 'Business Profile — Metamanager', 'Business Profile', 'manage_options', 'metamanager-business', [ $this, 'render_business' ] );
add_submenu_page( 'metamanager', 'Titles — Metamanager',     'Titles',     'manage_options', 'mm-meta-titles',   fn() => $this->render( 'mm-meta-titles' ) );
add_submenu_page( 'metamanager', 'Social — Metamanager',     'Social',     'manage_options', 'mm-meta-social',   fn() => $this->render( 'mm-meta-social' ) );
add_submenu_page( 'metamanager', 'Schema — Metamanager',     'Schema',     'manage_options', 'mm-meta-schema',   fn() => $this->render( 'mm-meta-schema' ) );
add_submenu_page( 'metamanager', 'Sitemaps — Metamanager',   'Sitemaps',   'manage_options', 'mm-meta-sitemaps', fn() => $this->render( 'mm-meta-sitemaps' ) );
add_submenu_page( 'metamanager', 'Robots.txt — Metamanager', 'Robots.txt', 'manage_options', 'mm-meta-robots',   fn() => $this->render( 'mm-meta-robots' ) );
add_submenu_page( 'metamanager', 'Authors — Metamanager',    'Authors',    'manage_options', 'mm-meta-authors',  fn() => $this->render( 'mm-meta-authors' ) );
add_submenu_page( 'metamanager', 'Hygiene — Metamanager',    'Hygiene',    'manage_options', 'mm-meta-hygiene',  fn() => $this->render( 'mm-meta-hygiene' ) );
add_submenu_page( 'metamanager', 'RSS Feed — Metamanager',   'RSS Feed',   'manage_options', 'mm-meta-feed',     fn() => $this->render( 'mm-meta-feed' ) );
add_submenu_page( 'metamanager', 'Links — Metamanager',      'Links',      'manage_options', 'mm-meta-links',    fn() => $this->render( 'mm-meta-links' ) );
add_submenu_page( 'metamanager', 'Tools — Metamanager',      'Tools',      'manage_options', 'mm-meta-tools',    fn() => $this->render( 'mm-meta-tools' ) );
add_submenu_page( 'metamanager', 'Contact Card — Metamanager', 'Contact Card', 'manage_options', 'mm-meta-contact', fn() => $this->render( 'mm-meta-contact' ) );
}

// -------------------------------------------------------------------------
// Settings API
// -------------------------------------------------------------------------

public function register_settings(): void {
// Business is a standalone option.
register_setting( 'mm_meta_business_group', MM_META_OPT_BUSINESS, [
'sanitize_callback' => [ $this, 'sanitize_business' ],
] );

// Each section registers its own group → same shared option key.
// NO sanitize_callback here — the pre_update_option_mm_meta_settings filter
// in MM_Site_Settings handles the section merge. Registering sanitize_callbacks
// on a shared option is destructive because they chain in sanitize_option():
// each callback reloads from DB via get_option(), so the POST data from the
// form is lost by the time the matching section's callback fires.
$section_groups = [
'mm_meta_titles_group'   => 'titles',
'mm_meta_social_group'   => 'social',
'mm_meta_schema_group'   => 'schema',
'mm_meta_sitemaps_group' => 'sitemap',
'mm_meta_robots_group'   => 'robots',
'mm_meta_authors_group'  => 'authors',
'mm_meta_hygiene_group'  => 'hygiene',
'mm_meta_feed_group'     => 'feed',
'mm_meta_links_group'    => 'links',
];

foreach ( $section_groups as $group => $section ) {
register_setting( $group, MM_META_OPT_SETTINGS );
}

// Contact card style — standalone option.
register_setting( MM_Mod_Business_Contact::OPT_GROUP, MM_Mod_Business_Contact::OPT_STYLE, [
'sanitize_callback' => [ $this, 'sanitize_contact_style' ],
] );
}

// -------------------------------------------------------------------------
// Rendering
// -------------------------------------------------------------------------

/** Named callback — required for add_menu_page() and the Business submenu. */
public function render_business(): void {
$this->render( 'metamanager-business' );
}

private function render( string $page_slug ): void {
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'Insufficient permissions.', 'metamanager' ) );
}

$page_cfg       = $this->pages[ $page_slug ] ?? $this->pages['metamanager-business'];
$settings       = $this->settings;
$pages          = $this->pages;
$current_page   = $page_slug;
$opt_group      = $page_cfg['option_group'];
$has_inner_form = $page_cfg['inner_form'];

		include MM_META_DIR . 'templates/admin/page-settings.php'; // shell wrapper
}

// -------------------------------------------------------------------------
// Sanitization
// -------------------------------------------------------------------------
public function sanitize_contact_style( $raw ): array {
if ( ! is_array( $raw ) ) {
return MM_Mod_Business_Contact::style_defaults();
}
return MM_Site_Settings::deep_sanitize_section( $raw, MM_Mod_Business_Contact::style_defaults() );
}

public function sanitize_business( $raw ): array {
if ( ! is_array( $raw ) ) {
return MM_Site_Settings::business_defaults();
}
return MM_Site_Settings::deep_sanitize_section( $raw, MM_Site_Settings::business_defaults() );
}

// -------------------------------------------------------------------------
// Assets
// -------------------------------------------------------------------------

public function enqueue_assets( string $hook ): void {
$gcm_hooks = [
'metamanager_page_metamanager-business',
'metamanager_page_mm-meta-titles',
'metamanager_page_mm-meta-social',
'metamanager_page_mm-meta-schema',
'metamanager_page_mm-meta-sitemaps',
'metamanager_page_mm-meta-robots',
'metamanager_page_mm-meta-authors',
'metamanager_page_mm-meta-hygiene',
'metamanager_page_mm-meta-feed',
'metamanager_page_mm-meta-links',
'metamanager_page_mm-meta-tools',
'metamanager_page_mm-meta-contact',
];

if ( ! in_array( $hook, $gcm_hooks, true ) ) {
return;
}

wp_enqueue_style( 'mm-meta-admin', MM_META_URL . 'assets/css/metadata-admin.css', [], MM_META_VERSION );
wp_enqueue_media();

wp_enqueue_script( 'mm-meta-admin-repeater', MM_META_URL . 'assets/js/admin-repeater.js', [ 'jquery', 'jquery-ui-sortable' ], MM_META_VERSION, true );
wp_enqueue_script( 'mm-meta-admin-media',    MM_META_URL . 'assets/js/admin-media.js',    [ 'jquery' ],                        MM_META_VERSION, true );
wp_enqueue_script( 'mm-meta-admin-tabs',     MM_META_URL . 'assets/js/admin-tabs.js',     [],                                  MM_META_VERSION, true );
wp_enqueue_script( 'mm-meta-checkbox-guard', MM_META_URL . 'assets/js/admin-checkbox-guard.js', [],                           MM_META_VERSION, true );

if ( 'metamanager_page_mm-meta-links' === $hook ) {
wp_enqueue_script( 'mm-meta-admin-links', MM_META_URL . 'assets/js/admin-links.js', [ 'jquery' ], MM_META_VERSION, true );
wp_localize_script( 'mm-meta-admin-links', 'gcmLinks', [
'ajax_url' => admin_url( 'admin-ajax.php' ),
'nonce'    => wp_create_nonce( 'mm_meta_links_nonce' ),
] );
}
}

// -------------------------------------------------------------------------
// Tools AJAX
// -------------------------------------------------------------------------

public function ajax_tools_action(): void {
check_ajax_referer( 'mm_meta_tools_nonce', '_nonce' );
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( 'Unauthorized', 403 );
}

$action = sanitize_key( $_POST['tools_action'] ?? '' );

		switch ( $action ) {
			case 'reset_settings':
				update_option( MM_META_OPT_SETTINGS, MM_Site_Settings::settings_defaults() );
				update_option( MM_META_OPT_BUSINESS, MM_Site_Settings::business_defaults() );
				wp_send_json_success( 'Settings reset to defaults.' );

			case 'flush_rewrite':
				flush_rewrite_rules();
				wp_send_json_success( 'Rewrite rules flushed.' );

			case 'ping_sitemap':
				( new MM_Mod_Sitemap_Web( $this->settings ) )->send_ping();
				wp_send_json_success( 'Ping sent.' );

			case 'purge_links':
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', MM_Mod_Links::table_name() ) );
				wp_send_json_success( 'Link table purged.' );

			default:
				wp_send_json_error( 'Unknown action.' );
		}
	}

// -------------------------------------------------------------------------
// Help tabs
// -------------------------------------------------------------------------

/**
 * Register contextual help tabs for every Metamanager admin screen.
 * Hooked to current_screen so $screen is already resolved.
 */
public function add_help_tabs( \WP_Screen $screen ): void {
	$map = [
		'metamanager_page_metamanager-business' => 'business',
		'metamanager_page_mm-meta-titles'       => 'titles',
		'metamanager_page_mm-meta-social'       => 'social',
		'metamanager_page_mm-meta-schema'       => 'schema',
		'metamanager_page_mm-meta-sitemaps'     => 'sitemaps',
		'metamanager_page_mm-meta-robots'       => 'robots',
		'metamanager_page_mm-meta-authors'      => 'authors',
		'metamanager_page_mm-meta-hygiene'      => 'hygiene',
		'metamanager_page_mm-meta-feed'         => 'feed',
		'metamanager_page_mm-meta-links'        => 'links',
		'metamanager_page_mm-meta-tools'        => 'tools',
		'metamanager_page_mm-meta-contact'      => 'contact',
	];

	if ( ! isset( $map[ $screen->id ] ) ) {
		return;
	}

	$page = $map[ $screen->id ];

	// Overview tab — every page gets one.
	$screen->add_help_tab( [
		'id'      => 'mm_meta_overview',
		'title'   => __( 'Overview', 'metamanager' ),
		'content' => MM_Metadata_Help::overview( $page ),
	] );

	// Page-specific tabs.
	$extra = MM_Metadata_Help::extra_tabs( $page );
	foreach ( $extra as $tab ) {
		$screen->add_help_tab( $tab );
	}

	// Sidebar with links — shared across all pages.
	$screen->set_help_sidebar(
		'<p><strong>' . esc_html__( 'More resources:', 'metamanager' ) . '</strong></p>' .
		'<p><a href="https://github.com/richardkentgates/metamanager/" target="_blank">' . esc_html__( 'Metamanager', 'metamanager' ) . '</a></p>' .
		'<p><a href="https://schema.org/docs/schemas.html" target="_blank">' . esc_html__( 'Schema.org reference', 'metamanager' ) . '</a></p>' .
		'<p><a href="https://search.google.com/test/rich-results" target="_blank">' . esc_html__( 'Google Rich Results Test', 'metamanager' ) . '</a></p>' .
		'<p><a href="https://developers.facebook.com/tools/debug/" target="_blank">' . esc_html__( 'Facebook Sharing Debugger', 'metamanager' ) . '</a></p>' .
		'<p><a href="https://cards-dev.twitter.com/validator" target="_blank">' . esc_html__( 'Twitter Card Validator', 'metamanager' ) . '</a></p>'
	);
}
// -------------------------------------------------------------------------
// Public helpers (available inside included templates via $this)
// -------------------------------------------------------------------------

public function get_page_url( string $slug ): string {
return admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
}
}
