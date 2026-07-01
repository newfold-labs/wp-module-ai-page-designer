<?php
/**
 * Feature grid section archetype: heading + intro over a row of value-prop cards.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a wide `core/group` section containing an optional heading/intro
 * and a `core/columns` row. Columns never declare an explicit width, so
 * WordPress auto-distributes them evenly — this is the one width state
 * {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator::has_invalid_columns()}
 * always accepts, avoiding the `invalid_column_widths` defect by construction
 * rather than by repair. The wrapping group always declares all four padding
 * sides together, avoiding `asymmetric_padding:group` the same way.
 *
 * Content shape:
 * ```
 * [
 *   'heading' => string|null,
 *   'intro'   => string|null,
 *   'items'   => [ [ 'title' => string, 'body' => string ], ... ] (exactly 3 for cards-3),
 * ]
 * ```
 *
 * v1 supports a single variant, `cards-3`.
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
		$text_slug = ( null !== $background_slug && $ctx->is_dark_slug( $background_slug ) )
			? $ctx->light_slug()
			: $ctx->dark_slug();

		$heading = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$intro   = isset( $content['intro'] ) ? (string) $content['intro'] : '';
		$items   = isset( $content['items'] ) && is_array( $content['items'] ) ? array_slice( $content['items'], 0, 3 ) : array();

		$group_attrs = array(
			'align' => 'wide',
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => $ctx->spacing_attr( 'lg' ),
						'bottom' => $ctx->spacing_attr( 'lg' ),
						'left'   => $ctx->spacing_attr( 'md' ),
						'right'  => $ctx->spacing_attr( 'md' ),
					),
				),
			),
		);
		$group_classes = array( 'wp-block-group', 'alignwide' );
		$group_style   = 'padding-top:' . $ctx->spacing_css( 'lg' ) . ';padding-bottom:' . $ctx->spacing_css( 'lg' )
			. ';padding-left:' . $ctx->spacing_css( 'md' ) . ';padding-right:' . $ctx->spacing_css( 'md' );

		if ( null !== $background_slug ) {
			$group_attrs['backgroundColor'] = $background_slug;
			$group_classes[]                = 'has-' . $background_slug . '-background-color';
			$group_classes[]                = 'has-background';
			$group_style                    .= ';background-color:var(--wp--preset--color--' . $background_slug . ')';
		}
		if ( null !== $text_slug ) {
			$group_attrs['textColor'] = $text_slug;
			$group_classes[]          = 'has-' . $text_slug . '-color';
			$group_classes[]          = 'has-text-color';
			$group_style             .= ';color:var(--wp--preset--color--' . $text_slug . ')';
		}

		$inner = '';
		if ( '' !== $heading ) {
			$inner .= $this->render_heading( $heading );
		}
		if ( '' !== $intro ) {
			$inner .= $this->render_paragraph( $intro, array( 'has-text-align-center' ), 'center' );
		}
		if ( ! empty( $items ) ) {
			$inner .= $this->render_columns( $items );
		}

		return $this->comment_wrap(
			'group',
			$group_attrs,
			'<div class="' . implode( ' ', $group_classes ) . '" style="' . $group_style . '">' . $inner . '</div>'
		);
	}

	/**
	 * Render the section heading.
	 *
	 * @param string $text Heading text.
	 * @return string
	 */
	private function render_heading( string $text ): string {
		return $this->comment_wrap(
			'heading',
			array( 'textAlign' => 'center' ),
			'<h2 class="wp-block-heading has-text-align-center">' . $this->esc_html( $text ) . '</h2>'
		);
	}

	/**
	 * Render a paragraph.
	 *
	 * @param string      $text    Paragraph text.
	 * @param string[]    $classes Extra classes.
	 * @param string|null $align   Text-align attribute value, or null.
	 * @return string
	 */
	private function render_paragraph( string $text, array $classes, ?string $align ): string {
		$attrs = null !== $align ? array( 'align' => $align ) : array();
		return $this->comment_wrap(
			'paragraph',
			$attrs,
			'<p class="' . implode( ' ', $classes ) . '">' . $this->esc_html( $text ) . '</p>'
		);
	}

	/**
	 * Render the 3-column feature row. Columns never declare a `width` attr —
	 * WordPress auto-distributes them evenly, which is the one width state the
	 * Validator's column-width check always accepts.
	 *
	 * @param array<int, array<string, string>> $items Up to 3 [ 'title', 'body' ] items.
	 * @return string
	 */
	private function render_columns( array $items ): string {
		$columns = '';
		foreach ( $items as $item ) {
			$title = isset( $item['title'] ) ? (string) $item['title'] : '';
			$body  = isset( $item['body'] ) ? (string) $item['body'] : '';

			$column_inner = $this->comment_wrap( 'heading', array( 'level' => 3 ), '<h3 class="wp-block-heading">' . $this->esc_html( $title ) . '</h3>' );
			$column_inner .= $this->comment_wrap( 'paragraph', array(), '<p>' . $this->esc_html( $body ) . '</p>' );

			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $column_inner . '</div>' );
		}

		return $this->comment_wrap( 'columns', array(), '<div class="wp-block-columns">' . $columns . '</div>' );
	}
}
