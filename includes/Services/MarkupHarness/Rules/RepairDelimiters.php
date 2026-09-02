<?php
/**
 * Repair block-comment delimiters whose leading `!` the model dropped.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * The model occasionally emits a block delimiter missing its `!` — `<-- wp:column -->`
 * instead of `<!-- wp:column -->`. That is not a valid HTML comment, so it renders as
 * visible text in the preview/front-end, and it is invisible to every block-aware tool
 * (WordPress's parse_blocks and the Worker's regexes all require `<!--`), so it slips
 * through unrepaired. Restore the missing `!` so the delimiter becomes a real block again.
 *
 * Pure string repair — must run FIRST in the pipeline, before any parse_blocks-based rule,
 * so the rest of the harness (and WordPress) see well-formed blocks. Idempotent: a
 * well-formed `<!--` has no bare `<--` to match.
 */
class RepairDelimiters implements Rule {

	/**
	 * {@inheritDoc}
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Context (unused).
	 * @return string
	 */
	public function apply( string $markup, Context $ctx ): string {
		// `<--` (no `!`) immediately before a wp: opening or /wp: closing token. A valid
		// `<!--` never matches: its `<` is followed by `!`, not `-`.
		$repaired = preg_replace( '/<--(\s*\/?\s*wp:)/i', '<!--$1', $markup );
		return null === $repaired ? $markup : $repaired;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'repair_delimiters';
	}
}
