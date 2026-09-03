<?php
/**
 * Lightweight WP-Cron execution tracker for MetaManager.
 *
 * Tracks last run, next run, pass/fail counts, and recent history
 * for each MetaManager cron event. Data stored in a single WP option.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Cron_Tracker
 */
class MM_Cron_Tracker {

	/** Option key for cron history. */
	private const OPTION_KEY = 'mm_cron_history';

	/** Maximum history entries per hook. */
	private const MAX_HISTORY = 20;

	/**
	 * Wrap a callback to auto-track execution.
	 *
	 * Usage:
	 *   add_action( 'hook', MM_Cron_Tracker::wrap( 'hook', [ $this, 'method' ] ) );
	 *
	 * @param string   $hook     Cron hook name.
	 * @param callable $callback The original callback.
	 * @return callable Wrapped callback.
	 */
	public static function wrap( string $hook, callable $callback ): callable {
		return function () use ( $hook, $callback ) {
			try {
				$result = $callback();
				self::record( $hook, 'pass' );
				return $result;
			} catch ( \Throwable $e ) {
				self::record( $hook, 'fail', $e->getMessage() );
				throw $e;
			}
		};
	}

	/**
	 * Record a cron run.
	 *
	 * @param string $hook    Cron hook name.
	 * @param string $status  'pass' or 'fail'.
	 * @param string $error   Error message (for failures).
	 */
	public static function record( string $hook, string $status, string $error = '' ): void {
		$history   = get_option( self::OPTION_KEY, array() );
		$now       = gmdate( 'Y-m-d\TH:i:s\Z' );
		$next_run  = self::get_next_run( $hook );

		if ( ! isset( $history[ $hook ] ) ) {
			$history[ $hook ] = array(
				'last_run'    => '',
				'last_status' => '',
				'next_run'    => '',
				'pass_count'  => 0,
				'fail_count'  => 0,
				'history'     => array(),
			);
		}

		$entry = array(
			'ts'     => $now,
			'status' => $status,
		);
		if ( '' !== $error ) {
			$entry['error'] = $error;
		}

		$history[ $hook ]['last_run']    = $now;
		$history[ $hook ]['last_status'] = $status;
		$history[ $hook ]['next_run']    = $next_run;

		if ( 'pass' === $status ) {
			++$history[ $hook ]['pass_count'];
		} else {
			++$history[ $hook ]['fail_count'];
		}

		array_unshift( $history[ $hook ]['history'], $entry );
		$history[ $hook ]['history'] = array_slice( $history[ $hook ]['history'], 0, self::MAX_HISTORY );

		update_option( self::OPTION_KEY, $history, false );
	}

	/**
	 * Get next scheduled run for a hook.
	 *
	 * @param string $hook Cron hook name.
	 * @return string|null ISO 8601 timestamp or null.
	 */
	private static function get_next_run( string $hook ): ?string {
		$timestamp = wp_next_scheduled( $hook );
		return $timestamp ? gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) : null;
	}

	/**
	 * Get history for all tracked hooks.
	 *
	 * @return array
	 */
	public static function get_all(): array {
		return get_option( self::OPTION_KEY, array() );
	}

	/**
	 * Get history for a single hook.
	 *
	 * @param string $hook Cron hook name.
	 * @return array|null Hook data or null if not tracked.
	 */
	public static function get( string $hook ): ?array {
		$history = get_option( self::OPTION_KEY, array() );
		return $history[ $hook ] ?? null;
	}
}
