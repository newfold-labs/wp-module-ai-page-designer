<?php
/**
 * WordPress Proxy Controller
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\RestApi
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\RestApi;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\CapabilityGate;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\ImageService;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Harness;

/**
 * REST API Controller for WordPress Content Operations
 *
 * Provides convenient endpoints for managing pages and posts
 */
class WordPressProxyController extends \WP_REST_Controller {

	/**
	 * The namespace of this controller's route.
	 *
	 * @var string
	 */
	protected $namespace = 'newfold-ai-page-designer/v1';

	/**
	 * Register the routes for this controller
	 */
	public function register_routes() {
		// List content (pages or posts)
		register_rest_route(
			$this->namespace,
			'/content/(?P<type>pages|posts)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_content' ),
					'args'                => array(
						'type' => array(
							'required'    => true,
							'type'        => 'string',
							'enum'        => array( 'pages', 'posts' ),
							'description' => __( 'Content type: pages or posts', 'wp-module-ai-page-designer' ),
						),
					),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Get single content item
		register_rest_route(
			$this->namespace,
			'/content/(?P<type>pages|posts)/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_content' ),
					'args'                => array(
						'type' => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'pages', 'posts' ),
						),
						'id'   => array(
							'required' => true,
							'type'     => 'integer',
						),
					),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Create content
		register_rest_route(
			$this->namespace,
			'/content/(?P<type>pages|posts)',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_content' ),
					'args'                => array(
						'type'           => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'pages', 'posts' ),
						),
						'title'          => array(
							'required' => true,
							'type'     => 'string',
						),
						'content'        => array(
							'required' => true,
							'type'     => 'string',
						),
						'status'         => array(
							'required' => false,
							'type'     => 'string',
							'default'  => 'publish',
							'enum'     => array( 'publish', 'draft', 'pending', 'private' ),
						),
						'slug'           => array(
							'required' => false,
							'type'     => 'string',
						),
						'excerpt'        => array(
							'required' => false,
							'type'     => 'string',
						),
						'featured_media' => array(
							'required' => false,
							'type'     => 'integer',
						),
						'design_tokens'  => array(
							'required' => false,
							'type'     => 'object',
						),
					),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Update content
		register_rest_route(
			$this->namespace,
			'/content/(?P<type>pages|posts)/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_content' ),
					'args'                => array(
						'type'          => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'pages', 'posts' ),
						),
						'id'            => array(
							'required' => true,
							'type'     => 'integer',
						),
						'content'       => array(
							'required' => false,
							'type'     => 'string',
						),
						'title'         => array(
							'required' => false,
							'type'     => 'string',
						),
						'status'        => array(
							'required' => false,
							'type'     => 'string',
						),
						'slug'          => array(
							'required' => false,
							'type'     => 'string',
						),
						'excerpt'       => array(
							'required' => false,
							'type'     => 'string',
						),
						'design_tokens' => array(
							'required' => false,
							'type'     => 'object',
						),
					),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Set homepage
		register_rest_route(
			$this->namespace,
			'/homepage/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_homepage' ),
					'args'                => array(
						'id' => array(
							'required' => true,
							'type'     => 'integer',
						),
					),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Recent pages/posts (server-persisted per user via _apd_recent_ids,
		// so the workspace drawer's Recent list follows the user across
		// devices — plan section 3: Client state).
		register_rest_route(
			$this->namespace,
			'/recent',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_recent' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'touch_recent' ),
					'args'                => array(
						'id' => array(
							'required' => true,
							'type'     => 'integer',
						),
					),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * List content (pages or posts)
	 *
	 * @param \WP_REST_Request $request The REST request
	 * @return \WP_REST_Response|\WP_Error The response
	 */
	public function list_content( \WP_REST_Request $request ) {
		$type      = $request['type'];
		$post_type = 'pages' === $type ? 'page' : 'post';

		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => 100,
			'post_status'    => 'publish',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		$posts = get_posts( $args );
		$data  = array();

		foreach ( $posts as $post ) {
			$data[] = $this->build_item_data( $post );
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Get single content item
	 *
	 * @param \WP_REST_Request $request The REST request
	 * @return \WP_REST_Response|\WP_Error The response
	 */
	public function get_content( \WP_REST_Request $request ) {
		$id   = $request['id'];
		$post = get_post( $id );

		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				__( 'Content not found', 'wp-module-ai-page-designer' ),
				array( 'status' => 404 )
			);
		}

		$data = $this->build_item_data( $post );

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Create content
	 *
	 * @param \WP_REST_Request $request The REST request
	 * @return \WP_REST_Response|\WP_Error The response
	 */
	public function create_content( \WP_REST_Request $request ) {
		$type      = $request['type'];
		$post_type = 'pages' === $type ? 'page' : 'post';

		// Idempotent re-conform on save: a no-op for already-conformed preview
		// markup, but guarantees correctness for any markup that reached save
		// without passing through the generate path (WYSIWYG + defense).
		$post_data = array(
			'post_title'   => sanitize_text_field( $request['title'] ),
			'post_content' => $this->conform_for_save( (string) $request['content'], (string) $request['title'] ),
			'post_status'  => sanitize_text_field( $request['status'] ),
			'post_type'    => $post_type,
		);

		if ( ! empty( $request['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $request['slug'] );
		}

		if ( ! empty( $request['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_text_field( $request['excerpt'] );
		}

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error(
				'creation_failed',
				$post_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		if ( isset( $request['design_tokens'] ) ) {
			$this->save_design_tokens( $post_id, $request['design_tokens'] );
		}

		$post = get_post( $post_id );

		$data = array(
			'id'   => $post->ID,
			'link' => get_permalink( $post->ID ),
			'guid' => array(
				'rendered' => get_the_guid( $post->ID ),
			),
		);

		return new \WP_REST_Response( $data, 201 );
	}

	/**
	 * Update content
	 *
	 * @param \WP_REST_Request $request The REST request
	 * @return \WP_REST_Response|\WP_Error The response
	 */
	public function update_content( \WP_REST_Request $request ) {
		$id   = $request['id'];
		$post = get_post( $id );

		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				__( 'Content not found', 'wp-module-ai-page-designer' ),
				array( 'status' => 404 )
			);
		}

		$post_data = array(
			'ID' => $id,
		);

		if ( isset( $request['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $request['title'] );
		}

		if ( isset( $request['content'] ) ) {
			// Idempotent re-conform on save (WYSIWYG + defense); no-op for
			// already-conformed preview markup.
			$title_context             = isset( $request['title'] ) ? (string) $request['title'] : get_the_title( $id );
			$post_data['post_content'] = $this->conform_for_save( (string) $request['content'], $title_context );
		}

		if ( isset( $request['status'] ) ) {
			$post_data['post_status'] = sanitize_text_field( $request['status'] );
		}

		if ( isset( $request['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $request['slug'] );
		}

		if ( isset( $request['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_text_field( $request['excerpt'] );
		}

		$result = wp_update_post( $post_data );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'update_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$post = get_post( $id );

		if ( isset( $request['featured_media'] ) ) {
			$featured_media = absint( $request['featured_media'] );
			if ( 0 === $featured_media ) {
				delete_post_thumbnail( $id );
			} else {
				set_post_thumbnail( $id, $featured_media );
			}
		}

		if ( isset( $request['design_tokens'] ) ) {
			$this->save_design_tokens( $id, $request['design_tokens'] );
		}

		$data = $this->build_item_data( $post );

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Set homepage
	 *
	 * @param \WP_REST_Request $request The REST request
	 * @return \WP_REST_Response|\WP_Error The response
	 */
	public function set_homepage( \WP_REST_Request $request ) {
		$id   = $request['id'];
		$post = get_post( $id );

		if ( ! $post || 'page' !== $post->post_type ) {
			return new \WP_Error(
				'invalid_page',
				__( 'Invalid page ID', 'wp-module-ai-page-designer' ),
				array( 'status' => 400 )
			);
		}

		// Set the page as homepage
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $id );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Homepage set successfully', 'wp-module-ai-page-designer' ),
			),
			200
		);
	}

	/**
	 * The post meta key storing a page's saved Design tab selection
	 * (palette/font pairing ids for restoring the UI, plus the resolved
	 * colors/fonts so the public-facing render — AIPageDesigner::
	 * enqueue_frontend_animations() — doesn't need to know about the
	 * curated palette definitions in designTokens.ts at all).
	 *
	 * @var string
	 */
	const DESIGN_TOKENS_META_KEY = '_apd_design_tokens';

	/**
	 * The user meta key storing the current user's recent-pages list.
	 *
	 * @var string
	 */
	const RECENT_META_KEY = '_apd_recent_ids';

	/**
	 * Cap on stored entries — comfortably above the drawer's displayed 15
	 * (plan section 2: PinnedList max=5, RecentList max=15) so trimming to
	 * that limit stays a client-side display concern, not a data-loss one.
	 *
	 * @var int
	 */
	const RECENT_MAX_ENTRIES = 20;

	/**
	 * List the current user's recent pages/posts.
	 *
	 * @param \WP_REST_Request $request The REST request
	 * @return \WP_REST_Response The response
	 */
	public function list_recent( \WP_REST_Request $request ) {
		$entries = $this->get_recent_entries();
		$data    = array();

		foreach ( $entries as $entry ) {
			$id   = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
			$post = $id ? get_post( $id ) : null;
			if ( ! $post ) {
				continue;
			}
			$data[] = $this->build_item_data( $post );
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Record that the current user just opened a page/post — moves it to the
	 * front of their recent list (or adds it), then returns the updated list.
	 *
	 * @param \WP_REST_Request $request The REST request
	 * @return \WP_REST_Response|\WP_Error The response
	 */
	public function touch_recent( \WP_REST_Request $request ) {
		$id   = absint( $request['id'] );
		$post = get_post( $id );

		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				__( 'Content not found', 'wp-module-ai-page-designer' ),
				array( 'status' => 404 )
			);
		}

		$entries = $this->get_recent_entries();
		$entries = array_values(
			array_filter(
				$entries,
				function ( $entry ) use ( $id ) {
					return ! isset( $entry['id'] ) || absint( $entry['id'] ) !== $id;
				}
			)
		);
		array_unshift(
			$entries,
			array(
				'id'   => $id,
				'type' => $post->post_type,
			)
		);
		$entries = array_slice( $entries, 0, self::RECENT_MAX_ENTRIES );

		update_user_meta( get_current_user_id(), self::RECENT_META_KEY, $entries );

		return $this->list_recent( $request );
	}

	/**
	 * Read the current user's raw recent-entries list from user meta.
	 *
	 * @return array
	 */
	private function get_recent_entries() {
		$stored = get_user_meta( get_current_user_id(), self::RECENT_META_KEY, true );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Sanitize and persist a page's Design tab selection, or clear it when the
	 * client sends an empty/default selection (palette null, font 'default').
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $raw     The `design_tokens` request value — expected shape:
	 *                       { paletteId: string|null, fontPairingId: string,
	 *                         colors: {slug: hex}|null, headingFont: string, bodyFont: string }.
	 */
	private function save_design_tokens( $post_id, $raw ) {
		if ( ! is_array( $raw ) ) {
			delete_post_meta( $post_id, self::DESIGN_TOKENS_META_KEY );
			return;
		}

		$colors = null;
		if ( ! empty( $raw['colors'] ) && is_array( $raw['colors'] ) ) {
			$colors = array();
			foreach ( $raw['colors'] as $slug => $value ) {
				$hex = sanitize_hex_color( (string) $value );
				if ( $hex ) {
					$colors[ sanitize_key( $slug ) ] = $hex;
				}
			}
			if ( empty( $colors ) ) {
				$colors = null;
			}
		}

		$heading_font = isset( $raw['headingFont'] ) ? sanitize_text_field( $raw['headingFont'] ) : '';
		$body_font    = isset( $raw['bodyFont'] ) ? sanitize_text_field( $raw['bodyFont'] ) : '';

		if ( null === $colors && '' === $heading_font && '' === $body_font ) {
			delete_post_meta( $post_id, self::DESIGN_TOKENS_META_KEY );
			return;
		}

		update_post_meta(
			$post_id,
			self::DESIGN_TOKENS_META_KEY,
			array(
				'paletteId'     => isset( $raw['paletteId'] ) ? sanitize_key( $raw['paletteId'] ) : null,
				'fontPairingId' => isset( $raw['fontPairingId'] ) ? sanitize_key( $raw['fontPairingId'] ) : 'default',
				'colors'        => $colors,
				'headingFont'   => $heading_font,
				'bodyFont'      => $body_font,
			)
		);
	}

	/**
	 * Conform markup for persistence: run the idempotent harness, then guarantee
	 * no placehold.co image is ever saved by swapping any survivor for a real
	 * Unsplash image, and finally sanitize with wp_kses_post.
	 *
	 * The harness runs at generate time too (so the preview already matches), so
	 * for content that came through the designer this is a no-op; it is the
	 * safety net for markup that reached save by another path.
	 *
	 * @param string $content Raw block markup from the request.
	 * @param string $title   Page title, used as image-search context.
	 * @return string Sanitized, conformed markup ready for post_content.
	 */
	private function conform_for_save( $content, $title = '' ) {
		$conformed = ( new Harness() )->conform( $content );
		$conformed = ( new ImageService() )->replace_placeholder_images( $conformed, $title );
		return wp_kses_post( $conformed );
	}

	/**
	 * Check permissions for routes.
	 *
	 * @return bool|\WP_Error True if user has permission, WP_Error otherwise
	 */
	public function check_permission() {
		return CapabilityGate::rest_permission();
	}

	/**
	 * Build a consistent content payload.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	private function build_item_data( $post ) {
		$post_type      = $post->post_type;
		$excerpt_raw    = $post->post_excerpt ?? '';
		$featured_media = get_post_thumbnail_id( $post->ID );
		$design_tokens  = get_post_meta( $post->ID, self::DESIGN_TOKENS_META_KEY, true );

		return array(
			'id'                 => $post->ID,
			'title'              => array(
				'rendered' => get_the_title( $post->ID ),
			),
			'content'            => array(
				'rendered' => apply_filters( 'the_content', $post->post_content ),
				'raw'      => $post->post_content,
			),
			'excerpt'            => array(
				'rendered' => apply_filters( 'the_excerpt', $excerpt_raw ),
				'raw'      => $excerpt_raw,
			),
			'featured_media'     => $featured_media,
			'featured_image_url' => $this->get_featured_image_url( $featured_media ),
			'supports_thumbnail' => post_type_supports( $post_type, 'thumbnail' ),
			'status'             => $post->post_status,
			'link'               => get_permalink( $post->ID ),
			'type'               => $post_type,
			'design_tokens'      => is_array( $design_tokens ) ? $design_tokens : null,
		);
	}

	/**
	 * Resolve featured image URL with size fallback.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function get_featured_image_url( $attachment_id ) {
		if ( ! $attachment_id ) {
			return '';
		}

		$sizes = array( 'medium', 'large', 'full' );
		foreach ( $sizes as $size ) {
			$image = wp_get_attachment_image_src( $attachment_id, $size );
			if ( $image && ! empty( $image[0] ) ) {
				return $image[0];
			}
		}

		return '';
	}
}
