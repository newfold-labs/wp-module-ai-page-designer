<?php
/**
 * Intent Classifier REST Controller (PROTOTYPE).
 *
 * Replaces the brittle keyword-regex routing in the frontend with a single
 * cheap AI call that turns a free-text instruction into a typed edit action.
 * The model does the fuzzy understanding regex is bad at — resolving "make the
 * heading a bit bluer" or "light blue" to a concrete colour, telling an
 * additive "add a gallery below" from a recolour, etc. — and returns strict
 * JSON the frontend can route on deterministically.
 *
 * Uses AiClientWorker::analyze() (the pass-through endpoint that forwards our
 * own system prompt), so no Worker-side changes are required.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\RestApi
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\RestApi;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\AiClientWorker;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\CapabilityGate;

/**
 * Class IntentClassifierController
 */
class IntentClassifierController {

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
	protected $rest_base = 'classify';

	/**
	 * The AI client.
	 *
	 * @var AiClientWorker
	 */
	private $ai_client;

	/**
	 * The set of actions the classifier may return. Anything else collapses to
	 * 'freeform' so the caller falls back to the normal AI generate path.
	 *
	 * @var string[]
	 */
	private const ALLOWED_ACTIONS = array(
		'recolor_text',
		'recolor_background',
		'remove',
		'redesign',
		'edit_metadata',
		'add_block',
		'replace_image',
		'freeform',
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
					'callback'            => array( $this, 'classify' ),
					'args'                => array(
						'text' => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'The user instruction to classify.', 'wp-module-ai-page-designer' ),
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
	 * Classify a free-text edit instruction into a typed action.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function classify( \WP_REST_Request $request ) {
		$text = (string) $request->get_param( 'text' );
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'invalid_text', __( 'No instruction to classify.', 'wp-module-ai-page-designer' ), array( 'status' => 400 ) );
		}

		$context             = $request->get_param( 'context' );
		$context             = is_array( $context ) ? $context : array();
		$has_selection       = ! empty( $context['has_selection'] );
		$selected_block_type = isset( $context['selected_block_type'] ) ? sanitize_text_field( (string) $context['selected_block_type'] ) : '';
		$has_generated       = ! empty( $context['has_generated'] );
		$palette             = isset( $context['palette'] ) && is_array( $context['palette'] ) ? $context['palette'] : array();

		$ai_messages = array(
			array(
				'role'    => 'system',
				'content' => $this->build_system_prompt( $palette ),
			),
			array(
				'role'    => 'user',
				'content' => $this->build_user_message( $text, $has_selection, $selected_block_type, $has_generated ),
			),
		);

		$result = $this->ai_client->analyze( $ai_messages );

		if ( is_wp_error( $result ) || empty( $result['content'] ) ) {
			// Fail soft: tell the caller to use its deterministic/AI fallback.
			return rest_ensure_response( $this->freeform( 'classifier_unavailable' ) );
		}

		$intent = $this->parse_intent( $result['content'] );

		return rest_ensure_response( $intent );
	}

	/**
	 * Build the classification system prompt.
	 *
	 * @param array $palette Theme palette entries ({slug,name,color}).
	 * @return string
	 */
	private function build_system_prompt( array $palette ) {
		$palette_lines = '';
		foreach ( $palette as $swatch ) {
			$slug  = isset( $swatch['slug'] ) ? $swatch['slug'] : '';
			$color = isset( $swatch['color'] ) ? $swatch['color'] : '';
			if ( $slug && $color ) {
				$palette_lines .= "- {$slug}: {$color}\n";
			}
		}
		$palette_block = $palette_lines ? "\n\nTheme palette (use these hexes when the user references the theme or a palette colour):\n{$palette_lines}" : '';

		return 'You classify a single WordPress page-editing instruction into a typed action. '
			. 'Return ONLY a JSON object, no prose, no code fences. Schema:' . "\n"
			. '{"action": one of '
			. '["recolor_text","recolor_background","remove","redesign","edit_metadata","add_block","replace_image","freeform"], '
			. '"target": "selected" | "page" | null, '
			. '"color": a concrete CSS colour as a hex like "#add8e6" (resolve names/descriptions e.g. "light blue", "a bit darker") or null, '
			. '"metadata_fields": array subset of ["title","excerpt","summary"], '
			. '"block_type": a core block slug without the "core/" prefix (e.g. "gallery","table","buttons","heading","paragraph","list") or null, '
			. '"insert_direction": "before" | "after" | null, '
			. '"confidence": number 0..1}' . "\n\n"
			. 'Guidance: '
			. '"change/make the text/font colour ..." => recolor_text. '
			. '"change the background ..." => recolor_background. '
			. '"remove/delete this section" => remove. '
			. '"redesign/regenerate/start over" => redesign. '
			. '"update/rewrite the excerpt/title" => edit_metadata with metadata_fields set (NOT when the phrase is about a colour/font of a heading). '
			. '"add a <block> below/above this" => add_block with block_type and insert_direction. '
			. '"replace/swap this image" => replace_image. '
			. 'Anything that needs the page rewritten by AI => freeform. '
			. 'When unsure, use "freeform" with low confidence.'
			. $palette_block;
	}

	/**
	 * Build the user message describing the instruction and edit context.
	 *
	 * @param string $text                The instruction.
	 * @param bool   $has_selection       Whether a block is selected.
	 * @param string $selected_block_type Selected block type (if any).
	 * @param bool   $has_generated       Whether the page already has generated content.
	 * @return string
	 */
	private function build_user_message( $text, $has_selection, $selected_block_type, $has_generated ) {
		$ctx  = 'Context: ';
		$ctx .= $has_selection
			? 'a block is currently selected' . ( $selected_block_type ? " (type: {$selected_block_type})" : '' ) . '. '
			: 'no block is selected. ';
		$ctx .= $has_generated ? 'The page already has content. ' : 'The page is empty. ';

		return $ctx . "\n\nInstruction: " . $text;
	}

	/**
	 * Parse and normalise the model's JSON into a safe typed intent.
	 *
	 * @param string $content Raw model content.
	 * @return array
	 */
	private function parse_intent( $content ) {
		// Strip markdown fences if the model wrapped the JSON.
		$content = preg_replace( '/```(?:json)?/i', '', (string) $content );
		$content = trim( $content );

		$data = json_decode( $content, true );
		if ( ! is_array( $data ) ) {
			return $this->freeform( 'unparseable' );
		}

		$action = isset( $data['action'] ) ? (string) $data['action'] : 'freeform';
		if ( ! in_array( $action, self::ALLOWED_ACTIONS, true ) ) {
			$action = 'freeform';
		}

		$target = isset( $data['target'] ) ? (string) $data['target'] : '';
		$target = in_array( $target, array( 'selected', 'page' ), true ) ? $target : null;

		$color = isset( $data['color'] ) && is_string( $data['color'] ) && '' !== trim( $data['color'] )
			? trim( $data['color'] )
			: null;

		$metadata_fields = array();
		if ( isset( $data['metadata_fields'] ) && is_array( $data['metadata_fields'] ) ) {
			foreach ( $data['metadata_fields'] as $field ) {
				if ( in_array( $field, array( 'title', 'excerpt', 'summary' ), true ) ) {
					$metadata_fields[] = $field;
				}
			}
		}

		$block_type = isset( $data['block_type'] ) && is_string( $data['block_type'] ) && '' !== trim( $data['block_type'] )
			? str_replace( 'core/', '', trim( $data['block_type'] ) )
			: null;

		$insert_direction = isset( $data['insert_direction'] ) ? (string) $data['insert_direction'] : '';
		$insert_direction = in_array( $insert_direction, array( 'before', 'after' ), true ) ? $insert_direction : null;

		$confidence = isset( $data['confidence'] ) && is_numeric( $data['confidence'] )
			? max( 0.0, min( 1.0, (float) $data['confidence'] ) )
			: 0.5;

		return array(
			'action'           => $action,
			'target'           => $target,
			'color'            => $color,
			'metadata_fields'  => $metadata_fields,
			'block_type'       => $block_type,
			'insert_direction' => $insert_direction,
			'confidence'       => $confidence,
		);
	}

	/**
	 * Build a freeform fallback intent.
	 *
	 * @param string $reason Diagnostic reason (not surfaced to the user).
	 * @return array
	 */
	private function freeform( $reason ) {
		return array(
			'action'           => 'freeform',
			'target'           => null,
			'color'            => null,
			'metadata_fields'  => array(),
			'block_type'       => null,
			'insert_direction' => null,
			'confidence'       => 0.0,
			'reason'           => $reason,
		);
	}
}
