<?php
/**
 * WP_CLI utility stubs for the PHPUnit test environment.
 *
 * Loaded by tests/bootstrap.php before the plugin, so class-mm-cli.php
 * and class-mm-metadata-cli.php can define their classes.
 *
 * @package Metamanager\Tests\Stubs
 */

namespace WP_CLI\Utils;

if ( ! function_exists( 'WP_CLI\Utils\make_progress_bar' ) ) {
	/**
	 * Stub for WP_CLI\Utils\make_progress_bar().
	 */
	function make_progress_bar( string $message, int $count = 0 ) {
		return new class( $message, $count ) {
			public function __construct( string $message, int $count ) {}
			public function tick(): void {}
			public function finish(): void {}
			public function display(): void {}
		};
	}
}

if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
	/**
	 * Stub for WP_CLI\Utils\format_items().
	 */
	function format_items( string $format, array $items, array $columns ): void {}
}
