<?php
/**
 * Base test case for page assembly.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use PHPUnit\Framework\TestCase;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\ImageService;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Same synthetic palette as {@see \NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness\MarkupHarnessTestCase}
 * plus a spacing scale and a spare light swatch, so archetype tests exercise
 * spacing-slug resolution and the muted-light background rhythm.
 */
abstract class PageAssemblyTestCase extends TestCase {

	/**
	 * Build a deterministic Context for tests: dark contrast, light base, mid
	 * accent, a spare light "muted" swatch, and a 7-step spacing scale mirroring
	 * the real Newfold baseline theme (`bluehost-blueprint`, slugs `20`-`80`).
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
				array(
					'slug'  => 'base-midtone',
					'color' => '#e5ded0',
					'name'  => 'Base Midtone',
				),
			),
			array( '20', '30', '40', '50', '60', '70', '80' )
		);
	}

	/**
	 * A stub ImageService returning a deterministic URL per query — no network,
	 * no WordPress dependency (`wp_remote_get`/`get_bloginfo` aren't available
	 * under this pure-PHP bootstrap).
	 *
	 * @return ImageService
	 */
	protected function fake_image_service(): ImageService {
		return new class() extends ImageService {
			public function get_unsplash_images( $query ) {
				return array( 'https://images.example.test/' . md5( (string) $query ) );
			}
		};
	}
}
