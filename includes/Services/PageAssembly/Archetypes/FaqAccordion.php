<?php
/**
 * FAQ accordion section archetype: a stack of collapsible question/answer pairs.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a {@see RendersMarkup::render_section()} surface section with a
 * stack of `core/details` blocks — WordPress's native collapsible
 * `<details>`/`<summary>` block — so the FAQ is actually interactive without
 * any custom JavaScript.
 *
 * Content shape:
 * ```
 * [
 *   'heading' => string|null,
 *   'items'   => [ [ 'q' => string, 'a' => string ], ... ],
 * ]
 * ```
 *
 * Auto-pickable variants:
 *  - `cards` (default): each `core/details` item styled as a rounded
 *    {@see RendersMarkup::card_slug_for_section()} card (quiet muted-light
 *    swatch, padding, radius) so the accordion reads as a modern stacked list.
 *  - `two-column`: the same card-styled items split across two side-by-side
 *    columns — halves the section's height for longer FAQ lists. Renders as
 *    the single `cards` stack when there are fewer than 4 items (a lone item
 *    in a second column reads broken).
 *
 * Legacy (explicit-only):
 *  - `stacked`: the original unstyled `core/details` stack, reachable only
 *    via an explicit `variant: "stacked"` plan item.
 */
class FaqAccordion implements Archetype {

	use RendersMarkup;

	/**
	 * Auto-pickable variant names — see the class docblock.
	 *
	 * @var string[]
	 */
	const VARIANTS = array( 'cards', 'two-column' );

	/**
	 * Explicit-only legacy variants, never auto-picked.
	 *
	 * @var string[]
	 */
	const LEGACY_VARIANTS = array( 'stacked' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'faqAccordion';
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
		$items   = isset( $content['items'] ) && is_array( $content['items'] ) ? $content['items'] : array();

		$variant   = $this->resolve_variant( $variant, $heading );
		$card_slug = 'stacked' !== $variant ? $this->card_slug_for_section( $ctx, $background_slug ) : null;
		$card_text = null !== $card_slug ? $this->text_slug_for_background( $ctx, $card_slug ) : null;

		$details = array();
		foreach ( $items as $item ) {
			$question  = isset( $item['q'] ) ? (string) $item['q'] : '';
			$answer    = isset( $item['a'] ) ? (string) $item['a'] : '';
			$details[] = $this->render_details( $question, $answer, $ctx, $card_slug, $card_text );
		}

		$inner = 'two-column' === $variant && count( $details ) >= 4
			? $this->render_two_columns( $details, $ctx )
			: implode( '', $details );

		return $this->render_section( $heading, null, $inner, $ctx, $background_slug );
	}

	/**
	 * Render the `two-column` variant: the card-styled details split across
	 * two side-by-side columns (no `width` attrs — the Validator-safe
	 * auto-distributed state, same as every other archetype's columns).
	 *
	 * @param string[] $details Rendered `core/details` blocks, in order.
	 * @param Context  $ctx     Theme/conformance context.
	 * @return string
	 */
	private function render_two_columns( array $details, Context $ctx ): string {
		$half = (int) ceil( count( $details ) / 2 );

		$columns = '';
		foreach ( array_chunk( $details, $half ) as $chunk ) {
			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . implode( '', $chunk ) . '</div>' );
		}

		return $this->render_columns_wrap( $columns, $ctx, false, 'md', false );
	}

	/**
	 * Render one `core/details` question/answer pair, optionally styled as a
	 * rounded card (the `cards` variant).
	 *
	 * @param string      $question  Question text.
	 * @param string      $answer    Answer text.
	 * @param Context     $ctx       Theme/conformance context.
	 * @param string|null $card_slug Card background slug, or null for the plain stack.
	 * @param string|null $text_slug Card text color slug.
	 * @return string
	 */
	private function render_details( string $question, string $answer, Context $ctx, ?string $card_slug, ?string $text_slug ): string {
		$classes = array( 'wp-block-details' );
		$attrs   = array();
		$style   = '';

		if ( null !== $card_slug ) {
			$classes[]                = 'has-' . $card_slug . '-background-color';
			$classes[]                = 'has-background';
			$attrs['backgroundColor'] = $card_slug;
			$style                    = 'border-radius:12px'
				. ';padding:' . $ctx->spacing_css( 'sm' ) . ' ' . $ctx->spacing_css( 'md' )
				. ';margin-bottom:' . $ctx->spacing_css( 'sm' )
				. ';background-color:var(--wp--preset--color--' . $card_slug . ')';
			if ( null !== $text_slug ) {
				$classes[]          = 'has-' . $text_slug . '-color';
				$classes[]          = 'has-text-color';
				$attrs['textColor'] = $text_slug;
				$style             .= ';color:var(--wp--preset--color--' . $text_slug . ')';
			}
		}

		$style_attr = '' !== $style ? ' style="' . $style . '"' : '';
		// Summary styling belongs to the cards look only — the legacy stacked
		// variant stays byte-identical to its original output.
		$summary = null !== $card_slug
			? '<summary style="cursor:pointer;font-weight:600">' . $this->esc_html( $question ) . '</summary>'
			: '<summary>' . $this->esc_html( $question ) . '</summary>';
		$html    = '<details class="' . implode( ' ', $classes ) . '"' . $style_attr . '>' . $summary
			. $this->render_paragraph( $answer, $text_slug )
			. '</details>';

		return $this->comment_wrap( 'details', $attrs, $html );
	}
}
