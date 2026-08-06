<?php
/**
 * Metamanager Memory Manager
 *
 * Fluid memory management that scales to available resources.
 * Monitors both PHP and system-level memory, sizes batches dynamically,
 * and only pauses when the system is genuinely under memory pressure.
 *
 * Called on every batch processing cycle (WP-Cron tick, admin AJAX scan,
 * WP-CLI batch). The notice is dynamic — it appears only when a cycle
 * actually hits the memory limit and disappears when memory is available.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Memory_Manager
 */
class MM_Memory_Manager {

	/**
	 * Minimum floor for available memory (bytes).
	 * Even on unlimited PHP, always reserve this much headroom.
	 */
	const MIN_FLOOR = 8 * 1024 * 1024; // 8 MB.

	/**
	 * System memory pressure threshold.
	 * If free system memory drops below this fraction of total, pause.
	 * 0.10 = pause when less than 10% of system RAM is free.
	 */
	const SYSTEM_PRESSURE_RATIO = 0.10;

	/**
	 * Base memory cost per job (bytes).
	 * Covers PHP execution, JSON decode, WP_Filesystem overhead.
	 */
	const BASE_COST_PER_JOB = 2 * 1024 * 1024; // 2 MB.

	/**
	 * Per-megapixel memory estimate for image jobs (bytes).
	 * Conservative: covers jpegtran/optipng/cwebp working set.
	 */
	const COST_PER_MEGAPIXEL = 1 * 1024 * 1024; // 1 MB.

	/**
	 * Per-minute-of-video memory estimate for video jobs (bytes).
	 * Conservative: covers ffmpeg remux working set.
	 */
	const COST_PER_MINUTE_VIDEO = 5 * 1024 * 1024; // 5 MB.

	/**
	 * Option key for the memory notice flag.
	 */
	const NOTICE_OPTION = 'mm_memory_limit_notice';

	/**
	 * Get available memory for batch processing.
	 *
	 * Returns the lesser of PHP available memory and system available memory,
	 * minus a small floor. On systems with unlimited PHP, this is governed
	 * entirely by system RAM. On systems with a PHP memory_limit, it's the
	 * lesser of the two.
	 *
	 * @return int Available bytes for batch processing.
	 */
	public static function get_available_memory(): int {
		$php_free    = self::get_php_available();
		$system_free = self::get_system_available();

		// If either source returns 0 (unavailable), use the other.
		if ( 0 === $php_free ) {
			return max( 0, $system_free - self::MIN_FLOOR );
		}
		if ( 0 === $system_free ) {
			return max( 0, $php_free - self::MIN_FLOOR );
		}

		// Use whichever is more constrained.
		$available = min( $php_free, $system_free );
		return max( 0, $available - self::MIN_FLOOR );
	}

	/**
	 * Get available PHP memory.
	 *
	 * @return int Free bytes within PHP memory_limit, or 0 if unlimited.
	 */
	private static function get_php_available(): int {
		$limit = self::get_memory_limit();
		if ( PHP_INT_MAX === $limit ) {
			return 0; // Unlimited — don't constrain by PHP.
		}
		$used = memory_get_usage( true );
		return max( 0, $limit - $used );
	}

	/**
	 * Get available system memory from /proc/meminfo.
	 *
	 * @return int Free bytes, or 0 if unable to determine.
	 */
	private static function get_system_available(): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = @file_get_contents( '/proc/meminfo' );
		if ( false === $content ) {
			return 0;
		}

		$mem_free      = self::parse_meminfo( $content, 'MemFree' );
		$mem_available = self::parse_meminfo( $content, 'MemAvailable' );
		$swap_free     = self::parse_meminfo( $content, 'SwapFree' );

		// Prefer MemAvailable (includes reclaimable buffers/cache), fall back to MemFree.
		$free = $mem_available > 0 ? $mem_available : $mem_free;

		// Add available swap as a safety cushion — don't count it fully,
		// but use it as headroom before we consider the system pressured.
		$free += (int) ( $swap_free * 0.25 );

		return $free;
	}

	/**
	 * Parse a value from /proc/meminfo.
	 *
	 * @param string $content File contents.
	 * @param string $key     Field name (e.g., "MemFree").
	 * @return int Value in bytes, or 0 if not found.
	 */
	private static function parse_meminfo( string $content, string $key ): int {
		if ( preg_match( '/^' . preg_quote( $key, '/' ) . ':\s+(\d+)\s+kB$/m', $content, $m ) ) {
			return (int) $m[1] * 1024; // kB to bytes.
		}
		return 0;
	}

	/**
	 * Get the PHP memory limit as bytes.
	 *
	 * @return int Memory limit in bytes. Returns PHP_INT_MAX for "unlimited".
	 */
	public static function get_memory_limit(): int {
		$limit = ini_get( 'memory_limit' );
		if ( '-1' === $limit ) {
			return PHP_INT_MAX;
		}
		$value  = (int) $limit;
		$suffix = strtolower( substr( trim( $limit ), -1 ) );
		return match ( $suffix ) {
			'g' => $value * 1024 * 1024 * 1024,
			'm' => $value * 1024 * 1024,
			'k' => $value * 1024,
			default => $value,
		};
	}

	/**
	 * Get total system memory from /proc/meminfo.
	 *
	 * @return int Total RAM in bytes, or 0 if unknown.
	 */
	public static function get_system_total(): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = @file_get_contents( '/proc/meminfo' );
		if ( false === $content ) {
			return 0;
		}
		return self::parse_meminfo( $content, 'MemTotal' );
	}

	/**
	 * Check if the system is under memory pressure.
	 *
	 * Uses system-level memory (not just PHP) to determine pressure.
	 * Returns true only when the system is genuinely low on memory.
	 *
	 * @return bool True if memory pressure is detected.
	 */
	public static function should_pause(): bool {
		$system_total = self::get_system_total();
		$system_free  = self::get_system_available();

		// If we can't read system memory, fall back to PHP-only check.
		if ( 0 === $system_total ) {
			$php_limit = self::get_memory_limit();
			if ( PHP_INT_MAX === $php_limit ) {
				return false; // Unlimited PHP, no system data — don't pause.
			}
			$php_free = self::get_php_available();
			return $php_free < self::MIN_FLOOR;
		}

		// System under pressure: free memory below threshold.
		$pressure_threshold = (int) ( $system_total * self::SYSTEM_PRESSURE_RATIO );
		if ( $system_free < $pressure_threshold ) {
			return true;
		}

		// PHP hitting its limit (and it's not unlimited).
		$php_limit = self::get_memory_limit();
		if ( PHP_INT_MAX !== $php_limit ) {
			$php_free = self::get_php_available();
			if ( $php_free < self::MIN_FLOOR ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Estimate memory cost for a single job.
	 *
	 * Uses attachment metadata when available (megapixels for images,
	 * duration for video), otherwise falls back to conservative defaults.
	 *
	 * @param array $job Job data from the queue JSON.
	 * @return int Estimated memory cost in bytes.
	 */
	public static function estimate_job_cost( array $job ): int {
		$cost = self::BASE_COST_PER_JOB;

		$mime = (string) ( $job['mime_type'] ?? '' );
		$size = (string) ( $job['size'] ?? 'full' );

		// Only estimate extra cost for full-size processing.
		if ( 'full' !== $size ) {
			return $cost;
		}

		// Image: estimate from dimensions (megapixels).
		if ( str_starts_with( $mime, 'image/' ) ) {
			$width  = (int) ( $job['width'] ?? 0 );
			$height = (int) ( $job['height'] ?? 0 );
			if ( $width > 0 && $height > 0 ) {
				$megapixels = ( $width * $height ) / 1_000_000;
				$cost      += (int) ( $megapixels * self::COST_PER_MEGAPIXEL );
			} else {
				$cost += self::COST_PER_MEGAPIXEL * 4; // Assume 4 MP if unknown.
			}
			return $cost;
		}

		// Video: estimate from duration (minutes).
		if ( str_starts_with( $mime, 'video/' ) ) {
			$duration = (int) ( $job['duration'] ?? 0 ); // seconds.
			$minutes  = max( 1, $duration / 60 );
			$cost    += (int) ( $minutes * self::COST_PER_MINUTE_VIDEO );
			return $cost;
		}

		// Audio/PDF/other: small working set.
		$cost += 1 * 1024 * 1024; // 1 MB.
		return $cost;
	}

	/**
	 * Calculate the safe batch size given a list of jobs.
	 *
	 * Returns the number of jobs that can be processed without exceeding
	 * available memory. Returns 0 if memory is critically low.
	 *
	 * @param array[] $jobs Array of job data arrays.
	 * @param int     $max_configured Maximum batch size from admin setting.
	 * @return int Number of jobs to process (0 = pause).
	 */
	public static function calculate_batch_size( array $jobs, int $max_configured ): int {
		$available = self::get_available_memory();

		// Below the floor — pause.
		if ( $available < self::MIN_FLOOR ) {
			return 0;
		}

		$remaining = $available;
		$count     = 0;
		$max       = min( $max_configured, count( $jobs ) );

		foreach ( $jobs as $job ) {
			if ( $count >= $max ) {
				break;
			}

			$cost = self::estimate_job_cost( $job );

			// Leave the floor for remaining PHP execution.
			if ( $remaining - $cost < self::MIN_FLOOR ) {
				break;
			}

			$remaining -= $cost;
			++$count;
		}

		return $count;
	}

	/**
	 * Set the memory limit notice flag.
	 *
	 * Called when a batch cycle hits the memory limit. The notice
	 * persists until the next successful cycle clears it.
	 */
	public static function set_notice(): void {
		if ( ! get_option( self::NOTICE_OPTION ) ) {
			add_option( self::NOTICE_OPTION, time(), '', false );
		}
	}

	/**
	 * Clear the memory limit notice flag.
	 *
	 * Called when a batch cycle has sufficient memory to proceed.
	 */
	public static function clear_notice(): void {
		delete_option( self::NOTICE_OPTION );
	}

	/**
	 * Check if the memory limit notice is active.
	 *
	 * @return bool
	 */
	public static function has_notice(): bool {
		return (bool) get_option( self::NOTICE_OPTION );
	}

	/**
	 * Render the memory limit admin notice.
	 *
	 * Hooked to admin_notices. Only renders when the flag is set.
	 */
	public static function render_notice(): void {
		if ( ! self::has_notice() ) {
			return;
		}

		$system_total = self::get_system_total();
		$system_free  = self::get_system_available();
		$php_limit    = self::get_memory_limit();
		$php_used     = memory_get_usage( true );

		$parts = [];
		if ( $system_total > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: used system memory, 2: total system memory */
				esc_html__( 'System: %1$s / %2$s', 'metamanager' ),
				esc_html( size_format( $system_total - $system_free ) ),
				esc_html( size_format( $system_total ) )
			);
		}
		if ( PHP_INT_MAX !== $php_limit ) {
			$parts[] = sprintf(
				/* translators: 1: used PHP memory, 2: PHP memory limit */
				esc_html__( 'PHP: %1$s / %2$s', 'metamanager' ),
				esc_html( size_format( $php_used ) ),
				esc_html( size_format( $php_limit ) )
			);
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s %s</p></div>',
			esc_html__( 'Metamanager:', 'metamanager' ),
			esc_html__( 'Batch processing paused — system under memory pressure.', 'metamanager' ),
			implode( ' | ', $parts )
		);
	}
}
