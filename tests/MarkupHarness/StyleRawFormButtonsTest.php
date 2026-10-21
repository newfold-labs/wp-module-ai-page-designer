<?php
/**
 * StyleRawFormButtons rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\StyleRawFormButtons;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( StyleRawFormButtons::class )]
class StyleRawFormButtonsTest extends MarkupHarnessTestCase {

	public function test_styles_a_bare_button_with_accent_cta(): void {
		$rule = new StyleRawFormButtons();
		$out  = $rule->apply( $this->bare_button(), $this->context() );

		$this->assertStringContainsString( 'background:var(--wp--preset--color--accent-4)', $out );
		$this->assertStringContainsString( 'color:var(--wp--preset--color--base)', $out );
		$this->assertStringContainsString( 'min-height:44px', $out );
		$this->assertStringContainsString( 'Confirm participation', $out );
	}

	public function test_leaves_already_styled_button_untouched(): void {
		$rule   = new StyleRawFormButtons();
		$styled = '<button type="submit" style="background:var(--wp--preset--color--accent-4);color:#fff">Go</button>';
		$this->assertSame( $styled, $rule->apply( $styled, $this->context() ) );
	}

	public function test_ignores_text_inputs(): void {
		$rule  = new StyleRawFormButtons();
		$input = '<input type="text" name="x" />';
		$this->assertSame( $input, $rule->apply( $input, $this->context() ) );
	}

	public function test_styles_submit_input(): void {
		$rule = new StyleRawFormButtons();
		$out  = $rule->apply( '<input type="submit" value="Send" />', $this->context() );
		$this->assertStringContainsString( 'background:var(--wp--preset--color--accent-4)', $out );
	}

	public function test_is_idempotent(): void {
		$rule = new StyleRawFormButtons();
		$once = $rule->apply( $this->bare_button(), $this->context() );
		$this->assertSame( $once, $rule->apply( $once, $this->context() ) );
	}
}
