<?php
/**
 * Alternating media/text section archetype: story rows alternating image and copy sides.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a {@see RendersMarkup::render_section()} surface section containing
 * one `core/columns` row per story row — an image column and a text column,
 * alternating left/right by row index. Uses plain `core/columns` (not
 * `core/media-text`) so it reuses the same never-declare-a-width convention
 * every other archetype's columns rely on, rather than introducing
 * `core/media-text`'s separate CSS-grid attribute shape.
 *
 * Content shape:
 * ```
 * [
 *   'heading' => string|null,
 *   'intro'   => string|null,
 *   'rows'    => [ [
 *     'heading'  => string,
 *     'body'     => string,
 *     'imageUrl' => string (resolved from an `imageQuery` slot by PageAssembler),
 *     'cta'      => [ 'label' => string, 'url' => string ]|null,
 *   ], ... ],
 * ]
 * ```
 *
 * Rows always alternate left/right automatically. Two visual variants:
 *  - `floating-media` (default): row images rendered rounded with a soft drop
 *    shadow ({@see RendersMarkup::render_image_block()} rounded mode) — the
 *    modern treatment matching the split hero's floating image card.
 *  - `flat`: the original unstyled images, reachable only via an explicit
 *    `variant: "flat"` plan item.
 */
class AlternatingMediaText implements Archetype {

	use RendersMarkup;

	/**
	 * Auto-pickable variant names — see the class docblock.
	 *
	 * @var string[]
	 */
	const VARIANTS = array( 'floating-media' );

	/**
	 * Explicit-only legacy variants, never auto-picked.
	 *
	 * @var string[]
	 */
	const LEGACY_VARIANTS = array( 'flat' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'alternatingMediaText';
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
		$intro   = isset( $content['intro'] ) ? (string) $content['intro'] : '';
		$rows    = isset( $content['rows'] ) && is_array( $content['rows'] ) ? $content['rows'] : array();

		$variant = $this->resolve_variant( $variant, $heading );
		$rounded = 'flat' !== $variant;

		$inner = '';
		foreach ( array_values( $rows ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$inner .= $this->render_row( $row, 0 === $index % 2, $ctx, $background_slug, $rounded );
		}

		return $this->render_section( $heading, $intro, $inner, $ctx, $background_slug );
	}

	/**
	 * Render one alternating media/text row.
	 *
	 * @param array<string, mixed> $row             Row content.
	 * @param bool                 $image_first     Whether the image column comes first (even rows).
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug The section's own background slug.
	 * @param bool                 $rounded         Whether the row image gets the rounded/shadowed treatment.
	 * @return string
	 */
	private function render_row( array $row, bool $image_first, Context $ctx, ?string $background_slug, bool $rounded = true ): string {
		$row_heading = isset( $row['heading'] ) ? (string) $row['heading'] : '';
		$body        = isset( $row['body'] ) ? (string) $row['body'] : '';
		$image_url   = isset( $row['imageUrl'] ) ? (string) $row['imageUrl'] : '';
		$cta         = isset( $row['cta'] ) && is_array( $row['cta'] ) ? $row['cta'] : null;

		$text_column  = $this->render_heading( $row_heading, 3, null );
		$text_column .= $this->render_paragraph( $body, null );
		if ( null !== $cta && ! empty( $cta['label'] ) ) {
			$button_bg    = $this->contrasting_slug( $ctx, $background_slug );
			$button_text  = $this->text_slug_for_background( $ctx, $button_bg );
			$text_column .= $this->render_buttons_wrap( $this->render_button( (string) $cta['label'], isset( $cta['url'] ) ? (string) $cta['url'] : '#', $button_bg, $button_text ), false );
		}
		$text_column = $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $text_column . '</div>' );

		$image_column = '';
		if ( '' !== $image_url ) {
			$image_column = $rounded
				? $this->render_image_block( $image_url, true )
				: $this->comment_wrap(
					'image',
					array( 'sizeSlug' => 'large' ),
					'<figure class="wp-block-image size-large"><img src="' . $this->esc_url( $image_url ) . '" alt=""/></figure>'
				);
		}
		$image_column = $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $image_column . '</div>' );

		$columns = $image_first ? ( $image_column . $text_column ) : ( $text_column . $image_column );

		return $this->render_columns_wrap( $columns, $ctx );
	}
}
