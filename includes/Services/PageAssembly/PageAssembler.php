<?php
/**
 * Assembles a page from a plan of typed section archetypes.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\ImageService;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Harness;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\AlternatingMediaText;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\Archetype;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\BookingForm;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\CtaBanner;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\FaqAccordion;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\FeatureGrid;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\GalleryGrid;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\HeroCover;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\PricingTiers;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\ProcessSteps;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\RichText;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\StatsBar;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\TeamGrid;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\Testimonials;

/**
 * Renders a page plan — `[{archetype, content, variant?, background?}]` — into
 * Gutenberg block markup by dispatching each item to its named {@see Archetype}.
 *
 * Two responsibilities live here rather than in the archetypes themselves, by
 * design (archetypes stay pure functions, see {@see Archetype}):
 *  - **Image resolution.** Any `imageQuery`/`avatarQuery` slot in an item's
 *    content is resolved to a concrete `imageUrl`/`avatarUrl` via the injected
 *    {@see ImageService} before the archetype ever sees it.
 *  - **Background rhythm.** An item's own `background` override wins; failing
 *    that, the archetype's own {@see Archetype::default_background()} is used;
 *    failing that (a plain "surface" section), this class alternates in
 *    {@see Context::muted_light_slug()} for every other such section so
 *    consecutive plain sections don't all look identical.
 *
 * The final concatenated markup is run through the existing, idempotent
 * {@see Harness::conform()} as a safety net — the same gate Stage 1 output goes
 * through — though archetypes are built to need zero repair passes.
 */
class PageAssembler {

	/**
	 * Image service, used to resolve `imageQuery`/`avatarQuery` slots.
	 *
	 * @var ImageService
	 */
	private $image_service;

	/**
	 * Registered archetypes, keyed by name.
	 *
	 * @var array<string, Archetype>
	 */
	private $archetypes;

	/**
	 * Constructor.
	 *
	 * @param ImageService|null     $image_service Image service (defaults to a new instance).
	 * @param array<Archetype>|null $archetypes   Custom archetype set (defaults to the built-in v1 catalogue).
	 */
	public function __construct( ?ImageService $image_service = null, ?array $archetypes = null ) {
		$this->image_service = $image_service ?? new ImageService();

		$archetypes       = null === $archetypes ? array(
			new HeroCover(),
			new FeatureGrid(),
			new AlternatingMediaText(),
			new CtaBanner(),
			new BookingForm(),
			new Testimonials(),
			new PricingTiers(),
			new FaqAccordion(),
			new StatsBar(),
			new RichText(),
			new GalleryGrid(),
			new TeamGrid(),
			new ProcessSteps(),
		) : $archetypes;
		$this->archetypes = array();
		foreach ( $archetypes as $archetype ) {
			$this->archetypes[ $archetype->name() ] = $archetype;
		}
	}

	/**
	 * Assemble a page plan into Gutenberg block markup.
	 *
	 * Unknown archetype names are skipped rather than failing the whole page —
	 * a partial page from a plan with one bad item is better than nothing.
	 *
	 * @param array<int, array<string, mixed>> $plan Plan items: `[{archetype, content, variant?, background?}]`.
	 * @param Context|null                     $ctx  Conformance context (defaults to the active theme).
	 * @return string Gutenberg block markup for the whole page.
	 */
	public function assemble( array $plan, ?Context $ctx = null ): string {
		$context = null === $ctx ? Context::from_theme() : $ctx;

		$markup       = '';
		$muted_toggle = false;

		foreach ( $plan as $item ) {
			$name = isset( $item['archetype'] ) ? (string) $item['archetype'] : '';
			if ( ! isset( $this->archetypes[ $name ] ) ) {
				continue;
			}
			$archetype = $this->archetypes[ $name ];

			$variant = isset( $item['variant'] ) ? (string) $item['variant'] : null;
			$content = isset( $item['content'] ) && is_array( $item['content'] ) ? $item['content'] : array();
			$content = $this->resolve_images( $content );

			list( $background, $muted_toggle ) = $this->resolve_background( $item, $archetype, $context, $muted_toggle );

			$markup .= $archetype->render( $content, $variant, $context, $background );
		}

		$markup = trim( $markup );
		if ( '' === $markup ) {
			return $markup;
		}

		return ( new Harness() )->conform( $markup );
	}

	/**
	 * Resolve a plan item's background slug: its own override, else the
	 * archetype's default, else the alternating muted-light rhythm for plain
	 * "surface" sections.
	 *
	 * @param array<string, mixed> $item         Plan item.
	 * @param Archetype            $archetype    Resolved archetype.
	 * @param Context              $ctx          Conformance context.
	 * @param bool                 $muted_toggle Current alternation state.
	 * @return array{0: string|null, 1: bool} [ background_slug, next muted_toggle state ].
	 */
	private function resolve_background( array $item, Archetype $archetype, Context $ctx, bool $muted_toggle ): array {
		if ( isset( $item['background'] ) && is_string( $item['background'] ) && '' !== $item['background'] ) {
			return array( $item['background'], $muted_toggle );
		}

		$default = $archetype->default_background( $ctx );
		if ( null !== $default ) {
			return array( $default, $muted_toggle );
		}

		$background = ( $muted_toggle && null !== $ctx->muted_light_slug() ) ? $ctx->muted_light_slug() : null;
		return array( $background, ! $muted_toggle );
	}

	/**
	 * Recursively resolve `imageQuery`/`avatarQuery` slots to concrete URLs.
	 *
	 * @param array<string, mixed> $content Plan item content (may be nested, e.g. `items[]`).
	 * @return array<string, mixed>
	 */
	private function resolve_images( array $content ): array {
		foreach ( $content as $key => $value ) {
			if ( is_array( $value ) ) {
				$content[ $key ] = $this->resolve_images( $value );
			}
		}

		foreach ( array(
			'imageQuery'  => 'imageUrl',
			'avatarQuery' => 'avatarUrl',
		) as $query_key => $url_key ) {
			if ( isset( $content[ $query_key ] ) && ! isset( $content[ $url_key ] ) ) {
				$content[ $url_key ] = $this->resolve_one_image( (string) $content[ $query_key ] );
				unset( $content[ $query_key ] );
			}
		}

		return $content;
	}

	/**
	 * Resolve a single image search query to a URL via the injected ImageService.
	 *
	 * @param string $query Search query.
	 * @return string Resolved URL, or empty string when unavailable.
	 */
	private function resolve_one_image( string $query ): string {
		if ( '' === trim( $query ) ) {
			return '';
		}
		$images = $this->image_service->get_unsplash_images( $query );
		return $images[0] ?? '';
	}
}
