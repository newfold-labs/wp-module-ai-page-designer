<?php
/**
 * Hero section archetype: full-bleed image cover with heading + CTAs.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a `core/cover` hero section directly in the shape the Stage 1
 * `CoverDefaults`/`CoverImage` rules already enforce (minHeight, dimRatio, a
 * solid backgroundColor fallback, and a rendered `<img>` background element),
 * so it is valid against {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator}
 * with zero repair passes.
 *
 * Content shape:
 * ```
 * [
 *   'eyebrow'      => string|null,
 *   'heading'      => string (required),
 *   'subheading'   => string|null,
 *   'primaryCta'   => [ 'label' => string, 'url' => string ] (required),
 *   'secondaryCta' => [ 'label' => string, 'url' => string ]|null,
 *   'imageUrl'     => string (required — already resolved; see PageAssembler),
 * ]
 * ```
 *
 * Four variants:
 *  - `split`: a two-column `core/columns` row — left is the text stack,
 *    right is the image wrapped in a rounded, drop-shadowed "floating card"
 *    (see {@see RendersMarkup::render_floating_card()}), the whole section
 *    backed by a gradient-over-solid-slug backdrop (see
 *    {@see RendersMarkup::render_gradient_section()}).
 *  - `image-bg`: the original full-bleed `core/cover` treatment.
 *  - `centered`: no image at all — a centered, width-constrained text stack
 *    (eyebrow/heading/subheading/CTAs) on the gradient backdrop. For a bold
 *    statement/mission opener where a photo would compete with the copy.
 *  - `stacked`: a short, centered image banner strip (120px tall, capped to
 *    the same 720px width as the text below it) above a centered,
 *    width-constrained text stack — a compact "eyebrow photo" layout,
 *    distinct from both `split`'s side-by-side columns and `image-bg`'s
 *    full-bleed treatment.
 *
 * The plan item's own `variant` wins when it names one of the four above;
 * otherwise render() picks one deterministically from a hash of the heading
 * (never randomly — archetypes are pure functions, see PageAssembler's own
 * design note) so that omitting `variant` (the common case — the model
 * doesn't reliably self-vary it, see
 * {@see \NewfoldLabs\WP\Module\AIPageDesigner\RestApi\PagePlanController::ARCHETYPE_SCHEMAS})
 * still produces visual variety across pages — no two pages share a heading
 * — instead of every hero defaulting to the same layout.
 */
class HeroCover implements Archetype {

	use RendersMarkup;

	const MIN_HEIGHT = 520;
	const DIM_RATIO  = 60;

	/**
	 * Recognized variant names — see the class docblock.
	 *
	 * @var string[]
	 */
	const VARIANTS = array( 'split', 'image-bg', 'centered', 'stacked' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'heroCover';
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
	 * @param array<string, mixed> $content         Fully-resolved slot content (see the concrete class docblock for its shape).
	 * @param string|null          $variant         Requested variant, or null for the archetype's default.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Palette slug to use as this section's background, or null for none.
	 * @return string Gutenberg block markup for one section.
	 */
	public function render( array $content, ?string $variant, Context $ctx, ?string $background_slug ): string {
		if ( null === $variant || ! in_array( $variant, self::VARIANTS, true ) ) {
			// Deterministic, not random: archetypes are pure functions (see the
			// class docblock and PageAssembler's own "archetypes stay pure
			// functions" design note) — assemble() is tested to return identical
			// output for identical input. Hashing the heading (always present,
			// required) into a variant index keeps that invariant (same content
			// -> same variant every time) while still varying across real pages,
			// since no two pages share a heading.
			$heading = isset( $content['heading'] ) ? (string) $content['heading'] : '';
			$variant = self::VARIANTS[ crc32( $heading ) % count( self::VARIANTS ) ];
		}
		switch ( $variant ) {
			case 'image-bg':
				return $this->render_image_bg( $content, $ctx, $background_slug );
			case 'centered':
				return $this->render_centered( $content, $ctx, $background_slug );
			case 'stacked':
				return $this->render_stacked( $content, $ctx, $background_slug );
			default:
				return $this->render_split( $content, $ctx, $background_slug );
		}
	}

	/**
	 * Render the `split` variant: text column + floating image card column.
	 *
	 * @param array<string, mixed> $content         Slot content.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Section background slug.
	 * @return string
	 */
	private function render_split( array $content, Context $ctx, ?string $background_slug ): string {
		$bg_slug   = $background_slug ?? $this->default_background( $ctx );
		$text_slug = $this->text_slug_for_background( $ctx, $bg_slug );

		$eyebrow       = isset( $content['eyebrow'] ) ? (string) $content['eyebrow'] : '';
		$heading       = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$subheading    = isset( $content['subheading'] ) ? (string) $content['subheading'] : '';
		$image_url     = isset( $content['imageUrl'] ) ? (string) $content['imageUrl'] : '';
		$primary_cta   = isset( $content['primaryCta'] ) && is_array( $content['primaryCta'] ) ? $content['primaryCta'] : null;
		$secondary_cta = isset( $content['secondaryCta'] ) && is_array( $content['secondaryCta'] ) ? $content['secondaryCta'] : null;

		$text_column = '';
		if ( '' !== $eyebrow ) {
			$text_column .= $this->render_paragraph( $eyebrow, $text_slug, false );
		}
		$text_column .= $this->render_heading( $heading, 1, $text_slug, false );
		if ( '' !== $subheading ) {
			$text_column .= $this->render_paragraph( $subheading, $text_slug, false );
		}
		if ( null !== $primary_cta || null !== $secondary_cta ) {
			$text_column .= $this->render_ctas( $primary_cta, $secondary_cta, $bg_slug, $ctx, false );
		}
		$text_column = $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $text_column . '</div>' );

		$card_slug    = $this->contrasting_slug( $ctx, $bg_slug );
		$card_text    = $this->text_slug_for_background( $ctx, $card_slug );
		$image_column = $this->render_floating_card( $this->render_image_block( $image_url ), $ctx, $card_slug, $card_text );
		$image_column = $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $image_column . '</div>' );

		$columns = $this->render_columns_wrap( $text_column . $image_column, $ctx );

		return $this->render_gradient_section( $columns, $ctx, $bg_slug );
	}

	/**
	 * Render the `image-bg` variant: the original full-bleed `core/cover` hero.
	 *
	 * @param array<string, mixed> $content         Slot content.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Cover background slug.
	 * @return string
	 */
	private function render_image_bg( array $content, Context $ctx, ?string $background_slug ): string {
		$bg_slug   = $background_slug ?? $this->default_background( $ctx );
		$text_slug = $this->text_slug_for_background( $ctx, $bg_slug );

		$eyebrow       = isset( $content['eyebrow'] ) ? (string) $content['eyebrow'] : '';
		$heading       = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$subheading    = isset( $content['subheading'] ) ? (string) $content['subheading'] : '';
		$image_url     = isset( $content['imageUrl'] ) ? (string) $content['imageUrl'] : '';
		$primary_cta   = isset( $content['primaryCta'] ) && is_array( $content['primaryCta'] ) ? $content['primaryCta'] : null;
		$secondary_cta = isset( $content['secondaryCta'] ) && is_array( $content['secondaryCta'] ) ? $content['secondaryCta'] : null;

		$attrs = array(
			'url'           => $image_url,
			'dimRatio'      => self::DIM_RATIO,
			'minHeight'     => self::MIN_HEIGHT,
			'minHeightUnit' => 'px',
		);
		if ( null !== $bg_slug ) {
			$attrs['backgroundColor'] = $bg_slug;
		}

		$cover_classes = array( 'wp-block-cover', 'has-background-dim-' . self::DIM_RATIO, 'has-background-dim' );
		$cover_style   = 'min-height:' . self::MIN_HEIGHT . 'px';
		if ( null !== $bg_slug ) {
			$cover_classes[] = 'has-' . $bg_slug . '-background-color';
			$cover_classes[] = 'has-background';
			$cover_style    .= ';background-color:var(--wp--preset--color--' . $bg_slug . ')';
		}

		$inner  = $this->render_image( $image_url );
		$inner .= '<div class="wp-block-cover__inner-container">';
		if ( '' !== $eyebrow ) {
			$inner .= $this->render_paragraph( $eyebrow, $text_slug, true );
		}
		$inner .= $this->render_heading( $heading, 1, $text_slug, true );
		if ( '' !== $subheading ) {
			$inner .= $this->render_paragraph( $subheading, $text_slug, true );
		}
		if ( null !== $primary_cta || null !== $secondary_cta ) {
			$inner .= $this->render_ctas( $primary_cta, $secondary_cta, $bg_slug, $ctx );
		}
		$inner .= '</div>';

		return $this->comment_wrap(
			'cover',
			$attrs,
			'<div class="' . implode( ' ', $cover_classes ) . '" style="' . $cover_style . '">' . $inner . '</div>'
		);
	}

	/**
	 * Render the `centered` variant: no image — a centered, width-constrained
	 * text stack on the gradient backdrop.
	 *
	 * @param array<string, mixed> $content         Slot content.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Section background slug.
	 * @return string
	 */
	private function render_centered( array $content, Context $ctx, ?string $background_slug ): string {
		$bg_slug   = $background_slug ?? $this->default_background( $ctx );
		$text_slug = $this->text_slug_for_background( $ctx, $bg_slug );

		$eyebrow       = isset( $content['eyebrow'] ) ? (string) $content['eyebrow'] : '';
		$heading       = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$subheading    = isset( $content['subheading'] ) ? (string) $content['subheading'] : '';
		$primary_cta   = isset( $content['primaryCta'] ) && is_array( $content['primaryCta'] ) ? $content['primaryCta'] : null;
		$secondary_cta = isset( $content['secondaryCta'] ) && is_array( $content['secondaryCta'] ) ? $content['secondaryCta'] : null;

		$stack = '';
		if ( '' !== $eyebrow ) {
			$stack .= $this->render_paragraph( $eyebrow, $text_slug, true );
		}
		$stack .= $this->render_heading( $heading, 1, $text_slug, true );
		if ( '' !== $subheading ) {
			$stack .= $this->render_paragraph( $subheading, $text_slug, true );
		}
		if ( null !== $primary_cta || null !== $secondary_cta ) {
			$stack .= $this->render_ctas( $primary_cta, $secondary_cta, $bg_slug, $ctx, true );
		}

		return $this->render_gradient_section( $this->wrap_constrained( $stack ), $ctx, $bg_slug );
	}

	/**
	 * Render the `stacked` variant: a rounded image above a centered,
	 * width-constrained text stack — a vertical "magazine cover" layout.
	 *
	 * @param array<string, mixed> $content         Slot content.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Section background slug.
	 * @return string
	 */
	private function render_stacked( array $content, Context $ctx, ?string $background_slug ): string {
		$bg_slug   = $background_slug ?? $this->default_background( $ctx );
		$text_slug = $this->text_slug_for_background( $ctx, $bg_slug );

		$eyebrow       = isset( $content['eyebrow'] ) ? (string) $content['eyebrow'] : '';
		$heading       = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$subheading    = isset( $content['subheading'] ) ? (string) $content['subheading'] : '';
		$image_url     = isset( $content['imageUrl'] ) ? (string) $content['imageUrl'] : '';
		$primary_cta   = isset( $content['primaryCta'] ) && is_array( $content['primaryCta'] ) ? $content['primaryCta'] : null;
		$secondary_cta = isset( $content['secondaryCta'] ) && is_array( $content['secondaryCta'] ) ? $content['secondaryCta'] : null;

		$stack = '';
		if ( '' !== $eyebrow ) {
			$stack .= $this->render_paragraph( $eyebrow, $text_slug, true );
		}
		$stack .= $this->render_heading( $heading, 1, $text_slug, true );
		if ( '' !== $subheading ) {
			$stack .= $this->render_paragraph( $subheading, $text_slug, true );
		}
		if ( null !== $primary_cta || null !== $secondary_cta ) {
			$stack .= $this->render_ctas( $primary_cta, $secondary_cta, $bg_slug, $ctx, true );
		}

		$image = $this->render_stacked_image( $image_url );
		$inner = $image . $this->wrap_constrained( $stack );

		return $this->render_gradient_section( $inner, $ctx, $bg_slug );
	}

	/**
	 * Render the `stacked` variant's image as a short, centered banner strip
	 * — 120px tall, capped to the same 720px width as {@see wrap_constrained()}
	 * uses for the text below it — rather than a full-bleed photo. Deliberately
	 * a bespoke renderer (not {@see RendersMarkup::render_image_block()}, which
	 * fills its parent at `width:100%;height:100%` with no cap): the fixed
	 * height needs its own clipping wrapper, and reusing the shared helper
	 * here would also change `GalleryGrid`/`AlternatingMediaText`'s sizing,
	 * which isn't wanted.
	 *
	 * @param string $image_url Resolved image URL.
	 * @return string
	 */
	private function render_stacked_image( string $image_url ): string {
		if ( '' === $image_url ) {
			return '';
		}
		$img = '<img src="' . $this->esc_url( $image_url ) . '" alt="" style="width:100%;height:100%;object-fit:cover"/>';
		return $this->comment_wrap(
			'image',
			array( 'sizeSlug' => 'large' ),
			'<figure class="wp-block-image size-large" '
				. 'style="max-width:720px;height:120px;margin-left:auto;margin-right:auto;overflow:hidden;border-radius:16px">'
				. $img . '</figure>'
		);
	}

	/**
	 * Wrap already-rendered block markup in a plain, width-constrained,
	 * horizontally-centered `core/group` — the shared shell behind the
	 * `centered`/`stacked` variants' text stack. Deliberately a bare inline
	 * `max-width`/`margin:auto` (no `layout: constrained` attribute), matching
	 * every other size/spacing declaration in this trait.
	 *
	 * @param string $inner Rendered inner block markup.
	 * @return string
	 */
	private function wrap_constrained( string $inner ): string {
		return $this->comment_wrap(
			'group',
			array(),
			'<div class="wp-block-group" style="max-width:720px;margin-left:auto;margin-right:auto">' . $inner . '</div>'
		);
	}

	/**
	 * Render the cover's rendered background image element.
	 *
	 * @param string $image_url Resolved image URL.
	 * @return string
	 */
	private function render_image( string $image_url ): string {
		if ( '' === $image_url ) {
			return '';
		}
		return '<img class="wp-block-cover__image-background" alt="" src="' . $this->esc_url( $image_url ) . '" data-object-fit="cover"/>';
	}

	/**
	 * Render the primary/secondary CTA buttons.
	 *
	 * Deliberately never gives a button the same backgroundColor as the cover's
	 * own background slug (see {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\ButtonBackgroundCollision}
	 * for the defect this avoids by construction): the primary button uses
	 * {@see RendersMarkup::contrasting_slug()} (the theme accent when it
	 * differs from the cover bg, else the opposite of dark/light), and the
	 * secondary button is an outline style with no background at all, so
	 * neither can ever collide with the section behind it.
	 *
	 * @param array<string, string>|null $primary_cta   [ 'label', 'url' ] or null.
	 * @param array<string, string>|null $secondary_cta [ 'label', 'url' ] or null.
	 * @param string|null                $cover_bg_slug The cover's own background slug.
	 * @param Context                    $ctx           Theme/conformance context.
	 * @param bool                       $center        Whether to center the button row (false for the left-aligned `split` variant).
	 * @return string
	 */
	private function render_ctas( ?array $primary_cta, ?array $secondary_cta, ?string $cover_bg_slug, Context $ctx, bool $center = true ): string {
		$buttons = '';
		if ( null !== $primary_cta && ! empty( $primary_cta['label'] ) ) {
			$bg_slug   = $this->contrasting_slug( $ctx, $cover_bg_slug );
			$text_slug = $this->text_slug_for_background( $ctx, $bg_slug );
			$buttons  .= $this->render_button( (string) $primary_cta['label'], isset( $primary_cta['url'] ) ? (string) $primary_cta['url'] : '#', $bg_slug, $text_slug );
		}
		if ( null !== $secondary_cta && ! empty( $secondary_cta['label'] ) ) {
			$text_slug = $this->text_slug_for_background( $ctx, $cover_bg_slug );
			$buttons  .= $this->render_button( (string) $secondary_cta['label'], isset( $secondary_cta['url'] ) ? (string) $secondary_cta['url'] : '#', null, $text_slug, true );
		}

		return $this->render_buttons_wrap( $buttons, $center );
	}
}
