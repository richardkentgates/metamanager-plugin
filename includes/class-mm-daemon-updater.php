<?php
/**
 * Metamanager Daemon Version Detector
 *
 * Reads the installed daemon VERSION file and the compatibility map
 * bundled with the plugin to determine version state. Daemon updates
 * are handled by the shell-based self-updater — this class is for
 * display and diagnostic purposes only.
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

	/**
	 * Boot the updater (no hooks needed — display only).
	 */
	public static function init(): void {
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

		// Case 6: Version mismatch — daemon is behind required, update needed.
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
}
