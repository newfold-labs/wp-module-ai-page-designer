<?php
/**
 * Shared markup-building helpers for archetypes.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

/**
 * Small string-building conveniences common to every archetype: Gutenberg
 * comment-delimiter wrapping, attribute JSON encoding, and escaping — each
 * falling back to a plain-PHP equivalent when WordPress isn't loaded (mirrors
 * the existing pattern in {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\CoverImage::escape_url()}),
 * so archetypes stay unit-testable under the pure-PHP PHPUnit bootstrap.
 */
trait RendersMarkup {

	/**
	 * Wrap rendered HTML in a Gutenberg block comment delimiter pair.
	 *
	 * @param string               $block_name Block name without the `core/` prefix.
	 * @param array<string, mixed> $attrs      Block JSON attributes (empty array omits the JSON blob).
	 * @param string               $html       Rendered inner HTML.
	 * @return string
	 */
	private function comment_wrap( string $block_name, array $attrs, string $html ): string {
		$json = empty( $attrs ) ? '' : $this->json_encode( $attrs ) . ' ';
		return "<!-- wp:{$block_name} {$json}-->\n{$html}\n<!-- /wp:{$block_name} -->\n\n";
	}

	/**
	 * JSON-encode block attributes, using WordPress's encoder when available.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return string
	 */
	private function json_encode( array $attrs ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $attrs );
		}
		return json_encode( $attrs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Escape a URL for an HTML attribute, using WordPress when available.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function esc_url( string $url ): string {
		if ( function_exists( 'esc_url' ) ) {
			return esc_url( $url );
		}
		return str_replace( array( '"', '<', '>' ), array( '%22', '%3C', '%3E' ), $url );
	}

	/**
	 * Escape text for HTML output, using WordPress when available.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function esc_html( string $text ): string {
		if ( function_exists( 'esc_html' ) ) {
			return esc_html( $text );
		}
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
