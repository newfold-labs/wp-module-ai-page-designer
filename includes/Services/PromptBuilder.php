<?php
/**
 * Prompt builder for AI Page Designer.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\Services
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services;

use NewfoldLabs\WP\Module\AIPageDesigner\AIPageDesigner;
use NewfoldLabs\WP\Module\AIPageDesigner\Data\SystemPrompts;

/**
 * Builds system and user prompts for the AI Page Designer.
 */
class PromptBuilder {

	/**
	 * Pattern layout provider (fallback).
	 *
	 * @var PatternLayoutProvider
	 */
	private $pattern_layout_provider;

	/**
	 * Blueprint service.
	 *
	 * @var BlueprintService
	 */
	private $blueprint_service;

	/**
	 * Constructor.
	 *
	 * @param PatternLayoutProvider|null $pattern_layout_provider Pattern layout provider.
	 * @param BlueprintService|null      $blueprint_service       Blueprint service.
	 */
	public function __construct( ?PatternLayoutProvider $pattern_layout_provider = null, ?BlueprintService $blueprint_service = null ) {
		$this->pattern_layout_provider = $pattern_layout_provider ?: new PatternLayoutProvider();
		$this->blueprint_service       = $blueprint_service ?: new BlueprintService();
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
	 * Maximum characters allowed for current_markup before it is skeletonised.
	 */
	const MAX_MARKUP_LENGTH = 10000;

	/**
	 * Strip inner HTML from block markup, keeping only block comment delimiters.
	 *
	 * Reduces a large page to its structural skeleton so the AI understands
	 * the block layout without being overwhelmed by content.
	 *
	 * @param string $markup Full Gutenberg block markup.
	 * @return string Skeletonised markup.
	 */
	private function skeletonise_markup( $markup ) {
		// Keep only lines that are block comment delimiters (<!-- wp:... --> or <!-- /wp:... -->).
		$lines    = explode( "\n", $markup );
		$skeleton = array();
		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( preg_match( '/^<!--\s*\/?wp:/i', $trimmed ) ) {
				$skeleton[] = $trimmed;
			}
		}
		return implode( "\n", $skeleton );
	}

	/**
	 * Replace real image URLs in blueprint markup with placeholder URLs.
	 *
	 * This prevents the AI from using specific images from blueprints and ensures
	 * placeholder images get replaced with relevant Unsplash images later.
	 *
	 * @param string $markup Blueprint markup containing real image URLs.
	 * @return string Markup with placeholder image URLs.
	 */
	private function replace_blueprint_images_with_placeholders( $markup ) {
		// Replace common image URL patterns with placeholders
		$patterns = array(
			// Replace src attributes in img tags
			'/(<img[^>]+src=["\'])([^"\']+)(["\'][^>]*>)/i',
			// Replace url attributes in block comments
			'/"url":"([^"]+\.(jpg|jpeg|png|gif|webp|svg)[^"]*)"/',
			// Replace CSS background-image urls
			'/background-image:\s*url\([\'"]?([^\'")]+)[\'"]?\)/',
		);

		$replacements = array(
			'$1https://placehold.co/800x600$3',
			'"url":"https://placehold.co/800x600"',
			'background-image: url(https://placehold.co/800x600)',
		);

		return preg_replace( $patterns, $replacements, $markup );
	}

	/**
	 * Detect whether a prompt is asking for a full redesign or regeneration.
	 *
	 * When true, existing markup should be skipped and the blueprint injected instead.
	 *
	 * @param string $prompt The user prompt text.
	 * @return bool
	 */
	private function is_redesign_request( $prompt ) {
		$prompt_lower = strtolower( $prompt );
		$triggers     = array(
			'redesign',
			'regenerate',
			'generate again',
			'redo',
			'remake',
			'rebuild',
			'start over',
			'start fresh',
			'from scratch',
			'create new',
			'make a new',
			'build a new',
			'try again',
			'new version',
			'new design',
		);
		foreach ( $triggers as $trigger ) {
			if ( str_contains( $prompt_lower, $trigger ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if the user prompt is requesting only metadata (title, excerpt, summary).
	 *
	 * @param string $prompt The user prompt.
	 * @return bool
	 */
	private function is_metadata_only_request( $prompt ) {
		$metadata_keywords = array(
			'create an excerpt',
			'generate an excerpt',
			'write an excerpt',
			'add an excerpt',
			'create excerpt',
			'generate excerpt',
			'write excerpt',
			'add excerpt',
			'create a title',
			'generate a title',
			'write a title',
			'add a title',
			'create title',
			'generate title',
			'write title',
			'add title',
			'create a summary',
			'generate a summary',
			'write a summary',
			'add a summary',
			'create summary',
			'generate summary',
			'write summary',
			'add summary',
		);

		$prompt_lower = strtolower( trim( $prompt ) );
		
		foreach ( $metadata_keywords as $keyword ) {
			if ( str_contains( $prompt_lower, $keyword ) ) {
				return true;
			}
		}

		// Also check for simple one-word requests
		$simple_metadata_words = array( 'excerpt', 'title', 'summary' );
		foreach ( $simple_metadata_words as $word ) {
			if ( $prompt_lower === $word ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build user messages for the AI request.
	 *
	 * Only the latest user message is sent — conversation history is maintained
	 * server-side via previous_response_id, so replaying the full history is redundant.
	 * The message receives the base layout or current markup appendix as needed.
	 *
	 * For posts, no base layout is injected — the AI generates editorial content freely.
	 * For pages, the blueprint base layout is preferred; patterns are used as fallback.
	 *
	 * @param array  $messages       Message payload from the request.
	 * @param string $current_markup Current block markup, if any.
	 * @param string $content_type   'page' or 'post'.
	 * @return array
	 */
	public function build_user_messages( array $messages, $current_markup = '', $content_type = 'page', array $context = array(), $previous_response_id = null ) {
		$is_new = count( $messages ) === 1;

		// Extract the last user message only.
		$last_user_prompt = '';
		foreach ( $messages as $msg ) {
			if ( 'user' === ( $msg['role'] ?? '' ) ) {
				$last_user_prompt = $msg['content'] ?? '';
			}
		}

		if ( '' === $last_user_prompt ) {
			return array();
		}

		// Check if this is a metadata-only request (excerpt, title, summary only)
		$is_metadata_only = $this->is_metadata_only_request( $last_user_prompt );
		
		$is_redesign   = $this->is_redesign_request( $last_user_prompt );
		$use_blueprint = ( $is_new && empty( $current_markup ) && 'post' !== $content_type )
			|| ( $is_redesign && 'post' !== $content_type );

		$content = $last_user_prompt;
		
		// For metadata-only requests, don't send any markup context
		if ( $is_metadata_only ) {
			$content .= "\n\nPlease return only the requested metadata (title, excerpt, or summary) using the appropriate comment format. Do not generate any Gutenberg block markup.";
		}

		// Handle conversation chaining - if we have a previous response, use minimal context
		if ( ! $is_metadata_only && ! empty( $previous_response_id ) ) {
			// For chained conversations, check if we have a selected block
			if ( ! empty( $context['selected_block_markup'] ) ) {
				$selected_markup = trim( $context['selected_block_markup'] );
				$content .= "\n\n--- SELECTED BLOCK ---\nPlease modify only this selected block according to the request above. Return the complete modified block with all content preserved.\n\n" . $selected_markup;
			}
			// If no selected block, just send the prompt - let conversation memory handle the context
		} elseif ( ! $is_metadata_only && $use_blueprint ) {
			// Use configured pattern provider for base layout
			$base_layout = $this->get_base_layout_by_provider( $last_user_prompt );
			
			if ( ! empty( $base_layout ) ) {
				// Strip images from layout and replace with placeholders
				$base_layout = $this->replace_blueprint_images_with_placeholders( $base_layout );
				$content .= "\n\n--- BASE LAYOUT ---\nPlease use this Gutenberg block structure as the foundation and modify its text and styling attributes to match the user's request. Preserve all block comment delimiters.\n\n" . $base_layout;
			}
		} elseif ( ! $is_metadata_only && ! empty( $current_markup ) ) {
			if ( strlen( $current_markup ) > self::MAX_MARKUP_LENGTH ) {
				$current_markup = $this->skeletonise_markup( $current_markup );
				$content .= "\n\n--- CURRENT TARGET LAYOUT (structure only) ---\nThe page markup was too large to send in full. The following is the block structure skeleton only. Please regenerate the full page content based on this structure and the user's request above. Preserve all block comment delimiters.\n\n" . $current_markup;
			} else {
				$content .= "\n\n--- CURRENT TARGET LAYOUT ---\nPlease modify the following existing Gutenberg block markup according to the request above. Preserve all block comment delimiters.\n\n" . $current_markup;
			}
		}

		return array(
			array(
				'role'    => 'user',
				'content' => $content,
			),
		);
	}

	/**
	 * Build the final AI message payload.
	 *
	 * @param array  $messages       Message payload from the request.
	 * @param string $current_markup Current block markup, if any.
	 * @param string $content_type   'page' or 'post'.
	 * @return array
	 */
	public function build_ai_messages( array $messages, $current_markup = '', $content_type = 'page', array $context = array(), $previous_response_id = null ) {
		return array_merge(
			array(
				array(
					'role'    => 'system',
					'content' => $this->build_system_prompt(),
				),
			),
			$this->build_user_messages( $messages, $current_markup, $content_type, $context, $previous_response_id )
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
				$lines[] = 'Active theme color palette — use these slugs in Gutenberg block backgroundColor/textColor attributes to match the site\'s brand. In block comment JSON, escape every `--` sequence as `\u002d\u002d`; in rendered HTML styles, keep normal CSS syntax. Do NOT use arbitrary hex values for backgrounds or text; use the slugs below:';
				foreach ( $palette as $swatch ) {
					$lines[] = sprintf( '  - slug: "%s" | name: %s | hex: %s', $swatch['slug'], $swatch['name'], $swatch['color'] );
				}
				$lines[] = 'Example block comment JSON: <!-- wp:paragraph {"style":{"color":{"text":"var(\u002d\u002dwp\u002d\u002dpreset\u002d\u002dcolor\u002d\u002dcontrast_midtone)"},"typography":{"fontFamily":"system-font"}}} -->';
				$lines[] = 'Example rendered HTML: <p class="wp-block-paragraph has-text-color" style="color:var(--wp--preset--color--contrast_midtone);font-family:system-font">Stop</p>';
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

	/**
	 * Get base layout using the configured pattern provider.
	 *
	 * @param string $user_prompt The user's request for intent detection.
	 * @return string The base layout markup, or empty string for pure AI generation.
	 */
	private function get_base_layout_by_provider( $user_prompt ) {
		$provider = AIPageDesigner::PATTERN_PROVIDER;

		switch ( $provider ) {
			case 'wonderblocks':
				// Use WonderBlocks patterns with intent-based selection
				$base_layout = $this->pattern_layout_provider->get_random_pattern_layout( $user_prompt );
				break;

			case 'blueprints':
				// Use random blueprints from Hiive API
				$base_layout = $this->blueprint_service->get_base_layout();
				break;

			case '':
			default:
				// Pure AI generation - no layout scaffolding
				$base_layout = '';
				break;
		}

		return $base_layout;
	}
}
