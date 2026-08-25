<?php
/**
 * PHPStan stubs for WooCommerce functions used by optional integrations.
 *
 * WooCommerce may not be installed; these declarations exist only for
 * static analysis and are guarded at runtime by class_exists checks.
 */

if ( ! function_exists( 'wc_get_products' ) ) {
	/**
	 * @param array $args Query arguments.
	 * @return array<int, \WC_Product>
	 */
	function wc_get_products( array $args = [] ): array {
		return [];
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * @param int|\WP_Post|\WC_Product|null $the_product Product ID or object.
	 */
	function wc_get_product( $the_product = null ) {
		return false;
	}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency(): string {
		return 'USD';
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {

		public function get_id(): int {
			return 0;
		}

		public function get_name(): string {
			return '';
		}

		public function get_price_html(): string {
			return '';
		}
	}
}
