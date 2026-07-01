<?php
/**
 * CTA banner section archetype: heading + subheading over a single call-to-action.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a {@see RendersMarkup::render_section()} accent-background section
 * with a single centered CTA button. The button uses
 * {@see RendersMarkup::contrasting_slug()} against the section's own
 * background, so it can never collide with it by construction.
 *
 * Content shape:
 * ```
 * [
 *   'heading'    => string (required),
 *   'subheading' => string|null,
 *   'cta'        => [ 'label' => string, 'url' => string ] (required),
 * ]
 * ```
 *
 * v1 supports a single variant, `accent-band`.
 */
class CtaBanner implements Archetype {

	use RendersMarkup;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ctaBanner';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_background( Context $ctx ): ?string {
		$accent = $ctx->accent_slug();
		return null !== $accent ? $accent : $ctx->dark_slug();
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $content, ?string $variant, Context $ctx, ?string $background_slug ): string {
		$bg_slug    = $background_slug ?? $this->default_background( $ctx );
		$heading    = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$subheading = isset( $content['subheading'] ) ? (string) $content['subheading'] : '';
		$cta        = isset( $content['cta'] ) && is_array( $content['cta'] ) ? $content['cta'] : null;

		$inner = '';
		if ( null !== $cta && ! empty( $cta['label'] ) ) {
			$button_bg   = $this->contrasting_slug( $ctx, $bg_slug );
			$button_text = $this->text_slug_for_background( $ctx, $button_bg );
			$button      = $this->render_button( (string) $cta['label'], isset( $cta['url'] ) ? (string) $cta['url'] : '#', $button_bg, $button_text );
			$inner        = $this->render_buttons_wrap( $button );
		}

		return $this->render_section( $heading, $subheading, $inner, $ctx, $bg_slug );
	}
}
