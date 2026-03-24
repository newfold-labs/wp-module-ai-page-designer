<?php
/**
 * Fast-path handling for AI Page Designer.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\Services
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services;

/**
 * Handles image replacement and theme-change requests without calling AI.
 */
class FastPathHandler {

	/**
	 * Image service.
	 *
	 * @var ImageService
	 */
	private $image_service;

	/**
	 * Constructor.
	 *
	 * @param ImageService|null $image_service Image service.
	 */
	public function __construct( ?ImageService $image_service = null ) {
		$this->image_service = $image_service ?: new ImageService();
	}

	/**
	 * Attempt to handle the request without calling the AI service.
	 *
	 * @param string $current_markup Current block markup.
	 * @param string $last_user_prompt Latest user prompt.
	 * @return \WP_REST_Response|null
	 */
	public function maybe_handle_fast_path( $current_markup, $last_user_prompt ) {
		if ( empty( $current_markup ) ) {
			return null;
		}

		$prompt_lower = strtolower( $last_user_prompt );

		// 1. Image Replacement Intent.
		if ( preg_match( '/\b(change|update|new|different|regenerate|replace|swap|add)\s+(images?|pictures?|photos?|pics?|backgrounds?)\b/i', $prompt_lower ) ) {
			$unsplash_images = $this->image_service->get_unsplash_images( $last_user_prompt );
			if ( ! empty( $unsplash_images ) ) {
				// Randomize array so identical queries do not always use the same first images.
				shuffle( $unsplash_images );

				$new_html = $this->image_service->replace_images_in_html( $current_markup, $unsplash_images );

				return $this->build_response( $new_html );
			}
		}

		// 2. Color/Theme Change Intent.
		if (
			preg_match( '/\b(change|make|use|switch to)\s+(dark|light|white|black|blue|red|green|yellow)\s*(mode|theme|colors?|background)?\b/i', $prompt_lower, $matches ) ||
			preg_match( '/\b(make it)\s+(dark|light|white|black|blue|red|green|yellow)\b/i', $prompt_lower, $matches )
		) {
			$theme_mode = $matches[2];

			$blocks = parse_blocks( $current_markup );
			if ( ! empty( $blocks ) ) {
				$this->update_block_theme_recursive( $blocks, $theme_mode );

				// Remove empty parsed blocks.
				$blocks = array_filter(
					$blocks,
					function ( $block ) {
						return ! empty( trim( $block['innerHTML'] ) ) || ! empty( $block['innerBlocks'] );
					}
				);

				$new_html = serialize_blocks( $blocks );

				return $this->build_response( $new_html );
			}
		}

		return null;
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
	 * Recursively update block themes.
	 *
	 * @param array  &$blocks Parsed blocks array.
	 * @param string $theme_mode The requested theme mode.
	 * @return void
	 */
	private function update_block_theme_recursive( &$blocks, $theme_mode ) {
		$target_slug = 'white';
		if ( in_array( $theme_mode, array( 'dark', 'black' ), true ) ) {
			$target_slug = 'dark';
		} elseif ( in_array( $theme_mode, array( 'blue', 'red', 'green', 'yellow' ), true ) ) {
			$target_slug = 'primary';
		}

		foreach ( $blocks as &$block ) {
			if ( isset( $block['attrs']['nfdGroupTheme'] ) ) {
				$old_slug = $block['attrs']['nfdGroupTheme'];

				$block['attrs']['nfdGroupTheme'] = $target_slug;

				if ( ! empty( $block['innerHTML'] ) ) {
					$block['innerHTML'] = str_replace(
						'is-style-nfd-theme-' . $old_slug,
						'is-style-nfd-theme-' . $target_slug,
						$block['innerHTML']
					);
				}

				if ( ! empty( $block['innerContent'] ) ) {
					foreach ( $block['innerContent'] as &$content_string ) {
						if ( is_string( $content_string ) ) {
							$content_string = str_replace(
								'is-style-nfd-theme-' . $old_slug,
								'is-style-nfd-theme-' . $target_slug,
								$content_string
							);
						}
					}
				}
			} else {
				if ( ! empty( $block['innerHTML'] ) && strpos( $block['innerHTML'], 'is-style-nfd-theme-' ) !== false ) {
					$block['innerHTML'] = preg_replace(
						'/is-style-nfd-theme-(white|dark|primary|secondary|tertiary|quaternary)/',
						'is-style-nfd-theme-' . $target_slug,
						$block['innerHTML']
					);
				}
				if ( ! empty( $block['innerContent'] ) ) {
					foreach ( $block['innerContent'] as &$content_string ) {
						if ( is_string( $content_string ) && strpos( $content_string, 'is-style-nfd-theme-' ) !== false ) {
							$content_string = preg_replace(
								'/is-style-nfd-theme-(white|dark|primary|secondary|tertiary|quaternary)/',
								'is-style-nfd-theme-' . $target_slug,
								$content_string
							);
						}
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->update_block_theme_recursive( $block['innerBlocks'], $theme_mode );
			}
		}
	}
}
