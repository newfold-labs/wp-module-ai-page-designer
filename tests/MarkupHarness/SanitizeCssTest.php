<?php
/**
 * SanitizeCss rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\SanitizeCss;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SanitizeCss::class )]
class SanitizeCssTest extends MarkupHarnessTestCase {

	public function test_fixes_run_together_grid_tracks(): void {
		$rule = new SanitizeCss();
		$out  = $rule->apply( $this->form_grid(), $this->context() );
		$this->assertStringContainsString( 'grid-template-columns:1fr 1fr', $out );
		$this->assertStringContainsString( 'grid-template-columns:1fr;', $out );
	}

	public function test_strips_bare_unit_declarations(): void {
		$rule = new SanitizeCss();
		$out  = $rule->apply( '<div style="padding-top:px;color:red">x</div>', $this->context() );
		$this->assertStringNotContainsString( 'padding-top:px', $out );
		$this->assertStringContainsString( 'color:red', $out );
	}

	public function test_is_idempotent(): void {
		$rule = new SanitizeCss();
		$once = $rule->apply( $this->form_grid(), $this->context() );
		$this->assertSame( $once, $rule->apply( $once, $this->context() ) );
	}
}
