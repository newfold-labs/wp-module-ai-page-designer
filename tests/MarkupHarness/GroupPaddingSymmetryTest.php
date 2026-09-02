<?php
/**
 * GroupPaddingSymmetry rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\GroupPaddingSymmetry;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( GroupPaddingSymmetry::class )]
class GroupPaddingSymmetryTest extends MarkupHarnessTestCase {

	public function test_fills_missing_horizontal_padding_in_comment_and_div(): void {
		$rule = new GroupPaddingSymmetry();
		$out  = $rule->apply( $this->cta_section(), $this->context() );

		$this->assertStringContainsString( '"left":"32px"', $out, 'comment gains left padding' );
		$this->assertStringContainsString( '"right":"32px"', $out, 'comment gains right padding' );
		$this->assertStringContainsString( 'padding-left:32px', $out, 'div gains padding-left' );
		$this->assertStringContainsString( 'padding-right:32px', $out, 'div gains padding-right' );
	}

	public function test_leaves_symmetric_card_untouched(): void {
		$rule = new GroupPaddingSymmetry();
		$card = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"24px","right":"24px","bottom":"24px","left":"24px"}}}} -->' . "\n"
			. '<div class="wp-block-group" style="padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px"><p>x</p></div>';
		$this->assertSame( $card, $rule->apply( $card, $this->context() ) );
	}

	public function test_is_idempotent(): void {
		$rule = new GroupPaddingSymmetry();
		$once = $rule->apply( $this->cta_section(), $this->context() );
		$this->assertSame( $once, $rule->apply( $once, $this->context() ) );
	}
}
