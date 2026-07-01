<?php
/**
 * PricingTiers archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\PricingTiers;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PricingTiers::class )]
class PricingTiersTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'heading' => 'Pricing',
			'tiers'   => array(
				array(
					'name'     => 'Basic',
					'price'    => '$9',
					'period'   => 'mo',
					'features' => array( 'Feature A', 'Feature B' ),
					'cta'      => array(
						'label' => 'Choose Basic',
						'url'   => '#basic',
					),
				),
				array(
					'name'        => 'Pro',
					'price'       => '$19',
					'period'      => 'mo',
					'features'    => array( 'Feature A', 'Feature B', 'Feature C' ),
					'cta'         => array(
						'label' => 'Choose Pro',
						'url'   => '#pro',
					),
					'highlighted' => true,
				),
			),
		);
	}

	public function test_renders_expected_slots(): void {
		$pricing = new PricingTiers();
		$out     = $pricing->render( $this->content(), '3-tier', $this->context(), null );

		$this->assertStringContainsString( 'Pricing', $out );
		$this->assertStringContainsString( 'Basic', $out );
		$this->assertStringContainsString( '$9', $out );
		$this->assertStringContainsString( 'Feature A', $out );
		$this->assertStringContainsString( 'Choose Basic', $out );
		$this->assertStringContainsString( 'Pro', $out );
		$this->assertSame( 2, substr_count( $out, '<!-- wp:column ' ) );
	}

	public function test_highlighted_tier_is_wrapped_in_a_card(): void {
		$pricing = new PricingTiers();
		$out     = $pricing->render( $this->content(), '3-tier', $this->context(), null );

		// The highlighted tier's card is a nested group inside its column.
		$this->assertGreaterThanOrEqual( 2, substr_count( $out, '<!-- wp:group ' ) );
	}

	public function test_columns_never_declare_a_width(): void {
		$pricing = new PricingTiers();
		$out     = $pricing->render( $this->content(), '3-tier', $this->context(), null );
		$this->assertStringNotContainsString( 'width', $out );
	}

	public function test_is_correct_by_construction_plain_and_on_background(): void {
		$pricing = new PricingTiers();
		$ctx     = $this->context();
		$v       = new Validator();
		$this->assertSame( array(), $v->validate( $pricing->render( $this->content(), '3-tier', $ctx, null ), $ctx ) );
		$this->assertSame( array(), $v->validate( $pricing->render( $this->content(), '3-tier', $ctx, $ctx->muted_light_slug() ), $ctx ) );
	}

	public function test_default_background_is_null_surface(): void {
		$pricing = new PricingTiers();
		$this->assertNull( $pricing->default_background( $this->context() ) );
	}

	public function test_is_deterministic(): void {
		$pricing = new PricingTiers();
		$ctx     = $this->context();
		$once    = $pricing->render( $this->content(), '3-tier', $ctx, null );
		$this->assertSame( $once, $pricing->render( $this->content(), '3-tier', $ctx, null ) );
	}
}
