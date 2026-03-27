<?php
/**
 * Block markup sanitizer for AI Page Designer.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\Services
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services;

/**
 * Sanitizes Gutenberg markup returned by the AI and extracts the page title.
 */
class BlockMarkupSanitizer {

	/**
	 * Extract the PAGE_TITLE comment embedded in every AI HTML response.
	 *
	 * @param string $content Raw AI response content.
	 * @return array{title:string,html:string}
	 */
	public function extract_page_title( $content ) {
		$title = '';
		$html  = $content;

		if ( preg_match( '/<!--\s*PAGE_TITLE:\s*(.+?)\s*-->/i', $content, $m ) ) {
			$title = trim( $m[1] );
			// Remove the title comment line from the HTML so it is not stored in WordPress content.
			$html = preg_replace( '/<!--\s*PAGE_TITLE:\s*.+?\s*-->\s*/i', '', $content, 1 );
		}

		return array(
			'title' => $title,
			'html'  => $this->sanitize_block_content( trim( $html ) ),
		);
	}

	/**
	 * Sanitize Gutenberg block markup returned by the AI.
	 *
	 * Handles two common failure modes:
	 *  1. Truncated/incomplete HTML comment at the end of the response.
	 *  2. Unclosed block tags.
	 *
	 * @param string $content Block markup to sanitize.
	 * @return string Sanitized block markup.
	 */
	public function sanitize_block_content( $content ) {
		// 1. Remove any incomplete HTML comment that was never closed.
		$content = preg_replace( '/<!--(?![\s\S]*?-->)[\s\S]*$/u', '', $content );
		$content = trim( $content );

		// 2. Walk every Gutenberg block comment and track nesting with a stack.
		preg_match_all(
			'/<!--\s*(\/?)wp:([\w\/-]+)(?:\s[^-]*)?\s*(\/?)-->/i',
			$content,
			$matches,
			PREG_SET_ORDER
		);

		$stack = array();
		foreach ( $matches as $match ) {
			$is_closing      = ( '/' === trim( $match[1] ) );
			$block_name      = trim( $match[2] );
			$is_self_closing = ( '/' === trim( $match[3] ) );

			if ( $is_self_closing ) {
				continue;
			}

			if ( $is_closing ) {
				// Pop the stack only when the closing tag matches the most recent opener.
				if ( ! empty( $stack ) && end( $stack ) === $block_name ) {
					array_pop( $stack );
				}
			} else {
				$stack[] = $block_name;
			}
		}

		// 3. Close any blocks that were opened but never closed (most recent first).
		while ( ! empty( $stack ) ) {
			$block_name = array_pop( $stack );
			$content   .= "\n<!-- /wp:{$block_name} -->";
		}

		return $content;
	}
}
