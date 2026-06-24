<?php
/**
 * Base test case for the markup harness.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use PHPUnit\Framework\TestCase;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Provides a Context built from a synthetic palette mirroring the real theme
 * (dark contrast, light base, mid accent), so tests are deterministic and do
 * not require a WordPress bootstrap.
 */
abstract class MarkupHarnessTestCase extends TestCase {

	/**
	 * Build a deterministic Context for tests.
	 *
	 * @return Context
	 */
	protected function context(): Context {
		return new Context(
			array(
				array(
					'slug'  => 'base',
					'color' => '#f5f1e9',
					'name'  => 'Base',
				),
				array(
					'slug'  => 'contrast',
					'color' => '#1c1917',
					'name'  => 'Contrast',
				),
				array(
					'slug'  => 'accent-4',
					'color' => '#a0522d',
					'name'  => 'Accent 4',
				),
			)
		);
	}

	/**
	 * The real "Ready when you are" CTA section: a group with vertical-only padding.
	 *
	 * @return string
	 */
	protected function cta_section(): string {
		return '<!-- wp:group {"backgroundColor":"accent-4","textColor":"base","style":{"spacing":{"padding":{"top":"64px","bottom":"64px"}}}} -->' . "\n"
			. '<div class="wp-block-group has-accent-4-background-color has-base-color has-background has-text-color fade-in is-layout-flow wp-block-group-is-layout-flow" style="background-color:var(--wp--preset--color--accent-4);color:var(--wp--preset--color--base);padding-top:64px;padding-bottom:64px"><p>x</p></div>' . "\n"
			. '<!-- /wp:group -->';
	}

	/**
	 * The real form grid with run-together track sizes.
	 *
	 * @return string
	 */
	protected function form_grid(): string {
		return '<div style="display:grid;grid-template-columns:1fr1fr;gap:12px">a</div>'
			. '<div style="display:grid;grid-template-columns:1fr;gap:12px">b</div>';
	}

	/**
	 * The real bare "Confirm participation" submit button.
	 *
	 * @return string
	 */
	protected function bare_button(): string {
		return '<button>Confirm participation</button>';
	}
}
