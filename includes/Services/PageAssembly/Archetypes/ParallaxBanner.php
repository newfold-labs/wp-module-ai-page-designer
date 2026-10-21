<?php
/**
 * Parallax banner archetype: full-bleed fixed-background image section.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a `core/cover` section with `hasParallax` set, so the background
 * image stays fixed while the page scrolls past it — a visual "breather"
 * between content sections, distinct from {@see HeroCover} (which always
 * opens the page and carries the full eyebrow/subheading/CTA content set).
 * This used to be a fifth `HeroCover` variant, but sharing that archetype's
 * auto-pick hash pool meant it only turned up on roughly 1 in 5 generated
 * pages, and only ever as the opening hero. A standalone archetype can be
 * placed anywhere in the plan (including more than once per page).
 *
 * Content shape:
 * ```
 * [
 *   'heading'  => string|null,
 *   'imageUrl' => string (required — already resolved; see PageAssembler),
 * ]
 * ```
 *
 * Two variants:
 *  - `image`: a clean full-bleed photo with no dim overlay (`dimRatio` 0) and
 *    no text — a pure visual showcase.
 *  - `heading`: the same full-bleed cover with a centered heading over a
 *    legible dim overlay (`dimRatio` 60, matching {@see HeroCover}'s own
 *    proven-legible default), in the same "fancy" display face
 *    ({@see RendersMarkup::render_heading()}'s `$fancy` param) as every
 *    other big statement heading in the catalogue.
 *
 * Both variants share the exact `hasParallax` cover shape {@see HeroCover}
 * already established and confirmed live against this WP version's block
 * editor (see {@see RendersMarkup::render_parallax_image()}) — only the
 * `dimRatio` value and the inner container's content differ.
 *
 * The plan item's own `variant` wins when it names one of the two above;
 * otherwise render() picks one deterministically from a hash of the image
 * URL (never randomly, and never the heading — unlike HeroCover, `heading`
 * is optional here, so it can't be relied on as a seed; `imageUrl` is always
 * present).
 */
class ParallaxBanner implements Archetype {

	use RendersMarkup;

	const MIN_HEIGHT        = 360;
	const DIM_RATIO_IMAGE   = 0;
	const DIM_RATIO_HEADING = 60;

	/**
	 * Recognized variant names — see the class docblock.
	 *
	 * @var string[]
	 */
	const VARIANTS = array( 'image', 'heading' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'parallaxBanner';
	}

	/**
	 * {@inheritDoc}
	 */
	public function variants(): array {
		return self::VARIANTS;
	}

	/**
	 * {@inheritDoc}
	 */
	public function legacy_variants(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Theme/conformance context.
	 * @return string|null
	 */
	public function default_background( Context $ctx ): ?string {
		return $ctx->has_palette() ? $ctx->dark_slug() : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $content         Fully-resolved slot content (see the class docblock for its shape).
	 * @param string|null          $variant         Requested variant, or null for the archetype's default.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Palette slug to use as this section's background, or null for none.
	 * @return string Gutenberg block markup for one section.
	 */
	public function render( array $content, ?string $variant, Context $ctx, ?string $background_slug ): string {
		$image_url = isset( $content['imageUrl'] ) ? (string) $content['imageUrl'] : '';
		$variant   = $this->resolve_variant( $variant, $image_url );

		if ( 'heading' === $variant ) {
			return $this->render_with_heading( $content, $ctx, $background_slug );
		}
		return $this->render_image_only( $content, $ctx, $background_slug );
	}

	/**
	 * Render the `image` variant: a clean full-bleed photo, no dim overlay, no text.
	 *
	 * @param array<string, mixed> $content         Slot content.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Cover background slug.
	 * @return string
	 */
	private function render_image_only( array $content, Context $ctx, ?string $background_slug ): string {
		$image_url = isset( $content['imageUrl'] ) ? (string) $content['imageUrl'] : '';

		$inner = '<div class="wp-block-cover__inner-container"></div>';
		return $this->render_shell( $image_url, $inner, self::DIM_RATIO_IMAGE, $ctx, $background_slug );
	}

	/**
	 * Render the `heading` variant: the same full-bleed cover, with a
	 * centered heading over a legible dim overlay.
	 *
	 * @param array<string, mixed> $content         Slot content.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Cover background slug.
	 * @return string
	 */
	private function render_with_heading( array $content, Context $ctx, ?string $background_slug ): string {
		$bg_slug   = $background_slug ?? $this->default_background( $ctx );
		$text_slug = $this->text_slug_for_background( $ctx, $bg_slug );
		$image_url = isset( $content['imageUrl'] ) ? (string) $content['imageUrl'] : '';
		$heading   = isset( $content['heading'] ) ? (string) $content['heading'] : '';

		$inner = '<div class="wp-block-cover__inner-container">';
		if ( '' !== $heading ) {
			$inner .= $this->render_heading( $heading, 2, $text_slug, true, true );
		}
		$inner .= '</div>';

		return $this->render_shell( $image_url, $inner, self::DIM_RATIO_HEADING, $ctx, $background_slug );
	}

	/**
	 * Render the shared `hasParallax` `core/cover` shell — same shape as
	 * {@see HeroCover}'s `image-bg`/parallax rendering, parameterized by dim
	 * ratio and inner content so both variants stay byte-for-byte consistent
	 * with the one already confirmed live against this WP version.
	 *
	 * @param string      $image_url       Resolved image URL.
	 * @param string      $inner_container Pre-rendered `.wp-block-cover__inner-container` markup.
	 * @param int         $dim_ratio       Dim ratio percentage (0-100).
	 * @param Context     $ctx             Theme/conformance context.
	 * @param string|null $background_slug Cover background slug.
	 * @return string
	 */
	private function render_shell( string $image_url, string $inner_container, int $dim_ratio, Context $ctx, ?string $background_slug ): string {
		$bg_slug = $background_slug ?? $this->default_background( $ctx );

		$attrs = array(
			'url'           => $image_url,
			'dimRatio'      => $dim_ratio,
			'minHeight'     => self::MIN_HEIGHT,
			'minHeightUnit' => 'px',
			'hasParallax'   => true,
		);
		if ( null !== $bg_slug ) {
			$attrs['backgroundColor'] = $bg_slug;
		}

		$cover_classes = array( 'wp-block-cover', 'has-parallax' );
		$cover_style   = 'min-height:' . self::MIN_HEIGHT . 'px';
		if ( null !== $bg_slug ) {
			$cover_classes[] = 'has-' . $bg_slug . '-background-color';
			$cover_classes[] = 'has-background';
		}

		$inner  = $this->render_parallax_image( $image_url );
		$inner .= $this->render_cover_dim_span( $dim_ratio );
		$inner .= $inner_container;

		return $this->comment_wrap(
			'cover',
			$attrs,
			'<div class="' . implode( ' ', $cover_classes ) . '" style="' . $cover_style . '">' . $inner . '</div>'
		);
	}
}
