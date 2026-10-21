<?php
/**
 * Fill missing horizontal padding on groups that declare vertical padding.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Section groups are frequently emitted with only top/bottom padding, so their
 * content runs flush against the section edge. Any wp:group that declares
 * vertical padding but is missing a horizontal side gets it filled (mirroring an
 * existing side, else the context default). Patches both the block JSON comment
 * and the rendered <div> so the two stay consistent.
 *
 * String-based (operates on a single block's comment + opening div) and proven;
 * idempotent because filled sides are detected and skipped on re-run.
 */
class GroupPaddingSymmetry implements Rule {

	/**
	 * {@inheritDoc}
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Context (provides the default horizontal padding).
	 * @return string
	 */
	public function apply( string $markup, Context $ctx ): string {
		$default_x = $ctx->section_padding_x();

		return preg_replace_callback(
			'/<!-- wp:group (\{.*?\}) -->(\s*<div\b[^>]*class="[^"]*"[^>]*?)(?: style="([^"]*)")?(>)/',
			function ( $matches ) use ( $default_x ) {
				$attrs = json_decode( $matches[1], true );
				if ( ! is_array( $attrs ) ) {
					return $matches[0];
				}

				$padding = isset( $attrs['style']['spacing']['padding'] ) ? $attrs['style']['spacing']['padding'] : null;
				if ( ! is_array( $padding ) ) {
					return $matches[0];
				}

				$has_vertical = ! empty( $padding['top'] ) || ! empty( $padding['bottom'] );
				$missing_side = empty( $padding['left'] ) || empty( $padding['right'] );
				if ( ! $has_vertical || ! $missing_side ) {
					return $matches[0];
				}

				$fill_left  = empty( $padding['left'] );
				$fill_right = empty( $padding['right'] );

				if ( ! empty( $padding['right'] ) ) {
					$fallback = $padding['right'];
				} elseif ( ! empty( $padding['left'] ) ) {
					$fallback = $padding['left'];
				} else {
					$fallback = $default_x;
				}

				if ( $fill_left ) {
					$padding['left'] = $fallback;
				}
				if ( $fill_right ) {
					$padding['right'] = $fallback;
				}
				$attrs['style']['spacing']['padding'] = $padding;

				$new_comment = '<!-- wp:group ' . $this->encode_attrs( $attrs ) . ' -->';

				$div_pre = $matches[2];
				$style   = isset( $matches[3] ) ? $matches[3] : '';
				$close   = $matches[4];

				$declarations = array_values( array_filter( array_map( 'trim', explode( ';', $style ) ) ) );
				if ( $fill_left && ! $this->has_declaration( $declarations, 'padding-left' ) ) {
					$declarations[] = 'padding-left:' . $padding['left'];
				}
				if ( $fill_right && ! $this->has_declaration( $declarations, 'padding-right' ) ) {
					$declarations[] = 'padding-right:' . $padding['right'];
				}

				return $new_comment . $div_pre . ' style="' . implode( ';', $declarations ) . '"' . $close;
			},
			$markup
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'group_padding_symmetry';
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

	/**
	 * Encode block attributes the way Gutenberg serializes them (escaping double hyphens).
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return string
	 */
	private function encode_attrs( array $attrs ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} else {
			$json = json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		}
		if ( false === $json ) {
			$json = '{}';
		}
		return str_replace( '--', '\\u002d\\u002d', $json );
	}
}
