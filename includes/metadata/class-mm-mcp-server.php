<?php
/**
 * MM_MCP_Server — registers a custom MCP server that exposes
 * Metamanager abilities as tools for AI agents.
 *
 * Requires the WordPress MCP Adapter plugin (https://github.com/WordPress/mcp-adapter).
 * Gracefully degrades if the adapter is not active.
 */

defined( 'ABSPATH' ) || exit;

class MM_MCP_Server {

	private const ABILITIES = [
		'metamanager/get-post-meta',
		'metamanager/update-post-meta',
		'metamanager/get-schema',
		'metamanager/get-business-profile',
		'metamanager/get-navigation',
		'metamanager/get-term-meta',
	];

	public function register_hooks(): void {
		// Only hook if MCP Adapter is available.
		if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
			return;
		}

		add_action( 'mcp_adapter_init', [ $this, 'create_server' ] );
	}

	/**
	 * Create the Metamanager MCP server via the adapter.
	 *
	 * @param object $adapter The MCP adapter instance.
	 */
	public function create_server( $adapter ): void {
		if ( ! is_a( $adapter, 'WP\MCP\Core\McpAdapter' ) ) {
			return;
		}

		// Check for required transport and error handler classes.
		if ( ! class_exists( 'WP\MCP\Transport\HttpTransport' ) ||
			! class_exists( 'WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler' ) ) {
			return;
		}

		$adapter->create_server(
			'metamanager-server',
			'metamanager-mcp',
			'mcp',
			'Metamanager',
			'SEO metadata, schema, and business info for this WordPress site.',
			'1.0.0',
			[ \WP\MCP\Transport\HttpTransport::class ],
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			self::ABILITIES
		);
	}
}
