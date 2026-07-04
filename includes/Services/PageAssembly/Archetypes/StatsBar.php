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
 * v1 supports a single variant, `accent-band`.
 */
class StatsBar implements Archetype {

	use RendersMarkup;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'statsBar';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_background( Context $ctx ): ?string {
		$accent = $ctx->accent_slug();
		return null !== $accent ? $accent : $ctx->dark_slug();
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $content, ?string $variant, Context $ctx, ?string $background_slug ): string {
		$bg_slug = $background_slug ?? $this->default_background( $ctx );
		$items   = isset( $content['items'] ) && is_array( $content['items'] ) ? $content['items'] : array();

		$columns = empty( $items ) ? '' : $this->render_columns( $items, $ctx );

		return $this->render_section( null, null, $columns, $ctx, $bg_slug );
	}

	/**
	 * Render one column per stat.
	 *
	 * @param array<int, array<string, string>> $items [ 'value', 'label' ] items.
	 * @param Context                            $ctx   Theme/conformance context.
	 * @return string
	 */
	private function render_columns( array $items, Context $ctx ): string {
		$columns = '';
		foreach ( $items as $item ) {
			$value = isset( $item['value'] ) ? (string) $item['value'] : '';
			$label = isset( $item['label'] ) ? (string) $item['label'] : '';

			$column_inner  = $this->render_heading( $value, 3, null, true );
			$column_inner .= $this->render_paragraph( $label, null, true );

			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $column_inner . '</div>' );
		}

		return $this->render_columns_wrap( $columns, $ctx, false, 'md', true );
	}
}
