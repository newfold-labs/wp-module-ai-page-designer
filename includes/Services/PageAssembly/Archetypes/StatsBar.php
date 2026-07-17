<?php
/**
 * Stats bar section archetype: a row of large-number metrics.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a {@see RendersMarkup::render_section()} accent-background section
 * with a `core/columns` row, one column per stat. As with {@see FeatureGrid},
 * columns never declare a `width` attr, so WordPress auto-distributes them —
 * the width state the Validator always accepts.
 *
 * Content shape:
 * ```
 * [
 *   'items' => [ [ 'value' => string, 'label' => string ], ... ] (2-4 typical, any count accepted),
 * ]
 * ```
 *
 * Auto-pickable variants:
 *  - `stat-cards` (default): each stat in a light
 *    {@see RendersMarkup::render_floating_card()} card on the accent band —
 *    the modern "lifted cards" treatment matching {@see FeatureGrid}'s default.
 *  - `panel`: all stats gathered inside ONE wide light
 *    {@see RendersMarkup::render_floating_card()} panel floating on the accent
 *    band — a single lifted strip instead of per-stat cards.
 *
 * Legacy (explicit-only):
 *  - `accent-band`: the original flat accent band, reachable only via an
 *    explicit `variant: "accent-band"` plan item.
 */
class StatsBar implements Archetype {

	use RendersMarkup;

	/**
	 * Auto-pickable variant names — see the class docblock.
	 *
	 * @var string[]
	 */
	const VARIANTS = array( 'stat-cards', 'panel' );

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
		return 'statsBar';
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
		$bg_slug = $background_slug ?? $this->default_background( $ctx );
		$items   = isset( $content['items'] ) && is_array( $content['items'] ) ? $content['items'] : array();

		// No heading slot here — the first stat's value+label is the most
		// identifying deterministic seed this archetype has.
		$seed     = isset( $items[0]['value'] ) ? (string) $items[0]['value'] . ( isset( $items[0]['label'] ) ? (string) $items[0]['label'] : '' ) : '';
		$variant = $this->resolve_variant( $variant, $seed );

		$inner = '';
		if ( ! empty( $items ) ) {
			$inner = 'panel' === $variant
				? $this->render_panel( $items, $ctx, $bg_slug )
				: $this->render_columns( $items, $ctx, $bg_slug, 'accent-band' !== $variant );
		}

		return $this->render_section( null, null, $inner, $ctx, $bg_slug );
	}

	/**
	 * Render one column per stat.
	 *
	 * @param array<int, array<string, string>> $items         [ 'value', 'label' ] items.
	 * @param Context                           $ctx           Theme/conformance context.
	 * @param string|null                       $bg_slug       The section's own background slug.
	 * @param bool                              $as_cards      Whether to wrap each stat in a floating card.
	 * @param string|null                       $text_override Text slug for flat columns (the `panel` variant colors them against its card).
	 * @return string
	 */
	private function render_columns( array $items, Context $ctx, ?string $bg_slug, bool $as_cards, ?string $text_override = null ): string {
		$card_slug = $as_cards ? $this->card_slug_for_section( $ctx, $bg_slug ) : null;
		$card_text = null !== $card_slug ? $this->text_slug_for_background( $ctx, $card_slug ) : $text_override;

		$columns = '';
		foreach ( $items as $item ) {
			$value = isset( $item['value'] ) ? (string) $item['value'] : '';
			$label = isset( $item['label'] ) ? (string) $item['label'] : '';

			$column_inner  = $this->render_heading( $value, 3, $card_text, true );
			$column_inner .= $this->render_paragraph( $label, $card_text, true );

			if ( $as_cards ) {
				$column_inner = $this->render_floating_card( $column_inner, $ctx, $card_slug, $card_text );
			}

			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $column_inner . '</div>' );
		}

		return $this->render_columns_wrap( $columns, $ctx, false, 'md', true );
	}

	/**
	 * Render the `panel` variant: the flat stat columns gathered inside a
	 * single wide floating card on the band, text colored against the CARD.
	 *
	 * @param array<int, array<string, string>> $items   [ 'value', 'label' ] items.
	 * @param Context                           $ctx     Theme/conformance context.
	 * @param string|null                       $bg_slug The section's own background slug.
	 * @return string
	 */
	private function render_panel( array $items, Context $ctx, ?string $bg_slug ): string {
		$card_slug = $this->card_slug_for_section( $ctx, $bg_slug );
		$card_text = null !== $card_slug ? $this->text_slug_for_background( $ctx, $card_slug ) : null;

		$columns = $this->render_columns( $items, $ctx, $bg_slug, false, $card_text );

		return $this->render_floating_card( $columns, $ctx, $card_slug, $card_text );
	}
}
