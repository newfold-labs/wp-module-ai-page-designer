<?php
/**
 * Fast-path handling for AI Page Designer.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\Services
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services;

/**
 * Handles requests that can be resolved without calling the AI service.
 */
class FastPathHandler {

	/**
	 * Image service.
	 *
	 * @var ImageService
	 */
	private $image_service;

	/**
	 * AI client.
	 *
	 * @var AiClientWorker|null
	 */
	private $ai_client;

	/**
	 * Constructor.
	 *
	 * @param ImageService|null   $image_service Image service.
	 * @param AiClientWorker|null $ai_client AI client for keyword generation.
	 */
	public function __construct( ?ImageService $image_service = null, ?AiClientWorker $ai_client = null ) {
		$this->image_service = $image_service ?? new ImageService();
		$this->ai_client     = $ai_client;
	}

	/**
	 * Attempt to handle the request without calling the AI service.
	 *
	 * @param string $current_markup Current block markup.
	 * @param string $last_user_prompt Latest user prompt.
	 * @param string $page_title Page title from the editor (for keyword targeting).
	 * @param string $page_excerpt Page excerpt from the editor (for keyword targeting).
	 * @return \WP_REST_Response|null
	 */
	public function maybe_handle_fast_path( $current_markup, $last_user_prompt, $page_title = '', $page_excerpt = '' ) {
		if ( empty( $current_markup ) ) {
			return null;
		}

		$prompt_lower = strtolower( $last_user_prompt );

		$has_image_word   = (bool) preg_match( '/\b(images?|pictures?|photos?|pics?|backgrounds?)\b/i', $prompt_lower );
		$has_replace_verb = (bool) preg_match( '/\b(change|update|replace|swap|regenerate|refresh|new|different)\b/i', $prompt_lower );
		$has_add_verb     = (bool) preg_match( '/\b(add|insert|include|put|place|append)\b/i', $prompt_lower );

		if ( ! $has_image_word ) {
			return null;
		}

		$has_images_in_markup = (bool) preg_match( '/<img\b|<!--\s*wp:(image|cover)\b/i', $current_markup );

		// 1. Image Replacement Intent — images already exist in markup.
		// Check separately so articles/filler words between verb and noun don't prevent matching
		// e.g. "change the images", "replace all photos", "update background images".
		if ( $has_replace_verb && $has_images_in_markup ) {
			$heading_title   = $this->extract_page_title( $current_markup );
			$search_context  = $this->generate_image_keywords( $last_user_prompt, $heading_title );
			$unsplash_images = $this->image_service->get_unsplash_images( $search_context );
			if ( ! empty( $unsplash_images ) ) {
				$new_html = $this->image_service->replace_images_in_html( $current_markup, $unsplash_images );
				return $this->build_response( $new_html );
			}
			// Unsplash unavailable — return a message so the request doesn't fall through to AI,
			// which would ignore the image request due to its system prompt.
			return $this->build_message_response( 'Image search is currently unavailable. Please try again in a moment.' );
		}

		// 2. Image Insertion Intent — no images in markup yet.
		if ( $has_add_verb && ! $has_images_in_markup ) {
			return $this->handle_image_insertion( $current_markup, $page_title, $page_excerpt );
		}

		return null;
	}

	/**
	 * Insert Unsplash images into a layout that currently contains no images.
	 *
	 * Uses page title and excerpt for keyword targeting, then asks the AI (via
	 * the pass-through analyze endpoint) to identify the best insertion positions
	 * in the block structure.
	 *
	 * @param string $markup       Current Gutenberg block markup.
	 * @param string $page_title   Page title (from editor state, for keyword targeting).
	 * @param string $page_excerpt Page excerpt (from editor state, for keyword targeting).
	 * @return \WP_REST_Response|null
	 */
	private function handle_image_insertion( $markup, $page_title, $page_excerpt ) {
		// Build search keywords from title + excerpt + first heading in markup.
		$heading_title = $this->extract_page_title( $markup );
		$raw_keywords  = trim( $page_title . ' ' . $page_excerpt . ' ' . $heading_title );

		if ( empty( $raw_keywords ) ) {
			return null;
		}

		$unsplash_images = $this->image_service->get_unsplash_images( $raw_keywords );
		if ( empty( $unsplash_images ) ) {
			return $this->build_message_response( 'Image search is currently unavailable. Please try again in a moment.' );
		}

		$blocks = $this->split_top_level_blocks( $markup );
		if ( empty( $blocks ) ) {
			return null;
		}

		$image_count = min( 3, max( 1, (int) floor( count( $blocks ) / 2 ) ) );
		$skeleton    = $this->build_block_skeleton( $blocks );
		$positions   = $this->analyze_layout_for_image_positions( $skeleton, $image_count );

		// Fallback: first block and middle of page if AI analysis fails.
		if ( empty( $positions ) ) {
			$positions = array( 0 );
			if ( count( $blocks ) > 2 ) {
				$positions[] = (int) floor( count( $blocks ) / 2 );
			}
		}

		$new_markup = $this->insert_images_at_positions( $blocks, $positions, $unsplash_images );

		return $this->build_response( $new_markup );
	}

	/**
	 * Ask AI to identify the best block positions for image insertion.
	 *
	 * Sends a skeletonised block list to the pass-through analyze endpoint so the
	 * caller-supplied system prompt is not overridden by the page-designer prompt.
	 *
	 * @param string $skeleton Numbered list of top-level block descriptions.
	 * @param int    $count    Number of images to insert.
	 * @return int[] Zero-indexed block positions to insert after (empty on failure).
	 */
	private function analyze_layout_for_image_positions( $skeleton, $count ) {
		if ( ! $this->ai_client ) {
			return array();
		}

		$ai_messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a layout analyzer for WordPress pages. Given a numbered list of top-level Gutenberg blocks, identify the best positions to insert image blocks to improve visual appeal. Rules: only insert after blocks containing text content (headings, paragraphs); skip blocks marked [has image]; aim for even distribution across the page. Return ONLY a valid JSON array with no explanation. Format: [{"after_block": N}] where N is the 0-indexed block number after which to insert the image.',
			),
			array(
				'role'    => 'user',
				'content' => 'Insert ' . $count . ' image(s) into this layout:' . "\n\n" . $skeleton,
			),
		);

		$result = $this->ai_client->analyze( $ai_messages );

		if ( is_wp_error( $result ) || empty( $result['content'] ) ) {
			return array();
		}

		// Strip markdown code fences if the AI wrapped the JSON.
		$content = preg_replace( '/```(?:json)?\s*/i', '', $result['content'] );
		$content = trim( $content );

		$positions_raw = json_decode( $content, true );

		if ( ! is_array( $positions_raw ) ) {
			return array();
		}

		$positions = array();
		foreach ( $positions_raw as $pos ) {
			if ( isset( $pos['after_block'] ) && is_numeric( $pos['after_block'] ) ) {
				$positions[] = (int) $pos['after_block'];
			}
		}

		return array_unique( $positions );
	}

	/**
	 * Insert image block markup strings after the specified block indices.
	 *
	 * Positions are processed in reverse order so earlier insertions do not
	 * shift the indices of later ones.
	 *
	 * @param string[] $blocks   Top-level block markup strings (from split_top_level_blocks).
	 * @param int[]    $positions Zero-indexed block numbers to insert after.
	 * @param string[] $images   Unsplash image URLs.
	 * @return string Updated Gutenberg markup.
	 */
	private function insert_images_at_positions( array $blocks, array $positions, array $images ) {
		// Process in reverse so earlier insertions don't shift later indices.
		rsort( $positions );

		$image_idx   = 0;
		$block_count = count( $blocks );

		foreach ( $positions as $pos ) {
			if ( $pos >= 0 && $pos < $block_count && $image_idx < count( $images ) ) {
				$image_block = $this->build_image_block_markup( $images[ $image_idx ] );
				array_splice( $blocks, $pos + 1, 0, array( $image_block ) );
				++$image_idx;
			}
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Build a wp:image block markup string for a given URL.
	 *
	 * @param string $url Unsplash image URL.
	 * @return string Gutenberg block markup.
	 */
	private function build_image_block_markup( $url ) {
		$safe_url   = esc_url( $url );
		$inner_html = '<figure class="wp-block-image size-large"><img src="' . $safe_url . '" alt=""/></figure>';
		return '<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->' . "\n" .
			$inner_html . "\n" .
			'<!-- /wp:image -->';
	}

	/**
	 * Split Gutenberg markup into an array of top-level block strings.
	 *
	 * @param string $markup Full Gutenberg block markup.
	 * @return string[] Array of top-level block markup strings.
	 */
	private function split_top_level_blocks( $markup ) {
		$blocks  = array();
		$depth   = 0;
		$current = '';

		foreach ( explode( "\n", $markup ) as $line ) {
			$trimmed         = trim( $line );
			$is_self_closing = (bool) preg_match( '/^<!--\s*wp:[^ ].*?\/-->/i', $trimmed );
			$is_opening      = ! $is_self_closing && (bool) preg_match( '/^<!--\s*wp:/i', $trimmed );
			$is_closing      = (bool) preg_match( '/^<!--\s*\/wp:/i', $trimmed );

			if ( ( $is_opening || $is_self_closing ) && 0 === $depth ) {
				if ( '' !== trim( $current ) ) {
					$blocks[] = trim( $current );
				}
				$current = '';
			}

			$current .= $line . "\n";

			if ( $is_opening ) {
				++$depth;
			}
			if ( $is_closing ) {
				--$depth;
			}
		}

		if ( '' !== trim( $current ) ) {
			$blocks[] = trim( $current );
		}

		return array_values(
			array_filter(
				$blocks,
				function ( $b ) {
					return (bool) preg_match( '/<!--\s*\/?wp:/i', $b );
				}
			)
		);
	}

	/**
	 * Build a concise skeleton description of top-level blocks for AI analysis.
	 *
	 * @param string[] $blocks Top-level block markup strings.
	 * @return string Numbered list of block descriptions.
	 */
	private function build_block_skeleton( array $blocks ) {
		$lines = array();

		foreach ( $blocks as $idx => $block ) {
			preg_match_all( '/<!--\s*(?!\/)wp:([^\s{\/-][^\s{\/]*)/i', $block, $matches );
			$block_names = array_values( array_unique( $matches[1] ) );
			$top_level   = $block_names[0] ?? 'unknown';
			$inner_types = implode( ', ', array_slice( $block_names, 1 ) );
			$has_image   = (bool) preg_match( '/<img\b|<!--\s*wp:(image|cover)\b/i', $block );
			$image_label = $has_image ? ' [has image]' : '';

			if ( $inner_types ) {
				$lines[] = 'Block ' . $idx . ' [' . $top_level . ']: contains ' . $inner_types . $image_label;
			} else {
				$lines[] = 'Block ' . $idx . ' [' . $top_level . ']' . $image_label;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Use AI to generate focused image search keywords from the user prompt and page title.
	 *
	 * Falls back to the raw prompt + title string if the AI call fails.
	 *
	 * @param string $prompt     The user's image request prompt.
	 * @param string $page_title The page title for additional context.
	 * @return string Search context string for Unsplash.
	 */
	private function generate_image_keywords( $prompt, $page_title ) {
		if ( ! $this->ai_client ) {
			return trim( $prompt . ' ' . $page_title );
		}

		$context = $page_title ? "Page title: {$page_title}\nUser request: {$prompt}" : $prompt;

		$ai_messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are an image search assistant. Given a user request and optional page context, return only 4-6 comma-separated search keywords suitable for finding the best matching stock photo on Unsplash. Return nothing else — no explanation, no punctuation other than commas.',
			),
			array(
				'role'    => 'user',
				'content' => $context,
			),
		);

		$result = $this->ai_client->generate_content( $ai_messages );

		if ( is_wp_error( $result ) || empty( $result['content'] ) ) {
			return trim( $prompt . ' ' . $page_title );
		}

		// Convert comma-separated keywords to a space-separated search string.
		$keywords = array_map( 'trim', explode( ',', $result['content'] ) );
		$keywords = array_filter( $keywords );
		return implode( ' ', $keywords );
	}

	/**
	 * Extract the first heading from block markup to use as page context.
	 *
	 * Strips HTML tags and common title separators so only meaningful words remain.
	 *
	 * @param string $markup Gutenberg block markup.
	 * @return string Plain text heading, or empty string if none found.
	 */
	private function extract_page_title( $markup ) {
		if ( preg_match( '/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', $markup, $m ) ) {
			$title = wp_strip_all_tags( $m[1] );
			// Strip common separators used in page titles (e.g. "Brand | Tagline - Site").
			$title = preg_replace( '/\s*[\|\-–—:]\s*/', ' ', $title );
			return trim( $title );
		}
		return '';
	}

	/**
	 * Build the shared fast-path response payload.
	 *
	 * @param string $html HTML content.
	 * @return \WP_REST_Response
	 */
	private function build_response( $html ) {
		return new \WP_REST_Response(
			array(
				'data' => array(
					'content' => trim( $html ) . '<!-- fastpath_cb=' . time() . ' -->',
					'title'   => '',
				),
			),
			200
		);
	}

	/**
	 * Build a message-only response (no content/preview update).
	 *
	 * Used when the fast path detects an intent but cannot fulfil it
	 * (e.g. Unsplash unavailable), preventing the request from falling
	 * through to the AI which would silently ignore the image request.
	 *
	 * @param string $message User-facing message to show in the chat.
	 * @return \WP_REST_Response
	 */
	private function build_message_response( $message ) {
		return new \WP_REST_Response(
			array(
				'data' => array(
					'content' => '',
					'title'   => '',
					'message' => $message,
				),
			),
			200
		);
	}
}
