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
	 * Fetch images from Unsplash based on a query.
	 *
	 * @param string $query The search query.
	 * @return array Array of image URLs.
	 */
	public function get_unsplash_images( $query ) {
		$hiive_base_url = defined( 'NFD_HIIVE_BASE_URL' ) ? NFD_HIIVE_BASE_URL : 'https://hiive.cloud';
		$endpoint       = '/workers/unsplash/search/photos';

		// Clean up query: remove common conversational words to get better image results.
		// Also remove site/brand name tokens to avoid skewing results.
		$stopwords = array(
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
		$site_name = get_bloginfo( 'name' );
		if ( $site_name ) {
			$site_words = explode( ' ', strtolower( preg_replace( '/[^a-zA-Z0-9\s]/', '', $site_name ) ) );
			$stopwords  = array_merge( $stopwords, array_filter( $site_words ) );
		}

		$words    = explode( ' ', strtolower( preg_replace( '/[^a-zA-Z\s]/', '', $query ) ) );
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
		$candidate_queries[] = 'nature'; // Final fallback.

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
				'per_page' => 8,
			);
			$request_url = $hiive_base_url . $endpoint . '?' . http_build_query( $args );
			$response    = wp_remote_get( $request_url, array( 'timeout' => 10 ) );
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
		return ! empty( $url ) && strpos( $url, 'placehold.co' ) !== false;
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
		if ( $placeholders_only && strpos( $html, 'placehold.co' ) === false ) {
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
				if ( $orig_url && ( ! $placeholders_only || $this->is_placeholder_url( $orig_url ) ) ) {
					$base_orig_url = preg_replace( '/[?&]cb=\d+/', '', $orig_url );
					if ( ! isset( $url_map[ $base_orig_url ] ) ) {
						$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
						$image_index++;
					}
					$url_map[ $orig_url ] = $url_map[ $base_orig_url ];

					$new_url = $url_map[ $orig_url ];
					if ( strpos( $new_url, 'cb=' ) === false ) {
						$new_url .= ( strpos( $new_url, '?' ) !== false ? '&' : '?' ) . 'cb=' . rand( 1000, 9999 );
					}
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
					$orig_url = $matches[1];

					if ( $placeholders_only && ! $this->is_placeholder_url( $orig_url ) ) {
						return $matches[0];
					}

					$base_orig_url = preg_replace( '/[?&]cb=\d+/', '', $orig_url );
					if ( ! isset( $url_map[ $base_orig_url ] ) ) {
						$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
						$image_index++;
					}
					$url_map[ $orig_url ] = $url_map[ $base_orig_url ];

					$new_url = $url_map[ $orig_url ];
					if ( strpos( $new_url, 'cb=' ) === false ) {
						$new_url .= ( strpos( $new_url, '?' ) !== false ? '&' : '?' ) . 'cb=' . rand( 1000, 9999 );
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
					$orig_url = $matches[1];

					if ( $placeholders_only && ! $this->is_placeholder_url( $orig_url ) ) {
						return $matches[0];
					}

					$base_orig_url = preg_replace( '/[?&]cb=\d+/', '', $orig_url );
					if ( ! isset( $url_map[ $base_orig_url ] ) ) {
						$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
						$image_index++;
					}
					$url_map[ $orig_url ] = $url_map[ $base_orig_url ];

					$new_url = $url_map[ $orig_url ];
					if ( strpos( $new_url, 'cb=' ) === false ) {
						$new_url .= ( strpos( $new_url, '?' ) !== false ? '&' : '?' ) . 'cb=' . rand( 1000, 9999 );
					}
					return str_replace( $orig_url, $new_url, $matches[0] );
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
				$orig_url = $matches[2];

				if ( $placeholders_only && ! $this->is_placeholder_url( $orig_url ) ) {
					return $matches[0];
				}

				$base_orig_url = preg_replace( '/[?&]cb=\d+/', '', $orig_url );
				if ( ! isset( $url_map[ $base_orig_url ] ) ) {
					$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
					$image_index++;
				}
				$url_map[ $orig_url ] = $url_map[ $base_orig_url ];

				$new_url = $url_map[ $orig_url ];
				if ( strpos( $new_url, 'cb=' ) === false ) {
					$new_url .= ( strpos( $new_url, '?' ) !== false ? '&' : '?' ) . 'cb=' . rand( 1000, 9999 );
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
	 * @param array &$blocks           Parsed blocks array.
	 * @param array &$url_map          Map of original to new URLs.
	 * @param array  $unsplash_images  Array of available Unsplash URLs.
	 * @param int   &$image_index      Current index in the Unsplash array.
	 * @param int    $total_images     Total number of Unsplash images.
	 * @param bool   $placeholders_only When true, only replace placehold.co URLs.
	 * @return void
	 */
	private function update_block_images_recursive( &$blocks, &$url_map, $unsplash_images, &$image_index, $total_images, $placeholders_only = false ) {
		foreach ( $blocks as &$block ) {
			// Check if block has an attrs array.
			if ( ! isset( $block['attrs'] ) ) {
				$block['attrs'] = array();
			}

			// Update wp:image block URL attribute.
			if ( 'core/image' === $block['blockName'] ) {
				if ( isset( $block['attrs']['url'] ) ) {
					$orig_url = $block['attrs']['url'];
				} else {
					// Sometimes the URL is only in the innerHTML, extract it.
					if ( preg_match( '/src=["\']([^"\']+)["\']/i', $block['innerHTML'], $m ) ) {
						$orig_url = $m[1];
					} else {
						$orig_url = '';
					}
				}

				if ( ! empty( $orig_url ) && ( ! $placeholders_only || $this->is_placeholder_url( $orig_url ) ) ) {
					$base_orig_url = preg_replace( '/[?&]cb=\d+/', '', $orig_url );

					if ( ! isset( $url_map[ $base_orig_url ] ) ) {
						$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
						$image_index++;
					}
					// Map both with and without cb to the same new base image.
					$url_map[ $orig_url ] = $url_map[ $base_orig_url ];

					if ( isset( $url_map[ $orig_url ] ) ) {
						$block['attrs']['url'] = $url_map[ $orig_url ];
						$block['attrs']['id']  = null;

						// Also update HTML content strings inside the block array.
						if ( ! empty( $block['innerHTML'] ) ) {
							$new_url = $url_map[ $orig_url ];
							// Add a random cache buster so images look "new" even if URL is same.
							if ( strpos( $new_url, 'cb=' ) === false ) {
								$new_url .= ( strpos( $new_url, '?' ) !== false ? '&' : '?' ) . 'cb=' . rand( 1000, 9999 );
							}
							$block['innerHTML'] = preg_replace_callback(
								'/(src=["\'])([^"\']+)(["\'])/i',
								function ( $matches ) use ( $new_url ) {
									return $matches[1] . $new_url . $matches[3];
								},
								$block['innerHTML']
							);
							$block['innerHTML'] = preg_replace( '/srcset=["\'][^"\']+["\']/i', '', $block['innerHTML'] );
						}
						if ( ! empty( $block['innerContent'] ) ) {
							foreach ( $block['innerContent'] as &$content_string ) {
								if ( is_string( $content_string ) ) {
									$new_url = $url_map[ $orig_url ];
									if ( strpos( $new_url, 'cb=' ) === false ) {
										$new_url .= ( strpos( $new_url, '?' ) !== false ? '&' : '?' ) . 'cb=' . rand( 1000, 9999 );
									}
									$content_string = preg_replace_callback(
										'/(src=["\'])([^"\']+)(["\'])/i',
										function ( $matches ) use ( $new_url ) {
											return $matches[1] . $new_url . $matches[3];
										},
										$content_string
									);
									$content_string = preg_replace_callback(
										'/"url":"([^"]+)"/i',
										function ( $matches ) use ( $new_url ) {
											return '"url":"' . $new_url . '"';
										},
										$content_string
									);
									$content_string = preg_replace( '/srcset=["\'][^"\']+["\']/i', '', $content_string );
								}
							}
						}
					}
				}
			}

			// Update wp:cover block URL attribute.
			if ( 'core/cover' === $block['blockName'] ) {
				if ( isset( $block['attrs']['url'] ) ) {
					$orig_url = $block['attrs']['url'];
				} else {
					$orig_url = '';
				}

				if ( ! empty( $orig_url ) && ( ! $placeholders_only || $this->is_placeholder_url( $orig_url ) ) ) {
					$base_orig_url = preg_replace( '/[?&]cb=\d+/', '', $orig_url );

					if ( ! isset( $url_map[ $base_orig_url ] ) ) {
						$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
						$image_index++;
					}
					// Map both with and without cb to the same new base image.
					$url_map[ $orig_url ] = $url_map[ $base_orig_url ];

					if ( isset( $url_map[ $orig_url ] ) ) {
						$block['attrs']['url'] = $url_map[ $orig_url ];
						$block['attrs']['id']  = null;

						// Also update HTML content strings inside the block array.
						if ( ! empty( $block['innerHTML'] ) ) {
							$new_url = $url_map[ $orig_url ];
							if ( strpos( $new_url, 'cb=' ) === false ) {
								$new_url .= ( strpos( $new_url, '?' ) !== false ? '&' : '?' ) . 'cb=' . rand( 1000, 9999 );
							}
							$block['innerHTML'] = preg_replace_callback(
								'/url\([\'"]?([^\'"]+)[\'"]?\)/i',
								function ( $matches ) use ( $new_url ) {
									return 'url(' . $new_url . ')';
								},
								$block['innerHTML']
							);
							$block['innerHTML'] = preg_replace_callback(
								'/(src=["\'])([^"\']+)(["\'])/i',
								function ( $matches ) use ( $new_url ) {
									return $matches[1] . $new_url . $matches[3];
								},
								$block['innerHTML']
							);
							$block['innerHTML'] = preg_replace( '/srcset=["\'][^"\']+["\']/i', '', $block['innerHTML'] );
						}
						if ( ! empty( $block['innerContent'] ) ) {
							foreach ( $block['innerContent'] as &$content_string ) {
								if ( is_string( $content_string ) ) {
									$new_url = $url_map[ $orig_url ];
									if ( strpos( $new_url, 'cb=' ) === false ) {
										$new_url .= ( strpos( $new_url, '?' ) !== false ? '&' : '?' ) . 'cb=' . rand( 1000, 9999 );
									}
									$content_string = preg_replace_callback(
										'/url\([\'"]?([^\'"]+)[\'"]?\)/i',
										function ( $matches ) use ( $new_url ) {
											return 'url(' . $new_url . ')';
										},
										$content_string
									);
									$content_string = preg_replace_callback(
										'/(src=["\'])([^"\']+)(["\'])/i',
										function ( $matches ) use ( $new_url ) {
											return $matches[1] . $new_url . $matches[3];
										},
										$content_string
									);
									$content_string = preg_replace_callback(
										'/"url":"([^"]+)"/i',
										function ( $matches ) use ( $new_url ) {
											return '"url":"' . $new_url . '"';
										},
										$content_string
									);
									$content_string = preg_replace( '/srcset=["\'][^"\']+["\']/i', '', $content_string );
								}
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
