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

		// Map intent keywords to pattern categories with multiple keyword variations
		$intent_mapping = array(
			// Contact pages
			'contact' => array(
				'keywords' => array( 'contact', 'reach', 'get in touch', 'contact us', 'reach out' ),
				'categories' => array( 'hero', 'contact', 'call-to-action' ),
			),
			// About pages
			'about' => array(
				'keywords' => array( 'about', 'about us', 'our story', 'who we are', 'our team', 'company' ),
				'categories' => array( 'hero', 'about', 'team', 'call-to-action' ),
			),
			// Services pages
			'services' => array(
				'keywords' => array( 'services', 'what we do', 'offerings', 'solutions', 'expertise' ),
				'categories' => array( 'hero', 'services', 'features', 'call-to-action' ),
			),
			// Product pages
			'product' => array(
				'keywords' => array( 'product', 'products', 'catalog', 'shop', 'store', 'ecommerce', 'buy' ),
				'categories' => array( 'hero', 'gallery', 'features', 'call-to-action' ),
			),
			// Portfolio pages
			'portfolio' => array(
				'keywords' => array( 'portfolio', 'work', 'projects', 'showcase', 'gallery', 'examples' ),
				'categories' => array( 'hero', 'portfolio', 'gallery', 'contact' ),
			),
			// Blog/content pages
			'blog' => array(
				'keywords' => array( 'blog', 'news', 'articles', 'posts', 'content', 'updates' ),
				'categories' => array( 'hero', 'text', 'call-to-action' ),
			),
			// Landing/homepage (comprehensive)
			'homepage' => array(
				'keywords' => array( 'homepage', 'home page', 'landing', 'main page', 'website', 'site', 'business' ),
				'categories' => array( 'hero', 'about', 'features', 'services', 'testimonials', 'call-to-action' ),
			),
		);

		// Default to a comprehensive homepage layout if no specific intent is found
		$categories = array( 'hero', 'about', 'features', 'call-to-action' );

		// Find the best matching intent based on keyword scoring
		$best_match = null;
		$best_score = 0;

		foreach ( $intent_mapping as $intent => $config ) {
			$score = 0;
			foreach ( $config['keywords'] as $keyword ) {
				if ( strpos( $prompt_lower, $keyword ) !== false ) {
					// Give higher scores for longer, more specific matches
					$score += strlen( $keyword );
				}
			}
			
			if ( $score > $best_score ) {
				$best_score = $score;
				$best_match = $intent;
			}
		}

		// Use the best matching intent's categories, or default if no match
		if ( $best_match && $best_score > 0 ) {
			$categories = $intent_mapping[ $best_match ]['categories'];
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
