<?php
/**
 * SectionGroupPattern rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\SectionGroupPattern;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SectionGroupPattern::class )]
class SectionGroupPatternTest extends MarkupHarnessTestCase {

	protected function setUp(): void {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			$this->markTestSkipped( 'parse_blocks/serialize_blocks unavailable (no WordPress install found).' );
		}
	}

	public function test_adds_wide_align_and_padding_to_bare_section(): void {
		$markup = '<!-- wp:group -->' . "\n"
			. '<div class="wp-block-group"><p>Hi</p></div>' . "\n"
			. '<!-- /wp:group -->';

		$out = ( new SectionGroupPattern() )->apply( $markup, $this->context() );

		$this->assertStringContainsString( '"align":"wide"', $out, 'comment gains wide align' );
		$this->assertStringContainsString( '"left":"32px"', $out, 'comment gains left padding' );
		$this->assertStringContainsString( '"right":"32px"', $out, 'comment gains right padding' );
		$this->assertStringContainsString( 'wp-block-group alignwide', $out, 'div gains alignwide class' );
		$this->assertStringContainsString( 'padding-left:32px', $out, 'div gains padding-left' );
		$this->assertStringContainsString( 'padding-right:32px', $out, 'div gains padding-right' );
	}

	public function test_leaves_conforming_section_untouched(): void {
		$markup = '<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"left":"32px","right":"32px"}}}} -->' . "\n"
			. '<div class="wp-block-group alignwide" style="padding-left:32px;padding-right:32px"><p>Hi</p></div>' . "\n"
			. '<!-- /wp:group -->';

		$this->assertSame( $markup, ( new SectionGroupPattern() )->apply( $markup, $this->context() ) );
	}

	public function test_preserves_full_alignment_and_adds_padding(): void {
		$markup = '<!-- wp:group {"align":"full"} -->' . "\n"
			. '<div class="wp-block-group alignfull"><p>Hi</p></div>' . "\n"
			. '<!-- /wp:group -->';

		$out = ( new SectionGroupPattern() )->apply( $markup, $this->context() );

		$this->assertStringContainsString( '"align":"full"', $out, 'full alignment preserved' );
		$this->assertStringNotContainsString( 'alignwide', $out, 'wide not forced over full' );
		$this->assertStringContainsString( 'padding-left:32px', $out, 'padding still added' );
	}

	public function test_is_idempotent(): void {
		$markup = '<!-- wp:group -->' . "\n"
			. '<div class="wp-block-group"><p>Hi</p></div>' . "\n"
			. '<!-- /wp:group -->';

		$rule = new SectionGroupPattern();
		$once = $rule->apply( $markup, $this->context() );
		$this->assertSame( $once, $rule->apply( $once, $this->context() ) );
	}
}
