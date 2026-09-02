<?php
/**
 * ColorLegibility rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\ColorLegibility;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ColorLegibility::class )]
class ColorLegibilityTest extends MarkupHarnessTestCase {

	/**
	 * Twenty Twenty-Five style palette: accent-6 is a non-solid color-mix token.
	 *
	 * @return Context
	 */
	private function tt5_context(): Context {
		return new Context(
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
					'slug'  => 'accent-3',
					'color' => '#503AA8',
					'name'  => 'Accent 3',
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
	}

	protected function setUp(): void {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			$this->markTestSkipped( 'parse_blocks/serialize_blocks unavailable (no WordPress install found).' );
		}
	}

	public function test_repairs_non_solid_text_color_on_button(): void {
		$markup = '<!-- wp:button {"backgroundColor":"accent-4","textColor":"accent-6"} -->' . "\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link has-accent-4-background-color has-accent-6-color has-background has-text-color wp-element-button" style="background-color:var(--wp--preset--color--accent-4);color:var(--wp--preset--color--accent-6)">Click</a></div>' . "\n"
			. '<!-- /wp:button -->';

		$out = ( new ColorLegibility() )->apply( $markup, $this->tt5_context() );

		$this->assertStringNotContainsString( 'accent-6', $out, 'the invisible token is gone' );
		$this->assertStringContainsString( '"textColor":"base"', $out, 'comment textColor repaired' );
		$this->assertStringContainsString( 'has-base-color', $out, 'rendered class repaired' );
		$this->assertStringContainsString( 'color:var(--wp--preset--color--base)', $out, 'inline color repaired' );
		// Background was solid and is kept.
		$this->assertStringContainsString( 'has-accent-4-background-color', $out );
	}

	public function test_repairs_non_solid_background_and_keeps_text_legible(): void {
		$markup = '<!-- wp:button {"backgroundColor":"accent-6","textColor":"contrast"} -->' . "\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link has-accent-6-background-color has-contrast-color has-background has-text-color wp-element-button" style="background-color:var(--wp--preset--color--accent-6);color:var(--wp--preset--color--contrast)">Book</a></div>' . "\n"
			. '<!-- /wp:button -->';

		$out = ( new ColorLegibility() )->apply( $markup, $this->tt5_context() );

		$this->assertStringNotContainsString( 'accent-6', $out, 'invisible background token gone' );
		// Background swapped to the solid accent slug.
		$this->assertStringContainsString( 'has-accent-4-background-color', $out );
		// Dark text on the new (dark) accent would be illegible -> swapped to light.
		$this->assertStringContainsString( '"textColor":"base"', $out );
		$this->assertStringNotContainsString( 'has-contrast-color', $out );
	}

	public function test_repairs_non_solid_text_on_dark_section(): void {
		$markup = '<!-- wp:group {"backgroundColor":"contrast","textColor":"accent-6"} -->' . "\n"
			. '<div class="wp-block-group has-contrast-background-color has-accent-6-color has-background has-text-color" style="background-color:var(--wp--preset--color--contrast);color:var(--wp--preset--color--accent-6)"><p>Hello</p></div>' . "\n"
			. '<!-- /wp:group -->';

		$out = ( new ColorLegibility() )->apply( $markup, $this->tt5_context() );

		$this->assertStringNotContainsString( 'accent-6', $out );
		$this->assertStringContainsString( 'has-base-color', $out, 'light text on dark section' );
		$this->assertStringContainsString( '"textColor":"base"', $out );
		$this->assertStringContainsString( 'has-contrast-background-color', $out, 'dark bg kept' );
	}

	public function test_leaves_legible_solid_pairing_untouched(): void {
		$markup = '<!-- wp:group {"backgroundColor":"contrast","textColor":"base"} -->' . "\n"
			. '<div class="wp-block-group has-contrast-background-color has-base-color has-background has-text-color" style="background-color:var(--wp--preset--color--contrast);color:var(--wp--preset--color--base)"><p>Hi</p></div>' . "\n"
			. '<!-- /wp:group -->';

		$out = ( new ColorLegibility() )->apply( $markup, $this->tt5_context() );
		$this->assertStringContainsString( '"backgroundColor":"contrast"', $out );
		$this->assertStringContainsString( '"textColor":"base"', $out );
	}

	public function test_is_idempotent(): void {
		$markup = '<!-- wp:button {"backgroundColor":"accent-6","textColor":"contrast"} -->' . "\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link has-accent-6-background-color has-contrast-color wp-element-button" style="background-color:var(--wp--preset--color--accent-6);color:var(--wp--preset--color--contrast)">Book</a></div>' . "\n"
			. '<!-- /wp:button -->';

		$rule = new ColorLegibility();
		$once = $rule->apply( $markup, $this->tt5_context() );
		$this->assertSame( $once, $rule->apply( $once, $this->tt5_context() ) );
	}

	public function test_validator_flags_then_passes(): void {
		$markup    = '<!-- wp:button {"backgroundColor":"accent-4","textColor":"accent-6"} -->' . "\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link has-accent-4-background-color has-accent-6-color" style="background-color:var(--wp--preset--color--accent-4);color:var(--wp--preset--color--accent-6)">Click</a></div>' . "\n"
			. '<!-- /wp:button -->';
		$ctx       = $this->tt5_context();
		$validator = new Validator();

		$this->assertContains( 'non_solid_color:accent-6', $validator->validate( $markup, $ctx ) );

		$out = ( new ColorLegibility() )->apply( $markup, $ctx );
		$this->assertSame( array(), $validator->validate( $out, $ctx ) );
	}
}
