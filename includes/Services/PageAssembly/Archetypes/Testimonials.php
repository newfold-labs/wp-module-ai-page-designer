<?php
/**
 * Testimonials section archetype: a row of quote cards.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a {@see RendersMarkup::render_section()} surface section with a
 * `core/columns` row, one `core/quote` per testimonial. As with
 * {@see FeatureGrid}, columns never declare a `width` attr.
 *
 * Content shape:
 * ```
 * [
 *   'heading' => string|null,
 *   'quotes'  => [ [ 'quote' => string, 'author' => string, 'role' => string|null, 'avatarUrl' => string|null ], ... ],
 * ]
 * ```
 * `avatarUrl` is resolved by PageAssembler from an `avatarQuery` slot.
 *
 * Auto-pickable variants:
 *  - `cards` (default): each quote wrapped in a light
 *    {@see RendersMarkup::render_floating_card()} card — the modern "lifted
 *    cards" treatment matching {@see FeatureGrid}'s default.
 *  - `spotlight`: quotes stacked full-width, one centered width-constrained
 *    row each, with larger quote type — an editorial single-voice treatment
 *    instead of the side-by-side grid.
 *
 * Legacy (explicit-only):
 *  - `grid-3`: the original flat quote columns, reachable only via an explicit
 *    `variant: "grid-3"` plan item.
 */
class Testimonials implements Archetype {

	use RendersMarkup;

	/**
	 * Auto-pickable variant names — see the class docblock.
	 *
	 * @var string[]
	 */
	const VARIANTS = array( 'cards', 'spotlight' );

	/**
	 * Explicit-only legacy variants, never auto-picked.
	 *
	 * @var string[]
	 */
	const LEGACY_VARIANTS = array( 'grid-3' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'testimonials';
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
		$quotes  = isset( $content['quotes'] ) && is_array( $content['quotes'] ) ? array_slice( $content['quotes'], 0, 3 ) : array();

		$variant = $this->resolve_variant( $variant, $heading );

		$inner = '';
		if ( ! empty( $quotes ) ) {
			$inner = 'spotlight' === $variant
				? $this->render_spotlight( $quotes, $ctx )
				: $this->render_columns( $quotes, $ctx, $background_slug, 'grid-3' !== $variant );
		}

		return $this->render_section( $heading, null, $inner, $ctx, $background_slug );
	}

	/**
	 * Render the `spotlight` variant: each quote as its own centered,
	 * width-constrained full-width row with larger quote type.
	 *
	 * @param array<int, array<string, mixed>> $quotes Up to 3 testimonials.
	 * @param Context                          $ctx    Theme/conformance context.
	 * @return string
	 */
	private function render_spotlight( array $quotes, Context $ctx ): string {
		$rows = '';
		foreach ( $quotes as $entry ) {
			$quote      = isset( $entry['quote'] ) ? (string) $entry['quote'] : '';
			$author     = isset( $entry['author'] ) ? (string) $entry['author'] : '';
			$role       = isset( $entry['role'] ) ? (string) $entry['role'] : '';
			$avatar_url = isset( $entry['avatarUrl'] ) ? (string) $entry['avatarUrl'] : '';

			$row_inner = '';
			if ( '' !== $avatar_url ) {
				$row_inner .= '<div style="text-align:center"><img src="' . $this->esc_url( $avatar_url ) . '" alt="" width="56" height="56" style="border-radius:9999px;object-fit:cover"/></div>';
			}
			$row_inner .= $this->render_spotlight_quote( $quote, $author, $role );

			// Symmetric on all four sides — top/bottom-only padding is the
			// exact `asymmetric_padding:group` defect the Validator rejects.
			$row_attrs = array(
				'style' => array(
					'spacing' => array(
						'padding' => array(
							'top'    => $ctx->spacing_attr( 'sm' ),
							'bottom' => $ctx->spacing_attr( 'sm' ),
							'left'   => $ctx->spacing_attr( 'sm' ),
							'right'  => $ctx->spacing_attr( 'sm' ),
						),
					),
				),
			);
			$row_style = 'max-width:720px;margin-left:auto;margin-right:auto'
				. ';padding-top:' . $ctx->spacing_css( 'sm' ) . ';padding-bottom:' . $ctx->spacing_css( 'sm' )
				. ';padding-left:' . $ctx->spacing_css( 'sm' ) . ';padding-right:' . $ctx->spacing_css( 'sm' );

			$rows .= $this->comment_wrap( 'group', $row_attrs, '<div class="wp-block-group" style="' . $row_style . '">' . $row_inner . '</div>' );
		}

		return $rows;
	}

	/**
	 * Render a spotlight `core/quote`: the same centered quote/citation shape
	 * as {@see render_quote()}, at larger, statement-piece type.
	 *
	 * @param string $quote  Quote text.
	 * @param string $author Author name.
	 * @param string $role   Author role/company, or empty string to omit.
	 * @return string
	 */
	private function render_spotlight_quote( string $quote, string $author, string $role ): string {
		$citation = $author;
		if ( '' !== $role ) {
			$citation .= ', ' . $role;
		}

		$html = '<p style="font-size:1.375rem;line-height:1.6">' . $this->esc_html( $quote ) . '</p>';
		if ( '' !== $citation ) {
			$html .= '<cite>' . $this->esc_html( $citation ) . '</cite>';
		}

		return $this->comment_wrap(
			'quote',
			array( 'textAlign' => 'center' ),
			'<blockquote class="wp-block-quote has-text-align-center" style="text-align:center">' . $html . '</blockquote>'
		);
	}

	/**
	 * Render one column per testimonial.
	 *
	 * @param array<int, array<string, mixed>> $quotes          Up to 3 testimonials.
	 * @param Context                          $ctx             Theme/conformance context.
	 * @param string|null                      $background_slug The section's own background slug.
	 * @param bool                             $as_cards        Whether to wrap each quote in a floating card.
	 * @return string
	 */
	private function render_columns( array $quotes, Context $ctx, ?string $background_slug, bool $as_cards ): string {
		$card_slug = $as_cards ? $this->card_slug_for_section( $ctx, $background_slug ) : null;
		$card_text = null !== $card_slug ? $this->text_slug_for_background( $ctx, $card_slug ) : null;

		$columns = '';
		foreach ( $quotes as $entry ) {
			$quote      = isset( $entry['quote'] ) ? (string) $entry['quote'] : '';
			$author     = isset( $entry['author'] ) ? (string) $entry['author'] : '';
			$role       = isset( $entry['role'] ) ? (string) $entry['role'] : '';
			$avatar_url = isset( $entry['avatarUrl'] ) ? (string) $entry['avatarUrl'] : '';

			$column_inner = '';
			if ( '' !== $avatar_url ) {
				$column_inner .= '<div style="text-align:center"><img src="' . $this->esc_url( $avatar_url ) . '" alt="" width="56" height="56" style="border-radius:9999px;object-fit:cover"/></div>';
			}
			$column_inner .= $this->render_quote( $quote, $author, $role );

			if ( $as_cards ) {
				$column_inner = $this->render_floating_card( $column_inner, $ctx, $card_slug, $card_text );
			}

			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $column_inner . '</div>' );
		}

		return $this->render_columns_wrap( $columns, $ctx, false, 'md', true );
	}

	/**
	 * Render a `core/quote` block with an author (and optional role) citation.
	 *
	 * @param string $quote  Quote text.
	 * @param string $author Author name.
	 * @param string $role   Author role/company, or empty string to omit.
	 * @return string
	 */
	private function render_quote( string $quote, string $author, string $role ): string {
		$citation = $author;
		if ( '' !== $role ) {
			$citation .= ', ' . $role;
		}

		$html = '<p>' . $this->esc_html( $quote ) . '</p>';
		if ( '' !== $citation ) {
			$html .= '<cite>' . $this->esc_html( $citation ) . '</cite>';
		}

		return $this->comment_wrap(
			'quote',
			array( 'textAlign' => 'center' ),
			'<blockquote class="wp-block-quote has-text-align-center" style="text-align:center">' . $html . '</blockquote>'
		);
	}
}
