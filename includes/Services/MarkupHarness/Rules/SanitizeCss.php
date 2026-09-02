<?php
/**
 * Sanitize invalid inline CSS produced by the model.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Fixes two observed inline-CSS defects:
 *  - grid-template-columns track sizes run together ("1fr1fr") — invalid, collapses the grid.
 *  - bare-unit declarations with no number ("padding-top:px") — invalid, must not be published.
 */
class SanitizeCss implements Rule {

	/**
	 * {@inheritDoc}
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Context (unused).
	 * @return string
	 */
	public function apply( string $markup, Context $ctx ): string {
		$markup = $this->fix_run_together_grid_columns( $markup );
		$markup = $this->strip_bare_unit_declarations( $markup );
		return $markup;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'sanitize_css';
	}

	/**
	 * Insert the missing space between run-together grid track sizes ("1fr1fr" -> "1fr 1fr").
	 *
	 * @param string $markup Block markup.
	 * @return string
	 */
	private function fix_run_together_grid_columns( string $markup ): string {
		return preg_replace_callback(
			'/(grid-template-columns\s*:\s*)([^;"\']+)/i',
			static function ( $matches ) {
				return $matches[1] . preg_replace( '/(fr)(?=\d)/i', '$1 ', $matches[2] );
			},
			$markup
		);
	}

	/**
	 * Strip declarations whose value is a bare unit with no number ("padding-top:px").
	 *
	 * @param string $markup Block markup.
	 * @return string
	 */
	private function strip_bare_unit_declarations( string $markup ): string {
		return preg_replace_callback(
			'/(style=")([^"]*)(")/i',
			static function ( $matches ) {
				$declarations = array_filter(
					array_map( 'trim', explode( ';', $matches[2] ) ),
					static function ( $declaration ) {
						if ( '' === $declaration ) {
							return false;
						}
						return ! preg_match( '/^[a-z-]+\s*:\s*(px|em|rem|%|vh|vw)\s*$/i', $declaration );
					}
				);
				return $matches[1] . implode( ';', $declarations ) . $matches[3];
			},
			$markup
		);
	}
}
