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
 * Auto-pickable variants:
 *  - `floating-cards` (default): each item wrapped in a light
 *    {@see RendersMarkup::render_floating_card()} card ({@see RendersMarkup::card_slug_for_section()}
 *    picks the quiet muted-light/light swatch, never the loud accent) — the
 *    modern "lifted cards" grid.
 *  - `accent-bar`: left-aligned flat columns, each anchored by a short accent
 *    {@see RendersMarkup::render_accent_bar()} separator above the title — an
 *    editorial, no-card treatment.
 *  - `panel`: the flat centered columns gathered inside ONE wide
 *    {@see RendersMarkup::render_floating_card()} panel — a single lifted
 *    surface instead of three.
 *
 * Legacy (explicit-only):
 *  - `cards-3`: the original flat text columns, reachable only via an explicit
 *    `variant: "cards-3"` plan item.
 */
class FeatureGrid implements Archetype {

	use RendersMarkup;

	/**
	 * Auto-pickable variant names — see the class docblock.
	 *
	 * @var string[]
	 */
	const VARIANTS = array( 'floating-cards', 'accent-bar', 'panel' );

	/**
	 * Explicit-only legacy variants, never auto-picked.
	 *
	 * @var string[]
	 */
	const LEGACY_VARIANTS = array( 'cards-3' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'featureGrid';
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
	 * No fixed default — a plain surface section uses the page's own background.
	 * PageAssembler passes an explicit `Context::muted_light_slug()` override
	 * when alternating this section against a sibling for a surface/surface-alt
	 * rhythm.
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
		$intro   = isset( $content['intro'] ) ? (string) $content['intro'] : '';
		$items   = isset( $content['items'] ) && is_array( $content['items'] ) ? array_slice( $content['items'], 0, 3 ) : array();

		$variant = $this->resolve_variant( $variant, $heading );

		$inner = '';
		if ( ! empty( $items ) ) {
			switch ( $variant ) {
				case 'accent-bar':
					$inner = $this->render_accent_bar_columns( $items, $ctx, $background_slug );
					break;
				case 'panel':
					$inner = $this->render_panel( $items, $ctx, $background_slug );
					break;
				default:
					$inner = $this->render_columns( $items, $ctx, $background_slug, 'cards-3' !== $variant );
			}
		}

		return $this->render_section( $heading, $intro, $inner, $ctx, $background_slug );
	}

	/**
	 * Render the `accent-bar` variant's columns: flat, left-aligned items, each
	 * anchored by a short accent separator bar above its title.
	 *
	 * @param array<int, array<string, string>> $items           Up to 3 [ 'title', 'body' ] items.
	 * @param Context                           $ctx             Theme/conformance context.
	 * @param string|null                       $background_slug The section's own background slug.
	 * @return string
	 */
	private function render_accent_bar_columns( array $items, Context $ctx, ?string $background_slug ): string {
		$bar_slug = $this->contrasting_slug( $ctx, $background_slug );

		$columns = '';
		foreach ( $items as $item ) {
			$title = isset( $item['title'] ) ? (string) $item['title'] : '';
			$body  = isset( $item['body'] ) ? (string) $item['body'] : '';

			$column_inner  = $this->render_accent_bar( $ctx, $bar_slug );
			$column_inner .= $this->render_heading( $title, 3, null );
			$column_inner .= $this->render_paragraph( $body, null );

			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $column_inner . '</div>' );
		}

		return $this->render_columns_wrap( $columns, $ctx, false, 'md', true );
	}

	/**
	 * Render the `panel` variant: the flat centered columns gathered inside a
	 * single wide floating card, text colored against the CARD's background.
	 *
	 * @param array<int, array<string, string>> $items           Up to 3 [ 'title', 'body' ] items.
	 * @param Context                           $ctx             Theme/conformance context.
	 * @param string|null                       $background_slug The section's own background slug.
	 * @return string
	 */
	private function render_panel( array $items, Context $ctx, ?string $background_slug ): string {
		$card_slug = $this->card_slug_for_section( $ctx, $background_slug );
		$card_text = null !== $card_slug ? $this->text_slug_for_background( $ctx, $card_slug ) : null;

		$columns = '';
		foreach ( $items as $item ) {
			$title = isset( $item['title'] ) ? (string) $item['title'] : '';
			$body  = isset( $item['body'] ) ? (string) $item['body'] : '';

			$column_inner  = $this->render_heading( $title, 3, $card_text, true );
			$column_inner .= $this->render_paragraph( $body, $card_text, true );

			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $column_inner . '</div>' );
		}

		$columns = $this->render_columns_wrap( $columns, $ctx, false, 'md', true );

		return $this->render_floating_card( $columns, $ctx, $card_slug, $card_text );
	}

	/**
	 * Render the 3-column feature row. Columns never declare a `width` attr —
	 * WordPress auto-distributes them evenly, which is the one width state the
	 * Validator's column-width check always accepts.
	 *
	 * @param array<int, array<string, string>> $items           Up to 3 [ 'title', 'body' ] items.
	 * @param Context                           $ctx             Theme/conformance context.
	 * @param string|null                       $background_slug The section's own background slug.
	 * @param bool                              $as_cards        Whether to wrap each item in a floating card.
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
