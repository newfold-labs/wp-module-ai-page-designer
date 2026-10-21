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
 * Auto-pickable variants:
 *  - `numbered` (default): one step per column in a horizontal row.
 *  - `vertical`: the same numbered steps stacked top-to-bottom as centered,
 *    width-constrained rows — a walk-down-the-page "journey" treatment.
 */
class ProcessSteps implements Archetype {

	use RendersMarkup;

	/**
	 * Auto-pickable variant names — see the class docblock.
	 *
	 * @var string[]
	 */
	const VARIANTS = array( 'numbered', 'vertical' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'processSteps';
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
		return array();
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
		$steps   = isset( $content['steps'] ) && is_array( $content['steps'] ) ? array_slice( $content['steps'], 0, 4 ) : array();

		$variant = $this->resolve_variant( $variant, $heading );

		$inner = '';
		if ( ! empty( $steps ) ) {
			$inner = 'vertical' === $variant
				? $this->render_vertical( $steps, $ctx, $background_slug )
				: $this->render_columns( $steps, $ctx, $background_slug );
		}

		return $this->render_section( $heading, $intro, $inner, $ctx, $background_slug );
	}

	/**
	 * Render the `vertical` variant: each numbered step as its own centered,
	 * width-constrained row stacked down the page. Row groups carry symmetric
	 * four-side padding — never top/bottom-only (`asymmetric_padding:group`).
	 *
	 * @param array<int, array<string, string>> $steps           Step definitions.
	 * @param Context                           $ctx             Theme/conformance context.
	 * @param string|null                       $background_slug The section's own background slug.
	 * @return string
	 */
	private function render_vertical( array $steps, Context $ctx, ?string $background_slug ): string {
		$badge_bg   = $this->contrasting_slug( $ctx, $background_slug );
		$badge_text = $this->text_slug_for_background( $ctx, $badge_bg );

		// className "nfd-max-w-720" for the width/centering (real CSS in
		// get_motion_css()), not an unbacked inline style — see
		// RendersMarkup::render_heading()'s note. Padding remains legitimately
		// inlined, in top/right/bottom/left order.
		$row_attrs = array(
			'className' => 'nfd-max-w-720',
			'style'     => array(
				'spacing' => array(
					'padding' => array(
						'top'    => $ctx->spacing_attr( 'sm' ),
						'right'  => $ctx->spacing_attr( 'sm' ),
						'bottom' => $ctx->spacing_attr( 'sm' ),
						'left'   => $ctx->spacing_attr( 'sm' ),
					),
				),
			),
		);
		$row_style = 'padding-top:' . $ctx->spacing_css( 'sm' ) . ';padding-right:' . $ctx->spacing_css( 'sm' )
			. ';padding-bottom:' . $ctx->spacing_css( 'sm' ) . ';padding-left:' . $ctx->spacing_css( 'sm' );

		$rows   = '';
		$number = 0;
		foreach ( $steps as $step ) {
			++$number;
			$title = isset( $step['title'] ) ? (string) $step['title'] : '';
			$body  = isset( $step['body'] ) ? (string) $step['body'] : '';

			$row_inner  = $this->render_number_badge( $number, $badge_bg, $badge_text );
			$row_inner .= $this->render_heading( $title, 3, null, true );
			$row_inner .= $this->render_paragraph( $body, null, true );

			$rows .= $this->comment_wrap( 'group', $row_attrs, '<div class="nfd-max-w-720 wp-block-group" style="' . $row_style . '">' . $row_inner . '</div>' );
		}

		return $rows;
	}

	/**
	 * Render one column per step.
	 *
	 * @param array<int, array<string, string>> $steps           Step definitions.
	 * @param Context                           $ctx             Theme/conformance context.
	 * @param string|null                       $background_slug The section's own background slug.
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
		// className "nfd-step-badge" (fixed circle sizing/typography, real CSS
		// in get_motion_css()), not an unbacked inline style — core/paragraph's
		// actual save() output is class-only for align/textColor/backgroundColor
		// and has no shape/size attrs at all. See render_heading()'s note.
		$classes = array( 'nfd-step-badge', 'has-text-align-center' );
		$attrs   = array(
			'className' => 'nfd-step-badge',
			'align'     => 'center',
		);

		if ( null !== $bg_slug ) {
			$classes[]                = 'has-' . $bg_slug . '-background-color';
			$attrs['backgroundColor'] = $bg_slug;
		}
		if ( null !== $text_slug ) {
			$classes[]          = 'has-' . $text_slug . '-color';
			$attrs['textColor'] = $text_slug;
		}
		if ( null !== $bg_slug ) {
			$classes[] = 'has-background';
		}
		if ( null !== $text_slug ) {
			$classes[] = 'has-text-color';
		}

		return $this->comment_wrap(
			'paragraph',
			$attrs,
			'<p class="' . implode( ' ', $classes ) . '">' . $number . '</p>'
		);
	}
}
