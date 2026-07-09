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
			. 'imageQuery: string (a short Unsplash search phrase for the hero image; not used by the "centered" variant), '
			. 'variant?: one of "split" | "image-bg" | "centered" | "stacked" — omit to let the renderer pick',
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
	 * Minimum stripped-text length an assembled plan must clear to be
	 * accepted without a retry. Catches a model response that's
	 * structurally valid (right archetypes, right JSON shape, so it passes
	 * the section-count check below) but returned empty content fields
	 * (heading/body strings of "") — PageAssembler renders that as real,
	 * non-empty HTML (divs, classes, block comments) with zero visible
	 * text, which the old "is the assembled string empty" check never
	 * caught.
	 *
	 * @var int
	 */
	private const MIN_VISIBLE_TEXT_LENGTH = 80;

	/**
	 * Maximum sections a focused, single-purpose page (About, Contact, FAQ,
	 * a single topic) is allowed to keep — see cap_focused_sections(). A
	 * deterministic guardrail: prompt guidance alone ("match section count
	 * to scope") doesn't reliably hold, and testing showed pushing it harder
	 * in the prompt measurably increased empty-content failures, so this is
	 * enforced in code instead of relying on the model to self-regulate.
	 *
	 * @var int
	 */
	private const MAX_FOCUSED_SECTIONS = 4;

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
						'prompt'           => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'A description of the page to build.', 'wp-module-ai-page-designer' ),
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'existing_title'   => array(
							'required'          => false,
							'type'              => 'string',
							'description'       => __( 'Title of an existing page being redesigned, for context only.', 'wp-module-ai-page-designer' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'existing_excerpt' => array(
							'required'          => false,
							'type'              => 'string',
							'description'       => __( 'Excerpt of an existing page being redesigned, for context only.', 'wp-module-ai-page-designer' ),
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

		$existing_title   = (string) $request->get_param( 'existing_title' );
		$existing_excerpt = (string) $request->get_param( 'existing_excerpt' );

		$ai_messages = array(
			array(
				'role'    => 'system',
				'content' => $this->build_system_prompt( $existing_title, $existing_excerpt ),
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		// Computed once, up front, and reused both for the retry's minimum
		// section threshold below and for cap_focused_sections() after the
		// loop — the same "match section count to scope" prompt guidance in
		// build_system_prompt() doesn't reliably hold in EITHER direction: a
		// focused page comes back bloated as often as a homepage comes back
		// too thin, so both ends need a deterministic guardrail, not just one.
		$is_homepage  = $this->is_homepage_scope( $prompt, $existing_title, $existing_excerpt );
		$min_sections = $is_homepage ? 4 : 2;

		// Retry once when the model returns unparseable JSON, too few sections
		// for the request's scope, or (right shape, but textually empty)
		// sections — plan parsing and blank-content responses are the main
		// reasons the page-plan flow falls through to the freeform generate
		// path (which produces weaker, generic metadata), and a single retry
		// recovers most of those due to run-to-run model variance.
		$plan                = array();
		$plan_title          = '';
		$plan_excerpt        = '';
		$last_error          = null;
		$best_visible_length = -1;
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
				$candidate_plan    = $parsed['sections'];
				$candidate_content = ( new PageAssembler() )->assemble( $candidate_plan );
				$visible_length    = strlen( trim( wp_strip_all_tags( $candidate_content ) ) );

				// Never let a retry that omitted title/excerpt clobber values a
				// previous attempt already provided.
				if ( '' !== $parsed['title'] ) {
					$plan_title = $parsed['title'];
				}
				if ( '' !== $parsed['excerpt'] ) {
					$plan_excerpt = $parsed['excerpt'];
				}
				// Keep the most substantial candidate seen so far, not simply
				// the latest one. A retry nudge asking for more sections can
				// come back the right shape (passes the section-count check)
				// but with the extra copy diluted into blank fields — a
				// right-shaped-but-empty page is a worse outcome than a
				// shorter one with real content, so visible text length (not
				// attempt order) decides what actually gets kept.
				if ( $visible_length > $best_visible_length ) {
					$plan                = $candidate_plan;
					$best_visible_length = $visible_length;
				}
				// Accept a substantial, non-blank plan right away; if it came
				// back below the scope's minimum section count (see
				// $min_sections above — a homepage needs more than a focused
				// page does) or textually empty (right shape, no actual copy
				// in the fields) try once more with the exact same messages —
				// run-to-run model variance alone recovers most of these — and
				// keep whichever attempt scored highest above. Deliberately
				// NOT adding an extra "expand to N sections" nudge message
				// here: that was tried and, verified live, it made the model
				// pad out the requested section count by leaving the new
				// sections' content fields blank far more often than a plain
				// retry does — the exact "right-shaped-but-empty" failure mode
				// this file already has to guard against elsewhere.
				$is_substantial = count( $candidate_plan ) >= $min_sections && $visible_length >= self::MIN_VISIBLE_TEXT_LENGTH;
				if ( $is_substantial || 1 === $attempt ) {
					break;
				}
			}
		}

		// Empty plan, or every attempt came back right-shaped but with no real
		// visible text (see the $best_visible_length tracking above) — neither
		// is a page worth showing. Fail the same way an empty plan does, so
		// the caller falls back to the freeform generate path instead of
		// shipping a blank page.
		if ( empty( $plan ) || $best_visible_length < self::MIN_VISIBLE_TEXT_LENGTH ) {
			if ( null !== $last_error ) {
				return $last_error;
			}
			return new \WP_Error( 'generation_failed', __( 'The AI response could not be turned into a page. Please try again.', 'wp-module-ai-page-designer' ), array( 'status' => 502 ) );
		}

		if ( ! $is_homepage ) {
			$plan = $this->cap_focused_sections( $plan );
		} elseif ( count( $plan ) < $min_sections ) {
			$plan = $this->pad_homepage_sections( $plan, $prompt, $min_sections - count( $plan ) );
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
	 * Guess whether a page-plan request is for a broad homepage/landing page
	 * (allowed the full section range) versus a focused, single-purpose page
	 * (About, Contact, FAQ, a single topic — capped by cap_focused_sections()
	 * below). Same simple keyword-match style as PatternLayoutProvider's
	 * existing intent scoring — good enough since this only gates a section
	 * count cap, not full generation. Defaults to "focused" (the safer,
	 * less-bloated default) when no homepage signal is present.
	 *
	 * @param string $prompt           The user's page-plan prompt.
	 * @param string $existing_title   Title of an existing page being redesigned, if any.
	 * @param string $existing_excerpt Excerpt of an existing page being redesigned, if any.
	 * @return bool True if this looks like a broad homepage/landing-page request.
	 */
	private function is_homepage_scope( string $prompt, string $existing_title, string $existing_excerpt ): bool {
		$haystack = strtolower( $prompt . ' ' . $existing_title . ' ' . $existing_excerpt );

		if ( 'home' === trim( strtolower( $existing_title ) ) ) {
			return true;
		}

		$homepage_keywords = array( 'homepage', 'home page', 'landing page', 'main page', 'front page' );
		foreach ( $homepage_keywords as $keyword ) {
			if ( false !== strpos( $haystack, $keyword ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Deterministically cap a focused page's sections to MAX_FOCUSED_SECTIONS
	 * — a guardrail because the "match section count to scope" prompt
	 * guidance alone doesn't reliably hold (see build_system_prompt()), and
	 * pushing it harder in the prompt measurably hurt content reliability
	 * during testing. Keeps the opening section, up to MAX_FOCUSED_SECTIONS-1
	 * of the sections that follow, and the closing "ctaBanner" if the plan
	 * already ends with one — rather than cutting it off arbitrarily.
	 *
	 * @param array<int, array<string, mixed>> $plan Parsed section items.
	 * @return array<int, array<string, mixed>>
	 */
	private function cap_focused_sections( array $plan ): array {
		if ( count( $plan ) <= self::MAX_FOCUSED_SECTIONS ) {
			return $plan;
		}

		$last            = end( $plan );
		$ends_with_cta   = is_array( $last ) && isset( $last['archetype'] ) && 'ctaBanner' === $last['archetype'];
		if ( $ends_with_cta ) {
			$kept   = array_slice( $plan, 0, self::MAX_FOCUSED_SECTIONS - 1 );
			$kept[] = $last;
			return $kept;
		}
		return array_slice( $plan, 0, self::MAX_FOCUSED_SECTIONS );
	}

	/**
	 * Top up a real but under-target homepage plan with a few more sections,
	 * via a small, SEPARATELY-scoped AI call — never appended to the main
	 * plan's own conversation thread. Asking the model to "expand" the
	 * existing plan in-thread was tried and, verified live, made it dilute
	 * the new sections' content into blank fields (the exact "right-shaped-
	 * but-empty" failure mode this file guards against elsewhere); a fresh,
	 * narrowly-scoped request for a small number of brand-new sections
	 * doesn't carry that instruction shape. Held to the same rule the rest
	 * of this file follows: a supplemental step may only make the result
	 * additively better — on any failure, or if the additions themselves
	 * come back blank, the original (shorter but real) plan is kept as-is.
	 *
	 * @param array<int, array<string, mixed>> $plan   The already-accepted, real plan.
	 * @param string                            $prompt The original page-plan prompt.
	 * @param int                                $needed How many more sections to try for.
	 * @return array<int, array<string, mixed>>
	 */
	private function pad_homepage_sections( array $plan, string $prompt, int $needed ): array {
		$used_archetypes = array_unique( array_column( $plan, 'archetype' ) );
		$archetype_lines = '';
		foreach ( self::ARCHETYPE_SCHEMAS as $name => $schema ) {
			$archetype_lines .= "- \"{$name}\": {$schema}\n";
		}
		$allowed_names = implode( '", "', array_keys( self::ARCHETYPE_SCHEMAS ) );

		$system = 'You are a website page planner. A homepage is being built for this request: '
			. "\"{$prompt}\". It already has these sections, in order: " . implode( ', ', $used_archetypes ) . '. '
			. "Suggest exactly {$needed} more distinct section(s) that would strengthen this homepage, different in "
			. 'purpose from the sections already listed. Return ONLY a JSON object (no prose, no markdown code '
			. 'fences) of the shape {"sections": [ {"archetype": one of ["' . $allowed_names . '"], "content": {...}}, ... ]}, '
			. "using ONLY these archetypes and their exact content fields:\n" . $archetype_lines
			. 'Write real, specific copy for the described business — never placeholder text like "Lorem ipsum" or "Heading here".';

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
			return $plan;
		}

		$additions = $this->parse_response( (string) $result['content'] )['sections'];
		if ( empty( $additions ) ) {
			return $plan;
		}

		// Only keep additions with real, visible copy of their own — never let
		// a blank supplemental section slip in just because the count matched.
		$kept = array();
		foreach ( $additions as $addition ) {
			$rendered = ( new PageAssembler() )->assemble( array( $addition ) );
			if ( strlen( trim( wp_strip_all_tags( $rendered ) ) ) >= 30 ) {
				$kept[] = $addition;
			}
		}
		if ( empty( $kept ) ) {
			return $plan;
		}

		// Insert before a trailing "ctaBanner" so the page still ends on a CTA.
		$last          = end( $plan );
		$ends_with_cta = is_array( $last ) && isset( $last['archetype'] ) && 'ctaBanner' === $last['archetype'];
		if ( $ends_with_cta ) {
			$body = array_slice( $plan, 0, -1 );
			return array_merge( $body, $kept, array( $last ) );
		}
		return array_merge( $plan, $kept );
	}

	/**
	 * Build the page-plan system prompt from the registered archetype schemas.
	 *
	 * @param string $existing_title   Title of the existing page being redesigned, if any.
	 * @param string $existing_excerpt Excerpt of the existing page being redesigned, if any.
	 * @return string
	 */
	private function build_system_prompt( string $existing_title = '', string $existing_excerpt = '' ): string {
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

		// Redesign of an existing page: the caller sends only its title/excerpt
		// (never the old body markup or images — see PagePlanController's REST
		// args), so the model has nothing of the old structure to anchor a
		// re-skin to. Kept unquoted and in the SYSTEM prompt (background fact),
		// not appended to the user's own task message — testing showed folding
		// this into the free-form prompt text measurably increased the odds of
		// the model returning right-shaped-but-textually-empty sections.
		$redesign_context = '';
		if ( $existing_title || $existing_excerpt ) {
			$redesign_context = "\nThis is a redesign of an existing page. Existing page title: {$existing_title}."
				. ( $existing_excerpt ? " Existing page excerpt: {$existing_excerpt}." : '' )
				. ' Use that only to understand the page\'s purpose — write entirely new copy for every section, '
				. "don't reuse the existing page's specific wording. ";
		}

		return 'You are a website page planner. Given a description of a page, return ONLY a JSON object '
			. '(no prose, no markdown code fences) of the shape '
			. '{"title": string, "excerpt": string, "sections": [ {"archetype": one of ["' . $allowed_names . '"], "content": {...}}, ... ]}. '
			. 'The "title" is a SHORT page title of 4 to 6 words — a real name/headline for the page, NOT a description of its layout or sections. '
			. 'The "excerpt" is a single-sentence summary of the page (max ~30 words). '
			. "The \"sections\" array lists the page sections top-to-bottom, using ONLY these archetypes and their exact content fields:\n"
			. $archetype_lines
			. "\nAlways open with \"heroCover\". Match the section count to the page's scope. A focused, "
			. 'single-purpose page (About/story, Contact, FAQ, a single topic) needs about 2-4 sections total: '
			. 'heroCover, one or two sections covering that purpose, and done. A homepage or broad landing-page '
			. 'request benefits from 4-6 varied sections (for example featureGrid, alternatingMediaText, testimonials, '
			. 'statsBar, pricingTiers, processSteps, galleryGrid, teamGrid, or faqAccordion), ending with a "ctaBanner". '
			. 'Prefer galleryGrid for visual businesses (food, interiors, portfolios), teamGrid for about/people pages, and '
			. 'processSteps when the service has a clear how-it-works flow. '
			. $site_context
			. $redesign_context
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
