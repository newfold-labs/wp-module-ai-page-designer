<?php
/**
 * Context tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Context::class )]
class ContextTest extends MarkupHarnessTestCase {

	public function test_resolves_palette_roles_by_brightness(): void {
		$ctx = $this->context();
		$this->assertSame( 'contrast', $ctx->dark_slug() );
		$this->assertSame( 'base', $ctx->light_slug() );
		$this->assertSame( 'accent-4', $ctx->accent_slug() );
		$this->assertTrue( $ctx->has_palette() );
	}

	public function test_empty_palette_has_no_roles(): void {
		$ctx = new Context( array() );
		$this->assertFalse( $ctx->has_palette() );
		$this->assertNull( $ctx->dark_slug() );
		$this->assertNull( $ctx->accent_slug() );
	}

	public function test_deduplicates_by_hex(): void {
		$ctx = new Context(
			array(
				array(
					'slug'  => 'a',
					'color' => '#000000',
					'name'  => 'A',
				),
				array(
					'slug'  => 'b',
					'color' => '#000000',
					'name'  => 'B',
				),
				array(
					'slug'  => 'c',
					'color' => '#ffffff',
					'name'  => 'C',
				),
			)
		);
		// Darkest is the first #000000 (slug a); lightest is #ffffff (slug c).
		$this->assertSame( 'a', $ctx->dark_slug() );
		$this->assertSame( 'c', $ctx->light_slug() );
	}
}
