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

	public function test_stat_cards_variant_renders_lifted_cards(): void {
		$stats = new StatsBar();
		$ctx   = $this->context();
		$out   = $stats->render( $this->content(), 'stat-cards', $ctx, $stats->default_background( $ctx ) );

		$this->assertSame( 3, substr_count( $out, 'class="card-hover-lift' ) );
		$this->assertStringContainsString( '10k', $out );
	}

	public function test_accent_band_variant_stays_flat(): void {
		$stats = new StatsBar();
		$ctx   = $this->context();
		$out   = $stats->render( $this->content(), 'accent-band', $ctx, $stats->default_background( $ctx ) );

		$this->assertStringNotContainsString( 'class="card-hover-lift', $out );
	}

	public function test_stat_cards_variant_is_correct_by_construction(): void {
		$stats = new StatsBar();
		$ctx   = $this->context();
		$out   = $stats->render( $this->content(), 'stat-cards', $ctx, $stats->default_background( $ctx ) );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_stat_cards_never_share_the_accent_band_background(): void {
		$stats = new StatsBar();
		$ctx   = $this->context();
		$bg    = $stats->default_background( $ctx );
		$out   = $stats->render( $this->content(), 'stat-cards', $ctx, $bg );

		$this->assertNotNull( $bg );
		$this->assertStringNotContainsString( 'wp-block-group has-' . $bg . '-background-color', $out );
	}

	public function test_panel_variant_renders_one_shared_card(): void {
		$stats = new StatsBar();
		$ctx   = $this->context();
		$out   = $stats->render( $this->content(), 'panel', $ctx, $stats->default_background( $ctx ) );

		$this->assertSame( 1, substr_count( $out, 'class="card-hover-lift' ) );
		$this->assertSame( 3, substr_count( $out, '<!-- wp:column ' ) );
		$this->assertStringContainsString( '10k', $out );
	}

	public function test_panel_variant_is_correct_by_construction(): void {
		$stats = new StatsBar();
		$ctx   = $this->context();
		$out   = $stats->render( $this->content(), 'panel', $ctx, $stats->default_background( $ctx ) );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_unspecified_variant_resolves_into_the_pickable_pool(): void {
		$stats = new StatsBar();
		$ctx   = $this->context();
		$bg    = $stats->default_background( $ctx );
		$out   = $stats->render( $this->content(), null, $ctx, $bg );

		$this->assertSame( $out, $stats->render( $this->content(), null, $ctx, $bg ) );

		$explicit = array();
		foreach ( StatsBar::VARIANTS as $variant ) {
			$explicit[] = $stats->render( $this->content(), $variant, $ctx, $bg );
		}
		$this->assertContains( $out, $explicit );
	}
}
