<?php
/**
 * Drop leftover placeholder background-images that shadow a real one.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * When the model (or an image edit) leaves a section with TWO `background-image`
 * declarations in one inline style — a real image plus a leftover
 * `placehold.co` placeholder — CSS last-wins means the placeholder can override
 * the real image, so the published page shows the placeholder (or nothing)
 * while the preview looked fine. This breaks WYSIWYG.
 *
 * This rule removes placeholder `background-image` declarations from any
 * `style="…"` attribute that ALSO carries a non-placeholder `background-image`,
 * leaving the real image to apply. A lone placeholder (no real image alongside)
 * is left untouched — filling it with a real image is the ImageService's job.
 *
 * Idempotent: once the placeholder is gone there is nothing left to strip, so a
 * second pass is a no-op. placehold.co URLs contain no quotes or parentheses, so
 * matching them can never corrupt a data: URI or a real image URL.
 */
class BackgroundImagePlaceholder implements Rule {

	/**
	 * A full `background-image:url(…placehold.co…);` declaration, including any
	 * trailing semicolon. The url() body is bounded by quotes/parens, neither of
	 * which appears in a placehold.co URL, so this cannot swallow other CSS.
	 */
	const PLACEHOLDER_DECLARATION = '/background-image\s*:\s*url\(\s*[\'"]?[^)\'"]*placehold\.co[^)\'"]*[\'"]?\s*\)\s*;?/i';

	/**
	 * {@inheritDoc}
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Context (unused).
	 * @return string
	 */
	public function apply( string $markup, Context $ctx ): string {
		return preg_replace_callback(
			'/(style=")([^"]*)(")/i',
			function ( $matches ) {
				$style = $matches[2];

				// Total background-image declarations vs. placeholder ones. Only strip
				// placeholders when a real background-image remains to take over.
				$total_bg       = preg_match_all( '/background-image\s*:/i', $style );
				$placeholder_bg = preg_match_all( self::PLACEHOLDER_DECLARATION, $style );

				if ( $placeholder_bg < 1 || $total_bg <= $placeholder_bg ) {
					return $matches[0];
				}

				$style = preg_replace( self::PLACEHOLDER_DECLARATION, '', $style );
				// Tidy any doubled/leading/trailing semicolons left behind.
				$style = preg_replace( '/;{2,}/', ';', $style );
				$style = trim( $style, "; \t\n\r" );

				return $matches[1] . $style . $matches[3];
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
		return 'background_image_placeholder';
	}
}
