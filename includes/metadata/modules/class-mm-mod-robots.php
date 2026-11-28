<?php
/**
 * MM_Mod_Robots — dynamic robots.txt via WordPress's built-in filter.
 *
 * WordPress intercepts /robots.txt requests via do_robots() which fires
 * the robots_txt filter.  We replace the content entirely when enabled.
  *
  * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mod robots.
 *
 * @package Metamanager
 */
class MM_Mod_Robots extends MM_Mod_Base {

	/**
	 * Nothing to add to the HTML head.
	 *
	 * @param array            $data Data.
	 * @param MM_Page_Context  $context Context.
	 * @param MM_Site_Settings $settings Settings.
	 */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		if ( $this->settings->get( 'robots.enabled', true ) ) {
			add_filter( 'robots_txt', array( $this, 'generate' ), 99, 2 );
		}
	}

	/**
	 * Generate.
	 *
	 * @param string $output Output.
	 * @param bool   $public Public.
	 * @return string Resulting string.
	 */
	public function generate( string $output, bool $public ): string {
		$s     = $this->settings;
		$lines = array();

		$lines[] = 'User-agent: *';

		foreach ( (array) $s->get( 'robots.disallow', array() ) as $path ) {
			$path = trim( $path );
			if ( $path ) {
				$lines[] = 'Disallow: ' . sanitize_text_field( $path );
			}
		}

		foreach ( (array) $s->get( 'robots.allow', array() ) as $path ) {
			$path = trim( $path );
			if ( $path ) {
				$lines[] = 'Allow: ' . sanitize_text_field( $path );
			}
		}

		$delay = $s->get( 'robots.crawl_delay', '' );
		if ( $delay !== '' && is_numeric( $delay ) ) {
			$lines[] = 'Crawl-delay: ' . (int) $delay;
		}

		// Custom directives (sanitised, one per line).
		$custom = trim( $s->get( 'robots.custom', '' ) );
		if ( $custom ) {
			foreach ( explode( "\n", $custom ) as $line ) {
				$line = sanitize_text_field( trim( $line ) );
				if ( $line ) {
					$lines[] = $line;
				}
			}
		}

		$lines[] = '';

		// Append Sitemap directive(s) for each active sitemap.
		if ( $s->get( 'sitemap.enabled', true ) ) {
			$lines[] = 'Sitemap: ' . esc_url( home_url( '/sitemap.xml' ) );
		}

		return implode( "\n", $lines );
	}
}
