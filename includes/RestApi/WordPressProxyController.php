<?php
/**
 * WordPress Proxy Controller
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\RestApi
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\RestApi;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\CapabilityGate;

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
						'type'    => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'pages', 'posts' ),
						),
						'title'   => array(
							'required' => true,
							'type'     => 'string',
						),
						'content' => array(
							'required' => true,
							'type'     => 'string',
						),
						'status'  => array(
							'required' => false,
							'type'     => 'string',
							'default'  => 'publish',
							'enum'     => array( 'publish', 'draft', 'pending', 'private' ),
						),
						'slug'    => array(
							'required' => false,
							'type'     => 'string',
						),
						'excerpt' => array(
							'required' => false,
							'type'     => 'string',
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
						'type'    => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'pages', 'posts' ),
						),
						'id'      => array(
							'required' => true,
							'type'     => 'integer',
						),
						'content' => array(
							'required' => false,
							'type'     => 'string',
						),
						'title'   => array(
							'required' => false,
							'type'     => 'string',
						),
						'status'  => array(
							'required' => false,
							'type'     => 'string',
						),
						'slug'    => array(
							'required' => false,
							'type'     => 'string',
						),
						'excerpt' => array(
							'required' => false,
							'type'     => 'string',
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
			$data[] = array(
				'id'      => $post->ID,
				'title'   => array(
					'rendered' => get_the_title( $post->ID ),
				),
				'content' => array(
					'rendered' => apply_filters( 'the_content', $post->post_content ),
					'raw'      => $post->post_content,
				),
				'status'  => $post->post_status,
				'link'    => get_permalink( $post->ID ),
				'type'    => $post_type,
			);
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

		$data = array(
			'id'      => $post->ID,
			'title'   => array(
				'rendered' => get_the_title( $post->ID ),
			),
			'content' => array(
				'rendered' => apply_filters( 'the_content', $post->post_content ),
				'raw'      => $post->post_content,
			),
			'status'  => $post->post_status,
			'link'    => get_permalink( $post->ID ),
			'type'    => $post->post_type,
		);

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

		$post_data = array(
			'post_title'   => sanitize_text_field( $request['title'] ),
			'post_content' => wp_kses_post( $request['content'] ),
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
			$post_data['post_content'] = wp_kses_post( $request['content'] );
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

		$data = array(
			'id'   => $post->ID,
			'link' => get_permalink( $post->ID ),
			'guid' => array(
				'rendered' => get_the_guid( $post->ID ),
			),
		);

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
	 * Check permissions for routes.
	 *
	 * @return bool|\WP_Error True if user has permission, WP_Error otherwise
	 */
	public function check_permission() {
		return CapabilityGate::rest_permission();
	}
}
