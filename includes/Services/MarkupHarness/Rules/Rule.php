<?php
/**
 * Conformance rule interface.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * A single, idempotent transformation applied to AI-generated block markup.
 *
 * Every rule MUST be idempotent: apply( apply( $m ) ) === apply( $m ). This is
 * what lets the harness run on generate (before preview) and again on save
 * without the saved markup ever diverging from what was previewed.
 */
interface Rule {

	/**
	 * Apply the rule to block markup and return the transformed markup.
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Theme/conformance context.
	 * @return string Transformed markup.
	 */
	public function apply( string $markup, Context $ctx ): string;

	/**
	 * Stable identifier for reports and tests.
	 *
	 * @return string
	 */
	public function name(): string;
}
