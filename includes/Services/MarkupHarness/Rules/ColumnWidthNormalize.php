<?php
/**
 * Normalize column widths within a columns block to a valid distribution.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * The model frequently emits a wp:columns block whose child column widths do not
 * form a valid layout — e.g. four columns each declaring 50% (so they sum to 200%
 * and overflow), or some columns with a width and others without. When the
 * percentage widths are inconsistent, redistribute them evenly across the columns
 * (100 / n each), patching both the block JSON `width` attribute and the rendered
 * `flex-basis` style so the two stay consistent.
 *
 * Uses native parse_blocks/serialize_blocks for sibling context; no-ops if
 * WordPress is unavailable, or if any column uses a non-percentage width (a
 * deliberate px/calc layout is left untouched). Idempotent: an evenly distributed
 * set of widths is valid, so a re-run makes no change.
 */
class ColumnWidthNormalize implements Rule {

	/**
	 * Acceptable deviation (percentage points) of the summed widths from 100.
	 *
	 * Covers rounding of repeating fractions (three columns at 33.33% sum to 99.99).
	 *
	 * @var float
	 */
	const SUM_TOLERANCE = 1.5;

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

		$blocks  = parse_blocks( $markup );
		$changed = $this->normalize_tree( $blocks );

		// Re-serialize only when a change was made, to avoid disturbing unrelated markup.
		if ( ! $changed ) {
			return $markup;
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'column_width_normalize';
	}

	/**
	 * Walk the block tree, normalizing each columns block's child widths.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks (by reference).
	 * @return bool Whether any block was changed.
	 */
	private function normalize_tree( array &$blocks ): bool {
		$changed = false;
		foreach ( $blocks as &$block ) {
			if ( isset( $block['blockName'] ) && 'core/columns' === $block['blockName'] ) {
				if ( $this->normalize_columns( $block ) ) {
					$changed = true;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				if ( $this->normalize_tree( $block['innerBlocks'] ) ) {
					$changed = true;
				}
			}
		}
		unset( $block );
		return $changed;
	}

	/**
	 * Normalize the direct column children of a single columns block.
	 *
	 * @param array<string, mixed> $columns_block Columns block (by reference).
	 * @return bool Whether the widths were changed.
	 */
	private function normalize_columns( array &$columns_block ): bool {
		$inner = isset( $columns_block['innerBlocks'] ) ? $columns_block['innerBlocks'] : array();

		$col_keys = array();
		foreach ( $inner as $key => $child ) {
			if ( isset( $child['blockName'] ) && 'core/column' === $child['blockName'] ) {
				$col_keys[] = $key;
			}
		}

		$count = count( $col_keys );
		if ( $count < 2 ) {
			return false;
		}

		$present = 0;
		$missing = 0;
		$sum     = 0.0;
		foreach ( $col_keys as $key ) {
			$width = isset( $inner[ $key ]['attrs']['width'] ) ? $inner[ $key ]['attrs']['width'] : '';
			if ( '' === $width || null === $width ) {
				++$missing;
				continue;
			}
			$percent = $this->parse_percent( $width );
			if ( null === $percent ) {
				// A non-percentage width (px/calc) signals a deliberate layout; leave it alone.
				return false;
			}
			++$present;
			$sum += $percent;
		}

		// All widths absent is valid (Gutenberg auto-distributes evenly).
		if ( 0 === $present ) {
			return false;
		}

		// All present and summing to ~100 is already valid.
		if ( 0 === $missing && abs( $sum - 100.0 ) <= self::SUM_TOLERANCE ) {
			return false;
		}

		$even = $this->format_percent( 100.0 / $count );
		foreach ( $col_keys as $key ) {
			$this->set_column_width( $columns_block['innerBlocks'][ $key ], $even );
		}

		return true;
	}

	/**
	 * Apply a width to a column block's JSON attribute and rendered flex-basis.
	 *
	 * @param array<string, mixed> $column Column block (by reference).
	 * @param string               $value  Width value, e.g. "25%".
	 * @return void
	 */
	private function set_column_width( array &$column, string $value ) {
		if ( ! isset( $column['attrs'] ) || ! is_array( $column['attrs'] ) ) {
			$column['attrs'] = array();
		}
		$column['attrs']['width'] = $value;

		if ( empty( $column['innerContent'] ) ) {
			return;
		}
		foreach ( $column['innerContent'] as $index => $chunk ) {
			if ( is_string( $chunk ) && false !== strpos( $chunk, 'wp-block-column' ) ) {
				$column['innerContent'][ $index ] = $this->patch_flex_basis( $chunk, $value );
				break;
			}
		}
	}

	/**
	 * Set (or insert) the flex-basis on the rendered column div.
	 *
	 * @param string $chunk Column opening HTML.
	 * @param string $value Width value, e.g. "25%".
	 * @return string
	 */
	private function patch_flex_basis( string $chunk, string $value ) {
		if ( preg_match( '/flex-basis\s*:/i', $chunk ) ) {
			return preg_replace( '/(flex-basis\s*:\s*)[^;"]*/i', '${1}' . $value, $chunk, 1 );
		}

		return preg_replace_callback(
			'/(<div\b[^>]*\bwp-block-column\b[^>]*?)(?: style="([^"]*)")?(\s*>)/i',
			function ( $matches ) use ( $value ) {
				$pre   = $matches[1];
				$style = isset( $matches[2] ) ? $matches[2] : '';
				$close = $matches[3];

				$declarations   = array_values( array_filter( array_map( 'trim', explode( ';', $style ) ) ) );
				$declarations[] = 'flex-basis:' . $value;

				return $pre . ' style="' . implode( ';', $declarations ) . '"' . $close;
			},
			$chunk,
			1
		);
	}

	/**
	 * Parse a percentage width string to a float, or null when not a percentage.
	 *
	 * @param mixed $width Width attribute value.
	 * @return float|null
	 */
	private function parse_percent( $width ) {
		if ( ! is_string( $width ) ) {
			return null;
		}
		if ( preg_match( '/^\s*([0-9]+(?:\.[0-9]+)?)%\s*$/', $width, $matches ) ) {
			return (float) $matches[1];
		}
		return null;
	}

	/**
	 * Format a percentage with up to two decimals and no trailing zeros (25, 33.33).
	 *
	 * @param float $percent Percentage value.
	 * @return string
	 */
	private function format_percent( float $percent ): string {
		$fixed   = number_format( round( $percent, 2 ), 2, '.', '' );
		$trimmed = rtrim( rtrim( $fixed, '0' ), '.' );
		return $trimmed . '%';
	}
}
