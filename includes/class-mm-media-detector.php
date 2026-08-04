<?php
/**
 * MM_Media_Detector — scans post_content for actual media elements.
 *
 * Extracts <img>, <video>, <audio>, <iframe>, and <a> tags from HTML content
 * and resolves them to WordPress attachment IDs for metadata enrichment.
 *
 * Used by MM_Mod_Schema and MM_Mod_Social to emit structured data and Open
 * Graph tags only for media that is actually present on the page.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

class MM_Media_Detector {

	/**
	 * Extract all media items from post content.
	 *
	 * Returns an array of media records, each with:
	 *   - type: 'image' | 'video' | 'audio' | 'embed' | 'document'
	 *   - url: the media URL
	 *   - attachment_id: WP attachment ID (0 if not a WP-managed file)
	 *   - width/height: dimensions (images only, 0 if unknown)
	 *   - alt: alt text (images only)
	 *   - caption: caption text (images only)
	 *
	 * @param  string $content  Post content HTML.
	 * @return array            Media records.
	 */
	public static function scan_content( string $content ): array {
		if ( '' === $content ) {
			return [];
		}

		$items   = [];
		$seen    = [];
		$results = [];

		// Images.
		if ( preg_match_all( '/<img\b[^>]*?>/is', $content, $matches ) ) {
			foreach ( $matches[0] as $tag ) {
				$img = self::parse_img_tag( $tag );
				if ( $img && ! isset( $seen[ $img['url'] ] ) ) {
					$seen[ $img['url'] ] = true;
					$results[]           = $img;
				}
			}
		}

		// Video.
		if ( preg_match_all( '/<video\b[^>]*>(.*?)<\/video>/is', $content, $matches ) ) {
			foreach ( $matches[1] as $inner ) {
				$vids = self::parse_video_sources( $inner );
				foreach ( $vids as $v ) {
					if ( ! isset( $seen[ $v['url'] ] ) ) {
						$seen[ $v['url'] ] = true;
						$results[]         = $v;
					}
				}
			}
		}

		// Audio.
		if ( preg_match_all( '/<audio\b[^>]*>(.*?)<\/audio>/is', $content, $matches ) ) {
			foreach ( $matches[1] as $inner ) {
				$auds = self::parse_audio_sources( $inner );
				foreach ( $auds as $a ) {
					if ( ! isset( $seen[ $a['url'] ] ) ) {
						$seen[ $a['url'] ] = true;
						$results[]         = $a;
					}
				}
			}
		}

		// Iframes (YouTube, Vimeo, etc.).
		if ( preg_match_all( '/<iframe\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*?>/is', $content, $matches ) ) {
			foreach ( $matches[1] as $src ) {
				$src = esc_url_raw( $src );
				if ( $src && ! isset( $seen[ $src ] ) ) {
					$seen[ $src ] = true;
					$results[]    = self::parse_iframe_src( $src );
				}
			}
		}

		// PDF/document links.
		if ( preg_match_all( '/<a\b[^>]*\bhref=["\']([^"\']+\.pdf(?:\?[^"\'*])?)["\'][^>]*?>/is', $content, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$href = esc_url_raw( $href );
				if ( $href && ! isset( $seen[ $href ] ) ) {
					$seen[ $href ] = true;
					$results[]     = [
						'type'          => 'document',
						'url'           => $href,
						'attachment_id' => attachment_url_to_postid( $href ),
					];
				}
			}
		}

		/**
		 * Filter the detected media items from post content.
		 *
		 * @param array  $items   Detected media records.
		 * @param string $content Original post content.
		 */
		return apply_filters( 'mm_detected_media', $results, $content );
	}

	/**
	 * Get the first image found in post content.
	 *
	 * @param  string $content  Post content HTML.
	 * @return array|null       Image record or null.
	 */
	public static function first_image( string $content ): ?array {
		$items = self::scan_content( $content );
		foreach ( $items as $item ) {
			if ( 'image' === $item['type'] ) {
				return $item;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Tag parsers
	// -------------------------------------------------------------------------

	/**
	 * Parse an <img> tag into a media record.
	 */
	private static function parse_img_tag( string $tag ): ?array {
		if ( ! preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag, $src ) ) {
			return null;
		}

		$url = esc_url_raw( $src[1] );
		if ( ! $url ) {
			return null;
		}

		$alt     = '';
		$width   = 0;
		$height  = 0;
		$caption = '';

		if ( preg_match( '/\balt=["\']([^"\']*)["\']/i', $tag, $a ) ) {
			$alt = $a[1];
		}
		if ( preg_match( '/\bwidth=["\'](\d+)["\']/i', $tag, $w ) ) {
			$width = (int) $w[1];
		}
		if ( preg_match( '/\bheight=["\'](\d+)["\']/i', $tag, $h ) ) {
			$height = (int) $h[1];
		}

		$attachment_id = attachment_url_to_postid( $url );

		// Enrich from WP attachment data when available.
		if ( $attachment_id ) {
			$att = get_post( $attachment_id );
			if ( $att ) {
				if ( '' === $alt ) {
					$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
				}
				$caption = trim( $att->post_excerpt );
			}
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( $meta ) {
				$width  = $width ?: (int) ( $meta['width'] ?? 0 );
				$height = $height ?: (int) ( $meta['height'] ?? 0 );
			}
		}

		return [
			'type'          => 'image',
			'url'           => $url,
			'attachment_id' => $attachment_id,
			'width'         => $width,
			'height'        => $height,
			'alt'           => $alt,
			'caption'       => $caption,
		];
	}

	/**
	 * Parse <source> tags inside a <video> element.
	 */
	private static function parse_video_sources( string $inner ): array {
		$items = [];

		// src attribute on <video> itself.
		if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $inner, $src ) ) {
			$items[] = self::make_video_record( esc_url_raw( $src[1] ) );
		}

		// <source> children.
		if ( preg_match_all( '/<source\b[^>]*\bsrc=["\']([^"\']+)["\']/is', $inner, $matches ) ) {
			foreach ( $matches[1] as $src ) {
				$items[] = self::make_video_record( esc_url_raw( $src ) );
			}
		}

		return array_filter( $items );
	}

	/**
	 * Parse <source> tags inside an <audio> element.
	 */
	private static function parse_audio_sources( string $inner ): array {
		$items = [];

		if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $inner, $src ) ) {
			$items[] = self::make_audio_record( esc_url_raw( $src[1] ) );
		}

		if ( preg_match_all( '/<source\b[^>]*\bsrc=["\']([^"\']+)["\']/is', $inner, $matches ) ) {
			foreach ( $matches[1] as $src ) {
				$items[] = self::make_audio_record( esc_url_raw( $src ) );
			}
		}

		return array_filter( $items );
	}

	/**
	 * Build a video media record from a URL.
	 */
	private static function make_video_record( string $url ): ?array {
		if ( ! $url ) {
			return null;
		}

		$attachment_id = attachment_url_to_postid( $url );
		$duration      = 0;

		if ( $attachment_id ) {
			$dur = (int) get_post_meta( $attachment_id, MM_Metadata::META_DURATION, true );
			if ( $dur > 0 ) {
				$duration = $dur;
			}
		}

		return [
			'type'          => 'video',
			'url'           => $url,
			'attachment_id' => $attachment_id,
			'duration'      => $duration,
		];
	}

	/**
	 * Build an audio media record from a URL.
	 */
	private static function make_audio_record( string $url ): ?array {
		if ( ! $url ) {
			return null;
		}

		$attachment_id = attachment_url_to_postid( $url );
		$duration      = 0;

		if ( $attachment_id ) {
			$dur = (int) get_post_meta( $attachment_id, MM_Metadata::META_DURATION, true );
			if ( $dur > 0 ) {
				$duration = $dur;
			}
		}

		return [
			'type'          => 'audio',
			'url'           => $url,
			'attachment_id' => $attachment_id,
			'duration'      => $duration,
		];
	}

	/**
	 * Build an embed media record from an iframe src URL.
	 *
	 * Detects YouTube and Vimeo and marks them as video embeds.
	 */
	private static function parse_iframe_src( string $url ): array {
		$type = 'embed';

		if ( preg_match( '/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)/i', $url ) ) {
			$type = 'video';
		} elseif ( preg_match( '/vimeo\.com\/(\d+)/i', $url ) ) {
			$type = 'video';
		}

		return [
			'type'          => $type,
			'url'           => $url,
			'attachment_id' => 0,
		];
	}
}
