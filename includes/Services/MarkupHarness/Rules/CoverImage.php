<?php
/**
 * Ensure a cover block with a background image URL renders the image element.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * A cover block can carry its background image in the `url` attribute while the
 * rendered HTML has no <img class="wp-block-cover__image-background"> element (nor
 * a background-image style). The block editor would render the image from the
 * attribute, but the preview/front-end render this markup directly, so the image
 * is invisible. When a cover declares a url but renders no image, inject the
 * standard cover image element right after the opening cover wrapper.
 *
 * Uses native parse_blocks/serialize_blocks; no-ops if WordPress is unavailable.
 * Idempotent: once the image element is present the cover is left untouched.
 */
class CoverImage implements Rule {

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
		$changed = $this->ensure_covers( $blocks );

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
		return 'cover_image';
	}

	/**
	 * Walk the block tree, ensuring each cover with a url renders its image.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks (by reference).
	 * @return bool Whether any block was changed.
	 */
	private function ensure_covers( array &$blocks ): bool {
		$changed = false;
		foreach ( $blocks as &$block ) {
			if ( isset( $block['blockName'] ) && 'core/cover' === $block['blockName'] ) {
				if ( $this->ensure_cover_image( $block ) ) {
					$changed = true;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				if ( $this->ensure_covers( $block['innerBlocks'] ) ) {
					$changed = true;
				}
			}
		}
		unset( $block );
		return $changed;
	}

	/**
	 * Inject the cover image element when the cover declares a url but renders none.
	 *
	 * @param array<string, mixed> $cover Cover block (by reference).
	 * @return bool Whether the cover was changed.
	 */
	private function ensure_cover_image( array &$cover ): bool {
		$url = isset( $cover['attrs']['url'] ) ? $cover['attrs']['url'] : '';
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		if ( self::renders_image( $this->cover_html( $cover ) ) ) {
			return false;
		}

		if ( empty( $cover['innerContent'] ) ) {
			return false;
		}

		$img = '<img class="wp-block-cover__image-background" alt="" src="' . $this->escape_url( $url ) . '" data-object-fit="cover"/>';

		foreach ( $cover['innerContent'] as $index => $chunk ) {
			if ( ! is_string( $chunk ) || false === stripos( $chunk, 'wp-block-cover' ) ) {
				continue;
			}
			$patched = preg_replace_callback(
				'/<div\b[^>]*\bwp-block-cover\b[^>]*>/i',
				function ( $matches ) use ( $img ) {
					return $matches[0] . $img;
				},
				$chunk,
				1
			);
			if ( null !== $patched && $patched !== $chunk ) {
				$cover['innerContent'][ $index ] = $patched;
				return true;
			}
		}

		return false;
	}

	/**
	 * Concatenate a block's string innerContent chunks.
	 *
	 * @param array<string, mixed> $block Block.
	 * @return string
	 */
	private function cover_html( array $block ): string {
		$html = '';
		if ( ! empty( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $chunk ) {
				if ( is_string( $chunk ) ) {
					$html .= $chunk;
				}
			}
		}
		return $html;
	}

	/**
	 * Whether cover HTML already renders a background image (element or CSS).
	 *
	 * @param string $html Cover HTML.
	 * @return bool
	 */
	public static function renders_image( string $html ): bool {
		return false !== stripos( $html, 'wp-block-cover__image-background' )
			|| (bool) preg_match( '/background-image\s*:\s*url\(/i', $html );
	}

	/**
	 * Escape a URL for an attribute, using WordPress when available.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function escape_url( string $url ): string {
		if ( function_exists( 'esc_url' ) ) {
			return esc_url( $url );
		}
		return str_replace( array( '"', '<', '>' ), array( '%22', '%3C', '%3E' ), $url );
	}
}
