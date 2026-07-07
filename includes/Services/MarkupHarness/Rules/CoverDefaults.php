<?php
/**
 * Fill missing cover defaults: minimum height, dim overlay, background fallback.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * A cover block emitted without a minHeight collapses to its content height, one
 * without a dimRatio has no overlay (so text over a busy image is unreadable),
 * and one without a backgroundColor has no fallback behind the image. Fill these
 * defaults — minHeight 520px, dimRatio 60, and a dark background fallback — on
 * both the block JSON attributes and the rendered cover div so preview and
 * front-end match.
 *
 * Uses native parse_blocks/serialize_blocks; no-ops if WordPress is unavailable.
 * Idempotent: defaults are only added when absent.
 */
class CoverDefaults implements Rule {

	/**
	 * Default cover minimum height in pixels.
	 *
	 * @var int
	 */
	const MIN_HEIGHT = 520;

	/**
	 * Default cover dim ratio (percentage).
	 *
	 * @var int
	 */
	const DIM_RATIO = 60;

	/**
	 * {@inheritDoc}
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Conformance context (provides the dark fallback slug).
	 * @return string
	 */
	public function apply( string $markup, Context $ctx ): string {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $markup;
		}

		$blocks  = parse_blocks( $markup );
		$changed = $this->process( $blocks, $ctx );

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
		return 'cover_defaults';
	}

	/**
	 * Walk the block tree, filling defaults on each cover.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks (by reference).
	 * @param Context                          $ctx    Context.
	 * @return bool Whether any block changed.
	 */
	private function process( array &$blocks, Context $ctx ): bool {
		$changed = false;
		foreach ( $blocks as &$block ) {
			if ( isset( $block['blockName'] ) && 'core/cover' === $block['blockName'] ) {
				if ( $this->fill_cover( $block, $ctx ) ) {
					$changed = true;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				if ( $this->process( $block['innerBlocks'], $ctx ) ) {
					$changed = true;
				}
			}
		}
		unset( $block );
		return $changed;
	}

	/**
	 * Fill a single cover's defaults (attrs + rendered div).
	 *
	 * @param array<string, mixed> $cover Cover block (by reference).
	 * @param Context              $ctx   Context.
	 * @return bool Whether the cover changed.
	 */
	private function fill_cover( array &$cover, Context $ctx ): bool {
		if ( ! isset( $cover['attrs'] ) || ! is_array( $cover['attrs'] ) ) {
			$cover['attrs'] = array();
		}
		$attrs = $cover['attrs'];

		$add_min_height = empty( $attrs['minHeight'] );
		$add_dim        = ! isset( $attrs['dimRatio'] );
		$dark_slug      = $ctx->has_palette() ? $ctx->dark_slug() : null;
		$add_background = empty( $attrs['backgroundColor'] ) && null !== $dark_slug;

		if ( ! $add_min_height && ! $add_dim && ! $add_background ) {
			return false;
		}

		if ( $add_min_height ) {
			$cover['attrs']['minHeight']     = self::MIN_HEIGHT;
			$cover['attrs']['minHeightUnit'] = 'px';
		}
		if ( $add_dim ) {
			$cover['attrs']['dimRatio'] = self::DIM_RATIO;
		}
		if ( $add_background ) {
			$cover['attrs']['backgroundColor'] = $dark_slug;
		}

		$this->patch_cover_div( $cover, $add_min_height, $add_dim, $add_background, $dark_slug );

		return true;
	}

	/**
	 * Patch the rendered cover div with the newly added defaults.
	 *
	 * @param array<string, mixed> $cover          Cover block (by reference).
	 * @param bool                 $add_min_height Whether min-height was added.
	 * @param bool                 $add_dim        Whether the dim overlay was added.
	 * @param bool                 $add_background Whether the background fallback was added.
	 * @param string|null          $dark_slug      Dark fallback slug (when adding background).
	 * @return void
	 */
	private function patch_cover_div( array &$cover, bool $add_min_height, bool $add_dim, bool $add_background, $dark_slug ) {
		if ( empty( $cover['innerContent'] ) ) {
			return;
		}
		foreach ( $cover['innerContent'] as $index => $chunk ) {
			if ( ! is_string( $chunk ) || false === stripos( $chunk, 'wp-block-cover' ) ) {
				continue;
			}
			$cover['innerContent'][ $index ] = preg_replace_callback(
				'/(<div\b[^>]*\bclass=")([^"]*\bwp-block-cover\b[^"]*)("[^>]*?)(?: style="([^"]*)")?(\s*>)/i',
				function ( $matches ) use ( $add_min_height, $add_dim, $add_background, $dark_slug ) {
					$pre     = $matches[1];
					$classes = $matches[2];
					$mid     = $matches[3];
					$style   = isset( $matches[4] ) ? $matches[4] : '';
					$close   = $matches[5];

					$class_list = array_values( array_filter( explode( ' ', $classes ) ) );
					$has_class  = function ( $needle ) use ( $class_list ) {
						return in_array( $needle, $class_list, true );
					};

					if ( $add_dim && ! $this->has_dim_class( $class_list ) ) {
						$class_list[] = 'has-background-dim-' . self::DIM_RATIO;
						$class_list[] = 'has-background-dim';
					}
					if ( $add_background && null !== $dark_slug && ! $has_class( 'has-background' ) ) {
						$class_list[] = 'has-' . $dark_slug . '-background-color';
						$class_list[] = 'has-background';
					}

					$declarations = array_values( array_filter( array_map( 'trim', explode( ';', $style ) ) ) );
					if ( $add_min_height && ! $this->has_declaration( $declarations, 'min-height' ) ) {
						$declarations[] = 'min-height:' . self::MIN_HEIGHT . 'px';
					}
					if ( $add_background && null !== $dark_slug && ! $this->has_declaration( $declarations, 'background-color' ) ) {
						$declarations[] = 'background-color:var(--wp--preset--color--' . $dark_slug . ')';
					}

					$style_attr = empty( $declarations ) ? '' : ' style="' . implode( ';', $declarations ) . '"';

					return $pre . implode( ' ', $class_list ) . $mid . $style_attr . $close;
				},
				$chunk,
				1
			);
			return;
		}
	}

	/**
	 * Whether the class list already declares a dim overlay.
	 *
	 * @param array<int, string> $class_list Class list.
	 * @return bool
	 */
	private function has_dim_class( array $class_list ): bool {
		foreach ( $class_list as $class ) {
			if ( 'has-background-dim' === $class || 0 === strpos( $class, 'has-background-dim-' ) ) {
				return true;
			}
		}
		return false;
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
