<?php
/**
 * MM_Mod_Discovery — generates machine-readable discovery files for AI agents:
 *
 *  - /llms.txt          — human-readable site description (llmstxt.org spec)
 *  - /llms-full.txt     — same but with content excerpts
 *  - /.well-known/api-catalog — RFC 9727 linkset catalog
 *
 * All output is cached as transients and regenerated on settings save.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mod discovery.
 *
 * @package Metamanager
 */
class MM_Mod_Discovery {

	/**
	 * Settings.
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
		if ( ! $this->settings->get( 'discovery.llms_txt_enabled', true ) ) {
			return;
		}

		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 1 );
		add_action( 'wp_ajax_mm_regenerate_discovery', array( $this, 'ajax_regenerate' ) );
		add_action( 'update_option_mm_meta_settings', array( $this, 'clear_cache' ) );
	}

	// -------------------------------------------------------------------------
	// Rewrite rules
	// -------------------------------------------------------------------------

	/**
	 * Add rewrite rules.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^llms-full\.txt/?$', 'index.php?mm_llms_full=1', 'top' );
		add_rewrite_rule( '^llms\.txt/?$', 'index.php?mm_llms=1', 'top' );
		add_rewrite_rule( '^\.well-known/api-catalog/?$', 'index.php?mm_api_catalog=1', 'top' );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Vars.
	 * @return array Resulting array.
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = 'mm_llms';
		$vars[] = 'mm_llms_full';
		$vars[] = 'mm_api_catalog';
		return $vars;
	}

	/**
	 * Intercept requests for discovery files.
	 */
	public function maybe_serve(): void {
		global $wp_query;

		if ( $wp_query->get( 'mm_llms' ) ) {
			$this->serve_llms_txt( false );
			exit;
		}

		if ( $wp_query->get( 'mm_llms_full' ) ) {
			$this->serve_llms_txt( true );
			exit;
		}

		if ( $wp_query->get( 'mm_api_catalog' ) ) {
			$this->serve_api_catalog();
			exit;
		}
	}

	// -------------------------------------------------------------------------
	// llms.txt
	// -------------------------------------------------------------------------

	/**
	 * Serve llms txt.
	 *
	 * @param bool $full Full.
	 */
	private function serve_llms_txt( bool $full ): void {
		$cache_key = $full ? 'mm_llms_full' : 'mm_llms';
		$cached    = get_transient( $cache_key );

		if ( false === $cached ) {
			$cached = $this->generate_llms_txt( $full );
			set_transient( $cache_key, $cached, DAY_IN_SECONDS );
		}

		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Generate llms txt.
	 *
	 * @param bool $full Full.
	 * @return string Resulting string.
	 */
	private function generate_llms_txt( bool $full ): string {
		$name        = get_bloginfo( 'name' );
		$description = get_bloginfo( 'description' );
		$home        = home_url( '/' );

		$lines   = array();
		$lines[] = "# {$name}";
		$lines[] = '';
		$lines[] = $description;
		$lines[] = '';
		$lines[] = '## Site Info';
		$lines[] = "- URL: {$home}";
		$lines[] = '- CMS: WordPress';
		$lines[] = '- SEO Plugin: Metamanager';

		// MCP endpoint.
		if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
			$lines[] = '- MCP Endpoint: ' . home_url( '/wp-json/metamanager-mcp/mcp' );
		}

		$lines[] = '';

		// Pages.
		$pages = get_pages(
			array(
				'number'      => 20,
				'post_status' => 'publish',
				'sort_column' => 'menu_order',
			)
		);
		if ( $pages ) {
			$lines[] = '## Pages';
			foreach ( $pages as $page ) {
				$url  = get_permalink( $page );
				$line = "- [{$page->post_title}]({$url})";
				if ( $full && $page->post_excerpt ) {
					$line .= ': ' . wp_trim_words( $page->post_excerpt, 30 );
				}
				$lines[] = $line;
			}
			$lines[] = '';
		}

		// Recent posts.
		$posts = get_posts(
			array(
				'number'      => 20,
				'post_status' => 'publish',
			)
		);
		if ( $posts ) {
			$lines[] = '## Recent Posts';
			foreach ( $posts as $post ) {
				$url  = get_permalink( $post );
				$line = "- [{$post->post_title}]({$url})";
				if ( $full ) {
					$excerpt = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
					$line   .= ': ' . $excerpt;
				}
				$lines[] = $line;
			}
			$lines[] = '';
		}

		// Taxonomies.
		$taxonomies = get_taxonomies(
			array(
				'public'       => true,
				'show_in_rest' => true,
			),
			'objects'
		);
		foreach ( $taxonomies as $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax->name,
					'number'     => 10,
					'hide_empty' => true,
				)
			);
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}
			$lines[] = '## ' . $tax->labels->name;
			foreach ( $terms as $term ) {
				$url = get_term_link( $term );
				if ( is_wp_error( $url ) ) {
					continue;
				}
				$lines[] = "- [{$term->name}]({$url})";
			}
			$lines[] = '';
		}

		// Contact.
		$local = $this->settings->all_business();
		if ( ! empty( $local['phone'] ) || ! empty( $local['email'] ) ) {
			$lines[] = '## Contact';
			if ( ! empty( $local['phone'] ) ) {
				$lines[] = "- Phone: {$local['phone']}";
			}
			if ( ! empty( $local['email'] ) ) {
				$lines[] = "- Email: {$local['email']}";
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// .well-known/api-catalog
	// -------------------------------------------------------------------------

	/**
	 * Serve API catalog.
	 */
	private function serve_api_catalog(): void {
		$cached = get_transient( 'mm_api_catalog' );

		if ( false === $cached ) {
			$cached = $this->generate_api_catalog();
			set_transient( 'mm_api_catalog', $cached, DAY_IN_SECONDS );
		}

		header( 'Content-Type: application/linkset+json; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Generate API catalog.
	 *
	 * @return string Resulting string.
	 */
	private function generate_api_catalog(): string {
		$home = home_url( '/' );

		$catalog = array(
			'rel'     => 'catalog',
			'href'    => $home,
			'title'   => get_bloginfo( 'name' ) . ' — API Catalog',
			'linkset' => array(
				array(
					'rel'   => 'describedby',
					'href'  => home_url( '/wp-json/' ),
					'title' => 'WordPress REST API Root',
					'type'  => 'application/json',
				),
			),
		);

		// MCP endpoint.
		if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
			$catalog['linkset'][] = array(
				'rel'   => 'mcp-server',
				'href'  => home_url( '/wp-json/metamanager-mcp/mcp' ),
				'title' => 'Metamanager MCP Server',
				'type'  => 'application/json',
			);
		}

		// llms.txt.
		if ( $this->settings->get( 'discovery.llms_txt_enabled', true ) ) {
			$catalog['linkset'][] = array(
				'rel'   => 'describedby',
				'href'  => home_url( '/llms.txt' ),
				'title' => 'Site description for AI agents',
				'type'  => 'text/plain',
			);
		}

		return wp_json_encode( $catalog, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	// -------------------------------------------------------------------------
	// Cache management
	// -------------------------------------------------------------------------

	/**
	 * Clear cache.
	 */
	public function clear_cache(): void {
		delete_transient( 'mm_llms' );
		delete_transient( 'mm_llms_full' );
		delete_transient( 'mm_api_catalog' );
	}

	/**
	 * AJAX handler: regenerate.
	 */
	public function ajax_regenerate(): void {
		check_ajax_referer( 'mm_tools_action', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$this->clear_cache();
		wp_send_json_success( 'Discovery files regenerated.' );
	}
}
