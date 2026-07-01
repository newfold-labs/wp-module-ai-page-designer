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
 * v1 supports a single variant, `stacked`.
 */
class FaqAccordion implements Archetype {

	use RendersMarkup;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'faqAccordion';
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
		$items   = isset( $content['items'] ) && is_array( $content['items'] ) ? $content['items'] : array();

		$inner = '';
		foreach ( $items as $item ) {
			$question = isset( $item['q'] ) ? (string) $item['q'] : '';
			$answer   = isset( $item['a'] ) ? (string) $item['a'] : '';
			$inner   .= $this->render_details( $question, $answer );
		}

		return $this->render_section( $heading, null, $inner, $ctx, $background_slug );
	}

	/**
	 * Render one `core/details` question/answer pair.
	 *
	 * @param string $question Question text.
	 * @param string $answer   Answer text.
	 * @return string
	 */
	private function render_details( string $question, string $answer ): string {
		$html = '<details class="wp-block-details"><summary>' . $this->esc_html( $question ) . '</summary>'
			. $this->render_paragraph( $answer, null )
			. '</details>';

		return $this->comment_wrap( 'details', array(), $html );
	}
}
