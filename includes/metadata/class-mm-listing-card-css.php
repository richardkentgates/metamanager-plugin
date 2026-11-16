<?php
/**
 * MM_Listing_Card_CSS — generates CSS for listing card "Learn more" buttons.
 *
 * Values are sanitized before CSS output to prevent injection.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

class MM_Listing_Card_CSS {

	/**
	 * Get the configured button text.
	 */
	public static function get_button_text(): string {
		$s = MM_Site_Settings::get_instance();
		return self::sanitize( (string) $s->get( 'listing.btn_text', 'Learn more' ) );
	}

	/**
	 * Generate CSS for the listing card link/button.
	 *
	 * @param array $s Style settings from MM_Site_Settings::get('listing').
	 * @return string Safe CSS string.
	 */
	public static function generate( array $s ): string {
		$btn_text        = self::sanitize( $s['btn_text'] ?? 'Learn more' );
		$btn_bg          = self::color( $s['btn_bg'] ?? '#0073aa' );
		$btn_text_color  = self::color( $s['btn_text_color'] ?? '#ffffff' );
		$btn_radius      = self::shorthand( $s['btn_radius'] ?? '4px' );
		$btn_padding     = self::shorthand( $s['btn_padding'] ?? '10px 16px' );
		$btn_font_size   = self::length( $s['btn_font_size'] ?? '14px' );
		$btn_font_weight = self::font_weight( $s['btn_font_weight'] ?? '600' );

		$css  = '.mm-service-card__link{display:inline-block;';
		$css .= "padding:{$btn_padding};background:{$btn_bg};color:{$btn_text_color};";
		$css .= "border-radius:{$btn_radius};font-size:{$btn_font_size};";
		$css .= "font-weight:{$btn_font_weight};text-decoration:none;margin-top:8px;}\n";
		$css .= ".mm-service-card__link:hover{opacity:0.85;}\n";

		return $css;
	}

	private static function color( string $v ): string {
		$v = trim( $v );
		return preg_match( '/^#[0-9a-fA-F]{3,8}$/', $v ) ? $v : '#0073aa';
	}

	private static function length( string $v ): string {
		$v = trim( $v );
		return preg_match( '/^[0-9.]+(px|rem|em|%)$/', $v ) ? $v : '14px';
	}

	private static function shorthand( string $v ): string {
		$v = trim( $v );
		return preg_match( '/^[0-9a-zA-Z .%]+$/', $v ) ? $v : '4px';
	}

	private static function font_weight( string $v ): string {
		$v = trim( $v );
		return in_array( $v, array( '400', '500', '600', '700' ), true ) ? $v : '600';
	}

	private static function sanitize( string $v ): string {
		return esc_html( trim( $v ) );
	}
}
