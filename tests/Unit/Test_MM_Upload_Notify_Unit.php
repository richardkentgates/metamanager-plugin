<?php
/**
 * Unit tests for MM_Upload_Notify batching and preference logic.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Upload_Notify_Unit extends WP_UnitTestCase {

	// ------------------------------------------------------------------
	// user_wants_receipt()
	// ------------------------------------------------------------------

	public function test_user_wants_receipt_defaults_true(): void {
		$user_id = $this->factory->user->create();
		// No meta set — should default to true.
		$this->assertTrue( MM_Upload_Notify::user_wants_receipt( $user_id ) );
	}

	public function test_user_wants_receipt_opt_out(): void {
		$user_id = $this->factory->user->create();
		update_user_meta( $user_id, 'mm_upload_receipt', '0' );
		$this->assertFalse( MM_Upload_Notify::user_wants_receipt( $user_id ) );
	}

	public function test_user_wants_receipt_opt_in(): void {
		$user_id = $this->factory->user->create();
		update_user_meta( $user_id, 'mm_upload_receipt', '1' );
		$this->assertTrue( MM_Upload_Notify::user_wants_receipt( $user_id ) );
	}

	// ------------------------------------------------------------------
	// get_failed_notices()
	// ------------------------------------------------------------------

	public function test_get_failed_notices_empty_by_default(): void {
		delete_option( 'mm_failed_upload_notices' );
		$notices = MM_Upload_Notify::get_failed_notices();
		$this->assertSame( [], $notices );
	}

	public function test_get_failed_notices_returns_array_for_non_array_option(): void {
		update_option( 'mm_failed_upload_notices', 'not_an_array' );
		$notices = MM_Upload_Notify::get_failed_notices();
		$this->assertSame( [], $notices );
	}

	// ------------------------------------------------------------------
	// Constants
	// ------------------------------------------------------------------

	public function test_batch_delay_constant(): void {
		$this->assertSame( 60, MM_Upload_Notify::BATCH_DELAY );
	}

	public function test_batch_transient_constant(): void {
		$this->assertSame( 'mm_upload_batch', MM_Upload_Notify::BATCH_TRANSIENT );
	}

	public function test_cron_event_constant(): void {
		$this->assertSame( 'mm_send_upload_receipt', MM_Upload_Notify::CRON_EVENT );
	}

	public function test_failed_option_constant(): void {
		$this->assertSame( 'mm_failed_upload_notices', MM_Upload_Notify::FAILED_OPTION );
	}
}
