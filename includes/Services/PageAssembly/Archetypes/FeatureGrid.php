<?php
/**
 * Feature grid section archetype: heading + intro over a row of value-prop cards.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a {@see RendersMarkup::render_section()} wide section containing a
 * `core/columns` row. Columns never declare an explicit width, so WordPress
 * auto-distributes them evenly — this is the one width state
 * {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator::has_invalid_columns()}
 * always accepts, avoiding the `invalid_column_widths` defect by construction
 * rather than by repair.
 *
 * Content shape:
 * ```
 * [
 *   'heading' => string|null,
 *   'intro'   => string|null,
 *   'items'   => [ [ 'title' => string, 'body' => string ], ... ] (exactly 3),
 * ]
 * ```
 *
 * Two variants:
 *  - `floating-cards` (default): each item wrapped in a light
 *    {@see RendersMarkup::render_floating_card()} card ({@see RendersMarkup::card_slug_for_section()}
 *    picks the quiet muted-light/light swatch, never the loud accent) — the
 *    modern "lifted cards" grid.
 *  - `cards-3`: the original flat text columns, reachable only via an explicit
 *    `variant: "cards-3"` plan item.
 */
class FeatureGrid implements Archetype {

	use RendersMarkup;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'featureGrid';
	}

	/**
	 * {@inheritDoc}
	 *
	 * No fixed default — a plain surface section uses the page's own background.
	 * PageAssembler passes an explicit `Context::muted_light_slug()` override
	 * when alternating this section against a sibling for a surface/surface-alt
	 * rhythm.
	 */
	public function default_background( Context $ctx ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $content, ?string $variant, Context $ctx, ?string $background_slug ): string {
		$heading = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$intro   = isset( $content['intro'] ) ? (string) $content['intro'] : '';
		$items   = isset( $content['items'] ) && is_array( $content['items'] ) ? array_slice( $content['items'], 0, 3 ) : array();

		$as_cards = 'cards-3' !== $variant;
		$columns  = empty( $items ) ? '' : $this->render_columns( $items, $ctx, $background_slug, $as_cards );

		return $this->render_section( $heading, $intro, $columns, $ctx, $background_slug );
	}

	/**
	 * Render the 3-column feature row. Columns never declare a `width` attr —
	 * WordPress auto-distributes them evenly, which is the one width state the
	 * Validator's column-width check always accepts.
	 *
	 * @param array<int, array<string, string>> $items           Up to 3 [ 'title', 'body' ] items.
	 * @param Context                            $ctx             Theme/conformance context.
	 * @param string|null                        $background_slug The section's own background slug.
	 * @param bool                               $as_cards        Whether to wrap each item in a floating card.
	 * @return string
	 */
	private function render_columns( array $items, Context $ctx, ?string $background_slug, bool $as_cards ): string {
		$card_slug = $as_cards ? $this->card_slug_for_section( $ctx, $background_slug ) : null;
		$card_text = null !== $card_slug ? $this->text_slug_for_background( $ctx, $card_slug ) : null;

		$columns = '';
		foreach ( $items as $item ) {
			$title = isset( $item['title'] ) ? (string) $item['title'] : '';
			$body  = isset( $item['body'] ) ? (string) $item['body'] : '';

			$column_inner  = $this->render_heading( $title, 3, $as_cards ? $card_text : null, true );
			$column_inner .= $this->render_paragraph( $body, $as_cards ? $card_text : null, true );

			if ( $as_cards ) {
				$column_inner = $this->render_floating_card( $column_inner, $ctx, $card_slug, $card_text );
			}

			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $column_inner . '</div>' );
		}

		return $this->render_columns_wrap( $columns, $ctx, false, 'md', true );
	}
}
