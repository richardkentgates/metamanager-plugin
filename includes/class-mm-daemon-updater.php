<?php
/**
 * Metamanager Daemon Version Detection
 *
 * Reads the installed daemon version from the VERSION file on disk.
 * Daemon updates are handled by the OS package manager (apt), not the plugin.
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
}
