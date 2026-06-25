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

	public function test_is_solid_color_classifies_values(): void {
		$this->assertTrue( Context::is_solid_color( '#FFEE58' ) );
		$this->assertTrue( Context::is_solid_color( '#503AA8' ) );
		$this->assertTrue( Context::is_solid_color( 'rgb(10,20,30)' ) );
		$this->assertFalse( Context::is_solid_color( 'color-mix(in srgb, currentColor 20%, transparent)' ) );
		$this->assertFalse( Context::is_solid_color( 'var(--wp--preset--color--x)' ) );
		$this->assertFalse( Context::is_solid_color( 'transparent' ) );
		$this->assertFalse( Context::is_solid_color( 'rgba(0,0,0,0.2)' ) );
	}

	public function test_roles_skip_non_solid_colors(): void {
		$ctx = new Context(
			array(
				array(
					'slug'  => 'base',
					'color' => '#FFFFFF',
					'name'  => 'Base',
				),
				array(
					'slug'  => 'contrast',
					'color' => '#111111',
					'name'  => 'Contrast',
				),
				array(
					'slug'  => 'accent-4',
					'color' => '#686868',
					'name'  => 'Accent 4',
				),
				array(
					'slug'  => 'accent-6',
					'color' => 'color-mix(in srgb, currentColor 20%, transparent)',
					'name'  => 'Accent 6',
				),
			)
		);

		// accent-6 must never become a role, but is still resolvable + flagged non-solid.
		$this->assertNotSame( 'accent-6', $ctx->accent_slug() );
		$this->assertNotSame( 'accent-6', $ctx->light_slug() );
		$this->assertNotSame( 'accent-6', $ctx->dark_slug() );
		$this->assertFalse( $ctx->is_solid_slug( 'accent-6' ) );
		$this->assertTrue( $ctx->is_solid_slug( 'accent-4' ) );
		$this->assertSame( 'color-mix(in srgb, currentColor 20%, transparent)', $ctx->color_for_slug( 'accent-6' ) );
	}
}
