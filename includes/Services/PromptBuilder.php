<?php
/**
 * Prompt builder for AI Page Designer.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\Services
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services;

use NewfoldLabs\WP\Module\AIPageDesigner\Data\SystemPrompts;

/**
 * Builds system and user prompts for the AI Page Designer.
 */
class PromptBuilder {

	/**
	 * Pattern layout provider.
	 *
	 * @var PatternLayoutProvider
	 */
	private $pattern_layout_provider;

	/**
	 * Constructor.
	 *
	 * @param PatternLayoutProvider|null $pattern_layout_provider Pattern layout provider.
	 */
	public function __construct( ?PatternLayoutProvider $pattern_layout_provider = null ) {
		$this->pattern_layout_provider = $pattern_layout_provider ?: new PatternLayoutProvider();
	}

	/**
	 * Build the full system prompt, including the theme context appendix.
	 *
	 * @return string
	 */
	public function build_system_prompt() {
		return SystemPrompts::get_page_designer_prompt() . $this->get_theme_context_prompt();
	}

	/**
	 * Build user messages for the AI request.
	 *
	 * Only user messages are passed through; assistant/system messages are ignored.
	 * The last user message receives the base layout or current markup appendix.
	 *
	 * @param array  $messages Message payload from the request.
	 * @param string $current_markup Current block markup, if any.
	 * @return array
	 */
	public function build_user_messages( array $messages, $current_markup = '' ) {
		$user_messages = array();
		$is_new_page   = count( $messages ) === 1;

		$last_user_index = -1;
		foreach ( $messages as $index => $msg ) {
			if ( isset( $msg['role'] ) && 'user' === $msg['role'] ) {
				$last_user_index = $index;
			}
		}

		foreach ( $messages as $index => $msg ) {
			if ( 'user' !== ( $msg['role'] ?? '' ) ) {
				continue;
			}

			$content = $msg['content'] ?? '';
			if ( $index === $last_user_index ) {
				// Only inject the base layout if this is the first message and we don't already have current markup.
				if ( $is_new_page && empty( $current_markup ) ) {
					$base_layout = $this->pattern_layout_provider->get_random_pattern_layout( $content );
					if ( ! empty( $base_layout ) ) {
						$content .= "\n\n--- BASE LAYOUT ---\nPlease use this Gutenberg block structure as the foundation and modify its text and styling attributes to match the user's request. Preserve all block comment delimiters.\n\n" . $base_layout;
					}
				} elseif ( ! empty( $current_markup ) ) {
					// If we HAVE current markup (either a follow-up message, or a targeted block edit).
					$content .= "\n\n--- CURRENT TARGET LAYOUT ---\nPlease modify the following existing Gutenberg block markup according to the request above. Preserve all block comment delimiters.\n\n" . $current_markup;
				}
			}

			$user_messages[] = array(
				'role'    => 'user',
				'content' => $content,
			);
		}

		return $user_messages;
	}

	/**
	 * Build the final AI message payload.
	 *
	 * @param array  $messages Message payload from the request.
	 * @param string $current_markup Current block markup, if any.
	 * @return array
	 */
	public function build_ai_messages( array $messages, $current_markup = '' ) {
		return array_merge(
			array(
				array(
					'role'    => 'system',
					'content' => $this->build_system_prompt(),
				),
			),
			$this->build_user_messages( $messages, $current_markup )
		);
	}

	/**
	 * Build a theme context string from the active theme's color palette and typography.
	 *
	 * @return string
	 */
	private function get_theme_context_prompt() {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return '';
		}

		$settings = wp_get_global_settings();
		$lines    = array();

		// --- Color palette ---
		// Only use the theme-defined palette. Ignore the WordPress default palette.
		$theme_swatches = $settings['color']['palette']['theme'] ?? array();

		if ( ! empty( $theme_swatches ) ) {
			$palette = array();
			foreach ( $theme_swatches as $swatch ) {
				if ( isset( $swatch['slug'], $swatch['color'] ) ) {
					$palette[] = array(
						'slug'  => $swatch['slug'],
						'name'  => $swatch['name'] ?? $swatch['slug'],
						'color' => $swatch['color'],
					);
				}
			}

			if ( ! empty( $palette ) ) {
				$lines[] = '';
				$lines[] = 'Active theme color palette — use these slugs in Gutenberg block backgroundColor/textColor attributes to match the site\'s brand. Do NOT use arbitrary hex values for backgrounds or text; use the slugs below:';
				foreach ( $palette as $swatch ) {
					$lines[] = sprintf( '  - slug: "%s" | name: %s | hex: %s', $swatch['slug'], $swatch['name'], $swatch['color'] );
				}
				$lines[] = 'Example: <!-- wp:button {"backgroundColor":"primary","textColor":"base"} -->';
				$lines[] = 'Example: <!-- wp:group {"align":"full","style":{"color":{"background":"var(--wp--preset--color--contrast)"}}} -->';
			}
		}

		// --- Primary font family ---
		$font_families = $settings['typography']['fontFamilies']['theme'] ?? array();
		if ( ! empty( $font_families ) ) {
			$primary = reset( $font_families );
			if ( ! empty( $primary['fontFamily'] ) ) {
				$lines[] = '';
				$lines[] = sprintf(
					'Active theme font: %s — use this font family slug "%s" in typography block attributes where a custom font is needed.',
					$primary['fontFamily'],
					$primary['slug'] ?? 'primary'
				);
			}
		}

		// --- Site title for contextual copy ---
		$site_name = get_bloginfo( 'name' );
		if ( $site_name ) {
			$lines[] = '';
			$lines[] = sprintf( 'Site name: "%s" — you may reference this naturally in headings or CTAs where appropriate.', $site_name );
		}

		return implode( "\n", $lines );
	}
}
