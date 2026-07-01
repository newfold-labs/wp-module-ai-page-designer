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
		$text_slug = ( null !== $bg_slug && $ctx->is_dark_slug( $bg_slug ) ) ? $ctx->light_slug() : $ctx->dark_slug();

		$eyebrow      = isset( $content['eyebrow'] ) ? (string) $content['eyebrow'] : '';
		$heading      = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$subheading   = isset( $content['subheading'] ) ? (string) $content['subheading'] : '';
		$image_url    = isset( $content['imageUrl'] ) ? (string) $content['imageUrl'] : '';
		$primary_cta  = isset( $content['primaryCta'] ) && is_array( $content['primaryCta'] ) ? $content['primaryCta'] : null;
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
			$inner .= $this->render_paragraph( $eyebrow, $text_slug, array( 'has-text-align-center' ) );
		}
		$inner .= $this->render_heading( $heading, $text_slug );
		if ( '' !== $subheading ) {
			$inner .= $this->render_paragraph( $subheading, $text_slug, array( 'has-text-align-center' ) );
		}
		if ( null !== $primary_cta || null !== $secondary_cta ) {
			$inner .= $this->render_buttons( $primary_cta, $secondary_cta, $bg_slug, $ctx );
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
	 * Render the hero heading.
	 *
	 * @param string      $text      Heading text.
	 * @param string|null $text_slug Text color slug.
	 * @return string
	 */
	private function render_heading( string $text, ?string $text_slug ): string {
		$classes = array( 'wp-block-heading', 'has-text-align-center' );
		$attrs   = array(
			'textAlign' => 'center',
			'level'     => 1,
		);
		$style = '';
		if ( null !== $text_slug ) {
			$classes[]        = 'has-' . $text_slug . '-color';
			$classes[]        = 'has-text-color';
			$attrs['textColor'] = $text_slug;
			$style             = ' style="color:var(--wp--preset--color--' . $text_slug . ')"';
		}
		return $this->comment_wrap(
			'heading',
			$attrs,
			'<h1 class="' . implode( ' ', $classes ) . '"' . $style . '>' . $this->esc_html( $text ) . '</h1>'
		);
	}

	/**
	 * Render a centered paragraph (used for the eyebrow and subheading).
	 *
	 * @param string      $text        Paragraph text.
	 * @param string|null $text_slug   Text color slug.
	 * @param string[]    $extra_class Extra classes to add.
	 * @return string
	 */
	private function render_paragraph( string $text, ?string $text_slug, array $extra_class = array() ): string {
		$classes = $extra_class;
		$attrs   = array( 'align' => 'center' );
		$style   = '';
		if ( null !== $text_slug ) {
			$classes[]          = 'has-' . $text_slug . '-color';
			$classes[]          = 'has-text-color';
			$attrs['textColor'] = $text_slug;
			$style              = ' style="color:var(--wp--preset--color--' . $text_slug . ')"';
		}
		return $this->comment_wrap(
			'paragraph',
			$attrs,
			'<p class="' . implode( ' ', $classes ) . '"' . $style . '>' . $this->esc_html( $text ) . '</p>'
		);
	}

	/**
	 * Render the primary/secondary CTA buttons.
	 *
	 * Deliberately never gives a button the same backgroundColor as the cover's
	 * own background slug (see {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\ButtonBackgroundCollision}
	 * for the defect this avoids by construction): the primary button uses the
	 * theme's accent slug (falling back to the opposite of the cover bg when no
	 * accent is available), and the secondary button is an outline style with no
	 * background at all, so neither can ever collide with the section behind it.
	 *
	 * @param array<string, string>|null $primary_cta   [ 'label', 'url' ] or null.
	 * @param array<string, string>|null $secondary_cta [ 'label', 'url' ] or null.
	 * @param string|null                $cover_bg_slug The cover's own background slug.
	 * @param Context                    $ctx           Theme/conformance context.
	 * @return string
	 */
	private function render_buttons( ?array $primary_cta, ?array $secondary_cta, ?string $cover_bg_slug, Context $ctx ): string {
		$buttons = '';
		if ( null !== $primary_cta && ! empty( $primary_cta['label'] ) ) {
			$bg_slug = $this->primary_button_bg( $cover_bg_slug, $ctx );
			$text_slug = ( null !== $bg_slug && $ctx->is_dark_slug( $bg_slug ) ) ? $ctx->light_slug() : $ctx->dark_slug();
			$buttons .= $this->render_button( (string) $primary_cta['label'], isset( $primary_cta['url'] ) ? (string) $primary_cta['url'] : '#', $bg_slug, $text_slug, false );
		}
		if ( null !== $secondary_cta && ! empty( $secondary_cta['label'] ) ) {
			$text_slug = ( null !== $cover_bg_slug && $ctx->is_dark_slug( $cover_bg_slug ) ) ? $ctx->light_slug() : $ctx->dark_slug();
			$buttons .= $this->render_button( (string) $secondary_cta['label'], isset( $secondary_cta['url'] ) ? (string) $secondary_cta['url'] : '#', null, $text_slug, true );
		}

		return $this->comment_wrap(
			'buttons',
			array(
				'layout' => array(
					'type'           => 'flex',
					'justifyContent' => 'center',
				),
			),
			'<div class="wp-block-buttons">' . $buttons . '</div>'
		);
	}

	/**
	 * Pick the primary CTA's background slug — the theme accent when it differs
	 * from the cover's own background, otherwise whichever of dark/light differs.
	 *
	 * @param string|null $cover_bg_slug The cover's own background slug.
	 * @param Context     $ctx           Theme/conformance context.
	 * @return string|null
	 */
	private function primary_button_bg( ?string $cover_bg_slug, Context $ctx ): ?string {
		$accent = $ctx->accent_slug();
		if ( null !== $accent && $accent !== $cover_bg_slug ) {
			return $accent;
		}
		foreach ( array( $ctx->light_slug(), $ctx->dark_slug() ) as $candidate ) {
			if ( null !== $candidate && $candidate !== $cover_bg_slug ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Render a single button.
	 *
	 * @param string      $label     Button label.
	 * @param string      $url       Button URL.
	 * @param string|null $bg_slug   Background slug, or null for no background (outline style).
	 * @param string|null $text_slug Text color slug.
	 * @param bool        $outline   Whether to render as an outline-style button.
	 * @return string
	 */
	private function render_button( string $label, string $url, ?string $bg_slug, ?string $text_slug, bool $outline ): string {
		$classes = array( 'wp-block-button__link', 'wp-element-button' );
		$attrs   = array();
		$style   = array();

		if ( $outline ) {
			$attrs['className'] = 'is-style-outline';
		}
		if ( null !== $bg_slug ) {
			$classes[]                  = 'has-' . $bg_slug . '-background-color';
			$classes[]                  = 'has-background';
			$attrs['backgroundColor']    = $bg_slug;
			$style[]                    = 'background-color:var(--wp--preset--color--' . $bg_slug . ')';
		}
		if ( null !== $text_slug ) {
			$classes[]           = 'has-' . $text_slug . '-color';
			$classes[]           = 'has-text-color';
			$attrs['textColor']  = $text_slug;
			$style[]             = 'color:var(--wp--preset--color--' . $text_slug . ')';
		}

		$style_attr = empty( $style ) ? '' : ' style="' . implode( ';', $style ) . '"';
		$wrapper_class = 'wp-block-button' . ( $outline ? ' is-style-outline' : '' );

		$link = '<a class="' . implode( ' ', $classes ) . '" href="' . $this->esc_url( $url ) . '"' . $style_attr . '>' . $this->esc_html( $label ) . '</a>';

		return $this->comment_wrap( 'button', $attrs, '<div class="' . $wrapper_class . '">' . $link . '</div>' );
	}

}
