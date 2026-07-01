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
 * v1 supports a single variant, auto left/right alternation (no named variant).
 */
class AlternatingMediaText implements Archetype {

	use RendersMarkup;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'alternatingMediaText';
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
		$intro   = isset( $content['intro'] ) ? (string) $content['intro'] : '';
		$rows    = isset( $content['rows'] ) && is_array( $content['rows'] ) ? $content['rows'] : array();

		$inner = '';
		foreach ( array_values( $rows ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$inner .= $this->render_row( $row, 0 === $index % 2, $ctx, $background_slug );
		}

		return $this->render_section( $heading, $intro, $inner, $ctx, $background_slug );
	}

	/**
	 * Render one alternating media/text row.
	 *
	 * @param array<string, mixed> $row             Row content.
	 * @param bool                  $image_first     Whether the image column comes first (even rows).
	 * @param Context               $ctx             Theme/conformance context.
	 * @param string|null           $background_slug The section's own background slug.
	 * @return string
	 */
	private function render_row( array $row, bool $image_first, Context $ctx, ?string $background_slug ): string {
		$row_heading = isset( $row['heading'] ) ? (string) $row['heading'] : '';
		$body        = isset( $row['body'] ) ? (string) $row['body'] : '';
		$image_url   = isset( $row['imageUrl'] ) ? (string) $row['imageUrl'] : '';
		$cta         = isset( $row['cta'] ) && is_array( $row['cta'] ) ? $row['cta'] : null;

		$text_column  = $this->render_heading( $row_heading, 3, null );
		$text_column .= $this->render_paragraph( $body, null );
		if ( null !== $cta && ! empty( $cta['label'] ) ) {
			$button_bg   = $this->contrasting_slug( $ctx, $background_slug );
			$button_text = $this->text_slug_for_background( $ctx, $button_bg );
			$text_column .= $this->render_buttons_wrap( $this->render_button( (string) $cta['label'], isset( $cta['url'] ) ? (string) $cta['url'] : '#', $button_bg, $button_text ), false );
		}
		$text_column = $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $text_column . '</div>' );

		$image_column = '';
		if ( '' !== $image_url ) {
			$image_column = $this->comment_wrap(
				'image',
				array( 'sizeSlug' => 'large' ),
				'<figure class="wp-block-image size-large"><img src="' . $this->esc_url( $image_url ) . '" alt=""/></figure>'
			);
		}
		$image_column = $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $image_column . '</div>' );

		$columns = $image_first ? ( $image_column . $text_column ) : ( $text_column . $image_column );

		return $this->comment_wrap( 'columns', array(), '<div class="wp-block-columns">' . $columns . '</div>' );
	}
}
