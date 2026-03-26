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
		// Check separately so articles/filler words between verb and noun don't prevent matching
		// e.g. "change the images", "replace all photos", "update background images".
		$has_image_word   = (bool) preg_match( '/\b(images?|pictures?|photos?|pics?|backgrounds?)\b/i', $prompt_lower );
		$has_replace_verb = (bool) preg_match( '/\b(change|update|replace|swap|regenerate|refresh|new|different)\b/i', $prompt_lower );

		if ( $has_image_word && $has_replace_verb ) {
			// Only fast-path if there are images in the markup to replace.
			$has_images_in_markup = (bool) preg_match( '/<img\b|<!--\s*wp:(image|cover)\b/i', $current_markup );

			if ( $has_images_in_markup ) {
				$unsplash_images = $this->image_service->get_unsplash_images( $last_user_prompt );
				if ( ! empty( $unsplash_images ) ) {
					// Randomize array so identical queries do not always use the same first images.
					shuffle( $unsplash_images );
					$new_html = $this->image_service->replace_images_in_html( $current_markup, $unsplash_images );
					return $this->build_response( $new_html );
				}
				// Unsplash unavailable — return a message so the request doesn't fall through to AI,
				// which would ignore the image request due to its system prompt.
				return $this->build_message_response( 'Image search is currently unavailable. Please try again in a moment.' );
			}
			// No images in the current markup — fall through to AI so it can add image blocks.
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
