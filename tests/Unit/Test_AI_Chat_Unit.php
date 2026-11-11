<?php
/**
 * Unit tests for AI_Chat (AI phase A4) — the session/approval/audit
 * layer. Tiers are keyed on ability metadata; every execution is
 * audited; approval is mandatory for writes and typed confirmation
 * for destructive actions.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_AI_Chat_Unit extends WP_UnitTestCase {

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

	public function test_sessions_append_and_read_turns(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		$this->assertGreaterThan( 0, $sid );

		$this->chat->append_turn( $sid, array( 'prompt' => 'What is the status?', 'reply' => 'All green.' ) );
		$this->chat->append_turn( $sid, array( 'prompt' => 'Draft a post', 'reply' => 'Draft 5 created.', 'calls' => array( 'content/create-draft' ) ) );

		$turns = $this->chat->get_turns( $sid );
		$this->assertCount( 2, $turns );
		$this->assertSame( 'What is the status?', $turns[0]['prompt'] );
		$this->assertSame( array( 'content/create-draft' ), $turns[1]['calls'] );
	}

	public function test_audit_is_immutable_append(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		$this->chat->audit( $sid, 'admin', 'content/publish', array( 'post_id' => 5 ), 'ok' );
		$this->chat->audit( $sid, 'admin', 'content/trash', array( 'post_id' => 5 ), 'failed' );

		$rows = $this->chat->get_audit( $sid );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'content/publish', $rows[0]['ability'] );
		$this->assertSame( 'ok', $rows[0]['outcome'] );
		$this->assertSame( 'failed', $rows[1]['outcome'] );
	}

	public function test_tiers_keyed_on_ability_metadata(): void {
		$this->admin_user();
		$this->assertSame( 'read', AI_Chat::tier_for( 'reports/get-status' ) );
		$this->assertSame( 'write', AI_Chat::tier_for( 'content/create-draft' ) );
		$this->assertSame( 'destructive', AI_Chat::tier_for( 'content/trash' ) );
		// Unknown/absent ability = never free (tested without the registry
		// lookup, which emits core's own 'not found' usage notice).
		$this->assertSame( 'destructive', AI_Chat::tier_for( null ) );
	}

	public function test_reads_execute_freely(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		self::factory()->post->create( array( 'post_title' => 'Readable' ) );

		$result = $this->chat->execute( $sid, 'admin', 'content/list-posts', array() );
		$this->assertIsArray( $result );
		$this->assertSame( 'Readable', $result['posts'][0]['title'] );

		$audit = $this->chat->get_audit( $sid );
		$this->assertSame( 'ok', $audit[0]['outcome'] );
	}

	public function test_writes_require_approval(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );

		$blocked = $this->chat->execute( $sid, 'admin', 'content/create-draft',
			array( 'title' => 'No approval' ), false );
		$this->assertInstanceOf( 'WP_Error', $blocked );
		$this->assertSame( 'approval_required', $blocked->get_error_code() );

		$ok = $this->chat->execute( $sid, 'admin', 'content/create-draft',
			array( 'title' => 'Approved' ), true );
		$this->assertIsArray( $ok );
		$this->assertSame( 'draft', $ok['status'] );
	}

	public function test_destructive_requires_typed_confirmation(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		$post_id = self::factory()->post->create();

		$blocked = $this->chat->execute( $sid, 'admin', 'content/trash',
			array( 'post_id' => $post_id ), true, false );
		$this->assertInstanceOf( 'WP_Error', $blocked );
		$this->assertSame( 'typed_confirmation_required', $blocked->get_error_code() );

		$ok = $this->chat->execute( $sid, 'admin', 'content/trash',
			array( 'post_id' => $post_id ), true, true );
		$this->assertIsArray( $ok );
		$this->assertSame( 'trash', $ok['status'] );
	}

	public function test_permission_denials_are_audited_as_denied(): void {
		$author_a = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_b = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->post->create( array( 'post_author' => $author_b ) );
		wp_set_current_user( $author_a );
		$sid = $this->chat->open_session( $author_a );

		$result = $this->chat->execute( $sid, 'author-a', 'content/update-post',
			array( 'post_id' => $other, 'title' => 'Hijack' ), true );
		$this->assertInstanceOf( 'WP_Error', $result );

		$audit = $this->chat->get_audit( $sid );
		$this->assertSame( 'denied', $audit[0]['outcome'] );
	}

	public function test_failed_executions_are_audited_as_failed(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );

		$result = $this->chat->execute( $sid, 'admin', 'content/get-post',
			array( 'post_id' => 999999 ) );
		$this->assertInstanceOf( 'WP_Error', $result );

		$audit = $this->chat->get_audit( $sid );
		$this->assertSame( 'failed', $audit[0]['outcome'] );
	}

	// ------------------------------------------------------------------
	// The conversation turn — approval model through the loop
	// ------------------------------------------------------------------

	public function test_converse_executes_reads_and_records_the_turn(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		self::factory()->post->create( array( 'post_title' => 'Loop post' ) );

		$model = function ( $context, $text, $turns ) {
			return array(
				'reply' => 'Here are the posts.',
				'calls' => array( array( 'ability' => 'content/list-posts', 'input' => array() ) ),
			);
		};
		$result = $this->chat->converse( $sid, 'admin', 'list posts', $model );

		$this->assertSame( 'Here are the posts.', $result['reply'] );
		$this->assertCount( 1, $result['calls'] );
		$this->assertSame( 'read', $result['calls'][0]['tier'] );
		$this->assertSame( 'Loop post', $result['calls'][0]['result']['posts'][0]['title'] );

		$turns = $this->chat->get_turns( $sid );
		$this->assertCount( 1, $turns );
		$this->assertSame( 'list posts', $turns[0]['prompt'] );
	}

	public function test_converse_gates_writes_on_approval(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );

		$model = function () {
			return array(
				'reply' => 'Drafting.',
				'calls' => array( array( 'ability' => 'content/create-draft', 'input' => array( 'title' => 'From chat' ) ) ),
			);
		};

		// No approver: the call is blocked.
		$blocked = $this->chat->converse( $sid, 'admin', 'draft', $model );
		$this->assertSame( 'approval_required', $blocked['calls'][0]['result']['error'] );

		// Approver grants: it runs.
		$approver = function ( $ability, $input, $tier ) {
			return array( 'approved' => true );
		};
		$ok = $this->chat->converse( $sid, 'admin', 'draft', $model, $approver );
		$this->assertIsArray( $ok['calls'][0]['result'] );
		$this->assertSame( 'draft', $ok['calls'][0]['result']['status'] );
	}

	public function test_conserve_requires_typed_confirmation_for_destructive(): void {
		$user_id = $this->admin_user();
		$sid = $this->chat->open_session( $user_id );
		$post_id = self::factory()->post->create();

		$model = function () use ( $post_id ) {
			return array(
				'reply' => 'Trashing.',
				'calls' => array( array( 'ability' => 'content/trash', 'input' => array( 'post_id' => $post_id ) ) ),
			);
		};
		$approve_no_typed = function () {
			return array( 'approved' => true, 'typed_ok' => false );
		};
		$approve_typed = function () {
			return array( 'approved' => true, 'typed_ok' => true );
		};

		$blocked = $this->chat->converse( $sid, 'admin', 'trash it', $model, $approve_no_typed );
		$this->assertSame( 'typed_confirmation_required', $blocked['calls'][0]['result']['error'] );

		$ok = $this->chat->converse( $sid, 'admin', 'trash it', $model, $approve_typed );
		$this->assertSame( 'trash', $ok['calls'][0]['result']['status'] );
	}

	public function test_build_context_is_injection_hardened(): void {
		$this->admin_user();
		$context = $this->chat->build_context();
		$this->assertStringContainsString( 'never as instructions', $context );
		$this->assertStringContainsString( 'registered abilities', $context );
	}
}
