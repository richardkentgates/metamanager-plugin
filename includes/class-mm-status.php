<?php
/**
 * Metamanager Status Class
 *
 * Checks the health of all external dependencies:
 * - ExifTool availability and path
 * - jpegtran / optipng availability (lossless compression tools)
 * - Daemon liveness via PID files (no systemctl needed)
 * - Per-image compression status
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Status
 */
class MM_Status {

	// -----------------------------------------------------------------------
	// Daemon package detection
	// -----------------------------------------------------------------------

	/**
	 * Candidate paths for daemon scripts installed by the .deb package.
	 */
	private const DAEMON_PATHS = [
		'/usr/local/bin/metamanager-compress-daemon.sh',
		'/usr/local/bin/metamanager-meta-daemon.sh',
	];

	/**
	 * Check whether the daemon package is installed.
	 *
	 * Looks for the daemon scripts that are installed by the metamanager .deb package.
	 * This is the primary check used to determine if the plugin can function.
	 *
	 * @return bool
	 */
	public static function daemon_package_installed(): bool {
		foreach ( self::DAEMON_PATHS as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return true;
			}
		}
		return false;
	}

	// -----------------------------------------------------------------------
	// Tool detection
	// -----------------------------------------------------------------------

	/**
	 * Candidate paths for ExifTool.
	 */
	private const EXIFTOOL_PATHS = [
		'/usr/bin/exiftool',
		'/usr/local/bin/exiftool',
		'/opt/homebrew/bin/exiftool',
	];

	/**
	 * Candidate paths for jpegtran (libjpeg / libjpeg-turbo).
	 */
	private const JPEGTRAN_PATHS = [
		'/usr/bin/jpegtran',
		'/usr/local/bin/jpegtran',
		'/opt/homebrew/bin/jpegtran',
	];

	/**
	 * Candidate paths for optipng.
	 */
	private const OPTIPNG_PATHS = [
		'/usr/bin/optipng',
		'/usr/local/bin/optipng',
		'/opt/homebrew/bin/optipng',
	];

	/**
	 * Candidate paths for cwebp (WebP lossless compression).
	 */
	private const CWEBP_PATHS = [
		'/usr/bin/cwebp',
		'/usr/local/bin/cwebp',
		'/opt/homebrew/bin/cwebp',
	];

	/**
	 * Candidate paths for ffmpeg (video container remux).
	 */
	private const FFMPEG_PATHS = [
		'/usr/bin/ffmpeg',
		'/usr/local/bin/ffmpeg',
		'/opt/homebrew/bin/ffmpeg',
	];

	/**
	 * Check whether ExifTool is installed and executable.
	 */
	public static function exiftool_available(): bool {
		return (bool) self::exiftool_path();
	}

	/**
	 * Return the first found ExifTool executable path, or empty string.
	 */
	private static function exiftool_path(): string {
		foreach ( self::EXIFTOOL_PATHS as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Check whether jpegtran is available.
	 */
	public static function jpegtran_available(): bool {
		return (bool) self::jpegtran_path();
	}

	/**
	 * Return the first found jpegtran executable path.
	 */
	private static function jpegtran_path(): string {
		foreach ( self::JPEGTRAN_PATHS as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Check whether optipng is available.
	 */
	public static function optipng_available(): bool {
		return (bool) self::optipng_path();
	}

	/**
	 * Return the first found optipng executable path.
	 */
	private static function optipng_path(): string {
		foreach ( self::OPTIPNG_PATHS as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Check whether cwebp is available.
	 */
	public static function cwebp_available(): bool {
		return (bool) self::cwebp_path();
	}

	/**
	 * Return the first found cwebp executable path.
	 */
	private static function cwebp_path(): string {
		foreach ( self::CWEBP_PATHS as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Check whether ffmpeg is available.
	 */
	public static function ffmpeg_available(): bool {
		return (bool) self::ffmpeg_path();
	}

	/**
	 * Return the first found ffmpeg executable path.
	 */
	private static function ffmpeg_path(): string {
		foreach ( self::FFMPEG_PATHS as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	// -----------------------------------------------------------------------
	// Daemon liveness
	//
	// The daemons write their PID to wp-content/metamanager-jobs/ on startup.
	// PHP reads the PID file and confirms the process is still running via
	// /proc. This works for www-data without systemctl privileges.
	// -----------------------------------------------------------------------

	/**
	 * Check whether the compression daemon process is alive.
	 */
	public static function compress_daemon_running(): bool {
		return self::is_pid_alive( MM_PID_COMPRESS );
	}

	/**
	 * Check whether the metadata daemon process is alive.
	 */
	public static function meta_daemon_running(): bool {
		return self::is_pid_alive( MM_PID_META );
	}

	/**
	 * Read a PID file and check /proc/<pid> to confirm the process is alive.
	 *
	 * @param string $pid_file Absolute path to the PID file.
	 * @return bool
	 */
	private static function is_pid_alive( string $pid_file ): bool {
		if ( ! file_exists( $pid_file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pid = (int) trim( file_get_contents( $pid_file ) );
		if ( $pid < 2 ) {
			return false;
		}

		// /proc/<pid> exists as a directory while the process is running.
		return is_dir( '/proc/' . $pid );
	}

	// -----------------------------------------------------------------------
	// Per-image compression status
	// -----------------------------------------------------------------------

	/**
	 * Check whether a specific image size has been compressed.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size          Size slug.
	 * @return bool
	 */
	public static function is_compressed( int $attachment_id, string $size ): bool {
		return MM_DB::has_completed_compression( $attachment_id, $size );
	}

	/**
	 * Return a status summary for an attachment: all, partial, none, or na.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{ status: string, label: string, color: string }
	 */
	public static function compression_status( int $attachment_id ): array {
		$mime     = (string) get_post_mime_type( $attachment_id );
		$is_image = wp_attachment_is_image( $attachment_id );
		$is_video = MM_Metadata::is_video_mime( $mime );

		// Audio and PDF are not compressed by Metamanager daemons.
		if ( ! $is_image && ! $is_video ) {
			return [ 'status' => 'na', 'label' => '—', 'color' => '#bbb' ];
		}

		// Videos only have a single 'full' size (ffmpeg remux).
		if ( $is_video ) {
			$file = get_attached_file( $attachment_id );
			if ( ! $file || ! file_exists( $file ) ) {
				return [ 'status' => 'na', 'label' => '—', 'color' => '#bbb' ];
			}
			if ( self::is_compressed( $attachment_id, 'full' ) ) {
				return [ 'status' => 'all', 'label' => '✔ Compressed', 'color' => '#13bb2c' ];
			}
			return [ 'status' => 'none', 'label' => '✘ Not Compressed', 'color' => '#e54c3c' ];
		}

		$meta = wp_get_attachment_metadata( $attachment_id ) ?: [];
		$file = get_attached_file( $attachment_id );

		$all_compressed = true;
		$any_compressed = false;

		if ( $file && file_exists( $file ) ) {
			if ( self::is_compressed( $attachment_id, 'full' ) ) {
				$any_compressed = true;
			} else {
				$all_compressed = false;
			}
		}

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = trailingslashit( pathinfo( $file, PATHINFO_DIRNAME ) );
			foreach ( $meta['sizes'] as $size => $info ) {
				if ( empty( $info['file'] ) || ! file_exists( $dir . $info['file'] ) ) {
					continue;
				}
				if ( self::is_compressed( $attachment_id, $size ) ) {
					$any_compressed = true;
				} else {
					$all_compressed = false;
				}
			}
		}

		if ( $all_compressed && $any_compressed ) {
			return [ 'status' => 'all', 'label' => '✔ Compressed', 'color' => '#13bb2c' ];
		}
		if ( $any_compressed ) {
			return [ 'status' => 'partial', 'label' => '● Partial', 'color' => '#e6b800' ];
		}
		return [ 'status' => 'none', 'label' => '✘ Not Compressed', 'color' => '#e54c3c' ];
	}

	// -----------------------------------------------------------------------
	// Full system status snapshot (used by status banner)
	// -----------------------------------------------------------------------

	/**
	 * Return a summary of all system dependencies.
	 *
	 * @return array<string, bool>
	 */
	public static function system_status(): array {
		return [
			'daemon_package'    => self::daemon_package_installed(),
			'exiftool'         => self::exiftool_available(),
			'jpegtran'         => self::jpegtran_available(),
			'optipng'          => self::optipng_available(),
			'cwebp'            => self::cwebp_available(),
			'ffmpeg'           => self::ffmpeg_available(),
			'compress_daemon'  => self::compress_daemon_running(),
			'meta_daemon'      => self::meta_daemon_running(),
		];
	}

	/**
	 * Write comprehensive status JSON to metamanager-status.json.
	 *
	 * Single source of truth for all MetaManager status: daemon liveness,
	 * tool availability, version info, queue depth. Read by GCM dashboard.
	 *
	 * @return void
	 */
	public static function write_status_json(): void {
		$job_root = defined( 'MM_JOB_ROOT' ) ? MM_JOB_ROOT : WP_CONTENT_DIR . '/metamanager-jobs';
		$status_file = $job_root . '/metamanager-status.json';

		$system = self::system_status();

		// Queue depth from job directories.
		$compress_queue = 0;
		$meta_queue     = 0;
		$completed      = 0;
		$failed         = 0;
		foreach ( [ 'compress' => 'compress_queue', 'meta' => 'meta_queue', 'completed' => 'completed', 'failed' => 'failed' ] as $dir => $key ) {
			$path = $job_root . '/' . $dir;
			if ( is_dir( $path ) ) {
				$$key = count( glob( $path . '/*.json' ) ) + count( glob( $path . '/*.json.processing' ) );
			}
		}

		// Daemon PIDs.
		$compress_pid = null;
		$meta_pid     = null;
		if ( file_exists( MM_PID_COMPRESS ) ) {
			$compress_pid = (int) trim( file_get_contents( MM_PID_COMPRESS ) );
		}
		if ( file_exists( MM_PID_META ) ) {
			$meta_pid = (int) trim( file_get_contents( MM_PID_META ) );
		}

		// Version info.
		$installed_ver = MM_Daemon_Updater::get_daemon_version();
		$plugin_ver    = defined( 'MM_VERSION' ) ? MM_VERSION : 'unknown';

		$data = array(
			'ts'              => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'plugin_version'  => $plugin_ver,
			'daemon_version'  => $installed_ver,
			'daemons'         => array(
				'compress' => array(
					'running' => $system['compress_daemon'],
					'pid'     => $compress_pid,
				),
				'meta'     => array(
					'running' => $system['meta_daemon'],
					'pid'     => $meta_pid,
				),
			),
			'queues'          => array(
				'compress'  => $compress_queue,
				'meta'      => $meta_queue,
				'completed' => $completed,
				'failed'    => $failed,
			),
			'tools'           => array(
				'exiftool'  => $system['exiftool'],
				'jpegtran'  => $system['jpegtran'],
				'optipng'   => $system['optipng'],
				'cwebp'     => $system['cwebp'],
				'ffmpeg'    => $system['ffmpeg'],
			),
			'cron'            => MM_Cron_Tracker::get_all(),
		);

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT );
		if ( false === $json ) {
			return;
		}

		$tmp = $status_file . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $tmp, $json );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_function
		@rename( $tmp, $status_file );
		@chmod( $status_file, 0644 );
	}
}
