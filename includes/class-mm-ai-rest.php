<?php
/**
 * MM_AI_Rest — the Site Assistant API (AI phase A4).
 *
 * Endpoints (namespace metamanager/v1):
 *   POST /ai/chat     {session_id?, message}      -> propose turn (reads run;
 *                        writes/destructive come back as pending cards)
 *   POST /ai/approve  {session_id, call_index, approved,
 *                      typed_confirmation?, input?} -> settle one card
 *   GET  /ai/session  {session_id}                -> turns + audit trail
 *
 * Admin-only (manage_options): the chat is operator tooling. Every
 * execution is audited by AI_Chat; rate caps apply per session and day.
 */

defined( 'ABSPATH' ) || exit;

class MM_AI_Rest {

	public static function register_routes(): void {
		$auth = static function (): bool {
			return is_user_logged_in() && current_user_can( 'manage_options' );
		};

		register_rest_route(
			'metamanager/v1',
			'/ai/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_chat' ),
				'permission_callback' => $auth,
				'args'                => array(
					'message'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'session_id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'metamanager/v1',
			'/ai/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_approve' ),
				'permission_callback' => $auth,
				'args'                => array(
					'session_id'          => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'call_index'          => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'approved'            => array( 'required' => true ),
					'typed_confirmation'  => array(),
					'input'               => array(),
				),
			)
		);

		register_rest_route(
			'metamanager/v1',
			'/ai/session',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_session' ),
				'permission_callback' => $auth,
				'args'                => array(
					'session_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/**
	 * POST /ai/chat — one proposal turn.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_chat( WP_REST_Request $request ) {
		$chat  = new AI_Chat();
		$sid   = (int) $request->get_param( 'session_id' );
		$user  = wp_get_current_user();
		if ( $sid <= 0 ) {
			$sid = $chat->open_session( (int) $user->ID );
		}
		$result = $chat->propose( $sid, (string) $user->user_login, (string) $request->get_param( 'message' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * POST /ai/approve — settle one pending card.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_approve( WP_REST_Request $request ) {
		$chat   = new AI_Chat();
		$user   = wp_get_current_user();
		$input  = $request->get_param( 'input' );
		$result = $chat->settle(
			(int) $request->get_param( 'session_id' ),
			(string) $user->user_login,
			(int) $request->get_param( 'call_index' ),
			(bool) $request->get_param( 'approved' ),
			(bool) $request->get_param( 'typed_confirmation' ),
			is_array( $input ) ? $input : null
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * GET /ai/session — the conversation + its audit trail.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_session( WP_REST_Request $request ) {
		$chat = new AI_Chat();
		$sid  = (int) $request->get_param( 'session_id' );
		return rest_ensure_response( array(
			'session_id' => $sid,
			'turns'      => $chat->get_turns( $sid ),
			'audit'      => $chat->get_audit( $sid ),
		) );
	}
}
