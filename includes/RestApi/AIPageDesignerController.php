<?php
/**
 * AI Page Designer Controller
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\RestApi
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\RestApi;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\AiClient;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\BlockMarkupSanitizer;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\CapabilityGate;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\FastPathHandler;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\ImageService;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PatternLayoutProvider;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PromptBuilder;

/**
 * REST API Controller for AI Page Generation
 */
class AIPageDesignerController extends \WP_REST_Controller {

	/**
	 * The namespace of this controller's route.
	 *
	 * @var string
	 */
	protected $namespace = 'newfold-ai-page-designer/v1';

	/**
	 * The base of this controller's route
	 *
	 * @var string
	 */
	protected $rest_base = 'generate';

	/**
	 * Prompt builder.
	 *
	 * @var PromptBuilder
	 */
	private $prompt_builder;

	/**
	 * AI client.
	 *
	 * @var AiClient
	 */
	private $ai_client;

	/**
	 * Image service.
	 *
	 * @var ImageService
	 */
	private $image_service;

	/**
	 * Markup sanitizer.
	 *
	 * @var BlockMarkupSanitizer
	 */
	private $block_markup_sanitizer;

	/**
	 * Fast path handler.
	 *
	 * @var FastPathHandler
	 */
	private $fast_path_handler;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->prompt_builder         = new PromptBuilder( new PatternLayoutProvider() );
		$this->ai_client              = new AiClient();
		$this->image_service          = new ImageService();
		$this->block_markup_sanitizer = new BlockMarkupSanitizer();
		$this->fast_path_handler      = new FastPathHandler( $this->image_service, $this->ai_client );
	}

	/**
	 * Register the routes for this controller
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate_content' ),
					'args'                => array(
						'messages' => array(
							'required'          => true,
							'type'              => 'array',
							'description'       => __( 'Array of conversation messages', 'wp-module-ai-page-designer' ),
							'validate_callback' => array( $this, 'validate_messages' ),
						),
						'context'  => array(
							'required'          => false,
							'type'              => 'object',
							'description'       => __( 'Additional context like current markup', 'wp-module-ai-page-designer' ),
							'validate_callback' => array( $this, 'validate_context' ),
						),
					),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Validate messages array
	 *
	 * @param mixed $messages The messages to validate
	 * @return bool True if valid
	 */
	public function validate_messages( $messages ) {
		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return false;
		}

		foreach ( $messages as $message ) {
			if ( ! isset( $message['role'] ) || ! isset( $message['content'] ) ) {
				return false;
			}

			if ( ! in_array( $message['role'], array( 'user', 'assistant', 'system' ), true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate optional context payload.
	 *
	 * @param mixed $context The context to validate.
	 * @return bool True if valid.
	 */
	public function validate_context( $context ) {
		if ( null === $context || '' === $context ) {
			return true;
		}

		if ( ! is_array( $context ) ) {
			return false;
		}

		if ( isset( $context['post_id'] ) ) {
			if ( ! is_numeric( $context['post_id'] ) || (int) $context['post_id'] < 1 ) {
				return false;
			}
		}

		if ( ! isset( $context['post_id'] ) && isset( $context['conversation_id'] ) ) {
			if ( ! is_string( $context['conversation_id'] ) || ! $this->is_valid_uuid_v4( $context['conversation_id'] ) ) {
				return false;
			}
		}

		if ( isset( $context['theme_mode'] ) ) {
			if ( ! is_string( $context['theme_mode'] ) ) {
				return false;
			}

			$theme_mode = sanitize_key( $context['theme_mode'] );
			if ( '' === $theme_mode ) {
				return false;
			}

			$allowed_modes = array( 'dark', 'black', 'blue', 'red', 'green', 'yellow', 'white' );
			if ( ! in_array( $theme_mode, $allowed_modes, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Generate content using AI
	 *
	 * @param \WP_REST_Request $request The REST request
	 * @return \WP_REST_Response|\WP_Error The response
	 */
	public function generate_content( \WP_REST_Request $request ) {
		try {
			$messages = $request['messages'];
			$context  = is_array( $request['context'] ?? null ) ? $request['context'] : array();

			$conversation_context = $this->get_conversation_context( $context );
			if ( is_wp_error( $conversation_context ) ) {
				return $conversation_context;
			}

			$conversation_key = $conversation_context['conversation_key'];
			$conversation_id  = $conversation_context['conversation_id'];

			$current_markup   = isset( $context['current_markup'] ) ? trim( $context['current_markup'] ) : '';
			$content_type     = isset( $context['content_type'] ) && 'post' === $context['content_type'] ? 'post' : 'page';
			$last_user_prompt = '';

			for ( $index = count( $messages ) - 1; $index >= 0; $index-- ) {
				if ( isset( $messages[ $index ]['role'] ) && 'user' === $messages[ $index ]['role'] ) {
					$last_user_prompt = $messages[ $index ]['content'] ?? '';
					break;
				}
			}

			$fast_path_response = $this->fast_path_handler->maybe_handle_fast_path( $current_markup, $last_user_prompt );
			if ( $fast_path_response ) {
				return $fast_path_response;
			}

			$ai_messages          = $this->prompt_builder->build_ai_messages( $messages, $current_markup, $content_type );
			$previous_response_id = $this->load_previous_response_id( $conversation_key );
			$ai_result            = $this->ai_client->generate_content(
				$ai_messages,
				array(
					'previous_response_id' => $previous_response_id,
				)
			);

			// If the stored response_id was stale/expired, clear it and retry without it.
			if ( is_wp_error( $ai_result ) && $previous_response_id ) {
				$error_message = $ai_result->get_error_message();
				if ( strpos( $error_message, 'not found' ) !== false || strpos( $error_message, 'unable to process' ) !== false ) {
					delete_transient( 'nfd_ai_pd_conv_' . $conversation_key );
					$ai_result = $this->ai_client->generate_content( $ai_messages, array() );
				}
			}

			if ( is_wp_error( $ai_result ) ) {
				return $ai_result;
			}

			$content     = $ai_result['content'] ?? '';
			$response_id = $ai_result['response_id'] ?? '';

			if ( empty( $response_id ) ) {
				return new \WP_Error(
					'ai_generation_error',
					__( 'AI response missing response_id', 'wp-module-ai-page-designer' ),
					array( 'status' => 500 )
				);
			}

			$this->store_response_id( $conversation_key, $response_id );

			$title_data = $this->block_markup_sanitizer->extract_page_title( $content );
			$final_html = $title_data['html'];

			// We didn't fetch images beforehand. Let's try doing it after using the AI's title and all prompts.
			$all_prompts = '';
			foreach ( $messages as $msg ) {
				if ( 'user' === ( $msg['role'] ?? '' ) ) {
					// Don't include the base layout markup we append in the system prompt.
					$clean_msg    = explode( '--- BASE LAYOUT ---', $msg['content'] )[0];
					$clean_msg    = explode( '--- CURRENT TARGET LAYOUT ---', $clean_msg )[0];
					$all_prompts .= ' ' . trim( $clean_msg );
				}
			}

			// Combine the AI-generated title and prompts to get a richer context for image search.
			$search_context = '';
			if ( ! empty( $title_data['title'] ) ) {
				$search_context .= rtrim( $title_data['title'], ' -|' ) . ' ';
			}
			$search_context .= $all_prompts;

			// Replace images only when the user explicitly asks for it.
			$has_images_in_markup = false;
			$blocks               = parse_blocks( $final_html );
			if ( ! empty( $blocks ) ) {
				$has_images_in_markup = $this->has_image_blocks( $blocks );
			}
			$featured_image_url = '';
			$wants_images       = (bool) preg_match( '/\b(image|images|photo|photos|picture|pictures|gallery|replace image|replace images|swap image|swap images|change image|change images)\b/i', $last_user_prompt );
			if ( ! $wants_images && ! empty( $current_markup ) ) {
				$final_html = $this->restore_image_urls( $final_html, $current_markup );
			} elseif ( $wants_images && $has_images_in_markup ) {
				$unsplash_images = $this->image_service->get_unsplash_images( $search_context );
				if ( ! empty( $unsplash_images ) ) {
					$featured_image_url = $unsplash_images[0];
					shuffle( $unsplash_images );
					$final_html = $this->image_service->replace_images_in_html( $final_html, $unsplash_images, true );
				}
			}

			$theme_mode = isset( $context['theme_mode'] ) ? sanitize_key( $context['theme_mode'] ) : '';
			if ( $theme_mode ) {
				$blocks = parse_blocks( $final_html );
				if ( ! empty( $blocks ) ) {
					$this->update_block_theme_recursive( $blocks, $theme_mode );
					$final_html = '';
					foreach ( $blocks as $block ) {
						$final_html .= serialize_blocks( array( $block ) );
					}
				}
			}

			$response_data = array(
				'content'            => $final_html,
				'title'              => $title_data['title'],
				'excerpt'            => $title_data['excerpt'] ?? '',
				'summary'            => $title_data['summary'] ?? '',
				'featured_image_url' => $featured_image_url,
				'response_id'        => $response_id,
				'conversation_key'   => $conversation_key,
			);

			if ( ! empty( $conversation_id ) ) {
				$response_data['conversation_id'] = $conversation_id;
			}

			return new \WP_REST_Response(
				array(
					'data' => $response_data,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'server_error',
				// translators: %s is the error message
				sprintf( __( 'AI generation failed: %s', 'wp-module-ai-page-designer' ), $e->getMessage() ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Resolve conversation key and id from context.
	 *
	 * @param array $context Context data.
	 * @return array|\WP_Error
	 */
	private function get_conversation_context( array $context ) {
		if ( isset( $context['post_id'] ) ) {
			$post_id = (int) $context['post_id'];
			$post    = get_post( $post_id );

			if ( ! $post ) {
				return new \WP_Error(
					'ai_post_not_found',
					__( 'Post not found.', 'wp-module-ai-page-designer' ),
					array( 'status' => 404 )
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new \WP_Error(
					'rest_forbidden',
					__( 'You are not allowed to edit this post.', 'wp-module-ai-page-designer' ),
					array( 'status' => 403 )
				);
			}

			return array(
				'conversation_key' => 'post-' . $post_id,
				'conversation_id'  => null,
			);
		}

		if ( isset( $context['conversation_id'] ) && '' !== $context['conversation_id'] ) {
			$conversation_id = (string) $context['conversation_id'];
			if ( ! $this->is_valid_uuid_v4( $conversation_id ) ) {
				return new \WP_Error(
					'ai_invalid_conversation_id',
					__( 'Invalid conversation_id format.', 'wp-module-ai-page-designer' ),
					array( 'status' => 400 )
				);
			}

			return array(
				'conversation_key' => 'conv-' . $conversation_id,
				'conversation_id'  => $conversation_id,
			);
		}

		$conversation_id = wp_generate_uuid4();

		return array(
			'conversation_key' => 'conv-' . $conversation_id,
			'conversation_id'  => $conversation_id,
		);
	}

	/**
	 * Validate UUID v4 string.
	 *
	 * @param string $value Value to validate.
	 * @return bool
	 */
	private function is_valid_uuid_v4( $value ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
	}

	/**
	 * Load previous response ID from transient.
	 *
	 * @param string $conversation_key Conversation key.
	 * @return string|null
	 */
	private function load_previous_response_id( $conversation_key ) {
		$previous_response_id = get_transient( 'nfd_ai_pd_conv_' . $conversation_key );
		return is_string( $previous_response_id ) ? $previous_response_id : null;
	}

	/**
	 * Store response ID in transient.
	 *
	 * @param string $conversation_key Conversation key.
	 * @param string $response_id Response ID.
	 * @return void
	 */
	private function store_response_id( $conversation_key, $response_id ) {
		set_transient( 'nfd_ai_pd_conv_' . $conversation_key, $response_id, DAY_IN_SECONDS );
	}

	/**
	 * Recursively update block themes
	 *
	 * @param array  &$blocks Parsed blocks array
	 * @param string $theme_mode The requested theme mode (dark, light, etc)
	 */
	private function update_block_theme_recursive( &$blocks, $theme_mode ) {
		// Map user intent to our standard theme slugs
		$target_slug = 'white';
		if ( in_array( $theme_mode, array( 'dark', 'black' ), true ) ) {
			$target_slug = 'dark';
		} elseif ( in_array( $theme_mode, array( 'blue', 'red', 'green', 'yellow' ), true ) ) {
			$target_slug = 'primary';
		}

		foreach ( $blocks as &$block ) {
			// Try to find ANY group block or block that might have our theme classes
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
				// Even if it doesn't have the nfdGroupTheme attr, maybe it has the class in the raw HTML
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

			// Process inner blocks recursively
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->update_block_theme_recursive( $block['innerBlocks'], $theme_mode );
			}
		}
	}

	/**
	 * Detect whether parsed blocks contain image/cover usage.
	 *
	 * @param array $blocks Parsed blocks array.
	 * @return bool
	 */
	private function has_image_blocks( array $blocks ) {
		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';
			if ( in_array( $block_name, array( 'core/image', 'core/cover', 'core/gallery', 'core/media-text' ), true ) ) {
				if ( ! empty( $block['attrs']['url'] ) ) {
					return true;
				}
				if ( ! empty( $block['innerHTML'] ) && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $block['innerHTML'] ) ) {
					return true;
				}
				if ( ! empty( $block['innerHTML'] ) && preg_match( '/background-image:\s*url\(/i', $block['innerHTML'] ) ) {
					return true;
				}
				if ( ! empty( $block['innerContent'] ) ) {
					foreach ( $block['innerContent'] as $content_string ) {
						if ( is_string( $content_string ) && preg_match( '/(src=["\']|background-image:\s*url\()/i', $content_string ) ) {
							return true;
						}
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && $this->has_image_blocks( $block['innerBlocks'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Restore original image URLs when the user did not request image changes.
	 *
	 * @param string $final_html Updated markup from the AI.
	 * @param string $current_markup Original markup before edits.
	 * @return string
	 */
	private function restore_image_urls( $final_html, $current_markup ) {
		$original_urls = $this->extract_image_urls( $current_markup );
		$updated_urls  = $this->extract_image_urls( $final_html );

		if ( empty( $original_urls ) || empty( $updated_urls ) ) {
			return $final_html;
		}

		$max = min( count( $original_urls ), count( $updated_urls ) );
		for ( $i = 0; $i < $max; $i++ ) {
			if ( $original_urls[ $i ] !== $updated_urls[ $i ] ) {
				$final_html = str_replace( $updated_urls[ $i ], $original_urls[ $i ], $final_html );
			}
		}

		return $final_html;
	}

	/**
	 * Extract image URLs from block markup in document order.
	 *
	 * @param string $markup Gutenberg block markup.
	 * @return string[]
	 */
	private function extract_image_urls( $markup ) {
		$urls   = array();
		$blocks = parse_blocks( $markup );

		if ( empty( $blocks ) ) {
			return $urls;
		}

		$stack = $blocks;
		while ( ! empty( $stack ) ) {
			$block = array_shift( $stack );
			$block_name = $block['blockName'] ?? '';

			if ( in_array( $block_name, array( 'core/image', 'core/cover', 'core/gallery', 'core/media-text' ), true ) ) {
				if ( ! empty( $block['attrs']['url'] ) ) {
					$urls[] = $block['attrs']['url'];
				}
				if ( ! empty( $block['innerHTML'] ) ) {
					if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $block['innerHTML'], $matches ) ) {
						foreach ( $matches[1] as $url ) {
							$urls[] = $url;
						}
					}
					if ( preg_match_all( '/background-image:\s*url\([\'"]?([^\'"]+)[\'"]?\)/i', $block['innerHTML'], $matches ) ) {
						foreach ( $matches[1] as $url ) {
							$urls[] = $url;
						}
					}
				}
				if ( ! empty( $block['innerContent'] ) ) {
					foreach ( $block['innerContent'] as $content_string ) {
						if ( is_string( $content_string ) ) {
							if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content_string, $matches ) ) {
								foreach ( $matches[1] as $url ) {
									$urls[] = $url;
								}
							}
							if ( preg_match_all( '/background-image:\s*url\([\'"]?([^\'"]+)[\'"]?\)/i', $content_string, $matches ) ) {
								foreach ( $matches[1] as $url ) {
									$urls[] = $url;
								}
							}
						}
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				foreach ( $block['innerBlocks'] as $inner ) {
					$stack[] = $inner;
				}
			}
		}

		return $urls;
	}
	/**
	 * Check permissions for routes.
	 *
	 * @return bool|\WP_Error True if user has permission, WP_Error otherwise
	 */
	public function check_permission() {
		return CapabilityGate::rest_permission();
	}
}
