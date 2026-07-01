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
 * v1 supports a single variant, `image-bg`.
 */
class HeroCover implements Archetype {

	use RendersMarkup;

	const MIN_HEIGHT = 520;
	const DIM_RATIO  = 60;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'heroCover';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_background( Context $ctx ): ?string {
		return $ctx->has_palette() ? $ctx->dark_slug() : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $content, ?string $variant, Context $ctx, ?string $background_slug ): string {
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
	 * @return string
	 */
	private function render_ctas( ?array $primary_cta, ?array $secondary_cta, ?string $cover_bg_slug, Context $ctx ): string {
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

		return $this->render_buttons_wrap( $buttons );
	}
}
