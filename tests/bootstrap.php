<?php
/**
 * Bootstrap for PHPUnit tests.
 * No Composer autoload — this is a server plugin.
 */

// Tell WP test suite where PHPUnit Polyfills are.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

// Load WordPress test suite (set up by wp-phpunit).
$wp_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';
if ( ! file_exists($wp_tests_dir . '/includes/functions.php') ) {
    echo "ERROR: WordPress test suite not found at $wp_tests_dir\n";
    exit(1);
}
require_once $wp_tests_dir . '/includes/functions.php';

// Load shared test helpers.
require_once __DIR__ . '/Helpers/helpers.php';

// Define WP_CLI stubs before the plugin loads, so class-mm-cli.php and
// class-mm-metadata-cli.php define their classes instead of returning early.
if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}
if ( ! class_exists( 'WP_CLI_Command' ) ) {
	class WP_CLI_Command {} // phpcs:ignore Generic.Classes.OpeningBraceSameLine
}
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI { // phpcs:ignore Generic.Classes.OpeningBraceSameLine
		public static array $output = [];
		public static function success( string $msg ): void {
			self::$output[] = [ 'type' => 'success', 'msg' => $msg ];
		}
		public static function error( string $msg, bool $exit = true ): void {
			self::$output[] = [ 'type' => 'error', 'msg' => $msg ];
			if ( $exit ) {
				throw new \RuntimeException( $msg );
			}
		}
		public static function line( string $msg = '' ): void {
			self::$output[] = [ 'type' => 'line', 'msg' => $msg ];
		}
		public static function add_command(): void {}
		public static function log( string $msg = '' ): void {
			self::$output[] = [ 'type' => 'log', 'msg' => $msg ];
		}
		public static function confirm( string $msg ): void {}
	}
}

// Register the plugin to load after WordPress is initialized.
tests_add_filter( 'muplugins_loaded', function () {
    require_once dirname( __DIR__ ) . '/metamanager.php';
} );

// Load WordPress (defines ABSPATH, loads WP functions, then fires registered callbacks).
require_once $wp_tests_dir . '/includes/bootstrap.php';
