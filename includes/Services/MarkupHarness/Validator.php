<?php
/**
 * The conformance definition-of-done, shared by the runtime gate and tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness;

/**
 * Asserts that conformed markup satisfies the harness's definition-of-done.
 *
 * Every past defect class becomes a permanent assertion here. The harness gates
 * on an empty violation list; the test suite uses the same instance as its
 * oracle, so "valid" means exactly one thing in production and in CI.
 */
class Validator {

	/**
	 * Validate markup and return a list of human-readable violations.
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Conformance context.
	 * @return array<int, string> Empty when valid.
	 */
	public function validate( string $markup, Context $ctx ): array {
		$violations = array();

		if ( '' === trim( $markup ) ) {
			return $violations;
		}

		$this->check_document_wrappers( $markup, $violations );
		$this->check_run_together_grid( $markup, $violations );
		$this->check_bare_units( $markup, $violations );
		$this->check_vertical_only_padding( $markup, $violations );
		$this->check_unstyled_form_buttons( $markup, $ctx, $violations );
		$this->check_non_solid_colors( $markup, $ctx, $violations );

		return $violations;
	}

	/**
	 * Whether the markup passes every assertion.
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Conformance context.
	 * @return bool
	 */
	public function is_valid( string $markup, Context $ctx ): bool {
		return array() === $this->validate( $markup, $ctx );
	}

	/**
	 * No full-document wrappers or embedded scripts/styles.
	 *
	 * @param string             $markup     Block markup.
	 * @param array<int, string> $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function check_document_wrappers( string $markup, array &$violations ) {
		if ( preg_match( '/<\s*(html|head|body|script|style)\b/i', $markup, $match ) ) {
			$violations[] = 'document_wrapper:<' . strtolower( $match[1] ) . '>';
		}
	}

	/**
	 * No run-together grid track sizes ("1fr1fr").
	 *
	 * @param string             $markup     Block markup.
	 * @param array<int, string> $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function check_run_together_grid( string $markup, array &$violations ) {
		if ( preg_match( '/grid-template-columns\s*:\s*[^;"\']*fr\d/i', $markup ) ) {
			$violations[] = 'invalid_grid:run_together_fr';
		}
	}

	/**
	 * No bare-unit declarations with no number ("padding-top:px").
	 *
	 * @param string             $markup     Block markup.
	 * @param array<int, string> $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function check_bare_units( string $markup, array &$violations ) {
		if ( preg_match( '/[a-z-]+\s*:\s*(?:px|em|rem|vh|vw)\s*(?:;|")/i', $markup ) ) {
			$violations[] = 'invalid_css:bare_unit';
		}
	}

	/**
	 * No group declaring vertical padding but missing a horizontal side.
	 *
	 * @param string             $markup     Block markup.
	 * @param array<int, string> $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function check_vertical_only_padding( string $markup, array &$violations ) {
		if ( ! preg_match_all( '/<!-- wp:group (\{.*?\}) -->/', $markup, $matches ) ) {
			return;
		}
		foreach ( $matches[1] as $json ) {
			$attrs = json_decode( $json, true );
			if ( ! is_array( $attrs ) ) {
				continue;
			}
			$padding = isset( $attrs['style']['spacing']['padding'] ) ? $attrs['style']['spacing']['padding'] : null;
			if ( ! is_array( $padding ) ) {
				continue;
			}
			$has_vertical = ! empty( $padding['top'] ) || ! empty( $padding['bottom'] );
			$missing_side = empty( $padding['left'] ) || empty( $padding['right'] );
			if ( $has_vertical && $missing_side ) {
				$violations[] = 'asymmetric_padding:group';
				return;
			}
		}
	}

	/**
	 * No raw form submit button left without a background.
	 *
	 * @param string             $markup     Block markup.
	 * @param Context            $ctx        Conformance context.
	 * @param array<int, string> $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function check_unstyled_form_buttons( string $markup, Context $ctx, array &$violations ) {
		// Only enforced when a palette is available to style with.
		if ( ! $ctx->has_palette() ) {
			return;
		}
		if ( ! preg_match_all( '/<(button|input)\b([^>]*?)\/?>/i', $markup, $matches, PREG_SET_ORDER ) ) {
			return;
		}
		foreach ( $matches as $match ) {
			$tag   = strtolower( $match[1] );
			$attrs = $match[2];
			if ( 'input' === $tag ) {
				$type = 'text';
				if ( preg_match( '/\btype\s*=\s*"([^"]*)"/i', $attrs, $type_match ) ) {
					$type = strtolower( $type_match[1] );
				}
				if ( 'submit' !== $type && 'button' !== $type ) {
					continue;
				}
			}
			$has_background = preg_match( '/\sstyle\s*=\s*"[^"]*background(?:-color)?\s*:/i', $attrs );
			if ( ! $has_background ) {
				$violations[] = 'unstyled_form_button:<' . $tag . '>';
				return;
			}
		}
	}

	/**
	 * No block uses a non-solid palette slug (e.g. a color-mix token) as its
	 * text or background color — those render invisible.
	 *
	 * @param string             $markup     Block markup.
	 * @param Context            $ctx        Conformance context.
	 * @param array<int, string> $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function check_non_solid_colors( string $markup, Context $ctx, array &$violations ) {
		if ( ! $ctx->has_palette() ) {
			return;
		}
		if ( ! preg_match_all( '/<!-- wp:[a-z0-9-]+(?:\/[a-z0-9-]+)? (\{.*?\}) -->/', $markup, $matches ) ) {
			return;
		}
		foreach ( $matches[1] as $json ) {
			$attrs = json_decode( $json, true );
			if ( ! is_array( $attrs ) ) {
				continue;
			}
			foreach ( array( 'textColor', 'backgroundColor' ) as $key ) {
				if ( ! empty( $attrs[ $key ] ) && ! $ctx->is_solid_slug( $attrs[ $key ] ) && null !== $ctx->color_for_slug( $attrs[ $key ] ) ) {
					$violations[] = 'non_solid_color:' . $attrs[ $key ];
					return;
				}
			}
		}
	}
}
