<?php
/**
 * Unit tests for content/* and comments/* abilities (AI phase A3).
 *
 * Acceptance (the phase gate): a draft and a scheduled post created via
 * ability calls are real WordPress posts, visible in wp-admin.
 * Plus: the registration contract (A0 shape), native-codex-wrap
 * behavior for every executor, and the per-target permission tiers.
 *
 * @package Metamanager\Tests\Unit
 */

class Test_MM_Content_Abilities_Unit extends WP_UnitTestCase {

	/** @var MM_Abilities */
	private $mm;

	public function set_up(): void {
		parent::set_up();
		$this->mm = new MM_Abilities();
	}

	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	// ------------------------------------------------------------------
	// Registration contract
	// ------------------------------------------------------------------

	public function test_content_and_comment_abilities_register_with_callbacks(): void {
		$this->admin_user();
		$names = array(
			'content/list-posts', 'content/get-post', 'content/create-draft',
			'content/update-post', 'content/schedule-publish', 'content/publish',
			'content/unpublish', 'content/trash', 'content/restore',
			'content/list-media', 'content/upload-media',
			'content/set-featured-image', 'content/list-terms', 'content/assign-term',
			'comments/list', 'comments/moderate',
		);
		foreach ( $names as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "$name must be registered" );
			$this->assertSame( $name, $ability->get_name() );
		}
		// trash is the destructive tier — the chat keys typed confirmation
		// on this flag.
		$trash = wp_get_ability( 'content/trash' );
		$this->assertTrue( (bool) $trash->get_meta_item( 'destructive' ) );
	}

	// ------------------------------------------------------------------
	// Acceptance: draft + schedule via abilities, visible in wp-admin
	// ------------------------------------------------------------------

	public function test_draft_and_schedule_visible_in_wp_admin(): void {
		$this->admin_user();

		$draft = $this->mm->ability_create_draft( array(
			'title'   => 'Draft via ability',
			'content' => '<p>Body text.</p>',
		) );
		$this->assertIsArray( $draft );
		$this->assertSame( 'draft', $draft['status'] );

		$scheduled = $this->mm->ability_schedule_publish( array(
			'post_id'   => $draft['id'],
			'post_date' => '2027-01-15 09:30:00',
		) );
		$this->assertIsArray( $scheduled );
		$this->assertSame( 'future', $scheduled['status'] );

		// The same visibility wp-admin has: real posts in the database.
		$draft_post = get_post( $draft['id'] );
		$this->assertNotNull( $draft_post );
		$this->assertSame( 'Draft via ability', $draft_post->post_title );
		$this->assertSame( 'future', $draft_post->post_status );
		$this->assertSame( '2027-01-15 09:30:00', $draft_post->post_date );
		$this->assertSame( '<p>Body text.</p>', $draft_post->post_content );
	}

	// ------------------------------------------------------------------
	// Reads
	// ------------------------------------------------------------------

	public function test_get_post_reads_full_post(): void {
		$this->admin_user();
		$id = self::factory()->post->create( array(
			'post_title'   => 'Read me',
			'post_content' => 'Full content',
			'post_status'  => 'publish',
		) );
		$result = $this->mm->ability_get_post( array( 'post_id' => $id ) );
		$this->assertSame( 'Read me', $result['title'] );
		$this->assertSame( 'Full content', $result['content'] );
		$this->assertSame( 'publish', $result['status'] );
	}

	public function test_get_post_missing_is_error(): void {
		$this->admin_user();
		$result = $this->mm->ability_get_post( array( 'post_id' => 999999 ) );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_list_posts_filters_and_counts(): void {
		$this->admin_user();
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_status' => 'draft', 'post_title' => 'Hidden' ) );

		$result = $this->mm->ability_list_posts( array( 'number' => 2 ) );
		$this->assertCount( 2, $result['posts'] );
		$this->assertSame( 3, $result['total'] );

		$drafts = $this->mm->ability_list_posts( array( 'post_status' => 'draft' ) );
		$this->assertSame( 'Hidden', $drafts['posts'][0]['title'] );
	}

	// ------------------------------------------------------------------
	// Writes on existing posts
	// ------------------------------------------------------------------

	public function test_update_post_changes_only_provided_fields(): void {
		$this->admin_user();
		$id = self::factory()->post->create( array(
			'post_title'   => 'Before',
			'post_content' => 'Keep me',
			'post_excerpt' => 'Keep me too',
		) );
		$result = $this->mm->ability_update_post( array(
			'post_id' => $id,
			'title'   => 'After',
		) );
		$this->assertSame( $id, $result['id'] );
		$post = get_post( $id );
		$this->assertSame( 'After', $post->post_title );
		$this->assertSame( 'Keep me', $post->post_content );
		$this->assertSame( 'Keep me too', $post->post_excerpt );
	}

	public function test_publish_and_unpublish_flip_status(): void {
		$this->admin_user();
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$pub = $this->mm->ability_publish( array( 'post_id' => $id ) );
		$this->assertSame( 'publish', $pub['status'] );
		$this->assertSame( 'publish', get_post_status( $id ) );

		$unpub = $this->mm->ability_unpublish( array( 'post_id' => $id ) );
		$this->assertSame( 'draft', $unpub['status'] );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_schedule_publish_validates_date(): void {
		$this->admin_user();
		$id = self::factory()->post->create();
		$result = $this->mm->ability_schedule_publish( array(
			'post_id'   => $id,
			'post_date' => 'next tuesday',
		) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_date', $result->get_error_code() );
	}

	public function test_trash_and_restore(): void {
		$this->admin_user();
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$trashed = $this->mm->ability_trash( array( 'post_id' => $id ) );
		$this->assertSame( 'trash', $trashed['status'] );
		$this->assertSame( 'trash', get_post_status( $id ) );

		$restored = $this->mm->ability_restore( array( 'post_id' => $id ) );
		$this->assertNotSame( 'trash', $restored['status'] );
		$this->assertNotNull( get_post( $id ) );
	}

	// ------------------------------------------------------------------
	// Permission tiers
	// ------------------------------------------------------------------

	public function test_write_permissions_are_per_target(): void {
		$author_a = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_b = self::factory()->user->create( array( 'role' => 'author' ) );
		$other    = self::factory()->post->create( array( 'post_author' => $author_b ) );

		// Author A cannot edit author B's post.
		wp_set_current_user( $author_a );
		$this->assertInstanceOf( 'WP_Error',
			$this->mm->can_edit_post( array( 'post_id' => $other ) ) );

		// Author A can edit their own.
		$own = self::factory()->post->create( array( 'post_author' => $author_a ) );
		$this->assertTrue( $this->mm->can_edit_post( array( 'post_id' => $own ) ) );

		// Author cannot publish (no publish_posts for others... authors CAN
		// publish their own — use a contributor for the negative).
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $contributor );
		$this->assertInstanceOf( 'WP_Error',
			$this->mm->can_publish_post( array( 'post_id' => $own ) ) );

		// Anonymous gets errors everywhere.
		wp_set_current_user( 0 );
		$this->assertInstanceOf( 'WP_Error', $this->mm->can_use() );
		$this->assertInstanceOf( 'WP_Error', $this->mm->can_trash_post( array( 'post_id' => $own ) ) );
	}

	// ------------------------------------------------------------------
	// Media, terms, comments
	// ------------------------------------------------------------------

	public function test_list_and_set_featured_image(): void {
		$this->admin_user();
		$media_id = self::factory()->attachment->create_object(
			'image.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$post_id  = self::factory()->post->create();

		$media = $this->mm->ability_list_media();
		$found = wp_list_pluck( $media['media'], 'id' );
		$this->assertContains( $media_id, $found );

		$result = $this->mm->ability_set_featured_image( array(
			'post_id'  => $post_id,
			'media_id' => $media_id,
		) );
		$this->assertSame( $media_id, $result['media_id'] );
		$this->assertSame( $media_id, (int) get_post_thumbnail_id( $post_id ) );
	}

	public function test_list_and_assign_terms(): void {
		$this->admin_user();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'AI Term' ) );
		$post_id = self::factory()->post->create();

		$terms = $this->mm->ability_list_terms( array( 'taxonomy' => 'category' ) );
		$found = wp_list_pluck( $terms['terms'], 'id' );
		$this->assertContains( $term_id, $found );

		$result = $this->mm->ability_assign_term( array(
			'post_id'  => $post_id,
			'taxonomy' => 'category',
			'term_ids' => array( $term_id ),
		) );
		$this->assertSame( array( $term_id ), $result['term_ids'] );
		$this->assertTrue( has_term( $term_id, 'category', $post_id ) );
	}

	public function test_comments_list_and_moderate(): void {
		$this->admin_user();
		$post_id = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array(
			'comment_post_ID'  => $post_id,
			'comment_approved' => 0,
		) );

		$list = $this->mm->ability_list_comments( array( 'post_id' => $post_id ) );
		$this->assertCount( 1, $list['comments'] );
		$this->assertSame( 'unapproved', $list['comments'][0]['status'] );

		$mod = $this->mm->ability_moderate_comment( array(
			'comment_id' => $comment_id,
			'status'     => 'approve',
		) );
		$this->assertSame( 'approve', $mod['status'] );
		$comment = get_comment( $comment_id );
		$this->assertSame( '1', $comment->comment_approved );
	}

	public function test_comment_moderate_validates_status(): void {
		$this->admin_user();
		$comment_id = self::factory()->comment->create();
		$result = $this->mm->ability_moderate_comment( array(
			'comment_id' => $comment_id,
			'status'     => 'obliterate',
		) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_status', $result->get_error_code() );
	}
}
