<?php
/**
 * StatsBar archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\StatsBar;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( StatsBar::class )]
class StatsBarTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'items' => array(
				array(
					'value' => '10k',
					'label' => 'Customers',
				),
				array(
					'value' => '99%',
					'label' => 'Satisfaction',
				),
				array(
					'value' => '24/7',
					'label' => 'Support',
				),
			),
		);
	}

	public function test_renders_expected_slots(): void {
		$stats = new StatsBar();
		$ctx   = $this->context();
		$out   = $stats->render( $this->content(), 'accent-band', $ctx, $stats->default_background( $ctx ) );

		$this->assertSame( 3, substr_count( $out, '<!-- wp:column ' ) );
		$this->assertStringContainsString( '10k', $out );
		$this->assertStringContainsString( 'Customers', $out );
	}

	public function test_columns_never_declare_a_width(): void {
		$stats = new StatsBar();
		$ctx   = $this->context();
		$out   = $stats->render( $this->content(), 'accent-band', $ctx, $stats->default_background( $ctx ) );
		$this->assertStringNotContainsString( 'width', $out );
	}

	public function test_is_correct_by_construction(): void {
		$stats = new StatsBar();
		$ctx   = $this->context();
		$out   = $stats->render( $this->content(), 'accent-band', $ctx, $stats->default_background( $ctx ) );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_default_background_is_accent(): void {
		$stats = new StatsBar();
		$ctx   = $this->context();
		$this->assertSame( $ctx->accent_slug(), $stats->default_background( $ctx ) );
	}

	public function test_is_deterministic(): void {
		$stats = new StatsBar();
		$ctx   = $this->context();
		$bg    = $stats->default_background( $ctx );
		$once  = $stats->render( $this->content(), 'accent-band', $ctx, $bg );
		$this->assertSame( $once, $stats->render( $this->content(), 'accent-band', $ctx, $bg ) );
	}
}
