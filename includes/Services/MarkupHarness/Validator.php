<?php
/**
 * The conformance definition-of-done, shared by the runtime gate and tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\UnrenderableContentFallback;

/**
 * Asserts that conformed markup satisfies the harness's definition-of-done.
 *
 * Every past defect class becomes a permanent assertion here. The harness gates
 * on an empty violation list; the test suite uses the same instance as its
 * oracle, so "valid" means exactly one thing in production and in CI.
 */
class Validator {

	/**
	 * The unrenderable-content rule, consulted as the assertion for its own
	 * defect class — see {@see Validator::check_unrenderable_content()}.
	 *
	 * @var UnrenderableContentFallback
	 */
	private $fallback;

	/**
	 * Constructor.
	 *
	 * @param RenderSupport|null $support Environment probe (defaults to a real one; tests inject a double).
	 */
	public function __construct( ?RenderSupport $support = null ) {
		$this->fallback = new UnrenderableContentFallback( $support );
	}

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

		$this->check_malformed_delimiters( $markup, $violations );
		$this->check_document_wrappers( $markup, $violations );
		$this->check_unrenderable_content( $markup, $violations );
		$this->check_run_together_grid( $markup, $violations );
		$this->check_bare_units( $markup, $violations );
		$this->check_vertical_only_padding( $markup, $violations );
		$this->check_column_widths( $markup, $violations );
		$this->check_cover_images( $markup, $violations );
		$this->check_cover_defaults( $markup, $ctx, $violations );
		$this->check_button_bg_collision( $markup, $ctx, $violations );
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
	 * No block delimiter missing its leading `!` (e.g. `<-- wp:column -->`).
	 *
	 * Mirrors {@see RepairDelimiters}: such a delimiter is not a valid HTML comment, so it
	 * renders as visible text and is invisible to parse_blocks.
	 *
	 * @param string             $markup     Block markup.
	 * @param array<int, string> $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function check_malformed_delimiters( string $markup, array &$violations ) {
		if ( preg_match( '/<--\s*\/?\s*wp:/i', $markup ) ) {
			$violations[] = 'malformed_delimiter';
		}
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
	 * Nothing on the page that this site cannot render: no unregistered block
	 * that renders nothing, no form (block or shortcode) whose plugin or form
	 * ID is absent, and no block pointing at a pattern, synced pattern,
	 * template part or menu that does not exist here.
	 *
	 * Mirrors {@see UnrenderableContentFallback} by *calling it* — the repair
	 * and the assertion run the same predicate over the same
	 * {@see RenderSupport}, so they cannot drift apart into a harness that
	 * loops (fixing what is never flagged) or lies (flagging what is never
	 * fixed).
	 *
	 * @param string             $markup     Block markup.
	 * @param array<int, string> $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function check_unrenderable_content( string $markup, array &$violations ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return;
		}
		$found = $this->find_unrenderable_content( parse_blocks( $markup ) );
		if ( null !== $found ) {
			$violations[] = $found;
		}
	}

	/**
	 * The first unrenderable block or shortcode in the tree, already formatted
	 * as a violation string, or null.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string|null
	 */
	private function find_unrenderable_content( array $blocks ) {
		foreach ( $blocks as $block ) {
			$reason = $this->fallback->unrenderable_reason( $block );
			if ( null !== $reason ) {
				return $reason;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$nested = $this->find_unrenderable_content( $block['innerBlocks'] );
				if ( null !== $nested ) {
					return $nested;
				}
			}
		}
		return null;
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
	 * No columns block whose percentage child widths fail to form a ~100% layout.
	 *
	 * Mirrors {@see ColumnWidthNormalize}: a columns block is a violation when its
	 * columns mix present and missing percentage widths, or when all are present
	 * but do not sum to ~100%. Columns using a non-percentage width (deliberate
	 * px/calc layouts) are skipped, as is a fully auto-distributed (all missing) set.
	 *
	 * @param string             $markup     Block markup.
	 * @param array<int, string> $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function check_column_widths( string $markup, array &$violations ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return;
		}
		if ( $this->has_invalid_columns( parse_blocks( $markup ) ) ) {
			$violations[] = 'invalid_column_widths';
		}
	}

	/**
	 * Whether any columns block in the tree has an invalid width distribution.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return bool
	 */
	private function has_invalid_columns( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && 'core/columns' === $block['blockName']
				&& $this->columns_block_invalid( $block ) ) {
				return true;
			}
			if ( ! empty( $block['innerBlocks'] ) && $this->has_invalid_columns( $block['innerBlocks'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a single columns block's direct column widths are invalid.
	 *
	 * @param array<string, mixed> $columns_block Columns block.
	 * @return bool
	 */
	private function columns_block_invalid( array $columns_block ): bool {
		$inner = isset( $columns_block['innerBlocks'] ) ? $columns_block['innerBlocks'] : array();

		$count   = 0;
		$present = 0;
		$missing = 0;
		$sum     = 0.0;
		foreach ( $inner as $child ) {
			if ( ! isset( $child['blockName'] ) || 'core/column' !== $child['blockName'] ) {
				continue;
			}
			++$count;
			$width = isset( $child['attrs']['width'] ) ? $child['attrs']['width'] : '';
			if ( '' === $width || null === $width ) {
				++$missing;
				continue;
			}
			if ( ! is_string( $width ) || ! preg_match( '/^\s*([0-9]+(?:\.[0-9]+)?)%\s*$/', $width, $matches ) ) {
				// Non-percentage width: deliberate layout, not a violation.
				return false;
			}
			++$present;
			$sum += (float) $matches[1];
		}

		if ( $count < 2 || 0 === $present ) {
			return false;
		}
		if ( $missing > 0 ) {
			return true;
		}
		return abs( $sum - 100.0 ) > 1.5;
	}

	/**
	 * No cover block that declares a background image url but renders no image.
	 *
	 * Mirrors {@see CoverImage}: a cover with a `url` attribute must contain a
	 * rendered <img class="wp-block-cover__image-background"> (or a background-image
	 * style), otherwise the directly-rendered preview/front-end shows no image.
	 *
	 * @param string             $markup     Block markup.
	 * @param array<int, string> $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function check_cover_images( string $markup, array &$violations ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return;
		}
		if ( $this->has_cover_without_image( parse_blocks( $markup ) ) ) {
			$violations[] = 'cover_missing_image';
		}
	}

	/**
	 * Whether any cover in the tree declares a url but renders no image.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return bool
	 */
	private function has_cover_without_image( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && 'core/cover' === $block['blockName'] ) {
				$url = isset( $block['attrs']['url'] ) ? $block['attrs']['url'] : '';
				if ( is_string( $url ) && '' !== $url ) {
					$html = '';
					if ( ! empty( $block['innerContent'] ) ) {
						foreach ( $block['innerContent'] as $chunk ) {
							if ( is_string( $chunk ) ) {
								$html .= $chunk;
							}
						}
					}
					$renders = false !== stripos( $html, 'wp-block-cover__image-background' )
						|| preg_match( '/background-image\s*:\s*url\(/i', $html );
					if ( ! $renders ) {
						return true;
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && $this->has_cover_without_image( $block['innerBlocks'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every cover declares the conformance defaults (minHeight, dimRatio, and —
	 * when a palette is available — a background color fallback).
	 *
	 * Mirrors {@see CoverDefaults}.
	 *
	 * @param string             $markup     Block markup.
	 * @param Context            $ctx        Conformance context.
	 * @param array<int, string> $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function check_cover_defaults( string $markup, Context $ctx, array &$violations ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return;
		}
		if ( $this->has_cover_missing_defaults( parse_blocks( $markup ), $ctx ) ) {
			$violations[] = 'cover_missing_defaults';
		}
	}

	/**
	 * Whether any cover is missing a conformance default.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param Context                          $ctx    Context.
	 * @return bool
	 */
	private function has_cover_missing_defaults( array $blocks, Context $ctx ): bool {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && 'core/cover' === $block['blockName'] ) {
				$attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();
				if ( empty( $attrs['minHeight'] ) || ! isset( $attrs['dimRatio'] ) ) {
					return true;
				}
				if ( $ctx->has_palette() && empty( $attrs['backgroundColor'] ) ) {
					return true;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && $this->has_cover_missing_defaults( $block['innerBlocks'], $ctx ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * No button whose background slug matches the section behind it (invisible button).
	 *
	 * Mirrors {@see ButtonBackgroundCollision}: walking the tree, a core/button whose
	 * backgroundColor equals the nearest ancestor's solid background slug is a violation.
	 *
	 * @param string             $markup     Block markup.
	 * @param Context            $ctx        Conformance context.
	 * @param array<int, string> $violations Violation accumulator (by reference).
	 * @return void
	 */
	private function check_button_bg_collision( string $markup, Context $ctx, array &$violations ) {
		if ( ! function_exists( 'parse_blocks' ) || ! $ctx->has_palette() ) {
			return;
		}
		if ( $this->has_button_collision( parse_blocks( $markup ), null, $ctx ) ) {
			$violations[] = 'button_bg_collision';
		}
	}

	/**
	 * Whether any button collides with its inherited section background.
	 *
	 * @param array<int, array<string, mixed>> $blocks       Parsed blocks.
	 * @param string|null                      $inherited_bg Nearest ancestor solid bg slug.
	 * @param Context                          $ctx          Context.
	 * @return bool
	 */
	private function has_button_collision( array $blocks, $inherited_bg, Context $ctx ): bool {
		foreach ( $blocks as $block ) {
			$own_bg = isset( $block['attrs']['backgroundColor'] ) ? $block['attrs']['backgroundColor'] : null;

			if ( isset( $block['blockName'] ) && 'core/button' === $block['blockName']
				&& null !== $own_bg && null !== $inherited_bg && $own_bg === $inherited_bg ) {
				return true;
			}

			$applied_bg = ( null !== $own_bg && $ctx->is_solid_slug( $own_bg ) ) ? $own_bg : $inherited_bg;
			if ( ! empty( $block['innerBlocks'] ) && $this->has_button_collision( $block['innerBlocks'], $applied_bg, $ctx ) ) {
				return true;
			}
		}
		return false;
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
