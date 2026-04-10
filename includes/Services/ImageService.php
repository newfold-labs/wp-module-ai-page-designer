<?php
/**
 * Image replacement service for AI Page Designer.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\Services
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services;

/**
 * Fetches Unsplash imagery and rewrites image URLs in HTML/block markup.
 */
class ImageService {
	/**
	 * Default Hiive base URL.
	 *
	 * @var string
	 */
	private const DEFAULT_HIIVE_BASE_URL = 'https://hiive.cloud';

	/**
	 * Unsplash search endpoint path.
	 *
	 * @var string
	 */
	private const UNSPLASH_ENDPOINT = '/workers/unsplash/search/photos';

	/**
	 * Number of images to fetch per Unsplash request.
	 *
	 * @var int
	 */
	private const UNSPLASH_PER_PAGE = 15;

	/**
	 * Unsplash request timeout in seconds.
	 *
	 * @var int
	 */
	private const UNSPLASH_TIMEOUT = 10;

	/**
	 * Fallback query when no keywords remain.
	 *
	 * @var string
	 */
	private const FALLBACK_QUERY = 'nature';

	/**
	 * Placeholder host used by templates.
	 *
	 * @var string
	 */
	private const PLACEHOLDER_HOST = 'placehold.co';

	/**
	 * Cache-buster minimum value.
	 *
	 * @var int
	 */
	private const CACHE_BUSTER_MIN = 1000;

	/**
	 * Cache-buster maximum value.
	 *
	 * @var int
	 */
	private const CACHE_BUSTER_MAX = 9999;

	// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine
	/**
	 * Common stopwords removed from user queries.
	 *
	 * @var string[]
	 */
	private const DEFAULT_STOPWORDS = array(
		// Articles / pronouns / prepositions.
		'a', 'an', 'the', 'this', 'that', 'these', 'those',
		'i', 'me', 'my', 'we', 'our', 'you', 'your', 'it', 'its',
		'he', 'she', 'him', 'her', 'they', 'them', 'their',
		'to', 'of', 'in', 'on', 'at', 'by', 'as', 'is', 'be',
		'are', 'was', 'were', 'do', 'does', 'did', 'have', 'has',
		'had', 'will', 'would', 'could', 'should', 'from', 'into',
		'with', 'about', 'for', 'and', 'or', 'but', 'so', 'yet',
		// Action words / verbs / gerunds common in chat prompts.
		'create', 'make', 'add', 'update', 'modify', 'change',
		'replace', 'swap', 'use', 'show', 'put', 'set', 'get',
		'turn', 'keep', 'give', 'look', 'want', 'like',
		'creating', 'making', 'adding', 'updating', 'modifying',
		'replacing', 'swapping', 'using', 'showing', 'putting',
		'getting', 'turning', 'keeping', 'giving', 'looking',
		'doing', 'going', 'having', 'being', 'trying', 'taking',
		'featuring', 'including', 'showing', 'displaying',
		// Image-related words.
		'image', 'images', 'picture', 'pictures', 'photo', 'photos',
		'pic', 'pics', 'background', 'backgrounds', 'icon', 'icons',
		// Generic people words — too broad for image search.
		'person', 'people', 'man', 'woman', 'girl', 'boy',
		'someone', 'anyone', 'everyone', 'somebody',
		// Content/page words.
		'page', 'post', 'site', 'website', 'design', 'layout',
		'new', 'some', 'same', 'good', 'great', 'nice', 'better',
		'landing', 'home', 'homepage', 'contact', 'services', 'portfolio',
	);
	// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine

	/**
	 * Fetch images from Unsplash based on a query.
	 *
	 * @param string $query The search query.
	 * @return array Array of image URLs.
	 */
	public function get_unsplash_images( $query ) {
		$hiive_base_url = defined( 'NFD_HIIVE_BASE_URL' ) ? NFD_HIIVE_BASE_URL : self::DEFAULT_HIIVE_BASE_URL;
		$endpoint       = self::UNSPLASH_ENDPOINT;

		// Clean up query: remove common conversational words to get better image results.
		// Include site title/tagline to improve relevance when available.
		$stopwords     = self::DEFAULT_STOPWORDS;
		$site_name     = get_bloginfo( 'name' );
		$tagline       = get_bloginfo( 'description' );
		$context_query = trim( $query );
		$context_site  = trim( $site_name . ' ' . $tagline );

		$words    = explode( ' ', strtolower( preg_replace( '/[^a-zA-Z\s]/', '', $context_query ) ) );
		$keywords = array_values( array_diff( $words, $stopwords ) );

		// Build a list of candidate queries to try, from most specific to broadest.
		$candidate_queries = array();
		if ( count( $keywords ) >= 2 ) {
			$candidate_queries[] = implode( ' ', array_slice( $keywords, 0, 6 ) );
			// Retry skipping the first keyword (may be a brand/Latin word with no results).
			$candidate_queries[] = implode( ' ', array_slice( $keywords, 1, 5 ) );
		} elseif ( ! empty( $keywords ) ) {
			$candidate_queries[] = $keywords[0];
		}
		// Add site context as a fallback query to avoid overpowering the user intent.
		if ( $context_site ) {
			$site_words    = explode( ' ', strtolower( preg_replace( '/[^a-zA-Z\s]/', '', $context_site ) ) );
			$site_keywords = array_values( array_diff( $site_words, $stopwords ) );
			if ( ! empty( $site_keywords ) ) {
				$candidate_queries[] = implode( ' ', array_slice( $site_keywords, 0, 6 ) );
			}
		}

		$candidate_queries[] = self::FALLBACK_QUERY; // Final fallback.

		foreach ( $candidate_queries as $search_query ) {
			$search_query = trim( $search_query );
			if ( empty( $search_query ) ) {
				continue;
			}

			$transient_key = 'nfd_unsplash_' . md5( $search_query );
			$cached_images = get_transient( $transient_key );

			if ( false !== $cached_images && is_array( $cached_images ) && ! empty( $cached_images ) ) {
				return $cached_images;
			}

			$args        = array(
				'query'    => $search_query,
				'per_page' => self::UNSPLASH_PER_PAGE,
			);
			$request_url = $hiive_base_url . $endpoint . '?' . http_build_query( $args );
			$response    = wp_remote_get( $request_url, array( 'timeout' => self::UNSPLASH_TIMEOUT ) );
			$images      = array();

			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $body['results'] ) ) {
					foreach ( $body['results'] as $result ) {
						if ( ! empty( $result['urls']['regular'] ) ) {
							$images[] = $result['urls']['regular'];
						}
					}
				}
			}

			if ( ! empty( $images ) ) {
				set_transient( $transient_key, $images, HOUR_IN_SECONDS );
				return $images;
			}
		}

		return array();
	}

	/**
	 * Check whether a URL is a placeholder that should be replaced.
	 *
	 * Only placehold.co URLs are placeholders. Real image URLs (Unsplash, WordPress
	 * media library, etc.) must be preserved.
	 *
	 * @param string $url Image URL to test.
	 * @return bool
	 */
	private function is_placeholder_url( $url ) {
		return ! empty( $url ) && strpos( $url, self::PLACEHOLDER_HOST ) !== false;
	}

	/**
	 * Resolve a replacement URL for a given original image URL.
	 *
	 * Checks $placeholders_only and is_placeholder_url(), strips any existing cb= param,
	 * consults/updates $url_map, advances $image_index as needed, and appends a fresh
	 * cb= cache buster to the resolved URL.
	 *
	 * @param array  &$url_map         Map of original (base) URLs to replacement URLs.
	 * @param array  $unsplash_images  Array of available Unsplash image URLs.
	 * @param int    &$image_index     Current position in the Unsplash array (advanced in place).
	 * @param int    $total_images     Total number of available Unsplash images.
	 * @param string $orig_url         The original image URL found in the markup.
	 * @param bool   $placeholders_only When true, only placehold.co URLs are replaced.
	 * @return string|null The new URL with cache buster, or null if the URL should not be replaced.
	 */
	private function resolve_replacement_url( &$url_map, $unsplash_images, &$image_index, $total_images, $orig_url, $placeholders_only ) {
		if ( empty( $orig_url ) ) {
			return null;
		}

		if ( $placeholders_only && ! $this->is_placeholder_url( $orig_url ) ) {
			return null;
		}

		$base_orig_url = preg_replace( '/[?&]cb=\d+/', '', $orig_url );

		// Placeholder URLs are all identical — always advance index so each gets a unique image.
		if ( $this->is_placeholder_url( $orig_url ) || ! isset( $url_map[ $base_orig_url ] ) ) {
			$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
			++$image_index;
		}

		// Map both with and without cb= to the same new base image.
		$url_map[ $orig_url ] = $url_map[ $base_orig_url ];

		$new_url = $url_map[ $orig_url ];

		// Add a random cache buster so images look "new" even if the URL is the same.
		if ( strpos( $new_url, 'cb=' ) === false ) {
			$new_url .= ( strpos( $new_url, '?' ) !== false ? '&' : '?' ) . 'cb=' . wp_rand( self::CACHE_BUSTER_MIN, self::CACHE_BUSTER_MAX );
		}

		return $new_url;
	}

	/**
	 * Apply common URL replacement patterns to a single HTML/content string.
	 *
	 * Rewrites src=, url(), and "url":"..." occurrences to $new_url, and strips srcset.
	 * For core/cover blocks the url() pattern is needed; for core/image it is not harmful.
	 *
	 * @param string $html_string The HTML or content string to update.
	 * @param string $new_url     The replacement image URL (with cache buster already appended).
	 * @return string The updated string.
	 */
	private function replace_urls_in_block_html( $html_string, $new_url ) {
		// Replace src= attribute values.
		$html_string = preg_replace_callback(
			'/(src=["\'])([^"\']+)(["\'])/i',
			function ( $matches ) use ( $new_url ) {
				return $matches[1] . $new_url . $matches[3];
			},
			$html_string
		);

		// Replace CSS url() values (used by core/cover).
		$html_string = preg_replace_callback(
			'/url\([\'"]?([^\'"]+)[\'"]?\)/i',
			function ( $matches ) use ( $new_url ) {
				return 'url(' . $new_url . ')';
			},
			$html_string
		);

		// Replace JSON "url":"..." values.
		$html_string = preg_replace_callback(
			'/"url":"([^"]+)"/i',
			function ( $matches ) use ( $new_url ) {
				return '"url":"' . $new_url . '"';
			},
			$html_string
		);

		// Strip srcset so the browser always uses our new src.
		$html_string = preg_replace( '/srcset=["\'][^"\']+["\']/i', '', $html_string );

		return $html_string;
	}

	/**
	 * Replace image URLs in HTML content with Unsplash images using native WP parsers.
	 *
	 * @param string $html             The HTML content containing images.
	 * @param array  $unsplash_images  Array of Unsplash image URLs.
	 * @param bool   $placeholders_only When true, only placehold.co URLs are replaced;
	 *                                  real image URLs (Unsplash, media library) are preserved.
	 *                                  Pass false (default) when explicitly replacing all images
	 *                                  (e.g. the image-swap fast path).
	 * @return string The updated HTML.
	 */
	public function replace_images_in_html( $html, $unsplash_images, $placeholders_only = false ) {
		if ( empty( $unsplash_images ) ) {
			return $html;
		}

		// When only replacing placeholders, skip entirely if none are present.
		if ( $placeholders_only && strpos( $html, self::PLACEHOLDER_HOST ) === false ) {
			return $html;
		}

		$image_index  = 0;
		$total_images = count( $unsplash_images );
		$url_map      = array();

		// 1. Replace URLs inside Gutenberg block comments safely using parse_blocks.
		$blocks = parse_blocks( $html );

		if ( ! empty( $blocks ) ) {
			$this->update_block_images_recursive( $blocks, $url_map, $unsplash_images, $image_index, $total_images, $placeholders_only );

			// Rebuild HTML from blocks.
			$html = '';
			foreach ( $blocks as $block ) {
				$html .= serialize_blocks( array( $block ) );
			}
		}

		// 2. Replace <img> src attributes safely using WP_HTML_Tag_Processor.
		// Doing this after parse_blocks/serialize_blocks catches any stragglers.
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$tags = new \WP_HTML_Tag_Processor( $html );
			while ( $tags->next_tag( 'img' ) ) {
				$orig_url = $tags->get_attribute( 'src' );
				$new_url  = $this->resolve_replacement_url( $url_map, $unsplash_images, $image_index, $total_images, $orig_url, $placeholders_only );
				if ( null !== $new_url ) {
					$tags->set_attribute( 'src', $new_url );
				}
				// Remove srcset if it exists so browser falls back to src.
				$tags->remove_attribute( 'srcset' );
			}
			$html = $tags->get_updated_html();

			// Also replace inline styles for background images.
			$html = preg_replace_callback(
				'/background-image:\s*url\([\'"]?([^\'"]+)[\'"]?\)/i',
				function ( $matches ) use ( &$image_index, &$url_map, $unsplash_images, $total_images, $placeholders_only ) {
					$new_url = $this->resolve_replacement_url( $url_map, $unsplash_images, $image_index, $total_images, $matches[1], $placeholders_only );
					if ( null === $new_url ) {
						return $matches[0];
					}
					return 'background-image: url(' . $new_url . ')';
				},
				$html
			);
		} else {
			// Fallback if WP_HTML_Tag_Processor doesn't exist (older WP versions).
			$html = preg_replace_callback(
				'/<img[^>]+src=["\']([^"\']+)["\']/i',
				function ( $matches ) use ( &$image_index, &$url_map, $unsplash_images, $total_images, $placeholders_only ) {
					$new_url = $this->resolve_replacement_url( $url_map, $unsplash_images, $image_index, $total_images, $matches[1], $placeholders_only );
					if ( null === $new_url ) {
						return $matches[0];
					}
					return str_replace( $matches[1], $new_url, $matches[0] );
				},
				$html
			);
			// Also strip srcset from fallback since we don't have srcset unsplash URLs.
			$html = preg_replace( '/srcset=["\'][^"\']+["\']/i', '', $html );
		}

		// Fallback for any other remaining images using regex just in case.
		$html = preg_replace_callback(
			'/(<img[^>]+src=["\'])([^"\']+)["\']/',
			function ( $matches ) use ( &$url_map, $unsplash_images, &$image_index, $total_images, $placeholders_only ) {
				$new_url = $this->resolve_replacement_url( $url_map, $unsplash_images, $image_index, $total_images, $matches[2], $placeholders_only );
				if ( null === $new_url ) {
					return $matches[0];
				}
				return $matches[1] . $new_url . '"';
			},
			$html
		);

		// Remove all srcset attributes to prevent old images from showing.
		$html = preg_replace( '/srcset=["\'][^"\']+["\']/i', '', $html );

		return trim( $html );
	}

	/**
	 * Recursively update image URLs in parsed Gutenberg blocks.
	 *
	 * @param array &$blocks            Parsed blocks array.
	 * @param array &$url_map           Map of original to new URLs.
	 * @param array $unsplash_images    Array of available Unsplash URLs.
	 * @param int   &$image_index       Current index in the Unsplash array.
	 * @param int   $total_images       Total number of Unsplash images.
	 * @param bool  $placeholders_only  When true, only replace placehold.co URLs.
	 * @return void
	 */
	private function update_block_images_recursive( &$blocks, &$url_map, $unsplash_images, &$image_index, $total_images, $placeholders_only = false ) {
		foreach ( $blocks as &$block ) {
			// Check if block has an attrs array.
			if ( ! isset( $block['attrs'] ) ) {
				$block['attrs'] = array();
			}

			// Handle core/image and core/cover blocks — they share the same replacement
			// logic; only the URL extraction differs (image also falls back to innerHTML).
			if ( 'core/image' === $block['blockName'] || 'core/cover' === $block['blockName'] ) {
				if ( isset( $block['attrs']['url'] ) ) {
					$orig_url = $block['attrs']['url'];
				} elseif ( 'core/image' === $block['blockName'] && preg_match( '/src=["\']([^"\']+)["\']/i', $block['innerHTML'], $m ) ) {
					// Sometimes the URL is only in the innerHTML for image blocks — extract it.
					$orig_url = $m[1];
				} else {
					$orig_url = '';
				}

				$new_url = $this->resolve_replacement_url( $url_map, $unsplash_images, $image_index, $total_images, $orig_url, $placeholders_only );

				if ( null !== $new_url ) {
					$block['attrs']['url'] = $url_map[ $orig_url ];
					$block['attrs']['id']  = null;

					// Also update HTML content strings inside the block array.
					if ( ! empty( $block['innerHTML'] ) ) {
						$block['innerHTML'] = $this->replace_urls_in_block_html( $block['innerHTML'], $new_url );
					}
					if ( ! empty( $block['innerContent'] ) ) {
						foreach ( $block['innerContent'] as &$content_string ) {
							if ( is_string( $content_string ) ) {
								$content_string = $this->replace_urls_in_block_html( $content_string, $new_url );
							}
						}
					}
				}
			}

			// Process inner blocks recursively.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->update_block_images_recursive( $block['innerBlocks'], $url_map, $unsplash_images, $image_index, $total_images, $placeholders_only );
			}
		}
	}
}
