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
 * Two variants:
 *  - `cards` (default): each quote wrapped in a light
 *    {@see RendersMarkup::render_floating_card()} card — the modern "lifted
 *    cards" treatment matching {@see FeatureGrid}'s default.
 *  - `grid-3`: the original flat quote columns, reachable only via an explicit
 *    `variant: "grid-3"` plan item.
 */
class Testimonials implements Archetype {

	use RendersMarkup;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'testimonials';
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

		$as_cards = 'grid-3' !== $variant;
		$columns  = empty( $quotes ) ? '' : $this->render_columns( $quotes, $ctx, $background_slug, $as_cards );

		return $this->render_section( $heading, null, $columns, $ctx, $background_slug );
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
			'<blockquote class="wp-block-quote has-text-align-center">' . $html . '</blockquote>'
		);
	}
}
