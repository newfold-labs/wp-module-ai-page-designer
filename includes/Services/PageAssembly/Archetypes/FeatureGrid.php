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
		$heading = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$intro   = isset( $content['intro'] ) ? (string) $content['intro'] : '';
		$items   = isset( $content['items'] ) && is_array( $content['items'] ) ? array_slice( $content['items'], 0, 3 ) : array();

		$columns = empty( $items ) ? '' : $this->render_columns( $items );

		return $this->render_section( $heading, $intro, $columns, $ctx, $background_slug );
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

			$column_inner  = $this->render_heading( $title, 3, null );
			$column_inner .= $this->render_paragraph( $body, null );

			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $column_inner . '</div>' );
		}

		return $this->comment_wrap( 'columns', array(), '<div class="wp-block-columns">' . $columns . '</div>' );
	}
}
