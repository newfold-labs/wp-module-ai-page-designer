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

		register_rest_route(
			$this->namespace,
			'/metadata',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate_metadata' ),
					'args'                => array(
						'field'  => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'Which metadata field to generate: excerpt or title.', 'wp-module-ai-page-designer' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'markup' => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'The current page content to summarise.', 'wp-module-ai-page-designer' ),
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
	 * Generate a single page-metadata field (excerpt or title) from the current
	 * page content. Harness-owned: we build the prompt and call the model via the
	 * analyze() pass-through, so the Worker stays a dumb AI endpoint. The model
	 * returns the field value only — never a status line.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_metadata( \WP_REST_Request $request ) {
		$field = strtolower( (string) $request->get_param( 'field' ) );
		if ( ! in_array( $field, array( 'excerpt', 'title' ), true ) ) {
			return new \WP_Error( 'invalid_field', __( 'Unsupported metadata field.', 'wp-module-ai-page-designer' ), array( 'status' => 400 ) );
		}

		$page_text = $this->markup_to_text( (string) $request->get_param( 'markup' ) );
		if ( '' === $page_text ) {
			return new \WP_Error( 'empty_content', __( 'There is no page content to summarise yet.', 'wp-module-ai-page-designer' ), array( 'status' => 400 ) );
		}

		if ( 'excerpt' === $field ) {
			$system = 'You write SEO meta descriptions for web pages. Given the page content, write ONE concise, '
				. 'compelling excerpt of at most 155 characters that summarises the page for search results and social '
				. 'sharing. Write about the page itself — never describe the edit. Return ONLY the excerpt text: no quotes, '
				. 'no preamble, no markdown, no label.';
		} else {
			$system = 'You write page titles. Given the page content, write ONE concise, descriptive title of at most 60 '
				. 'characters. Return ONLY the title text: no quotes, no preamble, no markdown, no label.';
		}

		$ai_messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => "Page content:\n\n" . $page_text,
			),
		);

		$result = $this->ai_client->analyze( $ai_messages );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['content'] ) ) {
			return new \WP_Error( 'generation_failed', __( 'Could not generate that right now. Please try again.', 'wp-module-ai-page-designer' ), array( 'status' => 502 ) );
		}

		$value = $this->clean_metadata_value( (string) $result['content'] );
		if ( '' === $value ) {
			return new \WP_Error( 'generation_failed', __( 'Could not generate that right now. Please try again.', 'wp-module-ai-page-designer' ), array( 'status' => 502 ) );
		}

		return rest_ensure_response(
			array(
				'field' => $field,
				'value' => $value,
			)
		);
	}

	/**
	 * Reduce block markup to plain text for a cheaper, cleaner prompt: drop block
	 * comments and HTML tags, collapse whitespace, and cap the length.
	 *
	 * @param string $markup Page block markup.
	 * @return string
	 */
	private function markup_to_text( $markup ) {
		$text = preg_replace( '/<!--.*?-->/s', ' ', $markup );
		$text = wp_strip_all_tags( (string) $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( strlen( $text ) > 4000 ) {
			$text = substr( $text, 0, 4000 );
		}
		return $text;
	}

	/**
	 * Strip the wrappers a model sometimes adds (code fences, surrounding quotes,
	 * a "Excerpt:" label) so we keep just the field value.
	 *
	 * @param string $content Raw model output.
	 * @return string
	 */
	private function clean_metadata_value( $content ) {
		$value = preg_replace( '/```(?:\w+)?/', '', $content );
		$value = trim( $value );
		// Drop a leading "Excerpt:" / "Title:" label if present.
		$value = preg_replace( '/^\s*(?:excerpt|title|meta description)\s*[:\-]\s*/i', '', $value );
		// Strip a single pair of surrounding quotes.
		$value = trim( $value );
		if ( strlen( $value ) >= 2 ) {
			$first = $value[0];
			$last  = $value[ strlen( $value ) - 1 ];
			if ( ( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last ) ) {
				$value = substr( $value, 1, -1 );
			}
		}
		return trim( $value );
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
			. '"color_label": a short, friendly, lowercase colour name a non-technical person would say, e.g. "light blue", "navy", "warm gray" — never a hex code — or null, '
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

		$color_label = isset( $data['color_label'] ) && is_string( $data['color_label'] ) && '' !== trim( $data['color_label'] )
			? trim( $data['color_label'] )
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
			'color_label'      => $color_label,
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
			'color_label'      => null,
			'metadata_fields'  => array(),
			'block_type'       => null,
			'insert_direction' => null,
			'confidence'       => 0.0,
			'reason'           => $reason,
		);
	}
}
