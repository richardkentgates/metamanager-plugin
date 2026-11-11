<?php
/**
 * Unit tests for the Site Assistant API (AI phase A4): propose/settle
 * approval-card flow, rate caps, and the REST endpoints.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_AI_Rest_Unit extends WP_UnitTestCase {

	/** @var AI_Chat */
	private $chat;

	public function set_up(): void {
		parent::set_up();
		AI_Chat::create_tables();
		$this->chat = new AI_Chat();
	}

	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	private function card_model(): callable {
		return function () {
			return array(
				'reply' => 'I can do that.',
				'calls' => array(
					array( 'ability' => 'content/list-posts', 'input' => array() ),
					array( 'ability' => 'content/create-draft', 'input' => array( 'title' => 'From card' ) ),
				),
			);
		};
	}

	// ------------------------------------------------------------------
	// propose / settle — the approval-card flow
	// ------------------------------------------------------------------

	public function test_propose_runs_reads_and_returns_pending_cards_for_writes(): void {
		$user_id = $this->admin_user();
		self::factory()->post->create( array( 'post_title' => 'Visible' ) );
		$sid = $this->chat->open_session( $user_id );

		$result = $this->chat->propose( $sid, 'admin', 'list and draft', $this->card_model() );

		$this->assertSame( 'I can do that.', $result['reply'] );
		$this->assertSame( 'executed', $result['calls'][0]['status'] );
		$this->assertSame( 'Visible', $result['calls'][0]['result']['posts'][0]['title'] );
		$this->assertSame( 'pending', $result['calls'][1]['status'] );
		$this->assertSame( 'write', $result['calls'][1]['tier'] );
	}

	public function test_settle_approve_executes_and_audits(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		$result = $this->chat->propose( $sid, 'admin', 'draft', $this->card_model() );
		$pending_index = 1;

		$settled = $this->chat->settle( $sid, 'admin', $pending_index, true );
		$this->assertSame( 'executed', $settled['status'] );
		$this->assertSame( 'draft', $settled['result']['status'] );

		$audit = $this->chat->get_audit( $sid );
		$abilities = wp_list_pluck( $audit, 'ability' );
		$this->assertContains( 'content/create-draft', $abilities );
		$turns = $this->chat->get_turns( $sid );
		$this->assertSame( 'executed', $turns[0]['calls'][ $pending_index ]['status'] );
	}

	public function test_settle_decline_is_audited_and_never_runs(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		$this->chat->propose( $sid, 'admin', 'draft', $this->card_model() );

		$settled = $this->chat->settle( $sid, 'admin', 1, false );
		$this->assertSame( 'declined', $settled['status'] );

		$audit = $this->chat->get_audit( $sid );
		$outcomes = wp_list_pluck( $audit, 'outcome' );
		$this->assertContains( 'declined', $outcomes );
		// The draft must NOT exist — nothing ran.
		$this->assertNull( get_page_by_path( 'from-card', OBJECT, 'post' ) );
	}

	public function test_settle_destructive_requires_typed_confirmation(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		$post_id = self::factory()->post->create();
		$model = function () use ( $post_id ) {
			return array(
				'reply' => 'Trashing.',
				'calls' => array( array( 'ability' => 'content/trash', 'input' => array( 'post_id' => $post_id ) ) ),
			);
		};
		$this->chat->propose( $sid, 'admin', 'trash', $model );

		$blocked = $this->chat->settle( $sid, 'admin', 0, true, false );
		$this->assertInstanceOf( 'WP_Error', $blocked );
		$this->assertSame( 'typed_confirmation_required', $blocked->get_error_code() );

		$ok = $this->chat->settle( $sid, 'admin', 0, true, true );
		$this->assertSame( 'executed', $ok['status'] );
	}

	public function test_settle_with_edited_input_uses_the_edits(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		$this->chat->propose( $sid, 'admin', 'draft', $this->card_model() );

		$settled = $this->chat->settle( $sid, 'admin', 1, true, false,
			array( 'title' => 'Edited by operator' ) );
		$this->assertSame( 'executed', $settled['status'] );
		$post = get_post( $settled['result']['id'] );
		$this->assertSame( 'Edited by operator', $post->post_title );
	}

	public function test_settle_rejects_non_pending_calls(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		$result = $this->chat->propose( $sid, 'admin', 'list', $this->card_model() );
		// index 0 is a read — already executed, not a card.
		$blocked = $this->chat->settle( $sid, 'admin', 0, true );
		$this->assertInstanceOf( 'WP_Error', $blocked );
		$this->assertSame( 'not_pending', $blocked->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Rate caps (roadmap §8)
	// ------------------------------------------------------------------

	public function test_session_rate_cap_blocks(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		for ( $i = 0; $i < AI_Chat::RATE_LIMIT_SESSION; $i++ ) {
			$this->chat->append_turn( $sid, array( 'prompt' => 'x', 'reply' => 'y', 'calls' => array() ) );
		}
		$blocked = $this->chat->propose( $sid, 'admin', 'one more', $this->card_model() );
		$this->assertInstanceOf( 'WP_Error', $blocked );
		$this->assertSame( 'rate_limited', $blocked->get_error_code() );
	}

	public function test_daily_rate_cap_blocks(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		$day_key = 'ai_chat_rate_' . md5( 'admin' ) . '_' . gmdate( 'Y-m-d' );
		update_option( $day_key, AI_Chat::RATE_LIMIT_DAILY, false );
		$blocked = $this->chat->propose( $sid, 'admin', 'one more', $this->card_model() );
		$this->assertInstanceOf( 'WP_Error', $blocked );
		$this->assertSame( 'rate_limited', $blocked->get_error_code() );
	}

	// ------------------------------------------------------------------
	// REST surface
	// ------------------------------------------------------------------

	public function test_rest_chat_creates_session_and_returns_turn_shape(): void {
		$user_id = $this->admin_user();
		$request = new WP_REST_Request( 'POST', '/metamanager/v1/ai/chat' );
		$request->set_param( 'message', 'Hello assistant' );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'session_id', $data );
		$this->assertArrayHasKey( 'reply', $data );
		$this->assertArrayHasKey( 'calls', $data );
	}

	public function test_rest_endpoints_require_admin(): void {
		wp_set_current_user( 0 );
		foreach ( array(
			array( 'POST', '/metamanager/v1/ai/chat', array( 'message' => 'hi' ) ),
			array( 'POST', '/metamanager/v1/ai/approve', array(
				'session_id' => 1, 'call_index' => 0, 'approved' => true ) ),
			array( 'GET', '/metamanager/v1/ai/session', array( 'session_id' => 1 ) ),
		) as $route ) {
			$request = new WP_REST_Request( $route[0], $route[1] );
			foreach ( $route[2] as $k => $v ) {
				$request->set_param( $k, $v );
			}
			$response = rest_do_request( $request );
			$this->assertSame( 401, $response->get_status(), $route[1] );
		}
	}

	public function test_rest_session_returns_turns_and_audit(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		$this->chat->propose( $sid, 'admin', 'draft', $this->card_model() );

		$request = new WP_REST_Request( 'GET', '/metamanager/v1/ai/session' );
		$request->set_param( 'session_id', $sid );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data['turns'] );
		$this->assertSame( 'pending', $data['turns'][0]['calls'][1]['status'] );
	}
}
