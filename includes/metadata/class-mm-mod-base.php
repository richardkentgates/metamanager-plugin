<?php
/**
 * MM_Mod_Base — abstract base class for all SEO modules.
 *
 * Each concrete module extends this class and implements populate(), which
 * writes its output into the shared $data array rather than echoing directly.
 */

defined( 'ABSPATH' ) || exit;

abstract class MM_Mod_Base {

	protected MM_Site_Settings $settings;

	public function __construct( MM_Site_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Populate the shared document data array.
	 *
	 * @param array            $data     Passed by reference.
	 * @param MM_Page_Context $context  Current page context.
	 * @param MM_Site_Settings $settings Plugin settings.
	 */
	abstract public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void;

	// -------------------------------------------------------------------------
	// Shared helpers available to all modules
	// -------------------------------------------------------------------------

	/**
	 * Get the primary navigation menu from WordPress theme locations.
	 *
	 * Returns the first assigned menu, or null if no menus are assigned.
	 *
	 * @return array{id: int, name: string, items: array}|null
	 */
	public static function get_primary_menu(): ?array {
		if ( ! function_exists( 'wp_get_nav_menu_locations' ) ) {
			return null;
		}
		$locations = wp_get_nav_menu_locations();
		if ( empty( $locations ) ) {
			return null;
		}

		$menu_id = 0;
		foreach ( $locations as $assigned ) {
			if ( $assigned ) {
				$menu_id = $assigned;
				break;
			}
		}

		if ( ! $menu_id ) {
			return null;
		}

		$menu_items = wp_get_nav_menu_items( $menu_id );
		$menu_obj  = get_term( $menu_id, 'nav_menu' );
		$menu_name = ( $menu_obj && ! is_wp_error( $menu_obj ) ) ? $menu_obj->name : 'Navigation';

		$items = [];
		if ( is_array( $menu_items ) ) {
			$position = 1;
			foreach ( $menu_items as $item ) {
				if ( $item->url && $item->title ) {
					$items[] = [
						'name'     => $item->title,
						'url'      => $item->url,
						'position' => $position++,
					];
				}
			}
		}

		return [
			'id'    => $menu_id,
			'name'  => $menu_name,
			'items' => $items,
		];
	}

	/**
	 * Build a sanitized PostalAddress schema node from raw address fields.
	 *
	 * @param array{street?: string, city?: string, state?: string, zip?: string, country?: string} $addr Raw address fields.
	 * @return array<string, mixed> PostalAddress node as associative array, or empty array if no street.
	 */
	public static function postal_address_node( array $addr ): array {
		if ( empty( $addr['street'] ) ) {
			return [];
		}
		return [
			'@type'           => 'PostalAddress',
			'streetAddress'   => sanitize_text_field( $addr['street'] ?? '' ),
			'addressLocality' => sanitize_text_field( $addr['city'] ?? '' ),
			'addressRegion'   => sanitize_text_field( $addr['state'] ?? '' ),
			'postalCode'      => sanitize_text_field( $addr['zip'] ?? '' ),
			'addressCountry'  => sanitize_text_field( $addr['country'] ?? 'US' ),
		];
	}

	/** Add a meta tag to the data array, preventing duplicates by name/property. */
	protected function add_meta( array &$data, array $attrs ): void {
		$key = $attrs['name'] ?? $attrs['property'] ?? null;
		if ( $key ) {
			// Remove existing with same identifier.
			$data['meta'] = array_values( array_filter( $data['meta'], function ( $m ) use ( $key ) {
				return ( $m['name'] ?? $m['property'] ?? null ) !== $key;
			} ) );
		}
		$data['meta'][] = $attrs;
	}

	/** Add a link tag. */
	protected function add_link( array &$data, array $attrs ): void {
		$data['links'][] = $attrs;
	}

	/** Add or replace a schema node by @id. */
	protected function add_node( array &$data, array $node ): void {
		if ( isset( $node['@id'] ) ) {
			foreach ( $data['schema'] as $i => $existing ) {
				if ( ( $existing['@id'] ?? '' ) === $node['@id'] ) {
					$data['schema'][ $i ] = $node;
					return;
				}
			}
		}
		$data['schema'][] = $node;
	}

	/** Merge additional properties into an existing schema node by @id. */
	protected function merge_node( array &$data, string $id, array $extra ): void {
		foreach ( $data['schema'] as $i => $node ) {
			if ( ( $node['@id'] ?? '' ) === $id ) {
				$data['schema'][ $i ] = array_merge( $node, $extra );
				return;
			}
		}
	}

	/** Safe site URL with trailing slash. */
	protected function site_url(): string {
		return trailingslashit( home_url() );
	}

	/** Build a schema @id string for the site. */
	protected function site_id( string $fragment = '' ): string {
		return $this->site_url() . ( $fragment ? '#' . $fragment : '' );
	}

	/**
	 * Get the best available image data (URL, width, height) for a given
	 * attachment ID or direct URL, with graceful fallback.
	 *
	 * @return array{url: string, width: int, height: int}
	 */
	protected function image_data( int $attachment_id = 0, string $url = '' ): array {
		if ( $attachment_id ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			$src  = wp_get_attachment_image_src( $attachment_id, 'full' );
			return [
				'url'    => $src ? $src[0] : '',
				'width'  => $meta['width']  ?? ( $src[1] ?? 0 ),
				'height' => $meta['height'] ?? ( $src[2] ?? 0 ),
			];
		}
		if ( $url ) {
			// Try to find the attachment by URL.
			$id = attachment_url_to_postid( $url );
			if ( $id ) {
				return $this->image_data( $id );
			}
			return [ 'url' => $url, 'width' => 0, 'height' => 0 ];
		}
		return [ 'url' => '', 'width' => 0, 'height' => 0 ];
	}
}
