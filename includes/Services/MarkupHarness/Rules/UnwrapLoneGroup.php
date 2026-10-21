<?php
/**
 * Hoist the children of a lone page-wrapper group to the top level.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * The model sometimes wraps the whole page in a single outer group. That breaks
 * every consumer that works on top-level blocks (block selection resolves "the
 * selected section" to the entire page, section detection, splicing). When the
 * page is exactly one backgroundless wp:group whose inner holds more than one
 * block, hoist the children to the top level.
 *
 * Uses native parse_blocks/serialize_blocks; no-ops if WordPress is unavailable.
 * Idempotent: after hoisting, the top level has more than one block so it will
 * not re-trigger.
 */
class UnwrapLoneGroup implements Rule {

	/**
	 * {@inheritDoc}
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Context (unused).
	 * @return string
	 */
	public function apply( string $markup, Context $ctx ): string {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $markup;
		}

		$blocks = parse_blocks( $markup );
		$real   = $this->real_blocks( $blocks );
		if ( 1 !== count( $real ) ) {
			return $markup;
		}

		$only = $real[0];
		if ( 'core/group' !== $only['blockName'] ) {
			return $markup;
		}

		$attrs = isset( $only['attrs'] ) ? $only['attrs'] : array();
		if ( ! empty( $attrs['backgroundColor'] ) ) {
			return $markup;
		}

		$inner_real = $this->real_blocks( isset( $only['innerBlocks'] ) ? $only['innerBlocks'] : array() );
		if ( count( $inner_real ) < 2 ) {
			return $markup;
		}

		return serialize_blocks( $only['innerBlocks'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'unwrap_lone_group';
	}

	/**
	 * Filter out whitespace/freeform placeholder blocks (blockName === null).
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function real_blocks( array $blocks ): array {
		return array_values(
			array_filter(
				$blocks,
				static function ( $block ) {
					return null !== $block['blockName'];
				}
			)
		);
	}
}
