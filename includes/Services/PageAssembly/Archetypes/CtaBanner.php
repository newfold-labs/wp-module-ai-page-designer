<?php
/**
 * CTA banner section archetype: heading + subheading over a single call-to-action.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Auto-pickable variants:
 *  - `floating-card` (default): a {@see RendersMarkup::render_gradient_section()}
 *    gradient-over-solid-slug backdrop with a centered {@see RendersMarkup::render_floating_card()}
 *    card (rounded corners, drop shadow) holding the heading/subheading/button.
 *    The button contrasts against the *card's* own background
 *    ({@see RendersMarkup::contrasting_slug()} chained one level deeper than the
 *    section, mirroring the proven pattern in {@see PricingTiers}'s highlighted
 *    tier), so it can never collide with either the card or the section.
 *  - `split`: a two-column accent band — heading/subheading left, the CTA
 *    button vertically centered right — for a horizontal, banner-like close.
 *
 * Legacy (explicit-only):
 *  - `accent-band`: the original flat {@see RendersMarkup::render_section()}
 *    accent-background band with a single centered CTA button, kept as an
 *    explicit fallback, reachable only via an explicit `variant: "accent-band"`
 *    plan item.
 *
 * Content shape:
 * ```
 * [
 *   'heading'          => string (required),
 *   'headingHighlight' => string|null (an optional trailing phrase in the theme
 *                         accent color — see {@see RendersMarkup::render_heading()}'s
 *                         `$highlight` param — for a two-tone closing headline
 *                         like "Begin Your **Adventure**"; only the `split` and
 *                         `floating-card` variants render it),
 *   'subheading'       => string|null,
 *   'cta'              => [ 'label' => string, 'url' => string ] (required),
 * ]
 * ```
 */
class CtaBanner implements Archetype {

	use RendersMarkup;

	/**
	 * Auto-pickable variant names — see the class docblock.
	 *
	 * @var string[]
	 */
	const VARIANTS = array( 'floating-card', 'split' );

	/**
	 * Explicit-only legacy variants, never auto-picked.
	 *
	 * @var string[]
	 */
	const LEGACY_VARIANTS = array( 'accent-band' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ctaBanner';
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
		return self::LEGACY_VARIANTS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Theme/conformance context.
	 * @return string|null
	 */
	public function default_background( Context $ctx ): ?string {
		$accent = $ctx->accent_slug();
		return null !== $accent ? $accent : $ctx->dark_slug();
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
		$variant = $this->resolve_variant( $variant, isset( $content['heading'] ) ? (string) $content['heading'] : '' );
		if ( 'accent-band' === $variant ) {
			return $this->render_accent_band( $content, $ctx, $background_slug );
		}
		if ( 'split' === $variant ) {
			return $this->render_split( $content, $ctx, $background_slug );
		}
		return $this->render_floating_card_variant( $content, $ctx, $background_slug );
	}

	/**
	 * Render the `split` variant: heading/subheading in the left column, the
	 * CTA button vertically centered in the right — the button contrasts
	 * against the band background, same construction as `accent-band`.
	 *
	 * @param array<string, mixed> $content         Slot content.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Section background slug.
	 * @return string
	 */
	private function render_split( array $content, Context $ctx, ?string $background_slug ): string {
		$bg_slug           = $background_slug ?? $this->default_background( $ctx );
		$heading           = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$heading_highlight = isset( $content['headingHighlight'] ) ? (string) $content['headingHighlight'] : '';
		$subheading        = isset( $content['subheading'] ) ? (string) $content['subheading'] : '';
		$cta               = isset( $content['cta'] ) && is_array( $content['cta'] ) ? $content['cta'] : null;

		$text_slug = $this->text_slug_for_background( $ctx, $bg_slug );

		$left_inner = '';
		if ( '' !== $heading ) {
			$left_inner .= $this->render_heading( $heading, 2, $text_slug, false, false, $heading_highlight, $this->contrasting_slug( $ctx, $bg_slug ) );
		}
		if ( '' !== $subheading ) {
			$left_inner .= $this->render_paragraph( $subheading, $text_slug );
		}

		$right_inner = '';
		if ( null !== $cta && ! empty( $cta['label'] ) ) {
			$button_bg   = $this->contrasting_slug( $ctx, $bg_slug );
			$button_text = $this->text_slug_for_background( $ctx, $button_bg );
			$button      = $this->render_button( (string) $cta['label'], isset( $cta['url'] ) ? (string) $cta['url'] : '#', $button_bg, $button_text );
			$right_inner = $this->render_buttons_wrap( $button );
		}

		$columns  = $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $left_inner . '</div>' );
		$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $right_inner . '</div>' );

		return $this->render_section( null, null, $this->render_columns_wrap( $columns, $ctx ), $ctx, $bg_slug );
	}

	/**
	 * Render the `floating-card` variant.
	 *
	 * @param array<string, mixed> $content         Slot content.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Section background slug.
	 * @return string
	 */
	private function render_floating_card_variant( array $content, Context $ctx, ?string $background_slug ): string {
		$bg_slug           = $background_slug ?? $this->default_background( $ctx );
		$heading           = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$heading_highlight = isset( $content['headingHighlight'] ) ? (string) $content['headingHighlight'] : '';
		$subheading        = isset( $content['subheading'] ) ? (string) $content['subheading'] : '';
		$cta               = isset( $content['cta'] ) && is_array( $content['cta'] ) ? $content['cta'] : null;

		$card_slug = $this->contrasting_slug( $ctx, $bg_slug );
		$card_text = $this->text_slug_for_background( $ctx, $card_slug );

		$card_inner = '';
		if ( ! empty( $heading ) ) {
			// The highlight phrase contrasts against the CARD's own background
			// (one level deeper than the section) — same chaining {@see contrasting_slug()}
			// already uses for this variant's button below, so the highlight can
			// never collide with the card behind it either.
			$card_inner .= $this->render_heading( $heading, 2, null, true, false, $heading_highlight, $this->contrasting_slug( $ctx, $card_slug ) );
		}
		if ( ! empty( $subheading ) ) {
			$card_inner .= $this->render_paragraph( $subheading, null, true );
		}
		if ( null !== $cta && ! empty( $cta['label'] ) ) {
			$button_bg   = $this->contrasting_slug( $ctx, $card_slug );
			$button_text = $this->text_slug_for_background( $ctx, $button_bg );
			$button      = $this->render_button( (string) $cta['label'], isset( $cta['url'] ) ? (string) $cta['url'] : '#', $button_bg, $button_text );
			$card_inner .= $this->render_buttons_wrap( $button );
		}

		$card = $this->render_floating_card( $card_inner, $ctx, $card_slug, $card_text, 640 );

		return $this->render_gradient_section( $card, $ctx, $bg_slug );
	}

	/**
	 * Render the `accent-band` variant: the original flat accent-background band.
	 *
	 * @param array<string, mixed> $content         Slot content.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Section background slug.
	 * @return string
	 */
	private function render_accent_band( array $content, Context $ctx, ?string $background_slug ): string {
		$bg_slug    = $background_slug ?? $this->default_background( $ctx );
		$heading    = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$subheading = isset( $content['subheading'] ) ? (string) $content['subheading'] : '';
		$cta        = isset( $content['cta'] ) && is_array( $content['cta'] ) ? $content['cta'] : null;

		$inner = '';
		if ( null !== $cta && ! empty( $cta['label'] ) ) {
			$button_bg   = $this->contrasting_slug( $ctx, $bg_slug );
			$button_text = $this->text_slug_for_background( $ctx, $button_bg );
			$button      = $this->render_button( (string) $cta['label'], isset( $cta['url'] ) ? (string) $cta['url'] : '#', $button_bg, $button_text );
			$inner       = $this->render_buttons_wrap( $button );
		}

		return $this->render_section( $heading, $subheading, $inner, $ctx, $bg_slug );
	}
}
