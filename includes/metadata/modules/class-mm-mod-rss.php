<?php
/**
 * MM_Mod_Rss — RSS feed cleanup and content formatting.
 *
 * WordPress's default RSS 2.0 output includes a number of elements that are
 * either outdated, expose server information, or add namespace clutter that
 * is irrelevant for feed readers.  This module strips those elements and
 * formats feed content as: featured image (if set) + excerpt.
 *
 * What is cleaned:
 *  - <generator> tag — reveals the WordPress version.
 *  - <wfw:commentRss> and <slash:comments> per-item elements — comment-API
 *    relics that no modern feed reader uses.
 *  - The xmlns:wfw and xmlns:slash namespace declarations on <rss> — become
 *    orphaned once the elements above are removed.
 *
 * Feed content format:
 *  - Featured image (if set) prepended to the excerpt.
 *  - Excerpt only — full post content is never served (rss_use_excerpt filter).
 *  - This eliminates page builder noise (widgets, shortcodes) from feeds.
 *
 * What can be configured:
 *  - Disable cleanup entirely (feed.cleanup_enabled).
 *  - Toggle each cleanup individually.
 *  - Override the feed channel title (feed.feed_title).
 *  - Add a copyright notice to the channel (feed.feed_copyright).
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mod RSS.
 *
 * @package Metamanager
 */
class MM_Mod_Rss extends MM_Mod_Base {

	/**
	 * Nothing to add to the HTML <head>.
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
		if ( ! $this->settings->get( 'feed.cleanup_enabled', true ) ) {
			return;
		}

		// Remove the WordPress version disclosure generator tag from all feeds.
		if ( $this->settings->get( 'feed.remove_generator', true ) ) {
			add_filter( 'the_generator', array( $this, 'filter_generator' ), 10, 2 );
		}

		// Strip wfw: / slash: elements and namespace declarations via output buffer.
		if ( $this->settings->get( 'feed.remove_comments_elements', true ) ) {
			add_action( 'template_redirect', array( $this, 'start_rss_buffer' ), 1 );
		}

		// Force excerpt mode — suppress full post content in feeds.
		// rss_use_excerpt filter ensures WordPress never serves full content
		// regardless of reading settings. This eliminates page builder noise.
		add_filter( 'rss_use_excerpt', '__return_true' );

		// Prepend featured image to the excerpt content.
		add_filter( 'the_excerpt_rss', [ $this, 'prepend_featured_image' ], 10, 2 );

		// Override the feed channel title.
		$title = trim( (string) $this->settings->get( 'feed.feed_title', '' ) );
		if ( '' !== $title ) {
			add_filter( 'wp_title_rss', fn() => esc_html( $title ) );
		}

		// Append a <copyright> element to the channel.
		$copyright = trim( (string) $this->settings->get( 'feed.feed_copyright', '' ) );
		if ( '' !== $copyright ) {
			add_action(
				'rss2_head',
				function () use ( $copyright ): void {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<copyright>' . esc_html( $copyright ) . '</copyright>' . "\n";
				}
			);
		}
	}

	// -------------------------------------------------------------------------
	// Generator filter
	// -------------------------------------------------------------------------

	/**
	 * Suppress the generator tag in all feed contexts.
	 *
	 * @param string $gen  The generator XML/HTML string produced by WordPress.
	 * @param string $type Feed type: 'rss2', 'rss', 'atom', 'rdf', 'comment', etc.
	 * @return string Empty string for feed types; unchanged for HTML <head>.
	 */
	public function filter_generator( string $gen, string $type ): string {
		$feed_types = array( 'rss2', 'rss', 'atom', 'rdf', 'comment', 'export', 'opml' );
		return in_array( $type, $feed_types, true ) ? '' : $gen;
	}

	// -------------------------------------------------------------------------
	// Featured image + excerpt
	// -------------------------------------------------------------------------

	/**
	 * Prepend the featured image to the feed excerpt.
	 *
	 * @param string   $excerpt Feed excerpt text.
	 * @param int|null $post_id Post ID (passed by the_excerpt_rss filter).
	 * @return string Excerpt with featured image prepended.
	 */
	public function prepend_featured_image( string $excerpt, $post_id = null ): string {
		if ( ! is_feed() ) {
			return $excerpt;
		}
		$post_id = $post_id ?: get_the_ID();
		if ( ! $post_id || ! has_post_thumbnail( $post_id ) ) {
			return $excerpt;
		}
		$image = get_the_post_thumbnail( $post_id, 'large', [ 'alt' => '' ] );
		if ( ! $image ) {
			return $excerpt;
		}
		return '<p>' . $image . '</p>' . $excerpt;
	}

	// -------------------------------------------------------------------------
	// Output buffer — wfw / slash cleanup
	// -------------------------------------------------------------------------

	/**
	 * Start buffering the feed template output on feed requests.
	 */
	public function start_rss_buffer(): void {
		if ( is_feed() ) {
			ob_start( array( $this, 'clean_rss_output' ) );
		}
	}

	/**
	 * Strip comment-API elements and their orphaned namespace declarations.
	 *
	 * Called by PHP as the output-buffer flush callback.
	 *
	 * @param string $output Raw RSS 2.0 XML as output by WordPress.
	 * @return string Cleaned XML.
	 */
	public function clean_rss_output( string $output ): string {
		// Strip wfw:commentRss and slash:comments per-item elements.
		$output = preg_replace( '#\s*<wfw:commentRss>[^<]*</wfw:commentRss>#', '', $output );
		$output = preg_replace( '#\s*<slash:comments>[^<]*</slash:comments>#', '', $output );

		// Remove the namespace declarations that are now unused on the <rss> tag.
		$output = preg_replace( '/\s+xmlns:wfw="[^"]*"/', '', $output );
		$output = preg_replace( '/\s+xmlns:slash="[^"]*"/', '', $output );

		return $output;
	}
}
