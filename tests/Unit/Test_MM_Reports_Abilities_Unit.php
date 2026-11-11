<?php
/**
 * Unit tests for the reports/* abilities (AI phase A1).
 *
 * Read-only status reports. Contract under test:
 * - the five abilities register with execute_callback + permission_callback
 *   (the A0 regression shape)
 * - each report reads exactly the file its dashboard widget reads
 * - missing or corrupt reports are explicit errors — absence of evidence
 *   is never reported as a state
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Reports_Abilities_Unit extends WP_UnitTestCase {

	/** @var array<string,string> */
	private $fixtures = array();

	public function set_up(): void {
		parent::set_up();
		$this->fixtures = array();
		add_filter( 'mm_report_path', array( $this, 'filter_report_path' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'mm_report_path', array( $this, 'filter_report_path' ), 10 );
		foreach ( $this->fixtures as $path ) {
			if ( file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $path );
			}
		}
		parent::tear_down();
	}

	/**
	 * Route one report kind to a per-test fixture file.
	 */
	public function filter_report_path( $path, $which ) {
		return isset( $this->fixtures[ $which ] ) ? $this->fixtures[ $which ] : $path;
	}

	private function fixture( string $which, string $contents ): string {
		$path = wp_tempnam( "mm-report-$which" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $contents );
		$this->fixtures[ $which ] = $path;
		return $path;
	}

	// ------------------------------------------------------------------
	// Registration contract (the A0 regression shape)
	// ------------------------------------------------------------------

	public function test_report_abilities_register_with_callbacks(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API requires WordPress 6.9+.' );
		}
		// The plugin registers at file load (bootstrap) — the test
		// asserts that surface, exactly as the MCP adapter sees it.
		$expected = array(
			'reports/get-status',
			'reports/get-os-update',
			'reports/get-security-scan',
			'reports/get-daemon-status',
			'reports/get-mail-status',
		);
		// First touch lazily creates the registry and fires
		// wp_abilities_api_init, where registration must happen. A
		// non-null ability proves BOTH callbacks were present and
		// callable — WP_Ability's constructor rejects anything else
		// (the A0 regression: passing 'callback' silently returned null).
		foreach ( $expected as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "$name must be registered" );
			$this->assertSame( $name, $ability->get_name() );
		}

		// Read abilities are free to execute for editorial users and
		// denied to anonymous callers.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$ability = wp_get_ability( 'reports/get-status' );
		$this->assertTrue( $ability->check_permissions() );
		wp_set_current_user( 0 );
		$this->assertInstanceOf( 'WP_Error', $ability->check_permissions() );
	}

	// ------------------------------------------------------------------
	// Acceptance harness: "what's the server status" from status.json
	// ------------------------------------------------------------------

	public function test_get_status_answers_from_status_json(): void {
		$status = array(
			'gcm_version'       => '2.3.325',
			'gcm_branch'        => 'test',
			'wp_version'        => '6.9.1',
			'ts'                => 1762800000,
			'busy'              => array( 'backup' => false, 'maint' => true, 'update' => false, 'scan' => false, 'restore' => false ),
			'backup'            => array( 'date' => '2026-11-11 07:00:29', 'size' => '2G', 'archive_valid' => true, 'sql_ok' => true, 'meta' => null ),
			'os_update'         => array( 'ts' => 1762790000, 'result' => 'ok', 'packages_upgraded' => 3, 'reboot_required' => true ),
			'wordpress_updates' => array( 'core' => 0, 'plugins' => 2, 'themes' => 0 ),
			'ssl'               => array( 'cert_days_left' => 83 ),
			'site_integrity'    => array( 'homepage_code' => 200 ),
			'sources'           => array( 'apt' => array( 'ok' => true ) ),
		);
		$this->fixture( 'gcm_status', wp_json_encode( $status ) );

		$result = ( new MM_Abilities() )->ability_get_status();
		$this->assertIsArray( $result );
		$this->assertSame( '2.3.325', $result['gcm_version'] );
		$this->assertSame( 'test', $result['gcm_branch'] );
		$this->assertFalse( $result['busy']['backup'] );
		$this->assertSame( 'ok', $result['os_update']['result'] );
		$this->assertTrue( $result['sources']['apt']['ok'] );
	}

	public function test_get_os_update_reads_the_block(): void {
		$this->fixture( 'gcm_status', wp_json_encode( array(
			'gcm_version' => '2.3.325',
			'os_update'   => array( 'ts' => 5, 'result' => 'ok', 'packages_upgraded' => 3, 'reboot_required' => true ),
		) ) );
		$result = ( new MM_Abilities() )->ability_get_os_update();
		$this->assertSame( 3, $result['os_update']['packages_upgraded'] );
		$this->assertTrue( $result['os_update']['reboot_required'] );
	}

	public function test_get_os_update_missing_block_is_error(): void {
		$this->fixture( 'gcm_status', wp_json_encode( array( 'gcm_version' => '2.3.325' ) ) );
		$result = ( new MM_Abilities() )->ability_get_os_update();
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'report_missing_field', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Writer contracts (daemon + mail status files)
	// ------------------------------------------------------------------

	public function test_get_daemon_status_reads_writer_contract(): void {
		$data = array(
			'ts'             => '2026-11-11T12:00:00Z',
			'plugin_version' => '2.3.198',
			'daemon_version' => '2.4.86',
			'daemons'        => array(
				'compress' => array( 'running' => true, 'pid' => 123 ),
				'meta'     => array( 'running' => false, 'pid' => null ),
			),
			'queues'         => array( 'compress' => 2, 'meta' => 0, 'completed' => 9, 'failed' => 1 ),
			'tools'          => array( 'exiftool' => true, 'jpegtran' => true, 'optipng' => true, 'cwebp' => true, 'ffmpeg' => true ),
			'cron'           => array(),
		);
		$this->fixture( 'daemon', wp_json_encode( $data ) );
		$result = ( new MM_Abilities() )->ability_get_daemon_status();
		$this->assertSame( '2.4.86', $result['daemon_version'] );
		$this->assertTrue( $result['daemons']['compress']['running'] );
		$this->assertSame( 1, $result['queues']['failed'] );
	}

	public function test_get_mail_status_reads_writer_contract(): void {
		$data = array(
			'pending'    => 4,
			'sent'       => 40,
			'failed'     => 2,
			'cron'       => array( 'last_run' => '2026-11-11 11:59:00', 'next_scheduled' => '2026-11-11 12:00:00', 'interval' => 60 ),
			'history'    => array(),
			'version'    => '1.4.99',
			'updated_at' => '2026-11-11 12:00:00',
		);
		$this->fixture( 'mail', wp_json_encode( $data ) );
		$result = ( new MM_Abilities() )->ability_get_mail_status();
		$this->assertSame( 4, $result['pending'] );
		$this->assertSame( 2, $result['failed'] );
		$this->assertSame( '1.4.99', $result['version'] );
		$this->assertSame( 60, $result['cron']['interval'] );
	}

	public function test_get_security_scan_report(): void {
		$this->fixture( 'security_scan', wp_json_encode( array(
			'last_ts' => 1762790000,
			'ok'      => true,
			'findings' => 0,
		) ) );
		$result = ( new MM_Abilities() )->ability_get_security_scan();
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0, $result['findings'] );
	}

	// ------------------------------------------------------------------
	// Error semantics — absence of evidence is never a state
	// ------------------------------------------------------------------

	public function test_missing_report_is_an_explicit_error(): void {
		$this->fixtures['gcm_status'] = '/nonexistent/status.json';
		$result = ( new MM_Abilities() )->ability_get_status();
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'report_unavailable', $result->get_error_code() );
	}

	public function test_corrupt_report_is_an_explicit_error(): void {
		$this->fixture( 'mail', '{not json' );
		$result = ( new MM_Abilities() )->ability_get_mail_status();
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'report_unreadable', $result->get_error_code() );
	}
}
