<?php
/**
 * Page Plan REST Controller (PROTOTYPE — Stage 2, catalogue at 2/10 archetypes).
 *
 * Asks the model for a theme-agnostic page plan (`[{archetype, content}]`)
 * restricted to the archetypes currently registered with {@see PageAssembler},
 * then renders it. Uses AiClientWorker::analyze() — the pass-through endpoint
 * that forwards our own system prompt — so no Worker-side changes are required
 * (same mechanism as IntentClassifierController).
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\RestApi
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\RestApi;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\AiClientWorker;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\CapabilityGate;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\PageAssembler;

/**
 * Class PagePlanController
 */
class PagePlanController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'newfold-ai-page-designer/v1';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'page-plan';

	/**
	 * The AI client.
	 *
	 * @var AiClientWorker
	 */
	private $ai_client;

	/**
	 * Content schema description per currently-registered archetype, keyed by
	 * archetype name. Extending the catalogue later is just adding an entry
	 * here — the prompt is generated from this list, never hand-maintained.
	 *
	 * @var array<string, string>
	 */
	private const ARCHETYPE_SCHEMAS = array(
		'heroCover'   => 'eyebrow?: string, heading: string, subheading?: string, '
			. 'primaryCta: { label: string, url: string }, secondaryCta?: { label: string, url: string }, '
			. 'imageQuery: string (a short Unsplash search phrase for the hero background)',
		'featureGrid' => 'heading?: string, intro?: string, items: exactly 3 of { title: string, body: string }',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->ai_client = new AiClientWorker();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate' ),
					'args'                => array(
						'prompt' => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'A description of the page to build.', 'wp-module-ai-page-designer' ),
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission() {
		return CapabilityGate::rest_permission();
	}

	/**
	 * Generate a page plan from a prompt and render it.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate( \WP_REST_Request $request ) {
		$prompt = (string) $request->get_param( 'prompt' );
		if ( '' === trim( $prompt ) ) {
			return new \WP_Error( 'invalid_prompt', __( 'No page description provided.', 'wp-module-ai-page-designer' ), array( 'status' => 400 ) );
		}

		$ai_messages = array(
			array(
				'role'    => 'system',
				'content' => $this->build_system_prompt(),
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		$result = $this->ai_client->analyze( $ai_messages );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['content'] ) ) {
			return new \WP_Error( 'generation_failed', __( 'Could not generate a page plan right now. Please try again.', 'wp-module-ai-page-designer' ), array( 'status' => 502 ) );
		}

		$plan = $this->parse_plan( (string) $result['content'] );
		if ( empty( $plan ) ) {
			return new \WP_Error( 'generation_failed', __( 'The AI response could not be turned into a page. Please try again.', 'wp-module-ai-page-designer' ), array( 'status' => 502 ) );
		}

		$content = ( new PageAssembler() )->assemble( $plan );
		if ( '' === trim( $content ) ) {
			return new \WP_Error( 'generation_failed', __( 'The AI response could not be turned into a page. Please try again.', 'wp-module-ai-page-designer' ), array( 'status' => 502 ) );
		}

		return rest_ensure_response(
			array(
				'content' => $content,
				'title'   => $this->derive_title( $plan ),
			)
		);
	}

	/**
	 * Build the page-plan system prompt from the registered archetype schemas.
	 *
	 * @return string
	 */
	private function build_system_prompt(): string {
		$archetype_lines = '';
		foreach ( self::ARCHETYPE_SCHEMAS as $name => $schema ) {
			$archetype_lines .= "- \"{$name}\": {$schema}\n";
		}
		$allowed_names = implode( '", "', array_keys( self::ARCHETYPE_SCHEMAS ) );

		return 'You are a website page planner. Given a description of a page, return ONLY a JSON array '
			. '(no prose, no markdown code fences) of page sections in top-to-bottom order. Each item has the shape '
			. '{"archetype": one of ["' . $allowed_names . '"], "content": {...}}. '
			. "Use ONLY these archetypes — nothing else exists yet — and their exact content fields:\n"
			. $archetype_lines
			. "\nA homepage-shaped request should open with \"heroCover\". "
			. 'Write real, specific copy for the described business/topic — never placeholder text like "Lorem ipsum" or "Heading here".';
	}

	/**
	 * Parse and validate the model's JSON into a plan PageAssembler can use.
	 *
	 * Drops malformed items rather than failing the whole request — mirrors
	 * PageAssembler::assemble()'s own tolerance for unknown archetype names.
	 *
	 * @param string $content Raw model content.
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_plan( string $content ): array {
		$content = preg_replace( '/```(?:json)?/i', '', $content );
		$content = trim( (string) $content );

		$data = json_decode( $content, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$plan = array();
		foreach ( $data as $item ) {
			if ( ! is_array( $item ) || empty( $item['archetype'] ) || ! is_string( $item['archetype'] ) ) {
				continue;
			}
			if ( ! isset( self::ARCHETYPE_SCHEMAS[ $item['archetype'] ] ) ) {
				continue;
			}
			$plan[] = array(
				'archetype' => $item['archetype'],
				'variant'   => isset( $item['variant'] ) && is_string( $item['variant'] ) ? $item['variant'] : null,
				'content'   => isset( $item['content'] ) && is_array( $item['content'] ) ? $item['content'] : array(),
			);
		}

		return $plan;
	}

	/**
	 * Derive a page title from the first heroCover item's heading, if any.
	 *
	 * @param array<int, array<string, mixed>> $plan Parsed plan.
	 * @return string
	 */
	private function derive_title( array $plan ): string {
		foreach ( $plan as $item ) {
			if ( 'heroCover' === $item['archetype'] && ! empty( $item['content']['heading'] ) && is_string( $item['content']['heading'] ) ) {
				return $item['content']['heading'];
			}
		}
		return '';
	}
}
