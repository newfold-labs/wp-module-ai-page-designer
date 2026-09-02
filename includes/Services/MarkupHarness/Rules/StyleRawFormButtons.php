<?php
/**
 * Style raw-HTML form buttons that ship unstyled.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * The model emits forms as raw HTML and the submit <button> is not a selectable
 * Gutenberg block, so the user cannot fix it through the edit flow and it often
 * ships with no styling (browser-default gray). Give it the standard accent CTA
 * look. Buttons that already declare a background are left untouched; existing
 * style declarations are preserved.
 *
 * Interim until the Stage 2 form archetype renders buttons as core/button.
 */
class StyleRawFormButtons implements Rule {

	/**
	 * {@inheritDoc}
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Context (provides accent/light slugs).
	 * @return string
	 */
	public function apply( string $markup, Context $ctx ): string {
		$accent_slug = $ctx->accent_slug();
		$light_slug  = $ctx->light_slug();
		if ( empty( $accent_slug ) || empty( $light_slug ) ) {
			return $markup;
		}

		$button_style = array(
			'border:none',
			'border-radius:12px',
			"background:var(--wp--preset--color--{$accent_slug})",
			"color:var(--wp--preset--color--{$light_slug})",
			'padding:12px 16px',
			'font-weight:700',
			'cursor:pointer',
			'line-height:1.2',
			'min-height:44px',
			'min-width:190px',
			'display:inline-flex',
			'align-items:center',
			'justify-content:center',
		);

		return preg_replace_callback(
			'/<(button|input)\b([^>]*?)(\/?)>/i',
			function ( $matches ) use ( $button_style ) {
				$tag   = strtolower( $matches[1] );
				$attrs = $matches[2];
				$slash = $matches[3];

				if ( 'input' === $tag ) {
					$type = 'text';
					if ( preg_match( '/\btype\s*=\s*"([^"]*)"/i', $attrs, $type_match ) ) {
						$type = strtolower( $type_match[1] );
					}
					if ( 'submit' !== $type && 'button' !== $type ) {
						return $matches[0];
					}
				}

				if ( preg_match( '/\sstyle\s*=\s*"([^"]*)"/i', $attrs, $style_match ) ) {
					if ( $this->has_background( $style_match[1] ) ) {
						return $matches[0];
					}
					$merged    = $this->merge_style( $style_match[1], $button_style );
					$new_attrs = str_replace( $style_match[0], ' style="' . $merged . '"', $attrs );
					return '<' . $tag . $new_attrs . $slash . '>';
				}

				return '<' . $tag . $attrs . ' style="' . implode( ';', $button_style ) . '"' . $slash . '>';
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
		return 'style_raw_form_buttons';
	}

	/**
	 * Whether a style string already declares a background.
	 *
	 * @param string $style Inline style declarations.
	 * @return bool
	 */
	private function has_background( string $style ): bool {
		return (bool) preg_match( '/(?:^|;)\s*background(?:-color)?\s*:/i', $style );
	}

	/**
	 * Merge the standard button declarations into an existing style, preserving present ones.
	 *
	 * @param string             $existing     Existing style declarations.
	 * @param array<int, string> $button_style Standard button declarations.
	 * @return string
	 */
	private function merge_style( string $existing, array $button_style ): string {
		$declarations = array_values( array_filter( array_map( 'trim', explode( ';', $existing ) ) ) );
		$present      = array();
		foreach ( $declarations as $declaration ) {
			$present[ strtolower( trim( explode( ':', $declaration )[0] ) ) ] = true;
		}

		foreach ( $button_style as $declaration ) {
			$property = strtolower( trim( explode( ':', $declaration )[0] ) );
			if ( 'background' === $property && ( isset( $present['background'] ) || isset( $present['background-color'] ) ) ) {
				continue;
			}
			if ( ! isset( $present[ $property ] ) ) {
				$declarations[] = $declaration;
			}
		}

		return implode( ';', $declarations );
	}
}
