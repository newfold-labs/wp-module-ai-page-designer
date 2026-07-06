<?php
/**
 * Page Plan REST Controller (Stage 2, v1 catalogue of 10 archetypes).
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
		'heroCover'            => 'eyebrow?: string, heading: string, subheading?: string, '
			. 'primaryCta: { label: string, url: string }, secondaryCta?: { label: string, url: string }, '
			. 'imageQuery: string (a short Unsplash search phrase for the hero background)',
		'featureGrid'          => 'heading?: string, intro?: string, items: exactly 3 of { title: string, body: string }',
		'alternatingMediaText' => 'heading?: string, intro?: string, rows: array of { heading: string, body: string, '
			. 'imageQuery: string (short Unsplash search phrase), cta?: { label: string, url: string } }',
		'ctaBanner'            => 'heading: string, subheading?: string, cta: { label: string, url: string }',
		'bookingForm'          => 'heading?: string, intro?: string, submitLabel?: string, fields: array of '
			. '{ type: one of "text"|"email"|"tel"|"date"|"time"|"number"|"select"|"textarea", name: string, label: string, '
			. 'required?: boolean, options?: string[] (only for type "select") }',
		'testimonials'         => 'heading?: string, quotes: 2-3 of { quote: string, author: string, role?: string, '
			. 'avatarQuery?: string (short Unsplash search phrase for a headshot) }',
		'pricingTiers'         => 'heading?: string, tiers: 2-3 of { name: string, price: string, period?: string, '
			. 'features: string[], cta: { label: string, url: string }, highlighted?: boolean }',
		'faqAccordion'         => 'heading?: string, items: array of { q: string, a: string }',
		'statsBar'             => 'items: 2-4 of { value: string, label: string }',
		'richText'             => 'heading?: string, body: string, cta?: { label: string, url: string }',
		'galleryGrid'          => 'heading?: string, intro?: string, images: 3-6 of '
			. '{ imageQuery: string (short Unsplash search phrase; vary the phrases so the images differ) }',
		'teamGrid'             => 'heading?: string, intro?: string, members: 2-4 of { name: string, role: string, '
			. 'bio?: string (one short sentence), avatarQuery?: string (short Unsplash search phrase for a headshot) }',
		'processSteps'         => 'heading?: string, intro?: string, steps: 3-4 of { title: string, body: string } '
			. '(a numbered "how it works" sequence)',
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

		// Retry once when the model returns unparseable JSON. Plan parsing is the
		// main reason the page-plan flow falls through to the freeform generate
		// path (which produces weaker, generic metadata), and a single retry
		// recovers most of those due to run-to-run model variance.
		$plan         = array();
		$plan_title   = '';
		$plan_excerpt = '';
		$last_error   = null;
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$result = $this->ai_client->analyze( $ai_messages );
			if ( is_wp_error( $result ) ) {
				$last_error = $result;
				continue;
			}
			if ( empty( $result['content'] ) ) {
				continue;
			}
			$parsed = $this->parse_response( (string) $result['content'] );
			if ( ! empty( $parsed['sections'] ) ) {
				$plan = $parsed['sections'];
				// Never let a retry that omitted title/excerpt clobber values a
				// previous attempt already provided.
				if ( '' !== $parsed['title'] ) {
					$plan_title = $parsed['title'];
				}
				if ( '' !== $parsed['excerpt'] ) {
					$plan_excerpt = $parsed['excerpt'];
				}
				// Accept a substantial plan right away; if it came back thin
				// (fewer than 4 sections) try once more for a fuller page, then
				// take whatever the second attempt gives.
				if ( count( $plan ) >= 4 || 1 === $attempt ) {
					break;
				}
			}
		}

		if ( empty( $plan ) ) {
			if ( null !== $last_error ) {
				return $last_error;
			}
			return new \WP_Error( 'generation_failed', __( 'The AI response could not be turned into a page. Please try again.', 'wp-module-ai-page-designer' ), array( 'status' => 502 ) );
		}

		$content = ( new PageAssembler() )->assemble( $plan );
		if ( '' === trim( $content ) ) {
			return new \WP_Error( 'generation_failed', __( 'The AI response could not be turned into a page. Please try again.', 'wp-module-ai-page-designer' ), array( 'status' => 502 ) );
		}

		// Prefer the model's dedicated short title/excerpt; fall back to the
		// hero-derived copy only when the model omitted them.
		$title   = '' !== $plan_title ? $plan_title : $this->derive_title( $plan );
		$excerpt = '' !== $plan_excerpt ? $plan_excerpt : $this->derive_excerpt( $plan );

		// Fallback 1: the site's own name / tagline when the plan yielded nothing.
		if ( '' === $title ) {
			$title = trim( (string) get_bloginfo( 'name' ) );
		}
		if ( '' === $excerpt ) {
			$excerpt = trim( (string) get_bloginfo( 'description' ) );
		}

		// Fallback 2 (last resort): a single extra AI call to write whatever is
		// still missing. Only fires when the plan had no heading/summary AND the
		// site has no name/tagline — rare, so it adds no latency to the common case.
		if ( '' === $title || '' === $excerpt ) {
			$meta = $this->generate_metadata_via_ai( $prompt );
			if ( '' === $title && '' !== $meta['title'] ) {
				$title = $meta['title'];
			}
			if ( '' === $excerpt && '' !== $meta['excerpt'] ) {
				$excerpt = $meta['excerpt'];
			}
		}

		// Final safety net: keep the title short (≤6 words) whatever source it came from.
		$title = $this->trim_title( $title );

		return rest_ensure_response(
			array(
				'content' => $content,
				'title'   => $title,
				'excerpt' => $excerpt,
			)
		);
	}

	/**
	 * Last-resort metadata generation: a single {@see AiClientWorker::analyze()}
	 * call (same pass-through pattern as the page plan itself) that returns a
	 * page title + excerpt for the prompt. Never throws — returns empty strings
	 * on any failure so the caller keeps whatever it already had.
	 *
	 * @param string $prompt The original page description.
	 * @return array{title: string, excerpt: string}
	 */
	private function generate_metadata_via_ai( string $prompt ): array {
		$empty     = array(
			'title'   => '',
			'excerpt' => '',
		);
		$site_name = trim( (string) get_bloginfo( 'name' ) );

		$system = 'You write website page metadata. Given a page description, return ONLY a JSON object '
			. '(no prose, no markdown code fences) of the shape {"title": string, "excerpt": string}. '
			. 'The title is a short, specific page title (max ~8 words). The excerpt is a single-sentence '
			. 'summary (max ~30 words). '
			. ( '' !== $site_name ? "This page is for the website \"{$site_name}\". " : '' )
			. 'Write real, specific copy — never placeholder text like "Untitled" or "Lorem ipsum".';

		$result = $this->ai_client->analyze(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			)
		);

		if ( is_wp_error( $result ) || empty( $result['content'] ) ) {
			return $empty;
		}

		$content = trim( (string) preg_replace( '/```(?:json)?/i', '', (string) $result['content'] ) );
		$data    = json_decode( $content, true );
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		return array(
			'title'   => isset( $data['title'] ) && is_string( $data['title'] ) ? trim( $data['title'] ) : '',
			'excerpt' => isset( $data['excerpt'] ) && is_string( $data['excerpt'] ) ? $this->trim_excerpt( $data['excerpt'] ) : '',
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

		// This plan's own content is a first-pass structural draft — a second,
		// content-focused pass (the existing streaming edit pipeline, which already
		// has full site context) rewrites the copy afterward. Site context is still
		// attached here as defense-in-depth so the first pass isn't generic even
		// before that second pass runs.
		$site_context = '';
		$site_name    = get_bloginfo( 'name' );
		$site_tagline = get_bloginfo( 'description' );
		if ( $site_name || $site_tagline ) {
			$site_context = "\nThis page is for the website \"{$site_name}\""
				. ( $site_tagline ? " — {$site_tagline}" : '' ) . '. ';
		}

		return 'You are a website page planner. Given a description of a page, return ONLY a JSON object '
			. '(no prose, no markdown code fences) of the shape '
			. '{"title": string, "excerpt": string, "sections": [ {"archetype": one of ["' . $allowed_names . '"], "content": {...}}, ... ]}. '
			. 'The "title" is a SHORT page title of 4 to 6 words — a real name/headline for the page, NOT a description of its layout or sections. '
			. 'The "excerpt" is a single-sentence summary of the page (max ~30 words). '
			. "The \"sections\" array lists the page sections top-to-bottom, using ONLY these archetypes and their exact content fields:\n"
			. $archetype_lines
			. "\nBuild a COMPLETE, substantial page: include AT LEAST 4 sections (ideally 5-6). Always open with \"heroCover\" "
			. 'and end with a "ctaBanner", and fill the middle with a varied mix (for example featureGrid, alternatingMediaText, '
			. 'testimonials, statsBar, pricingTiers, processSteps, galleryGrid, teamGrid, or faqAccordion) that fits the topic — '
			. 'prefer galleryGrid for visual businesses (food, interiors, portfolios), teamGrid for about/people pages, and '
			. 'processSteps when the service has a clear how-it-works flow. Even if the request only mentions one or '
			. 'two sections, still produce a full homepage with at least 4 sections. '
			. $site_context
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
		return $this->parse_response( $content )['sections'];
	}

	/**
	 * Parse the model's JSON into title/excerpt/sections.
	 *
	 * Accepts the object shape `{title, excerpt, sections:[...]}` and, for
	 * backward compatibility, a bare array of section items. Drops malformed
	 * section items rather than failing the whole request.
	 *
	 * @param string $content Raw model content.
	 * @return array{title: string, excerpt: string, sections: array<int, array<string, mixed>>}
	 */
	private function parse_response( string $content ): array {
		$content = trim( (string) preg_replace( '/```(?:json)?/i', '', $content ) );
		$data    = json_decode( $content, true );

		$empty = array(
			'title'    => '',
			'excerpt'  => '',
			'sections' => array(),
		);
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		// The object form format example : { title, excerpt, sections: [...] }.
		if ( isset( $data['sections'] ) && is_array( $data['sections'] ) ) {
			return array(
				'title'    => isset( $data['title'] ) && is_string( $data['title'] ) ? $this->trim_title( $data['title'] ) : '',
				'excerpt'  => isset( $data['excerpt'] ) && is_string( $data['excerpt'] ) ? $this->trim_excerpt( $data['excerpt'] ) : '',
				'sections' => $this->parse_plan_items( $data['sections'] ),
			);
		}

		// Backward-compatible bare array of section items.
		return array(
			'title'    => '',
			'excerpt'  => '',
			'sections' => $this->parse_plan_items( $data ),
		);
	}

	/**
	 * Validate a raw list of section items into a plan PageAssembler can use.
	 *
	 * @param array<int, mixed> $items Raw decoded section items.
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_plan_items( array $items ): array {
		$plan = array();
		foreach ( $items as $item ) {
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
	 * Normalise an AI-provided page title: strip wrapping quotes, collapse
	 * whitespace, and cap to 6 words (the "short 4-6 word title" contract).
	 *
	 * @param string $text Source title.
	 * @return string
	 */
	private function trim_title( string $text ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		$text = trim( $text, "\"'" );
		if ( '' === $text ) {
			return '';
		}
		$words = explode( ' ', $text );
		if ( count( $words ) > 6 ) {
			$text = implode( ' ', array_slice( $words, 0, 6 ) );
		}
		return rtrim( $text, " \t.,:;-" );
	}

	/**
	 * Derive a page title from the first heroCover item's heading, if any.
	 *
	 * @param array<int, array<string, mixed>> $plan Parsed plan.
	 * @return string
	 */
	private function derive_title( array $plan ): string {
		// Prefer the hero's heading.
		foreach ( $plan as $item ) {
			if ( 'heroCover' === $item['archetype'] && ! empty( $item['content']['heading'] ) && is_string( $item['content']['heading'] ) ) {
				return $item['content']['heading'];
			}
		}
		// Fallback: the first section that carries any heading-like field, so a
		// plan that doesn't open with heroCover still gets a title.
		foreach ( $plan as $item ) {
			$content = isset( $item['content'] ) && is_array( $item['content'] ) ? $item['content'] : array();
			foreach ( array( 'heading', 'name' ) as $field ) {
				if ( ! empty( $content[ $field ] ) && is_string( $content[ $field ] ) ) {
					return $content[ $field ];
				}
			}
		}
		return '';
	}

	/**
	 * Derive a page excerpt from the plan — the hero's subheading if present,
	 * else the first available intro/body/subheading among the sections. Kept
	 * to a sensible length for a meta excerpt.
	 *
	 * @param array<int, array<string, mixed>> $plan Parsed plan.
	 * @return string
	 */
	private function derive_excerpt( array $plan ): string {
		// Prefer the hero's subheading — it's written as the page's one-line pitch.
		foreach ( $plan as $item ) {
			if ( 'heroCover' === $item['archetype'] && ! empty( $item['content']['subheading'] ) && is_string( $item['content']['subheading'] ) ) {
				return $this->trim_excerpt( $item['content']['subheading'] );
			}
		}

		// Fallback: the first section that carries a usable summary-like field.
		foreach ( $plan as $item ) {
			$content = isset( $item['content'] ) && is_array( $item['content'] ) ? $item['content'] : array();
			foreach ( array( 'intro', 'subheading', 'body' ) as $field ) {
				if ( ! empty( $content[ $field ] ) && is_string( $content[ $field ] ) ) {
					return $this->trim_excerpt( $content[ $field ] );
				}
			}
		}

		return '';
	}

	/**
	 * Normalise a string into a meta excerpt (collapse whitespace, cap length on
	 * a word boundary).
	 *
	 * @param string $text Source text.
	 * @return string
	 */
	private function trim_excerpt( string $text ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( function_exists( 'wp_trim_words' ) ) {
			return wp_trim_words( $text, 40, '' );
		}
		if ( strlen( $text ) <= 300 ) {
			return $text;
		}
		$clipped = substr( $text, 0, 300 );
		$space   = strrpos( $clipped, ' ' );
		return false === $space ? $clipped : substr( $clipped, 0, $space );
	}
}
