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

	/** Log file path for daemon update operations. */
	private const UPDATE_LOG_FILE = '/var/log/metamanager-update.log';

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
	 * @return array{required: string|null, map_exists: bool, version_in_map: bool, raw_map: array}
	 */
	public static function get_required_daemon_version(): array {
		$map_file = MM_PLUGIN_DIR . self::COMPAT_MAP_FILE;

		if ( ! file_exists( $map_file ) ) {
			return [
				'required'       => null,
				'map_exists'     => false,
				'version_in_map' => false,
				'raw_map'        => [],
			];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = file_get_contents( $map_file );
		$map  = json_decode( $json, true );

		if ( ! is_array( $map ) ) {
			return [
				'required'       => null,
				'map_exists'     => true,
				'version_in_map' => false,
				'raw_map'        => [],
			];
		}

		$version_in_map = isset( $map[ MM_VERSION ] );

		return [
			'required'       => $map[ MM_VERSION ] ?? null,
			'map_exists'     => true,
			'version_in_map' => $version_in_map,
			'raw_map'        => $map,
		];
	}

	/**
	 * Diagnose the current version state.
	 *
	 * Returns a structured array describing exactly what is wrong (if anything),
	 * so callers can produce clear, specific error messages.
	 *
	 * @return array{status: string, message: string, current: string|null, required: string|null}
	 */
	public static function diagnose(): array {
		$current = self::get_daemon_version();
		$info    = self::get_required_daemon_version();

		// Case 1: VERSION file missing.
		if ( null === $current && ! file_exists( self::DAEMON_VERSION_FILE ) ) {
			return [
				'status'   => 'error',
				'message'  => sprintf(
					'Daemon VERSION file not found at %s. The daemon package may not be installed.',
					self::DAEMON_VERSION_FILE
				),
				'current'  => null,
				'required' => null,
			];
		}

		// Case 2: VERSION file exists but unreadable/empty.
		if ( null === $current ) {
			return [
				'status'   => 'error',
				'message'  => sprintf(
					'Daemon VERSION file exists at %s but is empty or unreadable.',
					self::DAEMON_VERSION_FILE
				),
				'current'  => null,
				'required' => null,
			];
		}

		// Case 3: Compatibility map file missing.
		if ( ! $info['map_exists'] ) {
			return [
				'status'   => 'error',
				'message'  => sprintf(
					'Compatibility map not found at %s. Cannot determine required daemon version for plugin v%s.',
					MM_PLUGIN_DIR . self::COMPAT_MAP_FILE,
					MM_VERSION
				),
				'current'  => $current,
				'required' => null,
			];
		}

		// Case 4: Plugin version not in compatibility map.
		if ( ! $info['version_in_map'] ) {
			return [
				'status'   => 'error',
				'message'  => sprintf(
					'Plugin v%s is not listed in daemon-compatibility.json. Add an entry mapping "%s" to the correct daemon version.',
					MM_VERSION,
					MM_VERSION
				),
				'current'  => $current,
				'required' => null,
			];
		}

		// Case 5: Versions match — everything is fine.
		if ( $current === $info['required'] ) {
			return [
				'status'   => 'ok',
				'message'  => sprintf( 'Daemon v%s is up to date.', $current ),
				'current'  => $current,
				'required' => $info['required'],
			];
		}

		// Case 6: Version mismatch — update needed.
		return [
			'status'   => 'mismatch',
			'message'  => sprintf(
				'Daemon version mismatch: installed v%s, required v%s.',
				$current,
				$info['required']
			),
			'current'  => $current,
			'required' => $info['required'],
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
			$message = 'PHP exec() is disabled. Cannot trigger daemon update automatically. Enable exec() or update the daemon manually via: sudo apt-get install -y metamanager';
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
			'update'           => 'sudo -n apt-get update -qq',
			'install'          => 'sudo -n apt-get install -y -qq metamanager',
			'restart-compress' => 'sudo -n systemctl restart metamanager-compress-daemon',
			'restart-meta'     => 'sudo -n systemctl restart metamanager-meta-daemon',
		];

		$output = '';

		foreach ( $commands as $step => $cmd ) {
			$step_out = '';
			$exit     = 0;
			$retries  = ( 'update' === $step ) ? 3 : 1;

			for ( $attempt = 1; $attempt <= $retries; $attempt++ ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_obfuscation
				@exec( $cmd . ' 2>&1', $step_out, $exit );
				$step_out = implode( "\n", (array) $step_out );
				$output  .= "[{$step}] attempt={$attempt}/{$retries} exit={$exit}\n{$step_out}\n\n";

				self::log_os( "daemon-update/{$step}: attempt={$attempt} exit={$exit} " . trim( $step_out ) );

				if ( 0 === $exit || $attempt === $retries ) {
					break;
				}

				// Wait 5 seconds before retrying (transient network issues).
				sleep( 5 );
				$step_out = '';
			}

			if ( $exit !== 0 ) {
				$message = sprintf(
					'Daemon update failed at step "%s" after %d attempt(s) (exit code %d). Check %s or WP debug log for details.',
					$step,
					$retries,
					$exit,
					self::UPDATE_LOG_FILE
				);
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
		$info        = self::get_required_daemon_version();
		$required    = $info['required'];

		if ( null === $new_version ) {
			$message = 'Daemon update completed but VERSION file is now unreadable. The daemon may not be installed correctly.';
			self::log_wordpress( $message, 'error' );
			self::store_result( false, $message, $output );

			return [
				'success' => false,
				'message' => $message,
				'output'  => $output,
			];
		}

		if ( $new_version !== $required ) {
			$message = sprintf(
				'Daemon update completed but version mismatch persists: installed v%s, required v%s. The apt repository may not have the required version yet.',
				$new_version,
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
	 * Called by MM_Updater::on_plugin_updated(). Diagnoses the version state
	 * and triggers daemon update only when there is a real version mismatch.
	 * Produces clear error messages for infrastructure problems (missing files,
	 * missing map entries) instead of running unnecessary apt upgrades.
	 *
	 * @return array{update_needed: bool, result: array|null}
	 */
	public static function handle_plugin_update(): array {
		$diagnosis = self::diagnose();

		// Versions match — clear any stale error and exit.
		if ( 'ok' === $diagnosis['status'] ) {
			$previous = get_option( self::OPTION_RESULT );
			if ( ! empty( $previous ) && empty( $previous['success'] ) ) {
				delete_option( self::OPTION_RESULT );
			}

			return [
				'update_needed' => false,
				'result'       => null,
			];
		}

		// Infrastructure error (missing VERSION file, missing map, version not in map).
		// Store the error but do NOT run apt — it won't help.
		if ( 'error' === $diagnosis['status'] ) {
			self::log_wordpress( $diagnosis['message'], 'error' );
			self::log_os( 'daemon-update/error: ' . $diagnosis['message'] );
			self::store_result( false, $diagnosis['message'], '' );

			return [
				'update_needed' => false,
				'result'       => [
					'success' => false,
					'message' => $diagnosis['message'],
					'output'  => '',
				],
			];
		}

		// Actual version mismatch — trigger the update.
		self::log_wordpress(
			sprintf(
				'Daemon version mismatch detected: installed=%s, required=%s. Triggering update.',
				$diagnosis['current'] ?? 'unknown',
				$diagnosis['required'] ?? 'unknown'
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
		$line = sprintf( "[%s] %s\n", gmdate( 'Y-m-d H:i:s' ), $message );

		// Try the primary log file first.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_error_log
		$wrote = @error_log( $line, 3, self::UPDATE_LOG_FILE );

		// Fallback: try WP_CONTENT_DIR if /var/log isn't writable.
		if ( ! $wrote && defined( 'WP_CONTENT_DIR' ) ) {
			$fallback = WP_CONTENT_DIR . '/metamanager-update.log';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_error_log
			$wrote = @error_log( $line, 3, $fallback );
		}

		// Final fallback: syslog.
		if ( ! $wrote && function_exists( 'openlog' ) ) {
			openlog( 'metamanager', LOG_PID, LOG_USER );
			syslog( LOG_INFO, $message );
			closelog();
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
			$log_hint = ! empty( $result['output'] )
				? sprintf(
					/* translators: 1: log file path */
					esc_html__( 'Check %1$s or WP debug log for details.', 'metamanager' ),
					self::UPDATE_LOG_FILE
				)
				: esc_html__( 'No update was attempted — fix the issue above and re-save to retry.', 'metamanager' );

			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p>%s</p></div>',
				esc_html__( 'Metamanager:', 'metamanager' ),
				esc_html( $result['message'] ),
				$log_hint
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
