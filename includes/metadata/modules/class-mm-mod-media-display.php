<?php
/**
 * MM_Mod_Media_Display — Frontend media display features.
 *
 * Handles featured image citation and other media display enhancements.
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Media_Display {

	public function register_hooks(): void {
		$settings = MM_Site_Settings::get_instance();

		if ( $settings->get( 'media.featured_image_citation', false ) ) {
			add_filter( 'wp_get_attachment_image', [ $this, 'filter_featured_image_citation' ], 10, 5 );
		}
	}

	/**
	 * Add citation HTML under featured images.
	 */
	public function filter_featured_image_citation( $image, $attachment_id, $size, $attr, $post_id ): string {
		if ( ! $post_id ) {
			return $image;
		}

		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumb_id || $thumb_id !== $attachment_id ) {
			return $image;
		}

		$citation = $this->get_attachment_citation( $attachment_id );
		if ( ! $citation ) {
			return $image;
		}

		return $image . '<figcaption class="mm-image-citation">' . $citation . '</figcaption>';
	}

	/**
	 * Get citation HTML for an attachment.
	 */
	private function get_attachment_citation( int $attachment_id ): string {
		$meta = static fn( string $key ): string => (string) get_post_meta( $attachment_id, $key, true );

		$creator  = $meta( MM_Metadata::META_CREATOR );
		$copyright = $meta( MM_Metadata::META_COPYRIGHT );
		$owner    = $meta( MM_Metadata::META_OWNER );
		$date     = $meta( MM_Metadata::META_DATE );

		$parts = [];

		if ( $creator ) {
			$parts[] = esc_html( $creator );
		}

		if ( $copyright ) {
			$parts[] = esc_html( $copyright );
		} elseif ( $owner ) {
			$parts[] = '&copy; ' . esc_html( $owner );
		}

		if ( $date ) {
			$parts[] = esc_html( $date );
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return implode( ' | ', $parts );
	}
}
