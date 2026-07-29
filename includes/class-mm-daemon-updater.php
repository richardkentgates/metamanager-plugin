<?php
/**
 * Metamanager Daemon Updater
 *
 * Automatically updates the OS daemon package when the plugin is updated.
 * Reads a compatibility map to determine which daemon version matches the
 * current plugin version, then triggers an apt upgrade if needed.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Daemon_Updater
 */
class MM_Daemon_Updater {

	/** Path to the daemon VERSION file on the server. */
	private const DAEMON_VERSION_FILE = '/usr/local/lib/metamanager/VERSION';

	/** Path to the compatibility map bundled with the plugin. */
	private const COMPAT_MAP_FILE = 'daemon-compatibility.json';

	/** Option key for storing the last update result. */
	private const OPTION_RESULT = 'mm_daemon_update_result';

	/**
	 * Boot the updater.
	 */
	public static function init(): void {
		$instance = new self();
		$instance->hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function hooks(): void {
		add_action( 'admin_notices', [ $this, 'admin_notice' ] );
	}

	// -------------------------------------------------------------------------
	// Version detection
	// -------------------------------------------------------------------------

	/**
	 * Read the installed daemon version from the VERSION file.
	 *
	 * @return string|null Version string or null if unreadable.
	 */
	public static function get_daemon_version(): ?string {
		if ( ! file_exists( self::DAEMON_VERSION_FILE ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$version = trim( file_get_contents( self::DAEMON_VERSION_FILE ) );
		return $version !== '' ? $version : null;
	}

	/**
	 * Read the compatibility map and return the required daemon version
	 * for the current plugin version.
	 *
	 * @return string|null Required daemon version, or null if not in map.
	 */
	public static function get_required_daemon_version(): ?string {
		$map_file = MM_PLUGIN_DIR . self::COMPAT_MAP_FILE;
		if ( ! file_exists( $map_file ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = file_get_contents( $map_file );
		$map  = json_decode( $json, true );

		if ( ! is_array( $map ) ) {
			return null;
		}

		return $map[ MM_VERSION ] ?? null;
	}

	/**
	 * Check if the installed daemon version matches what the plugin needs.
	 *
	 * @return array{match: bool, current: string|null, required: string|null}
	 */
	public static function check_version(): array {
		$current  = self::get_daemon_version();
		$required = self::get_required_daemon_version();

		return [
			'match'    => ( $current !== null && $required !== null && $current === $required ),
			'current'  => $current,
			'required' => $required,
		];
	}

	// -------------------------------------------------------------------------
	// Update trigger
	// -------------------------------------------------------------------------

	/**
	 * Check whether exec() is available and not disabled.
	 *
	 * @return bool
	 */
	private static function exec_available(): bool {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$disabled = ini_get( 'disable_functions' );
		if ( $disabled ) {
			$disabled = array_map( 'trim', explode( ',', $disabled ) );
			return ! in_array( 'exec', $disabled, true );
		}

		return true;
	}

	/**
	 * Trigger the daemon package update via apt.
	 *
	 * Runs commands individually:
	 *   1. apt-get update (refresh package lists)
	 *   2. apt-get install -y metamanager (upgrade daemon)
	 *   3. systemctl restart each daemon separately
	 *
	 * Each command is logged to the WordPress error log and OS syslog.
	 *
	 * @return array{success: bool, message: string, output: string}
	 */
	public static function trigger_update(): array {
		if ( ! self::exec_available() ) {
			$message = 'PHP exec() is disabled. Cannot trigger daemon update automatically.';
			self::log_wordpress( $message, 'error' );
			self::log_os( "daemon-update/error: {$message}" );
			self::store_result( false, $message, '' );

			return [
				'success' => false,
				'message' => $message,
				'output'  => '',
			];
		}

		$commands = [
			'update'          => 'sudo DEBIAN_FRONTEND=noninteractive apt-get update -qq',
			'install'         => 'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq metamanager',
			'restart-compress' => 'sudo systemctl restart metamanager-compress-daemon',
			'restart-meta'    => 'sudo systemctl restart metamanager-meta-daemon',
		];

		$output = '';

		foreach ( $commands as $step => $cmd ) {
			$step_out = '';
			$exit     = 0;

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_obfuscation
			@exec( $cmd . ' 2>&1', $step_out, $exit );
			$step_out = implode( "\n", (array) $step_out );
			$output  .= "[{$step}] exit={$exit}\n{$step_out}\n\n";

			self::log_os( "daemon-update/{$step}: exit={$exit} " . trim( $step_out ) );

			if ( $exit !== 0 ) {
				$message = "Daemon update failed at step '{$step}' (exit code {$exit}).";
				self::log_wordpress( $message, 'error' );
				self::store_result( false, $message, $output );

				return [
					'success' => false,
					'message' => $message,
					'output'  => $output,
				];
			}
		}

		// Verify the new version matches expectations.
		$new_version = self::get_daemon_version();
		$required    = self::get_required_daemon_version();

		if ( $new_version !== $required ) {
			$message = sprintf(
				'Daemon updated but version mismatch: got %s, expected %s.',
				$new_version ?? 'unknown',
				$required ?? 'unknown'
			);
			self::log_wordpress( $message, 'warning' );
			self::store_result( false, $message, $output );

			return [
				'success' => false,
				'message' => $message,
				'output'  => $output,
			];
		}

		$message = sprintf( 'Daemon updated successfully to v%s.', $new_version );
		self::log_wordpress( $message, 'info' );
		self::store_result( true, $message, $output );

		return [
			'success' => true,
			'message' => $message,
			'output'  => $output,
		];
	}

	// -------------------------------------------------------------------------
	// Called from MM_Updater after plugin update
	// -------------------------------------------------------------------------

	/**
	 * Handle post-plugin-update logic.
	 *
	 * Called by MM_Updater::on_plugin_updated(). Checks version compatibility
	 * and triggers daemon update if needed.
	 *
	 * @return array{update_needed: bool, result: array|null}
	 */
	public static function handle_plugin_update(): array {
		$check = self::check_version();

		if ( $check['match'] ) {
			// Clear any stale error from a previous failed update attempt.
			$previous = get_option( self::OPTION_RESULT );
			if ( ! empty( $previous ) && empty( $previous['success'] ) ) {
				delete_option( self::OPTION_RESULT );
			}

			return [
				'update_needed' => false,
				'result'       => null,
			];
		}

		self::log_wordpress(
			sprintf(
				'Daemon version mismatch detected: installed=%s, required=%s. Triggering update.',
				$check['current'] ?? 'unknown',
				$check['required'] ?? 'unknown'
			),
			'info'
		);

		$result = self::trigger_update();

		return [
			'update_needed' => true,
			'result'       => $result,
		];
	}

	// -------------------------------------------------------------------------
	// Logging
	// -------------------------------------------------------------------------

	/**
	 * Log a message to the WordPress error log.
	 *
	 * Always logs errors and warnings. In debug mode (WP_DEBUG), also logs
	 * info-level messages.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (info, warning, error).
	 */
	private static function log_wordpress( string $message, string $level = 'info' ): void {
		// Always log errors and warnings.
		if ( 'error' !== $level && 'warning' !== $level ) {
			if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
				return;
			}
		}

		$prefix = '[metamanager-daemon-updater] [' . strtoupper( $level ) . ']';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_obfuscation
		error_log( "{$prefix} {$message}" );
	}

	/**
	 * Log a message to the OS syslog (or /var/log/metamanager-update.log).
	 *
	 * @param string $message Log message.
	 */
	private static function log_os( string $message ): void {
		$log_file = '/var/log/metamanager-update.log';
		$line     = sprintf( "[%s] %s\n", gmdate( 'Y-m-d H:i:s' ), $message );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_error_log
		if ( ! @error_log( $line, 3, $log_file ) ) {
			if ( function_exists( 'openlog' ) ) {
				openlog( 'metamanager', LOG_PID, LOG_USER );
				syslog( LOG_INFO, $message );
				closelog();
			}
		}
	}

	// -------------------------------------------------------------------------
	// Result storage
	// -------------------------------------------------------------------------

	/**
	 * Store the update result for display in admin notices.
	 *
	 * Success notices auto-clear after 7 days. Error notices persist until
	 * the next successful update clears them.
	 *
	 * @param bool   $success Whether the update succeeded.
	 * @param string $message Human-readable message.
	 * @param string $output  Raw command output (for debug).
	 */
	private static function store_result( bool $success, string $message, string $output ): void {
		$result = [
			'success'   => $success,
			'message'   => $message,
			'output'    => $output,
			'timestamp' => time(),
			'version'   => self::get_daemon_version(),
		];

		update_option( self::OPTION_RESULT, $result, false );
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	/**
	 * Display admin notices for daemon update results.
	 */
	public function admin_notice(): void {
		$result = get_option( self::OPTION_RESULT );
		if ( empty( $result ) || ! is_array( $result ) ) {
			return;
		}

		// Success notices auto-clear after 7 days.
		if ( ! empty( $result['success'] ) && isset( $result['timestamp'] ) ) {
			if ( ( time() - $result['timestamp'] ) > 7 * DAY_IN_SECONDS ) {
				delete_option( self::OPTION_RESULT );
				return;
			}
		}

		// Error notices persist — clear only on next successful update.
		if ( ! empty( $result['success'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Metamanager:', 'metamanager' ),
				esc_html( $result['message'] )
			);
			delete_option( self::OPTION_RESULT );
		} else {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p>%s</p></div>',
				esc_html__( 'Metamanager:', 'metamanager' ),
				esc_html( $result['message'] ),
				esc_html__( 'Check /var/log/metamanager-update.log for details.', 'metamanager' )
			);
			// Do NOT delete — error persists until resolved.
		}

		// Debug output when WP_DEBUG is enabled.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $result['output'] ) ) {
			printf(
				'<div class="notice notice-info"><p><strong>%s</strong></p><pre>%s</pre></div>',
				esc_html__( 'Daemon update debug output:', 'metamanager' ),
				esc_textarea( $result['output'] )
			);
		}
	}
}
