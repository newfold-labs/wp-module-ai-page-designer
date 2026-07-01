<?php
/**
 * Section archetype interface.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * A typed, theme-correct page section. Archetypes are pure functions of their
 * inputs — no network calls, no WordPress dependency beyond string-building —
 * so a page is correct by construction rather than repaired after the fact.
 *
 * Image/avatar resolution (imageQuery -> imageUrl) is the caller's
 * responsibility (see {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\PageAssembler}),
 * not the archetype's — `render()` only ever sees already-resolved content.
 */
interface Archetype {

	/**
	 * Render this archetype's content to Gutenberg block markup.
	 *
	 * @param array<string, mixed> $content         Fully-resolved slot content (see the concrete class docblock for its shape).
	 * @param string|null          $variant         Requested variant, or null for the archetype's default.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Palette slug to use as this section's background, or null for none.
	 * @return string Gutenberg block markup for one section.
	 */
	public function render( array $content, ?string $variant, Context $ctx, ?string $background_slug ): string;

	/**
	 * Stable identifier matching the plan's `archetype` key.
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * This archetype's default background slug when the plan item doesn't
	 * request a specific one (drives the assembler's background rhythm).
	 *
	 * @param Context $ctx Theme/conformance context.
	 * @return string|null
	 */
	public function default_background( Context $ctx ): ?string;
}
