<?php
/**
 * ButtonBackgroundCollision rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\ButtonBackgroundCollision;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ButtonBackgroundCollision::class )]
class ButtonBackgroundCollisionTest extends MarkupHarnessTestCase {

	protected function setUp(): void {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			$this->markTestSkipped( 'parse_blocks/serialize_blocks unavailable (no WordPress install found).' );
		}
	}

	public function test_swaps_colliding_button_to_contrasting_color(): void {
		$out = ( new ButtonBackgroundCollision() )->apply( $this->accent_button_on_accent_section(), $this->context() );

		// Button background swapped off the section's accent.
		$this->assertStringContainsString( '"backgroundColor":"base"', $out, 'button bg swapped to base' );
		$this->assertStringContainsString( '"textColor":"contrast"', $out, 'button text legible on new bg' );
		$this->assertStringContainsString( 'has-base-background-color', $out, 'button class swapped' );
		// The section group still carries the accent background.
		$this->assertStringContainsString( '"backgroundColor":"accent-4"', $out, 'section bg preserved' );
		$this->assertStringContainsString( 'has-accent-4-background-color', $out );
	}

	public function test_leaves_contrasting_button_untouched(): void {
		$markup = '<!-- wp:group {"backgroundColor":"accent-4"} -->' . "\n"
			. '<div class="wp-block-group has-accent-4-background-color has-background">' . "\n"
			. '<!-- wp:buttons -->' . "\n"
			. '<div class="wp-block-buttons">' . "\n"
			. '<!-- wp:button {"backgroundColor":"base","textColor":"contrast"} -->' . "\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button" style="color:var(--wp--preset--color--contrast);background-color:var(--wp--preset--color--base)">Click</a></div>' . "\n"
			. '<!-- /wp:button -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:buttons -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:group -->';

		$this->assertSame( $markup, ( new ButtonBackgroundCollision() )->apply( $markup, $this->context() ) );
	}

	public function test_leaves_button_without_section_bg_untouched(): void {
		$markup = '<!-- wp:buttons -->' . "\n"
			. '<div class="wp-block-buttons">' . "\n"
			. '<!-- wp:button {"backgroundColor":"accent-4","textColor":"base"} -->' . "\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-accent-4-background-color has-text-color has-background wp-element-button" style="color:var(--wp--preset--color--base);background-color:var(--wp--preset--color--accent-4)">Click</a></div>' . "\n"
			. '<!-- /wp:button -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:buttons -->';

		$this->assertSame( $markup, ( new ButtonBackgroundCollision() )->apply( $markup, $this->context() ) );
	}

	public function test_is_idempotent(): void {
		$rule = new ButtonBackgroundCollision();
		$once = $rule->apply( $this->accent_button_on_accent_section(), $this->context() );
		$this->assertSame( $once, $rule->apply( $once, $this->context() ) );
	}

	public function test_validator_flags_then_passes(): void {
		$ctx       = $this->context();
		$validator = new Validator();

		$this->assertContains( 'button_bg_collision', $validator->validate( $this->accent_button_on_accent_section(), $ctx ) );

		$out = ( new ButtonBackgroundCollision() )->apply( $this->accent_button_on_accent_section(), $ctx );
		$this->assertSame( array(), $validator->validate( $out, $ctx ) );
	}
}
