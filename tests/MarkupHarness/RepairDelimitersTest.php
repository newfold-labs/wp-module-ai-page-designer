<?php
/**
 * RepairDelimiters rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\RepairDelimiters;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RepairDelimiters::class )]
class RepairDelimitersTest extends MarkupHarnessTestCase {

	/**
	 * A columns block where one column's opening delimiter lost its `!`.
	 *
	 * @return string
	 */
	private function columns_with_malformed_delimiter(): string {
		return '<!-- wp:columns -->' . "\n"
			. '<div class="wp-block-columns">' . "\n"
			. '<!-- wp:column -->' . "\n"
			. '<div class="wp-block-column"><p>A</p></div>' . "\n"
			. '<!-- /wp:column -->' . "\n"
			. '<-- wp:column -->' . "\n"
			. '<div class="wp-block-column"><p>B</p></div>' . "\n"
			. '<!-- /wp:column -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:columns -->';
	}

	public function test_repairs_malformed_opening_delimiter(): void {
		$out = ( new RepairDelimiters() )->apply( $this->columns_with_malformed_delimiter(), $this->context() );

		$this->assertStringNotContainsString( '<-- wp:column', $out, 'malformed delimiter repaired' );
		$this->assertSame( 2, substr_count( $out, '<!-- wp:column -->' ), 'both column openings now valid' );
	}

	public function test_repairs_malformed_closing_delimiter(): void {
		$markup = '<!-- wp:group -->' . "\n"
			. '<div class="wp-block-group"><p>Hi</p></div>' . "\n"
			. '<-- /wp:group -->';

		$out = ( new RepairDelimiters() )->apply( $markup, $this->context() );

		$this->assertStringContainsString( '<!-- /wp:group -->', $out );
		$this->assertStringNotContainsString( '<-- /wp:group -->', $out );
	}

	public function test_leaves_valid_delimiters_untouched(): void {
		$markup = '<!-- wp:paragraph -->' . "\n" . '<p>Hi</p>' . "\n" . '<!-- /wp:paragraph -->';
		$this->assertSame( $markup, ( new RepairDelimiters() )->apply( $markup, $this->context() ) );
	}

	public function test_does_not_touch_non_block_arrows(): void {
		// A literal arrow-ish comment that is not a block delimiter must be left alone.
		$markup = '<!-- wp:paragraph -->' . "\n" . '<p>Use a &lt;-- arrow --&gt; in copy</p>' . "\n" . '<!-- /wp:paragraph -->';
		$this->assertSame( $markup, ( new RepairDelimiters() )->apply( $markup, $this->context() ) );
	}

	public function test_is_idempotent(): void {
		$rule = new RepairDelimiters();
		$once = $rule->apply( $this->columns_with_malformed_delimiter(), $this->context() );
		$this->assertSame( $once, $rule->apply( $once, $this->context() ) );
	}

	public function test_validator_flags_then_passes(): void {
		$ctx       = $this->context();
		$validator = new Validator();

		$this->assertContains( 'malformed_delimiter', $validator->validate( $this->columns_with_malformed_delimiter(), $ctx ) );

		$out = ( new RepairDelimiters() )->apply( $this->columns_with_malformed_delimiter(), $ctx );
		$this->assertNotContains( 'malformed_delimiter', $validator->validate( $out, $ctx ) );
	}
}
