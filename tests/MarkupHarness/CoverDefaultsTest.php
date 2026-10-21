<?php
/**
 * CoverDefaults rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\CoverDefaults;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CoverDefaults::class )]
class CoverDefaultsTest extends MarkupHarnessTestCase {

	protected function setUp(): void {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			$this->markTestSkipped( 'parse_blocks/serialize_blocks unavailable (no WordPress install found).' );
		}
	}

	public function test_fills_missing_defaults_in_attrs_and_div(): void {
		$out = ( new CoverDefaults() )->apply( $this->cover_missing_defaults(), $this->context() );

		// Block attributes.
		$this->assertStringContainsString( '"minHeight":520', $out );
		$this->assertStringContainsString( '"minHeightUnit":"px"', $out );
		$this->assertStringContainsString( '"dimRatio":60', $out );
		// Dark fallback (test palette's darkest slug is "contrast").
		$this->assertStringContainsString( '"backgroundColor":"contrast"', $out );

		// Rendered div.
		$this->assertStringContainsString( 'min-height:520px', $out );
		$this->assertStringContainsString( 'has-background-dim', $out );
		$this->assertStringContainsString( 'has-contrast-background-color', $out );
	}

	public function test_leaves_complete_cover_untouched(): void {
		$markup = '<!-- wp:cover {"url":"https://x/p.jpg","minHeight":520,"minHeightUnit":"px","dimRatio":50,"backgroundColor":"contrast"} -->' . "\n"
			. '<div class="wp-block-cover has-background-dim-50 has-background-dim has-contrast-background-color has-background" style="min-height:520px">' . "\n"
			. '<img class="wp-block-cover__image-background" alt="" src="https://x/p.jpg" data-object-fit="cover"/>' . "\n"
			. '<div class="wp-block-cover__inner-container"><p>Hi</p></div>' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:cover -->';

		$this->assertSame( $markup, ( new CoverDefaults() )->apply( $markup, $this->context() ) );
	}

	public function test_is_idempotent(): void {
		$rule = new CoverDefaults();
		$once = $rule->apply( $this->cover_missing_defaults(), $this->context() );
		$this->assertSame( $once, $rule->apply( $once, $this->context() ) );
	}

	public function test_validator_flags_then_passes(): void {
		$ctx       = $this->context();
		$validator = new Validator();

		$this->assertContains( 'cover_missing_defaults', $validator->validate( $this->cover_missing_defaults(), $ctx ) );

		$out = ( new CoverDefaults() )->apply( $this->cover_missing_defaults(), $ctx );
		$this->assertSame( array(), $validator->validate( $out, $ctx ) );
	}
}
