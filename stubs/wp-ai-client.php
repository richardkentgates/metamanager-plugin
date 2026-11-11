<?php
/**
 * Stub: the WordPress 7.0 AI client (wp_supports_ai, wp_ai_client_prompt,
 * WP_AI_Client_Prompt_Builder). Absent from the WP stubs package (older
 * WP baselines) — declared here for static analysis only.
 */

function wp_supports_ai(): bool {
}

class WP_AI_Client_Ability_Function_Resolver {
	public static function function_name_to_ability_name( string $function_name ): string {
	}
}

function wp_ai_client_prompt( $prompt = null ): WP_AI_Client_Prompt_Builder {
}

class WP_AI_Client_Prompt_Builder {
	public function using_abilities( ...$abilities ): WP_AI_Client_Prompt_Builder {
	}

	public function with_text( $text ): WP_AI_Client_Prompt_Builder {
	}

	public function is_supported_for_text_generation(): bool {
	}

	public function generate_text_result( ...$args ) {
	}
}
