<?php
/**
 * Give top-level section groups a wide alignment and symmetric side padding.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Section groups read best constrained to the theme's wide width with breathing
 * room on the sides; the model often emits them with no alignment (so they snap
 * to the narrow content width) or no horizontal padding (so content runs flush to
 * the edge). For every TOP-LEVEL group — sections live at the top level after
 * {@see UnwrapLoneGroup} hoists a lone page wrapper — set align to "wide" (unless
 * already wide/full) and fill the default horizontal padding, patching both the
 * block JSON attributes and the rendered group div.
 *
 * This enforces a styling default rather than repairing a correctness defect, so
 * it is intentionally not gated by the {@see Validator}. Uses native
 * parse_blocks/serialize_blocks; no-ops if WordPress is unavailable. Idempotent.
 */
class SectionGroupPattern implements Rule {

	/**
	 * Default horizontal section padding.
	 *
	 * @var string
	 */
	const PADDING_X = '32px';

	/**
	 * {@inheritDoc}
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Context (unused; padding is a fixed default).
	 * @return string
	 */
	public function apply( string $markup, Context $ctx ): string {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $markup;
		}

		$blocks  = parse_blocks( $markup );
		$changed = false;
		foreach ( $blocks as &$block ) {
			if ( isset( $block['blockName'] ) && 'core/group' === $block['blockName'] ) {
				if ( $this->fix_section( $block ) ) {
					$changed = true;
				}
			}
		}
		unset( $block );

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
		return 'section_group_pattern';
	}

	/**
	 * Apply the section pattern to a single top-level group.
	 *
	 * @param array<string, mixed> $group Group block (by reference).
	 * @return bool Whether the group changed.
	 */
	private function fix_section( array &$group ): bool {
		if ( ! isset( $group['attrs'] ) || ! is_array( $group['attrs'] ) ) {
			$group['attrs'] = array();
		}
		$attrs = $group['attrs'];

		$align     = isset( $attrs['align'] ) ? $attrs['align'] : null;
		$add_align = ( 'wide' !== $align && 'full' !== $align );

		$padding   = isset( $attrs['style']['spacing']['padding'] ) && is_array( $attrs['style']['spacing']['padding'] )
			? $attrs['style']['spacing']['padding']
			: array();
		$add_left  = empty( $padding['left'] );
		$add_right = empty( $padding['right'] );

		if ( ! $add_align && ! $add_left && ! $add_right ) {
			return false;
		}

		if ( $add_align ) {
			$group['attrs']['align'] = 'wide';
		}
		if ( $add_left || $add_right ) {
			if ( ! isset( $group['attrs']['style'] ) || ! is_array( $group['attrs']['style'] ) ) {
				$group['attrs']['style'] = array();
			}
			if ( ! isset( $group['attrs']['style']['spacing'] ) || ! is_array( $group['attrs']['style']['spacing'] ) ) {
				$group['attrs']['style']['spacing'] = array();
			}
			if ( ! isset( $group['attrs']['style']['spacing']['padding'] ) || ! is_array( $group['attrs']['style']['spacing']['padding'] ) ) {
				$group['attrs']['style']['spacing']['padding'] = array();
			}
			if ( $add_left ) {
				$group['attrs']['style']['spacing']['padding']['left'] = self::PADDING_X;
			}
			if ( $add_right ) {
				$group['attrs']['style']['spacing']['padding']['right'] = self::PADDING_X;
			}
		}

		$this->patch_group_div( $group, $add_align, $add_left, $add_right );

		return true;
	}

	/**
	 * Patch the rendered group div with the new alignment and padding.
	 *
	 * @param array<string, mixed> $group     Group block (by reference).
	 * @param bool                 $add_align Whether wide alignment was added.
	 * @param bool                 $add_left  Whether left padding was added.
	 * @param bool                 $add_right Whether right padding was added.
	 * @return void
	 */
	private function patch_group_div( array &$group, bool $add_align, bool $add_left, bool $add_right ) {
		if ( empty( $group['innerContent'] ) ) {
			return;
		}
		foreach ( $group['innerContent'] as $index => $chunk ) {
			if ( ! is_string( $chunk ) || false === stripos( $chunk, 'wp-block-group' ) ) {
				continue;
			}
			$group['innerContent'][ $index ] = preg_replace_callback(
				'/(<div\b[^>]*\bclass=")([^"]*\bwp-block-group\b[^"]*)("[^>]*?)(?: style="([^"]*)")?(\s*>)/i',
				function ( $matches ) use ( $add_align, $add_left, $add_right ) {
					$pre     = $matches[1];
					$classes = $matches[2];
					$mid     = $matches[3];
					$style   = isset( $matches[4] ) ? $matches[4] : '';
					$close   = $matches[5];

					if ( $add_align && ! preg_match( '/\balign(?:wide|full)\b/', $classes ) ) {
						$classes = preg_replace( '/\bwp-block-group\b/', 'wp-block-group alignwide', $classes, 1 );
					}

					$declarations = array_values( array_filter( array_map( 'trim', explode( ';', $style ) ) ) );
					if ( $add_left && ! $this->has_declaration( $declarations, 'padding-left' ) ) {
						$declarations[] = 'padding-left:' . self::PADDING_X;
					}
					if ( $add_right && ! $this->has_declaration( $declarations, 'padding-right' ) ) {
						$declarations[] = 'padding-right:' . self::PADDING_X;
					}
					$style_attr = empty( $declarations ) ? '' : ' style="' . implode( ';', $declarations ) . '"';

					return $pre . $classes . $mid . $style_attr . $close;
				},
				$chunk,
				1
			);
			return;
		}
	}

	/**
	 * Whether a CSS property is already declared.
	 *
	 * @param array<int, string> $declarations Declaration list.
	 * @param string             $property     Property name.
	 * @return bool
	 */
	private function has_declaration( array $declarations, string $property ): bool {
		foreach ( $declarations as $declaration ) {
			if ( preg_match( '/^' . preg_quote( $property, '/' ) . '\s*:/i', $declaration ) ) {
				return true;
			}
		}
		return false;
	}
}
