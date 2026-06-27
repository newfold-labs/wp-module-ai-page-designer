<?php
/**
 * AI Page Designer Controller
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\RestApi
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\RestApi;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\AiClientWorker;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\CapabilityGate;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\FastPathHandler;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\ImageService;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PatternLayoutProvider;
use Web\AIPageDesignerDebug;

/**
 * REST API Controller for AI Page Generation
 */
class AIPageDesignerController extends \WP_REST_Controller {

	/**
	 * Maximum combined characters of page + selected-block markup sent to the AI backend.
	 *
	 * A structurally broken page can collapse into one oversized block (e.g. every section
	 * nested inside the hero), so a single-block edit would ship the whole page and overflow
	 * the Worker/model budget — surfacing as an opaque 500. ~40k chars (~10k tokens) leaves
	 * room for the system prompt and theme context.
	 *
	 * @var int
	 */
	const MAX_AI_MARKUP_CHARS = 40000;

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
	 * Pattern layout provider.
	 *
	 * @var PatternLayoutProvider
	 */
	private $pattern_layout_provider;

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
		$this->pattern_layout_provider = new PatternLayoutProvider();

		// Always use Worker client for AI Page Designer
		AIPageDesignerDebug::debug_log( 'Using Worker-based AI client' );
		$this->ai_client = new AiClientWorker();

		$this->image_service     = new ImageService();
		$this->fast_path_handler = new FastPathHandler( $this->image_service, $this->ai_client );
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stream',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate_content_stream' ),
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/poll/start',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_polling_generation' ),
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/poll/(?P<generation_id>[a-f0-9-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'poll_generation' ),
					'args'                => array(
						'generation_id' => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'Generation ID', 'wp-module-ai-page-designer' ),
							'validate_callback' => function ( $value ) {
								return (bool) preg_match( '/^[a-f0-9-]+$/', $value );
							},
						),
						'offset'        => array(
							'required'    => false,
							'type'        => 'integer',
							'default'     => 0,
							'description' => __( 'Chunk offset', 'wp-module-ai-page-designer' ),
						),
					),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_generation' ),
					'args'                => array(
						'generation_id' => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'Generation ID', 'wp-module-ai-page-designer' ),
							'validate_callback' => function ( $value ) {
								return (bool) preg_match( '/^[a-f0-9-]+$/', $value );
							},
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

		if ( isset( $context['selected_block_markup'] ) ) {
			if ( ! is_string( $context['selected_block_markup'] ) ) {
				return false;
			}
		}

		if ( isset( $context['single_block_edit'] ) ) {
			if ( ! is_bool( $context['single_block_edit'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Decide which markup the fast path should operate on.
	 *
	 * If a single image/cover block is selected, the fast path targets just that block so an
	 * image replacement changes only the selection (and the returned single block splices back
	 * at the selected index on the frontend). Otherwise it operates on the whole-page markup.
	 *
	 * @param array  $context        Request context.
	 * @param string $current_markup Whole-page block markup.
	 * @return array{0:string,1:bool,2:string} [ markup to fast-path, whether it is a selected
	 *                                         image block, specific target image URL (or '') ].
	 */
	private function resolve_fast_path_target( $context, $current_markup ) {
		$is_single_block_edit = ! empty( $context['single_block_edit'] ) && ! empty( $context['selected_block_markup'] );
		if ( $is_single_block_edit ) {
			$selected_markup = trim( (string) $context['selected_block_markup'] );
			$is_image_block  = (bool) preg_match( '/<img\b|<!--\s*wp:(image|cover)\b/i', $selected_markup );
			if ( $is_image_block ) {
				// When a single image was clicked inside a multi-image block, the frontend sends
				// its URL so only that image is swapped.
				$target_url = isset( $context['selected_image_url'] ) ? esc_url_raw( (string) $context['selected_image_url'] ) : '';
				return array( $selected_markup, true, $target_url );
			}
		}
		return array( $current_markup, false, '' );
	}

	/**
	 * Build the messages array sent to the (stateless) AI backend.
	 *
	 * The backend is stateless: it receives the current markup and system prompt on every call,
	 * so the page state is already supplied out of band. Replaying the whole transcript adds
	 * nothing and bloats the payload — the assistant messages in particular carry the full
	 * generated page HTML in a `code` field, which can push the request large enough for the
	 * Worker to reject it (500). Send only the latest user instruction, sanitized to the
	 * { role, content } shape the backend expects (dropping code/summary/link/isError fields).
	 *
	 * @param array $messages Raw messages from the request.
	 * @return array<int, array<string, string>>
	 */
	/**
	 * Reject an edit whose markup is too large to send to the AI backend.
	 *
	 * Guards against a malformed page collapsing into one oversized block: rather than letting
	 * the Worker fail with an opaque 500, return a clear, actionable error. See
	 * self::MAX_AI_MARKUP_CHARS for the rationale.
	 *
	 * @param string $current_markup        Whole-page markup being sent.
	 * @param string $selected_block_markup Selected-block markup being sent.
	 * @return \WP_Error|null WP_Error when the markup is too large, otherwise null.
	 */
	private function check_markup_size( $current_markup, $selected_block_markup ) {
		$markup_chars = strlen( (string) $current_markup ) + strlen( (string) $selected_block_markup );

		if ( $markup_chars > self::MAX_AI_MARKUP_CHARS ) {
			AIPageDesignerDebug::debug_log(
				'Edit markup exceeds size limit',
				array(
					'markup_chars' => $markup_chars,
					'limit'        => self::MAX_AI_MARKUP_CHARS,
				)
			);

			return new \WP_Error(
				'ai_payload_too_large',
				__( 'This section is too large to edit in one request — the page structure may be malformed. Try selecting a smaller block, or regenerate the page.', 'wp-module-ai-page-designer' ),
				array( 'status' => 413 )
			);
		}

		return null;
	}

	private function build_ai_messages( $messages ) {
		if ( ! is_array( $messages ) ) {
			return array();
		}

		for ( $index = count( $messages ) - 1; $index >= 0; $index-- ) {
			if ( isset( $messages[ $index ]['role'], $messages[ $index ]['content'] )
				&& 'user' === $messages[ $index ]['role'] ) {
				return array(
					array(
						'role'    => 'user',
						'content' => (string) $messages[ $index ]['content'],
					),
				);
			}
		}

		// Fallback: no user message found — sanitize whatever is present to role + content.
		return array_values(
			array_map(
				function ( $message ) {
					return array(
						'role'    => isset( $message['role'] ) ? (string) $message['role'] : 'user',
						'content' => isset( $message['content'] ) ? (string) $message['content'] : '',
					);
				},
				$messages
			)
		);
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

			$current_markup   = isset( $context['current_markup'] ) ? trim( $context['current_markup'] ) : '';
			$content_type     = isset( $context['content_type'] ) && 'post' === $context['content_type'] ? 'post' : 'page';
			$page_title       = isset( $context['page_title'] ) ? sanitize_text_field( $context['page_title'] ) : '';
			$page_excerpt     = isset( $context['page_excerpt'] ) ? sanitize_textarea_field( $context['page_excerpt'] ) : '';
			$last_user_prompt = '';

			for ( $index = count( $messages ) - 1; $index >= 0; $index-- ) {
				if ( isset( $messages[ $index ]['role'] ) && 'user' === $messages[ $index ]['role'] ) {
					$last_user_prompt = $messages[ $index ]['content'] ?? '';
					break;
				}
			}

			$stream = (bool) $request->get_param( 'stream' );

			// When a single image/cover block is selected, run the fast path against just that
			// block (and return only that block) so the edit targets the selection instead of
			// every image on the page — and so the single-block splice on the frontend works.
			list( $fast_path_markup, $fast_path_is_selected_image, $fast_path_target_url ) = $this->resolve_fast_path_target( $context, $current_markup );
			$fast_path_response = $this->fast_path_handler->maybe_handle_fast_path( $fast_path_markup, $last_user_prompt, $page_title, $page_excerpt, $fast_path_is_selected_image, $fast_path_target_url );
			if ( $fast_path_response ) {
				if ( $stream ) {
					$this->init_streaming_response();
					$fast_path_data = $fast_path_response->get_data();
					$this->send_stream_event( 'result', $fast_path_data['data'] ?? $fast_path_data );
					$this->send_stream_event( 'done', array() );
					exit;
				}
				return $fast_path_response;
			}

			// Each request is self-contained (system prompt + current markup/blueprint);
			// the AI backend is stateless, so there is no conversation chaining.
			$is_redesign_request  = $this->is_redesign_request( $last_user_prompt );
			$is_single_block_edit = ! empty( $context['single_block_edit'] ) && ! empty( $context['selected_block_markup'] );

			$is_new      = count( $messages ) === 1;
			$use_pattern = ( $is_new && empty( $current_markup ) && 'post' !== $content_type )
				|| ( $is_redesign_request && 'post' !== $content_type );

			$base_layout = '';
			if ( $use_pattern && \NewfoldLabs\WP\Module\AIPageDesigner\AIPageDesigner::PATTERN_PROVIDER === 'wonderblocks' ) {
				$base_layout = $this->pattern_layout_provider->get_random_pattern_layout( $last_user_prompt );
			}

			// Reject an oversized edit (e.g. a malformed page collapsed into one giant block)
			// before it reaches the Worker, where it surfaces as an opaque 500.
			$size_error = $this->check_markup_size( $current_markup, $context['selected_block_markup'] ?? '' );
			if ( is_wp_error( $size_error ) ) {
				return $size_error;
			}

			$ai_messages = $this->build_ai_messages( $messages );

			if ( $stream ) {
				$this->init_streaming_response();
				$raw_content     = '';
				$stream_response = null;

				// Prepare options for streaming (enhanced for Worker compatibility)
				$stream_options = array(
					'current_markup'        => $current_markup,
					'content_type'          => $content_type,
					'selected_block_markup' => $context['selected_block_markup'] ?? null,
					'single_block_edit'     => $is_single_block_edit,
					'base_layout'           => $base_layout,
				);

				$stream_error      = null;
				$processed_content = '';

				$stream_result = $this->ai_client->stream_content(
					$ai_messages,
					$stream_options,
					function ( $event ) use ( &$raw_content, &$stream_response, &$stream_error, &$processed_content ) {
						$event_type = $event['type'] ?? '';
						if ( 'delta' === $event_type && ! empty( $event['text'] ) ) {
							$raw_content .= $event['text'];
							$this->send_stream_event( 'delta', array( 'text' => $event['text'] ) );
						}
						if ( 'meta' === $event_type && ! empty( $event['response_id'] ) ) {
							$stream_response = $event['response_id'];
						}
						// The Worker's final done event carries the post-processed
						// markup (layout/color guards applied). Prefer it over the
						// raw delta accumulation for the published result.
						if ( 'done' === $event_type ) {
							if ( ! empty( $event['content'] ) ) {
								$processed_content = $event['content'];
							}
							if ( ! empty( $event['response_id'] ) ) {
								$stream_response = $event['response_id'];
							}
						}
						// The Worker reports generation failures as an SSE error event
						// on an otherwise successful (HTTP 200) stream.
						if ( 'error' === $event_type ) {
							$stream_error = isset( $event['message'] ) && '' !== $event['message']
								? $event['message']
								: __( 'AI generation failed. Please try again.', 'wp-module-ai-page-designer' );
						}
					}
				);

				if ( '' !== $processed_content ) {
					$raw_content = $processed_content;
				}

				if ( is_wp_error( $stream_result ) ) {
					$this->send_stream_event( 'error', array( 'message' => $stream_result->get_error_message() ) );
					exit;
				}

				if ( null !== $stream_error ) {
					$this->send_stream_event( 'error', array( 'message' => $stream_error ) );
					exit;
				}

				if ( '' === trim( $raw_content ) ) {
					$this->send_stream_event( 'error', array( 'message' => __( 'AI generation returned no content. Please try again.', 'wp-module-ai-page-designer' ) ) );
					exit;
				}

				$response_id   = is_string( $stream_result ) && $stream_result ? $stream_result : $stream_response;
				$response_data = $this->build_response_payload(
					$raw_content,
					$response_id,
					$messages,
					$context,
					$conversation_context,
					$last_user_prompt
				);

				if ( is_wp_error( $response_data ) ) {
					$this->send_stream_event( 'error', array( 'message' => $response_data->get_error_message() ) );
					exit;
				}

				$this->send_stream_event( 'result', $response_data );
				$this->send_stream_event( 'done', array() );
				exit;
			}

			// Prepare options for AI client (enhanced for Worker compatibility)
			$ai_options = array(
				'current_markup'        => $current_markup,
				'content_type'          => $content_type,
				'selected_block_markup' => $context['selected_block_markup'] ?? null,
				'single_block_edit'     => $is_single_block_edit,
				'base_layout'           => $base_layout,
			);

			$ai_result = $this->ai_client->generate_content( $ai_messages, $ai_options );

			if ( is_wp_error( $ai_result ) ) {
				return $ai_result;
			}

			$content     = $ai_result['content'] ?? '';
			$response_id = $ai_result['response_id'] ?? '';

			$response_data = $this->build_response_payload(
				$content,
				$response_id,
				$messages,
				$context,
				$conversation_context,
				$last_user_prompt
			);

			if ( is_wp_error( $response_data ) ) {
				return $response_data;
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
	 * Generate content via streaming (dedicated /generate/stream endpoint).
	 *
	 * @param \WP_REST_Request $request The REST request
	 * @return \WP_REST_Response|\WP_Error The response or error
	 */
	public function generate_content_stream( \WP_REST_Request $request ) {
		$request['stream'] = true;
		return $this->generate_content( $request );
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
	 * Build the response payload from AI content.
	 *
	 * @param string $content Raw AI response content.
	 * @param string $response_id AI response ID.
	 * @param array  $messages Original messages array.
	 * @param array  $context Request context.
	 * @param array  $conversation_context Conversation key/id.
	 * @param string $last_user_prompt Latest user prompt.
	 * @return array|\WP_Error
	 */
	private function build_response_payload( $content, $response_id, array $messages, array $context, array $conversation_context, $last_user_prompt ) {
		$conversation_key = $conversation_context['conversation_key'];
		$conversation_id  = $conversation_context['conversation_id'];

		$is_single_block_edit = ! empty( $context['single_block_edit'] ) && ! empty( $context['selected_block_markup'] );

		$title_data = $this->extract_page_title( $content );
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

		// Build a focused search context for image search using page-specific content.
		$search_context_parts = array();

		// 1. Add existing post/page title when editing existing content
		if ( ! empty( $context['post_id'] ) ) {
			$post_title = get_the_title( (int) $context['post_id'] );
			if ( ! empty( $post_title ) ) {
				$search_context_parts[] = $post_title;
			}
		}

		// 2. Add AI-generated title if available
		if ( ! empty( $title_data['title'] ) ) {
			$search_context_parts[] = rtrim( $title_data['title'], ' -|' );
		}

		// 3. Add AI-generated excerpt if available
		if ( ! empty( $title_data['excerpt'] ) ) {
			$search_context_parts[] = $title_data['excerpt'];
		}

		// 4. Add user prompts
		if ( ! empty( $all_prompts ) ) {
			$search_context_parts[] = trim( $all_prompts );
		}

		$search_context = implode( ' ', $search_context_parts );

		// Replace images for new pages with placeholders, or when the user explicitly asks for it.
		$has_images_in_markup = false;
		$blocks               = parse_blocks( $final_html );
		if ( ! empty( $blocks ) ) {
			$has_images_in_markup = $this->has_image_blocks( $blocks );
		}
		$featured_image_url = '';
		$wants_images       = (bool) preg_match( '/\b(image|images|photo|photos|picture|pictures|gallery|replace image|replace images|swap image|swap images|change image|change images)\b/i', $last_user_prompt );
		$current_markup     = isset( $context['current_markup'] ) ? trim( $context['current_markup'] ) : '';
		// Fetch fresh imagery only for a first generation or an explicit redesign.
		// An edit of existing markup must preserve its images. Keying on post_id (or
		// just current_markup) treated edits of an unsaved page as new requests, so
		// all images were re-fetched and replaced. A request is an edit when it
		// references existing content in any form — full-page markup OR a selected
		// block (single-block edits send only the selected block, not current_markup).
		$is_redesign      = $this->is_redesign_request( $last_user_prompt );
		$is_edit          = ! empty( $current_markup )
			|| ! empty( $context['selected_block_markup'] )
			|| ! empty( $context['single_block_edit'] );
		$is_new_request   = $is_redesign || ( ! $is_edit && empty( $context['post_id'] ) );
		$has_placeholders = strpos( $final_html, 'placehold.co' ) !== false;

		// Fallback: if we have placeholders but didn't detect image blocks,
		// there might be images in non-standard blocks or malformed markup
		if ( ! $has_images_in_markup && $has_placeholders ) {
			$has_images_in_markup = true;
		}

		if ( $is_new_request && $has_images_in_markup ) {
			$unsplash_images = $this->image_service->get_unsplash_images( $search_context );
			if ( ! empty( $unsplash_images ) ) {
				$featured_image_url = $unsplash_images[0];
				shuffle( $unsplash_images );
				$final_html = $this->image_service->replace_images_in_html( $final_html, $unsplash_images, ! $has_placeholders ? false : true );
			}
		} elseif ( ! $wants_images && ! empty( $current_markup ) ) {
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

		// Handle metadata-only responses (e.g., when user asks for excerpt generation)
		$is_metadata_only = empty( $final_html ) && ( ! empty( $title_data['title'] ) || ! empty( $title_data['excerpt'] ) || ! empty( $title_data['summary'] ) );

		$response_data = array(
			'content'            => $final_html,
			'title'              => $title_data['title'],
			'excerpt'            => $title_data['excerpt'] ?? '',
			'summary'            => $title_data['summary'] ?? '',
			'featured_image_url' => $featured_image_url,
			'conversation_key'   => $conversation_key,
			'is_metadata_only'   => $is_metadata_only,
		);
		if ( ! empty( $response_id ) ) {
			$response_data['response_id'] = $response_id;
		}

		if ( ! empty( $conversation_id ) ) {
			$response_data['conversation_id'] = $conversation_id;
		}

		return $response_data;
	}

	/**
	 * Initialize SSE streaming response.
	 *
	 * @return void
	 */
	private function init_streaming_response() {
		// Disable any PHP-level compression that might buffer output.
		ini_set( 'zlib.output_compression', 'Off' );
		// Attempt to disable output buffering at the PHP level.
		// May be blocked on restricted hosting, but harmless to try.
		@ini_set( 'output_buffering', '0' );
		// Auto-flush after every echo/print — belt-and-suspenders.
		ob_implicit_flush( true );

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'X-Accel-Buffering: no' ); // nginx; harmless on Apache
		header( 'Content-Encoding: identity' ); // prevent Apache mod_deflate buffering

		// Connection: close prevents Apache from buffering the response to
		// determine keep-alive size. With keep-alive, Apache sometimes waits
		// for Content-Length before sending, which defeats streaming.
		header( 'Connection: close' );

		// Apache-specific: tell mod_deflate not to compress this response.
		// Compression forces buffering, which breaks SSE streaming.
		// Note: with PHP-FPM (fpm-fcgi), apache_setenv() is unavailable;
		// the Content-Encoding: identity header handles this instead.
		if ( function_exists( 'apache_setenv' ) ) {
			apache_setenv( 'no-gzip', '1' );
		}

		// Close the session if one is open. Session locks can block
		// concurrent requests and interfere with streaming output.
		if ( session_status() === PHP_SESSION_ACTIVE ) {
			session_write_close();
		}

		// Strip all output buffers — use ob_end_clean() so stale data
		// from WordPress/plugins isn't dumped into our SSE stream.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Send padding to fill both PHP's output buffer and Apache's proxy
		// buffer on the first write, triggering an immediate transport flush.
		echo ': ' . str_repeat( ' ', 16384 ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		flush();
	}

	/**
	 * Send an SSE event.
	 *
	 * @param string $event Event name.
	 * @param array  $data Event payload.
	 * @return void
	 */
	private function send_stream_event( $event, array $data ) {
		echo 'event: ' . sanitize_key( $event ) . "\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";
		// Aggressively flush ALL buffer levels. Even with ob_implicit_flush,
		// some buffers may not auto-flush (e.g., those started after init).
		// This ensures PHP buffers are emptied before we call flush().
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		// Send a FastCGI FLUSH packet to tell Apache to forward data now.
		flush();
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

			// Check if this block contains any image URLs regardless of block type
			$block_html = $block['innerHTML'] ?? '';
			if ( ! empty( $block_html ) && strpos( $block_html, 'placehold.co' ) !== false ) {
				return true;
			}

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
			$block      = array_shift( $stack );
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

		return array_unique( $urls );
	}

	/**
	 * Detect whether a prompt is asking for a full redesign or regeneration.
	 *
	 * @param string $prompt The user prompt text.
	 * @return bool
	 */
	private function is_redesign_request( $prompt ) {
		$prompt_lower = strtolower( $prompt );
		$triggers     = array(
			'redesign',
			'regenerate',
			'generate again',
			'redo',
			'remake',
			'rebuild',
			'start over',
			'start fresh',
			'from scratch',
			'create new',
			'make a new',
			'build a new',
			'try again',
			'new version',
			'new design',
		);
		foreach ( $triggers as $trigger ) {
			if ( str_contains( $prompt_lower, $trigger ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extract the PAGE_TITLE comment embedded in every AI HTML response.
	 *
	 * @param string $content Raw AI response content.
	 * @return array{title:string,excerpt:string,summary:string,html:string}
	 */
	private function extract_page_title( $content ) {
		$title   = '';
		$excerpt = '';
		$summary = '';
		$html    = $content;

		if ( preg_match( '/<!--\s*PAGE_TITLE:\s*(.+?)\s*-->/i', $html, $m ) ) {
			$title = trim( $m[1] );
			$html  = preg_replace( '/<!--\s*PAGE_TITLE:\s*.+?\s*-->\s*/i', '', $html, 1 );
		}

		if ( preg_match( '/<!--\s*PAGE_EXCERPT:\s*(.+?)\s*-->/i', $html, $m ) ) {
			$excerpt = trim( $m[1] );
			$html    = preg_replace( '/<!--\s*PAGE_EXCERPT:\s*.+?\s*-->\s*/i', '', $html, 1 );
		}

		if ( preg_match( '/<!--\s*RESPONSE_SUMMARY:\s*(.+?)\s*-->/i', $html, $m ) ) {
			$summary = trim( $m[1] );
			$html    = preg_replace( '/<!--\s*RESPONSE_SUMMARY:\s*.+?\s*-->\s*/i', '', $html, 1 );
		}

		return array(
			'title'   => $title,
			'excerpt' => $excerpt,
			'summary' => $summary,
			'html'    => $this->sanitize_block_content( trim( $html ) ),
		);
	}

	/**
	 * Sanitize Gutenberg block markup returned by the AI.
	 *
	 * @param string $content Block markup to sanitize.
	 * @return string Sanitized block markup.
	 */
	private function sanitize_block_content( $content ) {
		$content = preg_replace( '/<!--(?![\s\S]*?-->)[\s\S]*$/u', '', $content );
		$content = trim( $content );

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
				if ( ! empty( $stack ) && end( $stack ) === $block_name ) {
					array_pop( $stack );
				}
			} else {
				$stack[] = $block_name;
			}
		}

		while ( ! empty( $stack ) ) {
			$block_name = array_pop( $stack );
			$content   .= "\n<!-- /wp:{$block_name} -->";
		}

		return $content;
	}

	/**
	 * Start a polling-based generation process.
	 *
	 * Stores initial state, returns the generation_id immediately,
	 * then processes the Worker stream in the background.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function start_polling_generation( \WP_REST_Request $request ) {
		try {
			$messages = $request['messages'];
			$context  = is_array( $request['context'] ?? null ) ? $request['context'] : array();

			$conversation_context = $this->get_conversation_context( $context );
			if ( is_wp_error( $conversation_context ) ) {
				return $conversation_context;
			}

			$generation_id = wp_generate_uuid4();

			set_transient(
				"nfd_ai_poll_{$generation_id}_meta",
				array(
					'status'     => 'in_progress',
					'created_at' => time(),
				),
				HOUR_IN_SECONDS
			);
			set_transient(
				"nfd_ai_poll_{$generation_id}_chunks",
				array(),
				HOUR_IN_SECONDS
			);

			// Extract options (mirrors generate_content logic)
			$current_markup = isset( $context['current_markup'] ) ? trim( $context['current_markup'] ) : '';
			$content_type   = isset( $context['content_type'] ) && 'post' === $context['content_type'] ? 'post' : 'page';
			$page_title     = isset( $context['page_title'] ) ? sanitize_text_field( $context['page_title'] ) : '';
			$page_excerpt   = isset( $context['page_excerpt'] ) ? sanitize_textarea_field( $context['page_excerpt'] ) : '';

			$last_user_prompt = '';
			for ( $index = count( $messages ) - 1; $index >= 0; $index-- ) {
				if ( isset( $messages[ $index ]['role'] ) && 'user' === $messages[ $index ]['role'] ) {
					$last_user_prompt = $messages[ $index ]['content'] ?? '';
					break;
				}
			}

			// Image add/replace requests are resolved without an AI round-trip. Store the result
			// directly as a completed generation so the first poll returns it. (Mirrors the
			// fast path in generate_content; without it, image requests fall through to the AI,
			// which leaves the block unchanged.)
			list( $fast_path_markup, $fast_path_is_selected_image, $fast_path_target_url ) = $this->resolve_fast_path_target( $context, $current_markup );
			$fast_path_response = $this->fast_path_handler->maybe_handle_fast_path( $fast_path_markup, $last_user_prompt, $page_title, $page_excerpt, $fast_path_is_selected_image, $fast_path_target_url );
			if ( $fast_path_response ) {
				$fast_path_data = $fast_path_response->get_data();
				set_transient(
					"nfd_ai_poll_{$generation_id}_meta",
					array(
						'status'     => 'completed',
						'result'     => $fast_path_data['data'] ?? $fast_path_data,
						'created_at' => time(),
					),
					HOUR_IN_SECONDS
				);
				return new \WP_REST_Response(
					array( 'generation_id' => $generation_id ),
					200
				);
			}

			// Reject an oversized edit before it reaches the Worker (opaque 500). Done after the
			// fast path so local image edits still work; clean up the transients we reserved.
			$size_error = $this->check_markup_size( $current_markup, $context['selected_block_markup'] ?? '' );
			if ( is_wp_error( $size_error ) ) {
				delete_transient( "nfd_ai_poll_{$generation_id}_meta" );
				delete_transient( "nfd_ai_poll_{$generation_id}_chunks" );
				return $size_error;
			}

			$is_redesign_request  = $this->is_redesign_request( $last_user_prompt );
			$is_single_block_edit = ! empty( $context['single_block_edit'] ) && ! empty( $context['selected_block_markup'] );

			$is_new      = count( $messages ) === 1;
			$use_pattern = ( $is_new && empty( $current_markup ) && 'post' !== $content_type )
				|| ( $is_redesign_request && 'post' !== $content_type );

			$base_layout = '';
			if ( $use_pattern && \NewfoldLabs\WP\Module\AIPageDesigner\AIPageDesigner::PATTERN_PROVIDER === 'wonderblocks' ) {
				$base_layout = $this->pattern_layout_provider->get_random_pattern_layout( $last_user_prompt );
			}

			$stream_options = array(
				'current_markup'        => $current_markup,
				'content_type'          => $content_type,
				'selected_block_markup' => $context['selected_block_markup'] ?? null,
				'single_block_edit'     => $is_single_block_edit,
				'base_layout'           => $base_layout,
			);

			// Try to close connection before background processing
			$connection_closed = $this->try_close_connection(
				wp_json_encode( array( 'generation_id' => $generation_id ) )
			);

			// Background processing
			ignore_user_abort( true );
			@set_time_limit( 0 );

			$raw_content       = '';
			$processed_content = '';
			$stream_response   = null;
			$stream_error      = null;

			$stream_result = $this->ai_client->stream_content(
				$this->build_ai_messages( $messages ),
				$stream_options,
				function ( $event ) use (
					&$raw_content,
					&$stream_response,
					&$stream_error,
					&$processed_content,
					$generation_id
				) {
					$event_type = $event['type'] ?? '';
					if ( 'delta' === $event_type && ! empty( $event['text'] ) ) {
						$raw_content .= $event['text'];
						$this->store_poll_chunk( $generation_id, $event['text'] );
					}
					if ( 'meta' === $event_type && ! empty( $event['response_id'] ) ) {
						$stream_response = $event['response_id'];
					}
					if ( 'done' === $event_type ) {
						if ( ! empty( $event['content'] ) ) {
							$processed_content = $event['content'];
						}
						if ( ! empty( $event['response_id'] ) ) {
							$stream_response = $event['response_id'];
						}
					}
					if ( 'error' === $event_type ) {
						$stream_error = isset( $event['message'] ) && '' !== $event['message']
							? $event['message']
							: __( 'AI generation failed. Please try again.', 'wp-module-ai-page-designer' );
					}
				}
			);

			if ( '' !== $processed_content ) {
				$raw_content = $processed_content;
			}

			// Check for errors
			if ( is_wp_error( $stream_result ) ) {
				set_transient(
					"nfd_ai_poll_{$generation_id}_meta",
					array(
						'status'        => 'error',
						'error_message' => $stream_result->get_error_message(),
						'created_at'    => time(),
					),
					HOUR_IN_SECONDS
				);
				if ( $connection_closed ) {
					exit;
				}
				return $stream_result;
			}

			if ( null !== $stream_error ) {
				set_transient(
					"nfd_ai_poll_{$generation_id}_meta",
					array(
						'status'        => 'error',
						'error_message' => $stream_error,
						'created_at'    => time(),
					),
					HOUR_IN_SECONDS
				);
				if ( $connection_closed ) {
					exit;
				}
				return new \WP_Error( 'ai_generation_error', $stream_error, array( 'status' => 500 ) );
			}

			if ( '' === trim( $raw_content ) ) {
				set_transient(
					"nfd_ai_poll_{$generation_id}_meta",
					array(
						'status'        => 'error',
						'error_message' => __( 'AI generation returned no content. Please try again.', 'wp-module-ai-page-designer' ),
						'created_at'    => time(),
					),
					HOUR_IN_SECONDS
				);
				if ( $connection_closed ) {
					exit;
				}
				return new \WP_Error(
					'ai_empty_content',
					__( 'AI generation returned no content. Please try again.', 'wp-module-ai-page-designer' ),
					array( 'status' => 500 )
				);
			}

			$response_id   = is_string( $stream_result ) && $stream_result ? $stream_result : $stream_response;
			$response_data = $this->build_response_payload(
				$raw_content,
				$response_id,
				$messages,
				$context,
				$conversation_context,
				$last_user_prompt
			);

			if ( is_wp_error( $response_data ) ) {
				set_transient(
					"nfd_ai_poll_{$generation_id}_meta",
					array(
						'status'        => 'error',
						'error_message' => $response_data->get_error_message(),
						'created_at'    => time(),
					),
					HOUR_IN_SECONDS
				);
				if ( $connection_closed ) {
					exit;
				}
				return $response_data;
			}

			set_transient(
				"nfd_ai_poll_{$generation_id}_meta",
				array(
					'status'     => 'completed',
					'result'     => $response_data,
					'created_at' => time(),
				),
				HOUR_IN_SECONDS
			);

			if ( $connection_closed ) {
				exit;
			}

			return new \WP_REST_Response(
				array( 'generation_id' => $generation_id ),
				200
			);
		} catch ( \Exception $e ) {
			if ( isset( $generation_id ) ) {
				set_transient(
					"nfd_ai_poll_{$generation_id}_meta",
					array(
						'status'        => 'error',
						'error_message' => $e->getMessage(),
						'created_at'    => time(),
					),
					HOUR_IN_SECONDS
				);
			}
			return new \WP_Error(
				'server_error',
				// translators: %s is the error message.
				sprintf( __( 'AI generation failed: %s', 'wp-module-ai-page-designer' ), $e->getMessage() ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Poll for generation chunks.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function poll_generation( \WP_REST_Request $request ) {
		$generation_id = $request['generation_id'];
		$offset        = (int) $request->get_param( 'offset' );

		$meta = get_transient( "nfd_ai_poll_{$generation_id}_meta" );
		if ( false === $meta ) {
			return new \WP_Error(
				'generation_not_found',
				__( 'Generation not found.', 'wp-module-ai-page-designer' ),
				array( 'status' => 404 )
			);
		}

		$chunks       = get_transient( "nfd_ai_poll_{$generation_id}_chunks" );
		$chunks       = is_array( $chunks ) ? $chunks : array();
		$chunks_count = count( $chunks );

		$new_chunks = array();
		for ( $i = $offset; $i < $chunks_count; $i++ ) {
			$new_chunks[] = $chunks[ $i ];
		}

		$response = array(
			'status' => $meta['status'],
			'chunks' => $new_chunks,
			'offset' => $chunks_count,
		);

		if ( 'completed' === $meta['status'] && isset( $meta['result'] ) ) {
			$response['result'] = $meta['result'];
		}

		if ( 'error' === $meta['status'] && isset( $meta['error_message'] ) ) {
			$response['error_message'] = $meta['error_message'];
		}

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Delete generation data (cleanup).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function delete_generation( \WP_REST_Request $request ) {
		$generation_id = $request['generation_id'];

		$meta = get_transient( "nfd_ai_poll_{$generation_id}_meta" );
		if ( false === $meta ) {
			return new \WP_Error(
				'generation_not_found',
				__( 'Generation not found.', 'wp-module-ai-page-designer' ),
				array( 'status' => 404 )
			);
		}

		delete_transient( "nfd_ai_poll_{$generation_id}_meta" );
		delete_transient( "nfd_ai_poll_{$generation_id}_chunks" );

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Try to close the HTTP connection early using fastcgi_finish_request().
	 *
	 * @param string $response_body The JSON body to send before closing.
	 * @return bool True if the connection was closed, false otherwise.
	 */
	private function try_close_connection( $response_body ) {
		if ( ! function_exists( 'fastcgi_finish_request' ) ) {
			return false;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		header( 'Content-Type: application/json' );
		header( 'Content-Length: ' . strlen( $response_body ) );
		echo $response_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		fastcgi_finish_request();
		return true;
	}

	/**
	 * Atomically store a generation chunk for polling.
	 *
	 * @param string $generation_id The generation ID.
	 * @param string $text The chunk text to store.
	 * @return void
	 */
	private function store_poll_chunk( $generation_id, $text ) {
		$key    = "nfd_ai_poll_{$generation_id}_chunks";
		$chunks = get_transient( $key );
		if ( ! is_array( $chunks ) ) {
			$chunks = array();
		}
		$chunks[] = $text;
		set_transient( $key, $chunks, HOUR_IN_SECONDS );
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
