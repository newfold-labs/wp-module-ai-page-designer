<?php
/**
 * Process steps section archetype: numbered "how it works" steps.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a {@see RendersMarkup::render_section()} wide section with a
 * `core/columns` row, one step per column: a circular accent number badge
 * (a real `core/paragraph` carrying `backgroundColor`/`textColor` attrs, so it
 * stays a selectable, theme-recolorable block — not raw HTML), then a centered
 * step title and body. Columns never declare a `width` attr — the
 * Validator-safe auto-distributed state.
 *
 * Content shape:
 * ```
 * [
 *   'heading' => string|null,
 *   'intro'   => string|null,
 *   'steps'   => [ [ 'title' => string, 'body' => string ], ... ] (3-4 typical, capped at 4),
 * ]
 * ```
 *
 * Single variant, `numbered`.
 */
class ProcessSteps implements Archetype {

	use RendersMarkup;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'processSteps';
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
		$steps   = isset( $content['steps'] ) && is_array( $content['steps'] ) ? array_slice( $content['steps'], 0, 4 ) : array();

		$columns = empty( $steps ) ? '' : $this->render_columns( $steps, $ctx, $background_slug );

		return $this->render_section( $heading, $intro, $columns, $ctx, $background_slug );
	}

	/**
	 * Render one column per step.
	 *
	 * @param array<int, array<string, string>> $steps           Step definitions.
	 * @param Context                            $ctx             Theme/conformance context.
	 * @param string|null                        $background_slug The section's own background slug.
	 * @return string
	 */
	private function render_columns( array $steps, Context $ctx, ?string $background_slug ): string {
		$badge_bg   = $this->contrasting_slug( $ctx, $background_slug );
		$badge_text = $this->text_slug_for_background( $ctx, $badge_bg );

		$columns = '';
		$number  = 0;
		foreach ( $steps as $step ) {
			++$number;
			$title = isset( $step['title'] ) ? (string) $step['title'] : '';
			$body  = isset( $step['body'] ) ? (string) $step['body'] : '';

			$column_inner  = $this->render_number_badge( $number, $badge_bg, $badge_text );
			$column_inner .= $this->render_heading( $title, 3, null, true );
			$column_inner .= $this->render_paragraph( $body, null, true );

			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $column_inner . '</div>' );
		}

		return $this->render_columns_wrap( $columns, $ctx, false, 'md', true );
	}

	/**
	 * Render the circular step-number badge as a `core/paragraph` with real
	 * color attrs (selectable + recolorable), circled via inline sizing.
	 *
	 * @param int         $number    Step number (1-based).
	 * @param string|null $bg_slug   Badge background slug.
	 * @param string|null $text_slug Badge text slug.
	 * @return string
	 */
	private function render_number_badge( int $number, ?string $bg_slug, ?string $text_slug ): string {
		$classes = array( 'has-text-align-center' );
		$attrs   = array( 'align' => 'center' );
		$style   = 'width:56px;height:56px;line-height:56px;border-radius:9999px;margin-left:auto;margin-right:auto;text-align:center;font-weight:700;font-size:1.25rem';

		if ( null !== $bg_slug ) {
			$classes[]                = 'has-' . $bg_slug . '-background-color';
			$classes[]                = 'has-background';
			$attrs['backgroundColor'] = $bg_slug;
			$style                   .= ';background-color:var(--wp--preset--color--' . $bg_slug . ')';
		}
		if ( null !== $text_slug ) {
			$classes[]          = 'has-' . $text_slug . '-color';
			$classes[]          = 'has-text-color';
			$attrs['textColor'] = $text_slug;
			$style             .= ';color:var(--wp--preset--color--' . $text_slug . ')';
		}

		return $this->comment_wrap(
			'paragraph',
			$attrs,
			'<p class="' . implode( ' ', $classes ) . '" style="' . $style . '">' . $number . '</p>'
		);
	}
}
