<?php
/**
 * Metamanager Memory Manager
 *
 * Calculates available memory, sizes batches accordingly, and manages
 * the admin notice when memory is exhausted.
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
	 * Minimum free memory (bytes) to reserve for PHP operational overhead.
	 * If free memory drops below this, batch processing pauses.
	 */
	const OPERATIONAL_BUFFER = 128 * 1024 * 1024; // 128 MB.

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
	 * @return int Free bytes after operational buffer.
	 */
	public static function get_available_memory(): int {
		$limit = self::get_memory_limit();
		$used  = memory_get_usage( true );
		$free  = $limit - $used - self::OPERATIONAL_BUFFER;
		return max( 0, $free );
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
		$value = (int) $limit;
		$suffix = strtolower( substr( trim( $limit ), -1 ) );
		return match ( $suffix ) {
			'g' => $value * 1024 * 1024 * 1024,
			'm' => $value * 1024 * 1024,
			'k' => $value * 1024,
			default => $value,
		};
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

		// Memory is critically low — pause processing.
		if ( $available < self::OPERATIONAL_BUFFER ) {
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

			// Leave at least OPERATIONAL_BUFFER for remaining PHP execution.
			if ( $remaining - $cost < self::OPERATIONAL_BUFFER ) {
				break;
			}

			$remaining -= $cost;
			++$count;
		}

		return $count;
	}

	/**
	 * Check if batch processing should pause due to memory limits.
	 *
	 * @return bool True if memory is too low to process any jobs.
	 */
	public static function should_pause(): bool {
		return self::get_available_memory() < self::OPERATIONAL_BUFFER;
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
		$limit = size_format( self::get_memory_limit() );
		$used  = size_format( memory_get_usage( true ) );
		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Metamanager:', 'metamanager' ),
			sprintf(
				/* translators: 1: used memory, 2: memory limit */
				esc_html__( 'Batch processing paused — memory limit reached (%1$s / %2$s). The queue will resume automatically when memory is available.', 'metamanager' ),
				esc_html( $used ),
				esc_html( $limit )
			)
		);
	}
}
