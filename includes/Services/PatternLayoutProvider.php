<?php
/**
 * Pattern layout provider for AI Page Designer.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\Services
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services;

use NewfoldLabs\WP\Module\Patterns\Library\Items;

/**
 * Selects and minifies Gutenberg pattern layouts for prompt assembly.
 */
class PatternLayoutProvider {

	/**
	 * Get a randomized pattern layout based on user intent.
	 *
	 * @param string $user_prompt The user's request.
	 * @return string The assembled block markup.
	 */
	public function get_random_pattern_layout( $user_prompt ) {
		$prompt_lower = strtolower( $user_prompt );

		// Map common keywords to pattern categories.
		$intent_mapping = array(
			'contact'   => array( 'hero', 'contact' ),
			'about'     => array( 'hero', 'about', 'team' ),
			'services'  => array( 'hero', 'services', 'call-to-action' ),
			'product'   => array( 'hero', 'gallery', 'call-to-action' ),
			'store'     => array( 'hero', 'gallery', 'call-to-action' ),
			'portfolio' => array( 'hero', 'portfolio', 'contact' ),
			'blog'      => array( 'hero', 'text' ),
			'post'      => array( 'hero', 'text' ),
			'landing'   => array( 'hero', 'features', 'call-to-action' ),
		);

		// Default to a standard homepage layout if no specific intent is found.
		$categories = array( 'hero', 'about' );

		foreach ( $intent_mapping as $keyword => $cats ) {
			if ( strpos( $prompt_lower, $keyword ) !== false ) {
				$categories = array_slice( $cats, 0, 2 );
				break;
			}
		}

		$layout = '';

		foreach ( $categories as $category ) {
			// Get a wider pool per category to increase randomness without large payloads.
			$patterns = Items::get(
				'patterns',
				array(
					'category' => $category,
					'per_page' => 12,
				)
			);

			if ( ! is_wp_error( $patterns ) && is_array( $patterns ) && ! empty( $patterns ) ) {
				// Filter to only patterns with content, then shuffle to randomize order.
				$patterns = array_values(
					array_filter(
						$patterns,
						function ( $pattern ) {
							return ! empty( $pattern['content'] );
						}
					)
				);

				if ( empty( $patterns ) ) {
					continue;
				}

				shuffle( $patterns );

				// Avoid repeating the same pattern twice in a row for the same category.
				$transient_key = 'nfd_aipd_last_pattern_' . sanitize_key( $category );
				$last_slug     = get_transient( $transient_key );
				$selected      = $patterns[0];

				if ( $last_slug && count( $patterns ) > 1 ) {
					foreach ( $patterns as $pattern ) {
						$pattern_slug = $pattern['slug'] ?? md5( $pattern['content'] );
						if ( $pattern_slug !== $last_slug ) {
							$selected = $pattern;
							break;
						}
					}
				}

				$selected_slug = $selected['slug'] ?? md5( $selected['content'] );
				set_transient( $transient_key, $selected_slug, HOUR_IN_SECONDS );

				// Minify the pattern content to save AI tokens (remove tabs, newlines, duplicate spaces).
				$content = $selected['content'];
				$content = preg_replace( '/>\s+</', '><', $content );
				$content = preg_replace( '/\s+/', ' ', $content );
				$layout .= trim( $content ) . "\n\n";
			}
		}

		// If for some reason we couldn't fetch patterns, fallback to empty string.
		return $layout;
	}
}
