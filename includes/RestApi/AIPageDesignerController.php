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

			$current_markup = isset( $context['current_markup'] ) ? trim( $context['current_markup'] ) : '';
			$content_type   = isset( $context['content_type'] ) && 'post' === $context['content_type'] ? 'post' : 'page';
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
					$clean_msg   = explode( '--- BASE LAYOUT ---', $msg['content'] )[0];
					$clean_msg   = explode( '--- CURRENT TARGET LAYOUT ---', $clean_msg )[0];
					$all_prompts .= ' ' . trim( $clean_msg );
				}
			}

			// Combine the AI-generated title and prompts to get a richer context for image search.
			$search_context = '';
			if ( ! empty( $title_data['title'] ) ) {
				$search_context .= rtrim( $title_data['title'], ' -|' ) . ' ';
			}
			$search_context .= $all_prompts;

			// Only fetch images if we actually found placeholders that need replacing.
			$has_image_placeholders = (
				preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $final_html ) ||
				preg_match( '/<!-- wp:(image|cover)/i', $final_html ) ||
				preg_match( '/background-image:\s*url\(/i', $final_html ) ||
				preg_match( '/https?:\/\/images\.unsplash\.com\//i', $final_html ) ||
				preg_match( '/https?:\/\/(www\.)?unsplash\.com\//i', $final_html ) ||
				preg_match( '/https?:\/\/placehold\.co\//i', $final_html )
			);
			if ( $has_image_placeholders ) {
				$unsplash_images = $this->image_service->get_unsplash_images( $search_context );
				if ( ! empty( $unsplash_images ) ) {
					shuffle( $unsplash_images );
					$final_html = $this->image_service->replace_images_in_html( $final_html, $unsplash_images, true );
				}
			}

			$response_data = array(
				'content'          => $final_html,
				'title'            => $title_data['title'],
				'response_id'      => $response_id,
				'conversation_key' => $conversation_key,
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
	 * Get a randomized pattern layout based on user intent.
	 *
	 * @param string $user_prompt The user's request
	 * @return string The assembled block markup
	 */
	private function get_random_pattern_layout( $user_prompt ) {
		$prompt_lower = strtolower( $user_prompt );
		
		// Map common keywords to pattern categories
		$intent_mapping = array(
			'contact'     => array( 'hero', 'contact' ),
			'about'       => array( 'hero', 'about', 'team' ),
			'services'    => array( 'hero', 'services', 'call-to-action' ),
			'product'     => array( 'hero', 'gallery', 'call-to-action' ),
			'store'       => array( 'hero', 'gallery', 'call-to-action' ),
			'portfolio'   => array( 'hero', 'portfolio', 'contact' ),
			'blog'        => array( 'hero', 'text' ),
			'post'        => array( 'hero', 'text' ),
			'landing'     => array( 'hero', 'features', 'call-to-action' ),
		);

		// Default to a standard homepage layout if no specific intent is found
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
				$content = preg_replace( '/>\s+</', '><', $content ); // Remove spaces between tags
				$content = preg_replace( '/\s+/', ' ', $content ); // Replace multiple spaces with single space
				$layout .= trim( $content ) . "\n\n";
			}
		}

		// If for some reason we couldn't fetch patterns, fallback to empty string
		return $layout;
	}

	/**
	 * Build a theme context string from the active theme's color palette and typography.
	 *
	 * Reads wp_get_global_settings() (theme.json) and returns a prompt appendix
	 * instructing the AI to use the site's actual color slugs in block attributes
	 * instead of hardcoded hex values.
	 *
	 * @return string Theme context prompt string, or empty string if no palette found.
	 */
	private function get_theme_context_prompt() {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return '';
		}

		$settings = wp_get_global_settings();
		$lines    = array();

		// --- Color palette ---
		// Only use the theme-defined palette. Ignore the WordPress default palette
		// (vivid-red, luminous-orange, etc.) — it is too broad and leads to poor color choices.
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
				$lines[] = 'Active theme color palette — use these slugs in Gutenberg block backgroundColor/textColor attributes to match the site\'s brand. Do NOT use arbitrary hex values for backgrounds or text; use the slugs below:';
				foreach ( $palette as $swatch ) {
					$lines[] = sprintf( '  - slug: "%s" | name: %s | hex: %s', $swatch['slug'], $swatch['name'], $swatch['color'] );
				}
				$lines[] = 'Example: <!-- wp:button {"backgroundColor":"primary","textColor":"base"} -->';
				$lines[] = 'Example: <!-- wp:group {"align":"full","style":{"color":{"background":"var(--wp--preset--color--contrast)"}}} -->';
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
	 * Extract the PAGE_TITLE comment embedded in every AI HTML response.
	 *
	 * The AI is instructed to always start with: <!-- PAGE_TITLE: ... -->
	 * This method extracts that title, strips the comment from the HTML,
	 * and sanitizes the remaining block markup.
	 *
	 * @param string $content Raw AI response content.
	 * @return array { title: string, html: string }
	 */
	private function extract_page_title( $content ) {
		$title = '';
		$html  = $content;

		if ( preg_match( '/<!--\s*PAGE_TITLE:\s*(.+?)\s*-->/i', $content, $m ) ) {
			$title = trim( $m[1] );
			// Remove the title comment line from the HTML so it is not stored in WordPress content
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
	 *  1. Truncated/incomplete HTML comment at the end of the response
	 *     (e.g. the response was cut off mid-tag: "<!-- /wp:" or "<!-- wp:image {").
	 *  2. Unclosed block tags — uses a stack to detect opening blocks that were
	 *     never closed and appends the missing closing comments.
	 *
	 * @param string $content Block markup to sanitize.
	 * @return string Sanitized block markup.
	 */
	private function sanitize_block_content( $content ) {
		// 1. Remove any incomplete HTML comment that was never closed.
		//    The regex matches "<!--" followed by anything that does NOT contain "-->"
		//    which means the comment was truncated before its closing delimiter.
		$content = preg_replace( '/<!--(?![\s\S]*?-->)[\s\S]*$/u', '', $content );
		$content = trim( $content );

		// 2. Walk every Gutenberg block comment and track nesting with a stack.
		//    Pattern captures:
		//      group 1 — "/" for closing tags
		//      group 2 — block name (e.g. "columns", "wp:group/inner" etc.)
		//      group 3 — "/" at the end for self-closing tags
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
				// Self-closing blocks (e.g. <!-- wp:spacer /--> ) need no stack entry.
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

	/**
	 * Parse a [PUBLISH_READY] AI response into structured fields.
	 *
	 * Returns an array with keys: is_publish_ready, title, slug, description, type, html.
	 * If the content is not a [PUBLISH_READY] response, is_publish_ready is false
	 * and all other keys are empty strings.
	 *
	 * @param string $content Raw AI response content.
	 * @return array Parsed publish metadata and HTML.
	 */
	private function parse_publish_ready( $content ) {
		$result = array(
			'is_publish_ready' => false,
			'title'            => '',
			'slug'             => '',
			'description'      => '',
			'type'             => '',
			'html'             => '',
		);

		if ( strpos( $content, '[PUBLISH_READY]' ) === false ) {
			return $result;
		}

		$result['is_publish_ready'] = true;

		// Extract metadata fields
		if ( preg_match( '/^Title:\s*(.+)$/m', $content, $m ) ) {
			$result['title'] = trim( $m[1] );
		}
		if ( preg_match( '/^Slug:\s*(.+)$/m', $content, $m ) ) {
			$result['slug'] = trim( $m[1] );
		}
		if ( preg_match( '/^Description:\s*(.+)$/m', $content, $m ) ) {
			$result['description'] = trim( $m[1] );
		}
		if ( preg_match( '/^Type:\s*(.+)$/m', $content, $m ) ) {
			$result['type'] = strtolower( trim( $m[1] ) );
		}

		// Everything after the --- separator is the HTML content
		$separator_pos = strpos( $content, "\n---\n" );
		if ( false !== $separator_pos ) {
			$result['html'] = trim( substr( $content, $separator_pos + 5 ) );
		}

		return $result;
	}

	/**
	 * Exchange Hiive token for JWT token from JWT worker
	 *
	 * @param string $hiive_token The Hiive authentication token
	 * @return string|\WP_Error JWT token or WP_Error on failure
	 */
	private function get_jwt_token( $hiive_token ) {
		// Get brand from plugin or fallback to option
		$brand = get_option( 'mm_brand', 'web' );
		
		// Apply the AI SiteGen brand filter to allow brand mapping
		$brand = apply_filters( 'newfold_ai_sitegen_brand', $brand );
		
		// Prepare headers
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
		
		// Add brand as X-Newfold-Brand header if available (matches cf-worker-ai-sitegen pattern)
		if ( $brand ) {
			$headers['X-Newfold-Brand'] = $brand;
		}
		
		$test_token = "test-ai-sitegen";
		
		$response = wp_remote_post(
			'https://cf-worker-newfold-services-jwt.bluehost.workers.dev/',
			array(
				'headers' => $headers,
				'timeout' => 30,
				'body'    => wp_json_encode(
					array(
						'hiiveToken' => $test_token,
					)
				),
			)
		);

		$response_code = wp_remote_retrieve_response_code( $response );
		
		if ( 200 !== $response_code ) {
			return new \WP_Error(
				'jwt_generation_error',
				__( 'Failed to obtain JWT token from JWT worker', 'wp-module-ai-page-designer' ),
				array( 'status' => $response_code )
			);
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );
		
		if ( ! isset( $result['jwt'] ) ) {
			return new \WP_Error(
				'jwt_generation_error',
				__( 'Invalid response from JWT worker', 'wp-module-ai-page-designer' ),
				array( 'status' => 500 )
			);
		}
		return $result['jwt'];
	}

	/**
	 * Fetch images from Unsplash based on a query.
	 *
	 * @param string $query The search query.
	 * @return array Array of image URLs.
	 */
	private function get_unsplash_images( $query ) {
		$hiive_base_url = defined( 'NFD_HIIVE_BASE_URL' ) ? NFD_HIIVE_BASE_URL : 'https://hiive.cloud';
		$endpoint       = '/workers/unsplash/search/photos';
		
		// Clean up query: remove common conversational words to get better image results.
		// Also remove site/brand name tokens to avoid skewed image results.
		$stopwords = array('create', 'a', 'an', 'the', 'page', 'post', 'about', 'for', 'with', 'design', 'make', 'website', 'site', 'my', 'new', 'add', 'some', 'images', 'image', 'picture', 'photos', 'photo', 'update', 'modify', 'change', 'landing', 'home', 'homepage', 'contact', 'services', 'portfolio');
		$site_name = get_bloginfo( 'name' );
		if ( $site_name ) {
			$site_words = explode( ' ', strtolower( preg_replace( '/[^a-zA-Z0-9\s]/', '', $site_name ) ) );
			$stopwords  = array_merge( $stopwords, array_filter( $site_words ) );
		}
		$words = explode( ' ', strtolower( preg_replace( '/[^a-zA-Z0-9\s]/', '', $query ) ) );
		$keywords = array_diff( $words, $stopwords );
		$search_query = implode( ' ', array_slice( $keywords, 0, 4 ) );
		
		if ( empty( trim( $search_query ) ) ) {
			$search_query = 'nature'; // fallback
		}

		$args = array(
			'query'    => trim( $search_query ),
			'per_page' => 15, // Increase to 15 to have a better pool to randomize from
		);
		$transient_key = 'nfd_unsplash_' . md5( $args['query'] );
		$cached_images = get_transient( $transient_key );

		if ( false !== $cached_images && is_array( $cached_images ) && ! empty( $cached_images ) ) {
			return $cached_images;
		}

		$request_url = $hiive_base_url . $endpoint . '?' . http_build_query( $args );

		$response = wp_remote_get( $request_url, array( 'timeout' => 10 ) );
		$images = array();

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $body['results'] ) ) {
				foreach ( $body['results'] as $result ) {
					if ( ! empty( $result['urls']['regular'] ) ) {
						$images[] = $result['urls']['regular'];
					}
				}
				
				if ( ! empty( $images ) ) {
					// Cache the image results for an hour
					set_transient( $transient_key, $images, HOUR_IN_SECONDS );
				}
			}
		}

		return $images;
	}

	/**
	 * Replace image URLs in HTML content with Unsplash images using native WP parsers.
	 *
	 * @param string $html The HTML content containing images
	 * @param array $unsplash_images Array of Unsplash image URLs
	 * @return string The updated HTML
	 */
	private function replace_images_in_html( $html, $unsplash_images ) {
		if ( empty( $unsplash_images ) ) {
			return $html;
		}

		$image_index = 0;
		$total_images = count( $unsplash_images );
		$url_map = array();

		// 1. Replace URLs inside Gutenberg block comments safely using parse_blocks
		$blocks = parse_blocks( $html );
		
		// We only need to process if there are actual blocks
		if ( ! empty( $blocks ) ) {
			$this->update_block_images_recursive( $blocks, $url_map, $unsplash_images, $image_index, $total_images );
			
			// Rebuild HTML from blocks
			$html = '';
			foreach ( $blocks as $block ) {
				$html .= serialize_blocks( array($block) );
			}
		}

		// 2. Replace <img> src attributes safely using WP_HTML_Tag_Processor
		// Doing this AFTER parse_blocks/serialize_blocks to catch any stragglers 
		// that weren't inside a core/image or core/cover block's specific attrs
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$tags = new \WP_HTML_Tag_Processor( $html );
			while ( $tags->next_tag( 'img' ) ) {
				$orig_url = $tags->get_attribute( 'src' );
				if ( $orig_url ) {
					$base_orig_url = preg_replace('/[?&]cb=\d+/', '', $orig_url);
					if ( ! isset( $url_map[ $base_orig_url ] ) ) {
						$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
						$image_index++;
					}
					$url_map[ $orig_url ] = $url_map[ $base_orig_url ];
					
					if ( isset( $url_map[ $orig_url ] ) ) {
						$new_url = $url_map[ $orig_url ];
						if ( strpos($new_url, 'cb=') === false ) {
							$new_url .= (strpos($new_url, '?') !== false ? '&' : '?') . 'cb=' . rand(1000, 9999);
						}
						$tags->set_attribute( 'src', $new_url );
					}
					// Remove srcset if it exists so browser falls back to src
					$tags->remove_attribute( 'srcset' );
				}
			}
			$html = $tags->get_updated_html();
			
			// Also replace inline styles for background images
			$html = preg_replace_callback('/background-image:\s*url\([\'"]?([^\'"]+)[\'"]?\)/i', function($matches) use (&$image_index, &$url_map, $unsplash_images, $total_images) {
				$orig_url = $matches[1];
				
				$base_orig_url = preg_replace('/[?&]cb=\d+/', '', $orig_url);
				if ( ! isset( $url_map[ $base_orig_url ] ) ) {
					$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
					$image_index++;
				}
				$url_map[ $orig_url ] = $url_map[ $base_orig_url ];
				
				$new_url = $url_map[ $orig_url ];
				if ( strpos($new_url, 'cb=') === false ) {
					$new_url .= (strpos($new_url, '?') !== false ? '&' : '?') . 'cb=' . rand(1000, 9999);
				}
				return 'background-image: url(' . $new_url . ')';
			}, $html);
			
		} else {
			// Fallback if WP_HTML_Tag_Processor doesn't exist (older WP versions)
			$html = preg_replace_callback( '/<img[^>]+src=["\']([^"\']+)["\']/i', function( $matches ) use ( &$image_index, &$url_map, $unsplash_images, $total_images ) {
				$orig_url = $matches[1];
				
				$base_orig_url = preg_replace('/[?&]cb=\d+/', '', $orig_url);
				if ( ! isset( $url_map[ $base_orig_url ] ) ) {
					$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
					$image_index++;
				}
				$url_map[ $orig_url ] = $url_map[ $base_orig_url ];
				
				$new_url = $url_map[ $orig_url ];
				if ( strpos($new_url, 'cb=') === false ) {
					$new_url .= (strpos($new_url, '?') !== false ? '&' : '?') . 'cb=' . rand(1000, 9999);
				}
				return str_replace( $orig_url, $new_url, $matches[0] );
			}, $html );
			// Also strip srcset from fallback since we don't have srcset unsplash URLs
			$html = preg_replace('/srcset=["\'][^"\']+["\']/i', '', $html);
		}

		// Fallback for any other remaining images using regex just in case
		$html = preg_replace_callback( '/(<img[^>]+src=["\'])([^"\']+)["\']/', function( $matches ) use ( &$url_map, $unsplash_images, &$image_index, $total_images ) {
			$orig_url = $matches[2];
			
			$base_orig_url = preg_replace('/[?&]cb=\d+/', '', $orig_url);
			if ( ! isset( $url_map[ $base_orig_url ] ) ) {
				$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
				$image_index++;
			}
			$url_map[ $orig_url ] = $url_map[ $base_orig_url ];
			
			$new_url = $url_map[ $orig_url ];
			if ( strpos($new_url, 'cb=') === false ) {
				$new_url .= (strpos($new_url, '?') !== false ? '&' : '?') . 'cb=' . rand(1000, 9999);
			}
			return $matches[1] . $new_url . '"';
		}, $html );
		
		// Remove all srcset attributes to prevent old images from showing
		$html = preg_replace('/srcset=["\'][^"\']+["\']/i', '', $html);

		return trim($html);
	}

	/**
	 * Recursively update image URLs in parsed Gutenberg blocks
	 *
	 * @param array &$blocks Parsed blocks array
	 * @param array &$url_map Map of original to new URLs
	 * @param array $unsplash_images Array of available Unsplash URLs
	 * @param int &$image_index Current index in the Unsplash array
	 * @param int $total_images Total number of Unsplash images
	 */
	private function update_block_images_recursive( &$blocks, &$url_map, $unsplash_images, &$image_index, $total_images ) {
		foreach ( $blocks as &$block ) {
			// Check if block has an attrs array
			if ( ! isset( $block['attrs'] ) ) {
				$block['attrs'] = array();
			}
			
			// Update wp:image block URL attribute
			if ( 'core/image' === $block['blockName'] ) {
				if ( isset( $block['attrs']['url'] ) ) {
					$orig_url = $block['attrs']['url'];
				} else {
					// Sometimes the URL is only in the innerHTML, extract it
					if ( preg_match('/src=["\']([^"\']+)["\']/i', $block['innerHTML'], $m) ) {
						$orig_url = $m[1];
					} else {
						$orig_url = '';
					}
				}
				
				// Make sure we have a URL to map
				if ( ! empty( $orig_url ) ) {
					$base_orig_url = preg_replace('/[?&]cb=\d+/', '', $orig_url);
					
					if ( ! isset( $url_map[ $base_orig_url ] ) ) {
						$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
						$image_index++;
					}
					// Map both with and without cb to the same new base image
					$url_map[ $orig_url ] = $url_map[ $base_orig_url ];
					
					if ( isset( $url_map[ $orig_url ] ) ) {
						$block['attrs']['url'] = $url_map[ $orig_url ];
						$block['attrs']['id'] = null; // Remove the old ID to prevent block validation errors and forcing it to look new
						
						// Also update HTML content strings inside the block array
						if ( ! empty( $block['innerHTML'] ) ) {
							$new_url = $url_map[ $orig_url ];
							// Add a random cache buster so images look "new" even if URL is same
							if ( strpos($new_url, 'cb=') === false ) {
								$new_url .= (strpos($new_url, '?') !== false ? '&' : '?') . 'cb=' . rand(1000, 9999);
							}
							$block['innerHTML'] = preg_replace_callback('/(src=["\'])([^"\']+)(["\'])/i', function($matches) use ($new_url) {
								return $matches[1] . $new_url . $matches[3];
							}, $block['innerHTML']);
							// Check for img src inside srcset as well
							$block['innerHTML'] = preg_replace('/srcset=["\'][^"\']+["\']/i', '', $block['innerHTML']);
						}
						if ( ! empty( $block['innerContent'] ) ) {
							foreach ( $block['innerContent'] as &$content_string ) {
								if ( is_string( $content_string ) ) {
									$new_url = $url_map[ $orig_url ];
									if ( strpos($new_url, 'cb=') === false ) {
										$new_url .= (strpos($new_url, '?') !== false ? '&' : '?') . 'cb=' . rand(1000, 9999);
									}
									// Update to correctly replace inside Gutenberg comments and image tags in innerContent
									$content_string = preg_replace_callback('/(src=["\'])([^"\']+)(["\'])/i', function($matches) use ($new_url) {
										return $matches[1] . $new_url . $matches[3];
									}, $content_string);
									// Catch innerContent comments that store the raw image block before parsing
									$content_string = preg_replace_callback('/"url":"([^"]+)"/i', function($matches) use ($new_url) {
										return '"url":"' . $new_url . '"';
									}, $content_string);
									$content_string = preg_replace('/srcset=["\'][^"\']+["\']/i', '', $content_string);
								}
							}
						}
					}
				}
			}
			
			// Update wp:cover block URL attribute
			if ( 'core/cover' === $block['blockName'] ) {
				if ( isset( $block['attrs']['url'] ) ) {
					$orig_url = $block['attrs']['url'];
				} else {
					$orig_url = '';
				}
				
				if ( ! empty( $orig_url ) ) {
					$base_orig_url = preg_replace('/[?&]cb=\d+/', '', $orig_url);
					
					if ( ! isset( $url_map[ $base_orig_url ] ) ) {
						$url_map[ $base_orig_url ] = $unsplash_images[ $image_index % $total_images ];
						$image_index++;
					}
					// Map both with and without cb to the same new base image
					$url_map[ $orig_url ] = $url_map[ $base_orig_url ];
					
					if ( isset( $url_map[ $orig_url ] ) ) {
						$block['attrs']['url'] = $url_map[ $orig_url ];
						$block['attrs']['id'] = null; // Remove the old ID to prevent block validation errors and forcing it to look new
						
						// Also update HTML content strings inside the block array
						if ( ! empty( $block['innerHTML'] ) ) {
							$new_url = $url_map[ $orig_url ];
							if ( strpos($new_url, 'cb=') === false ) {
								$new_url .= (strpos($new_url, '?') !== false ? '&' : '?') . 'cb=' . rand(1000, 9999);
							}
							$block['innerHTML'] = preg_replace_callback('/url\([\'"]?([^\'"]+)[\'"]?\)/i', function($matches) use ($new_url) {
								return 'url(' . $new_url . ')';
							}, $block['innerHTML']);
							$block['innerHTML'] = preg_replace_callback('/(src=["\'])([^"\']+)(["\'])/i', function($matches) use ($new_url) {
								return $matches[1] . $new_url . $matches[3];
							}, $block['innerHTML']);
							$block['innerHTML'] = preg_replace('/srcset=["\'][^"\']+["\']/i', '', $block['innerHTML']);
						}
						if ( ! empty( $block['innerContent'] ) ) {
							foreach ( $block['innerContent'] as &$content_string ) {
								if ( is_string( $content_string ) ) {
									$new_url = $url_map[ $orig_url ];
									if ( strpos($new_url, 'cb=') === false ) {
										$new_url .= (strpos($new_url, '?') !== false ? '&' : '?') . 'cb=' . rand(1000, 9999);
									}
									$content_string = preg_replace_callback('/url\([\'"]?([^\'"]+)[\'"]?\)/i', function($matches) use ($new_url) {
										return 'url(' . $new_url . ')';
									}, $content_string);
									$content_string = preg_replace_callback('/(src=["\'])([^"\']+)(["\'])/i', function($matches) use ($new_url) {
										return $matches[1] . $new_url . $matches[3];
									}, $content_string);
									$content_string = preg_replace_callback('/"url":"([^"]+)"/i', function($matches) use ($new_url) {
										return '"url":"' . $new_url . '"';
									}, $content_string);
									$content_string = preg_replace('/srcset=["\'][^"\']+["\']/i', '', $content_string);
								}
							}
						}
					}
				}
			}

			// Process inner blocks recursively
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->update_block_images_recursive( $block['innerBlocks'], $url_map, $unsplash_images, $image_index, $total_images );
			}
		}
	}

	/**
	 * Recursively update block themes
	 *
	 * @param array &$blocks Parsed blocks array
	 * @param string $theme_mode The requested theme mode (dark, light, etc)
	 */
	private function update_block_theme_recursive( &$blocks, $theme_mode ) {
		// Map user intent to our standard theme slugs
		$target_slug = 'white';
		if ( in_array( $theme_mode, array( 'dark', 'black' ) ) ) {
			$target_slug = 'dark';
		} elseif ( in_array( $theme_mode, array( 'blue', 'red', 'green', 'yellow' ) ) ) {
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
	 * Check permissions for routes.
	 *
	 * @return bool|\WP_Error True if user has permission, WP_Error otherwise
	 */
	public function check_permission() {
		return CapabilityGate::rest_permission();
	}
}
