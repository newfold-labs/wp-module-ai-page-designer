<?php
/**
 * Pricing tiers section archetype: a row of plan cards.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a {@see RendersMarkup::render_section()} surface section with a
 * `core/columns` row, one plan per column. A `highlighted` tier wraps its
 * content in a nested `core/group` card using {@see RendersMarkup::contrasting_slug()}
 * against the *outer* section background, so the highlight always stands out
 * from the page regardless of theme; its CTA button in turn contrasts against
 * that nested card background, so neither can ever collide by construction.
 *
 * Content shape:
 * ```
 * [
 *   'heading' => string|null,
 *   'tiers'   => [ [
 *     'name'        => string,
 *     'price'       => string,
 *     'period'      => string|null,
 *     'features'    => string[],
 *     'cta'         => [ 'label' => string, 'url' => string ],
 *     'highlighted' => bool|null,
 *   ], ... ] (3 typical, any count accepted),
 * ]
 * ```
 *
 * Auto-pickable variants:
 *  - `cards` (default): every tier in a {@see RendersMarkup::render_floating_card()}
 *    card — the highlighted tier keeps the loud {@see RendersMarkup::contrasting_slug()}
 *    accent card so it still stands out, plain tiers get the quiet
 *    {@see RendersMarkup::card_slug_for_section()} swatch.
 *  - `accent-bar`: plain tiers rendered flat with a centered accent
 *    {@see RendersMarkup::render_accent_bar()} above the plan name; the
 *    highlighted tier keeps the same loud accent card as `cards` so it still
 *    dominates the row.
 *
 * Legacy (explicit-only):
 *  - `3-tier`: the original flat columns (highlighted tier only gets a card),
 *    reachable only via an explicit `variant: "3-tier"` plan item.
 */
class PricingTiers implements Archetype {

	use RendersMarkup;

	/**
	 * Auto-pickable variant names — see the class docblock.
	 *
	 * @var string[]
	 */
	const VARIANTS = array( 'cards', 'accent-bar' );

	/**
	 * Explicit-only legacy variants, never auto-picked.
	 *
	 * @var string[]
	 */
	const LEGACY_VARIANTS = array( '3-tier' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'pricingTiers';
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
	 * No fixed default — see {@see FeatureGrid::default_background()} for why.
	 *
	 * @param Context $ctx Theme/conformance context.
	 * @return string|null
	 */
	public function default_background( Context $ctx ): ?string {
		return null;
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
		$heading = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$tiers   = isset( $content['tiers'] ) && is_array( $content['tiers'] ) ? $content['tiers'] : array();

		$variant = $this->resolve_variant( $variant, $heading );
		$columns = empty( $tiers ) ? '' : $this->render_columns( $tiers, $ctx, $background_slug, $variant );

		return $this->render_section( $heading, null, $columns, $ctx, $background_slug );
	}

	/**
	 * Render one column per tier.
	 *
	 * @param array<int, array<string, mixed>> $tiers           Tier definitions.
	 * @param Context                          $ctx             Theme/conformance context.
	 * @param string|null                      $background_slug The section's own background slug.
	 * @param string                           $variant         Resolved variant name.
	 * @return string
	 */
	private function render_columns( array $tiers, Context $ctx, ?string $background_slug, string $variant ): string {
		$columns = '';
		foreach ( $tiers as $tier ) {
			$is_highlighted = ! empty( $tier['highlighted'] );

			if ( '3-tier' === $variant ) {
				$tier_inner = $this->render_tier( $tier, $ctx, $is_highlighted ? $this->contrasting_slug( $ctx, $background_slug ) : null );
			} elseif ( 'accent-bar' === $variant && ! $is_highlighted ) {
				// Plain tiers go flat with a centered accent bar; the
				// highlighted tier falls through to the loud card below so it
				// still dominates the row.
				$tier_inner  = $this->render_accent_bar( $ctx, $this->contrasting_slug( $ctx, $background_slug ), true );
				$tier_inner .= $this->render_tier_content( $tier, $ctx, null );
			} else {
				// The highlighted tier keeps the LOUD accent card so it stands
				// out from its (quiet, muted-light) siblings.
				$card_slug  = $is_highlighted
					? $this->contrasting_slug( $ctx, $background_slug )
					: $this->card_slug_for_section( $ctx, $background_slug );
				$text_slug  = null !== $card_slug ? $this->text_slug_for_background( $ctx, $card_slug ) : null;
				$tier_inner = $this->render_floating_card( $this->render_tier_content( $tier, $ctx, $card_slug ), $ctx, $card_slug, $text_slug );
			}

			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $tier_inner . '</div>' );
		}

		return $this->render_columns_wrap( $columns, $ctx, false, 'md', true );
	}

	/**
	 * Render a single tier's content, optionally wrapped in a highlighted card.
	 *
	 * @param array<string, mixed> $tier      Tier definition.
	 * @param Context              $ctx       Theme/conformance context.
	 * @param string|null          $card_slug Highlighted card background slug, or null for a plain tier.
	 * @return string
	 */
	private function render_tier( array $tier, Context $ctx, ?string $card_slug ): string {
		$content = $this->render_tier_content( $tier, $ctx, $card_slug );

		if ( null === $card_slug ) {
			return $content;
		}

		return $this->render_card( $content, $card_slug, $this->text_slug_for_background( $ctx, $card_slug ), $ctx );
	}

	/**
	 * Render a tier's inner content (heading, price line, features, CTA) with
	 * text/button colors computed against the tier's card background — shared
	 * by both the legacy flat wrap and the floating-card variant.
	 *
	 * @param array<string, mixed> $tier      Tier definition.
	 * @param Context              $ctx       Theme/conformance context.
	 * @param string|null          $card_slug The tier's card background slug, or null for none.
	 * @return string
	 */
	private function render_tier_content( array $tier, Context $ctx, ?string $card_slug ): string {
		$name     = isset( $tier['name'] ) ? (string) $tier['name'] : '';
		$price    = isset( $tier['price'] ) ? (string) $tier['price'] : '';
		$period   = isset( $tier['period'] ) ? (string) $tier['period'] : '';
		$features = isset( $tier['features'] ) && is_array( $tier['features'] ) ? $tier['features'] : array();
		$cta      = isset( $tier['cta'] ) && is_array( $tier['cta'] ) ? $tier['cta'] : null;

		$text_slug  = null !== $card_slug ? $this->text_slug_for_background( $ctx, $card_slug ) : null;
		$price_line = $price . ( '' !== $period ? ' / ' . $period : '' );

		$content  = $this->render_heading( $name, 3, $text_slug, true );
		$content .= $this->render_paragraph( $price_line, $text_slug, true );
		if ( ! empty( $features ) ) {
			$content .= $this->render_feature_list( $features, $text_slug );
		}
		if ( null !== $cta && ! empty( $cta['label'] ) ) {
			$button_bg   = $this->contrasting_slug( $ctx, $card_slug );
			$button_text = $this->text_slug_for_background( $ctx, $button_bg );
			$content    .= $this->render_buttons_wrap( $this->render_button( (string) $cta['label'], isset( $cta['url'] ) ? (string) $cta['url'] : '#', $button_bg, $button_text ), true );
		}

		return $content;
	}

	/**
	 * Render the feature list as `core/list` / `core/list-item`.
	 *
	 * @param string[]    $features  Feature lines.
	 * @param string|null $text_slug Text color slug, or null for the default.
	 * @return string
	 */
	private function render_feature_list( array $features, ?string $text_slug ): string {
		$classes = array( 'wp-block-list', 'has-text-align-center' );
		// Centre the list and drop bullet markers — centered bullets read awkwardly;
		// a clean centered list is the standard pricing-tier treatment. The
		// browser's default <ul> padding-inline-start (~40px) must go too, or
		// the "centered" text sits visibly right of centre once bullets are gone.
		$declarations = array( 'text-align:center', 'list-style:none', 'padding-left:0', 'margin-left:0' );
		if ( null !== $text_slug ) {
			$classes[]      = 'has-' . $text_slug . '-color';
			$classes[]      = 'has-text-color';
			$declarations[] = 'color:var(--wp--preset--color--' . $text_slug . ')';
		}
		$style = ' style="' . implode( ';', $declarations ) . '"';

		$items = '';
		foreach ( $features as $feature ) {
			$items .= $this->comment_wrap( 'list-item', array(), '<li>' . $this->esc_html( (string) $feature ) . '</li>' );
		}

		return $this->comment_wrap( 'list', array( 'textAlign' => 'center' ), '<ul class="' . implode( ' ', $classes ) . '"' . $style . '>' . $items . '</ul>' );
	}

	/**
	 * Wrap tier content in a highlighted `core/group` card.
	 *
	 * @param string      $content   Rendered tier content.
	 * @param string      $card_slug Card background slug.
	 * @param string|null $text_slug Card text color slug.
	 * @param Context     $ctx       Theme/conformance context.
	 * @return string
	 */
	private function render_card( string $content, string $card_slug, ?string $text_slug, Context $ctx ): string {
		$classes = array( 'wp-block-group', 'has-' . $card_slug . '-background-color', 'has-background' );
		$style   = 'padding-top:' . $ctx->spacing_css( 'md' ) . ';padding-bottom:' . $ctx->spacing_css( 'md' )
			. ';padding-left:' . $ctx->spacing_css( 'md' ) . ';padding-right:' . $ctx->spacing_css( 'md' )
			. ';background-color:var(--wp--preset--color--' . $card_slug . ')';
		$attrs   = array(
			'backgroundColor' => $card_slug,
			'style'           => array(
				'spacing' => array(
					'padding' => array(
						'top'    => $ctx->spacing_attr( 'md' ),
						'bottom' => $ctx->spacing_attr( 'md' ),
						'left'   => $ctx->spacing_attr( 'md' ),
						'right'  => $ctx->spacing_attr( 'md' ),
					),
				),
			),
		);
		if ( null !== $text_slug ) {
			$classes[]          = 'has-' . $text_slug . '-color';
			$classes[]          = 'has-text-color';
			$attrs['textColor'] = $text_slug;
			$style             .= ';color:var(--wp--preset--color--' . $text_slug . ')';
		}

		return $this->comment_wrap( 'group', $attrs, '<div class="' . implode( ' ', $classes ) . '" style="' . $style . '">' . $content . '</div>' );
	}
}
