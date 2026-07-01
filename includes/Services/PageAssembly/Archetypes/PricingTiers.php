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
 * v1 supports a single variant, `3-tier`.
 */
class PricingTiers implements Archetype {

	use RendersMarkup;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'pricingTiers';
	}

	/**
	 * {@inheritDoc}
	 *
	 * No fixed default — see {@see FeatureGrid::default_background()} for why.
	 */
	public function default_background( Context $ctx ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $content, ?string $variant, Context $ctx, ?string $background_slug ): string {
		$heading = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$tiers   = isset( $content['tiers'] ) && is_array( $content['tiers'] ) ? $content['tiers'] : array();

		$columns = empty( $tiers ) ? '' : $this->render_columns( $tiers, $ctx, $background_slug );

		return $this->render_section( $heading, null, $columns, $ctx, $background_slug );
	}

	/**
	 * Render one column per tier.
	 *
	 * @param array<int, array<string, mixed>> $tiers           Tier definitions.
	 * @param Context                          $ctx             Theme/conformance context.
	 * @param string|null                      $background_slug The section's own background slug.
	 * @return string
	 */
	private function render_columns( array $tiers, Context $ctx, ?string $background_slug ): string {
		$columns = '';
		foreach ( $tiers as $tier ) {
			$is_highlighted = ! empty( $tier['highlighted'] );
			$tier_inner      = $this->render_tier( $tier, $ctx, $is_highlighted ? $this->contrasting_slug( $ctx, $background_slug ) : null );
			$columns        .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $tier_inner . '</div>' );
		}

		return $this->comment_wrap( 'columns', array(), '<div class="wp-block-columns">' . $columns . '</div>' );
	}

	/**
	 * Render a single tier's content, optionally wrapped in a highlighted card.
	 *
	 * @param array<string, mixed> $tier      Tier definition.
	 * @param Context               $ctx       Theme/conformance context.
	 * @param string|null           $card_slug Highlighted card background slug, or null for a plain tier.
	 * @return string
	 */
	private function render_tier( array $tier, Context $ctx, ?string $card_slug ): string {
		$name     = isset( $tier['name'] ) ? (string) $tier['name'] : '';
		$price    = isset( $tier['price'] ) ? (string) $tier['price'] : '';
		$period   = isset( $tier['period'] ) ? (string) $tier['period'] : '';
		$features = isset( $tier['features'] ) && is_array( $tier['features'] ) ? $tier['features'] : array();
		$cta      = isset( $tier['cta'] ) && is_array( $tier['cta'] ) ? $tier['cta'] : null;

		$text_slug   = null !== $card_slug ? $this->text_slug_for_background( $ctx, $card_slug ) : null;
		$price_line  = $price . ( '' !== $period ? ' / ' . $period : '' );

		$content  = $this->render_heading( $name, 3, $text_slug );
		$content .= $this->render_paragraph( $price_line, $text_slug );
		if ( ! empty( $features ) ) {
			$content .= $this->render_feature_list( $features, $text_slug );
		}
		if ( null !== $cta && ! empty( $cta['label'] ) ) {
			$button_bg   = $this->contrasting_slug( $ctx, $card_slug );
			$button_text = $this->text_slug_for_background( $ctx, $button_bg );
			$content    .= $this->render_buttons_wrap( $this->render_button( (string) $cta['label'], isset( $cta['url'] ) ? (string) $cta['url'] : '#', $button_bg, $button_text ), false );
		}

		if ( null === $card_slug ) {
			return $content;
		}

		return $this->render_card( $content, $card_slug, $text_slug, $ctx );
	}

	/**
	 * Render the feature list as `core/list` / `core/list-item`.
	 *
	 * @param string[]    $features  Feature lines.
	 * @param string|null $text_slug Text color slug, or null for the default.
	 * @return string
	 */
	private function render_feature_list( array $features, ?string $text_slug ): string {
		$classes = array( 'wp-block-list' );
		$style   = '';
		if ( null !== $text_slug ) {
			$classes[] = 'has-' . $text_slug . '-color';
			$classes[] = 'has-text-color';
			$style     = ' style="color:var(--wp--preset--color--' . $text_slug . ')"';
		}

		$items = '';
		foreach ( $features as $feature ) {
			$items .= $this->comment_wrap( 'list-item', array(), '<li>' . $this->esc_html( (string) $feature ) . '</li>' );
		}

		return $this->comment_wrap( 'list', array(), '<ul class="' . implode( ' ', $classes ) . '"' . $style . '>' . $items . '</ul>' );
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
			$classes[]         = 'has-' . $text_slug . '-color';
			$classes[]         = 'has-text-color';
			$attrs['textColor'] = $text_slug;
			$style             .= ';color:var(--wp--preset--color--' . $text_slug . ')';
		}

		return $this->comment_wrap( 'group', $attrs, '<div class="' . implode( ' ', $classes ) . '" style="' . $style . '">' . $content . '</div>' );
	}
}
