<?php
/**
 * ColumnWidthNormalize rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\ColumnWidthNormalize;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ColumnWidthNormalize::class )]
class ColumnWidthNormalizeTest extends MarkupHarnessTestCase {

	protected function setUp(): void {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			$this->markTestSkipped( 'parse_blocks/serialize_blocks unavailable (no WordPress install found).' );
		}
	}

	public function test_redistributes_four_fifty_columns_evenly(): void {
		$out = ( new ColumnWidthNormalize() )->apply( $this->four_fifty_columns(), $this->context() );

		$this->assertStringContainsString( '"width":"25%"', $out, 'comment width redistributed to 25%' );
		$this->assertStringContainsString( 'flex-basis:25%', $out, 'rendered flex-basis redistributed' );
		$this->assertStringNotContainsString( '"width":"50%"', $out, 'overflowing width is gone' );
		$this->assertStringNotContainsString( 'flex-basis:50%', $out, 'overflowing flex-basis is gone' );
		$this->assertSame( 4, substr_count( $out, '"width":"25%"' ), 'all four columns updated' );
	}

	public function test_leaves_valid_split_untouched(): void {
		$markup = '<!-- wp:columns -->' . "\n"
			. '<div class="wp-block-columns">' . "\n"
			. '<!-- wp:column {"width":"66.66%"} -->' . "\n"
			. '<div class="wp-block-column" style="flex-basis:66.66%"><p>a</p></div>' . "\n"
			. '<!-- /wp:column -->' . "\n"
			. '<!-- wp:column {"width":"33.33%"} -->' . "\n"
			. '<div class="wp-block-column" style="flex-basis:33.33%"><p>b</p></div>' . "\n"
			. '<!-- /wp:column -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:columns -->';

		$this->assertSame( $markup, ( new ColumnWidthNormalize() )->apply( $markup, $this->context() ) );
	}

	public function test_leaves_auto_distributed_columns_untouched(): void {
		$markup = '<!-- wp:columns -->' . "\n"
			. '<div class="wp-block-columns">' . "\n"
			. '<!-- wp:column -->' . "\n"
			. '<div class="wp-block-column"><p>a</p></div>' . "\n"
			. '<!-- /wp:column -->' . "\n"
			. '<!-- wp:column -->' . "\n"
			. '<div class="wp-block-column"><p>b</p></div>' . "\n"
			. '<!-- /wp:column -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:columns -->';

		$this->assertSame( $markup, ( new ColumnWidthNormalize() )->apply( $markup, $this->context() ) );
	}

	public function test_skips_non_percentage_widths(): void {
		$markup = '<!-- wp:columns -->' . "\n"
			. '<div class="wp-block-columns">' . "\n"
			. '<!-- wp:column {"width":"300px"} -->' . "\n"
			. '<div class="wp-block-column" style="flex-basis:300px"><p>a</p></div>' . "\n"
			. '<!-- /wp:column -->' . "\n"
			. '<!-- wp:column {"width":"50%"} -->' . "\n"
			. '<div class="wp-block-column" style="flex-basis:50%"><p>b</p></div>' . "\n"
			. '<!-- /wp:column -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:columns -->';

		$this->assertSame( $markup, ( new ColumnWidthNormalize() )->apply( $markup, $this->context() ) );
	}

	public function test_is_idempotent(): void {
		$rule = new ColumnWidthNormalize();
		$once = $rule->apply( $this->four_fifty_columns(), $this->context() );
		$this->assertSame( $once, $rule->apply( $once, $this->context() ) );
	}

	public function test_validator_flags_then_passes(): void {
		$ctx       = $this->context();
		$validator = new Validator();

		$this->assertContains( 'invalid_column_widths', $validator->validate( $this->four_fifty_columns(), $ctx ) );

		$out = ( new ColumnWidthNormalize() )->apply( $this->four_fifty_columns(), $ctx );
		$this->assertSame( array(), $validator->validate( $out, $ctx ) );
	}
}
