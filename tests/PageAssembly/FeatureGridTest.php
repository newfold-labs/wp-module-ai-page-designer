<?php
/**
 * FeatureGrid archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\FeatureGrid;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( FeatureGrid::class )]
class FeatureGridTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'heading' => 'Why choose us',
			'intro'   => 'We care about quality.',
			'items'   => array(
				array(
					'title' => 'Ethically sourced',
					'body'  => 'Direct trade with farmers.',
				),
				array(
					'title' => 'Locally roasted',
					'body'  => 'Small batch, every week.',
				),
				array(
					'title' => 'Community first',
					'body'  => 'A portion of profits reinvested.',
				),
			),
		);
	}

	public function test_renders_expected_slots(): void {
		$grid = new FeatureGrid();
		$ctx  = $this->context();
		$out  = $grid->render( $this->content(), 'cards-3', $ctx, null );

		$this->assertStringContainsString( '<!-- wp:columns', $out );
		$this->assertSame( 3, substr_count( $out, '<!-- wp:column ' ) );
		$this->assertStringContainsString( 'Why choose us', $out );
		$this->assertStringContainsString( 'We care about quality.', $out );
		$this->assertStringContainsString( 'Ethically sourced', $out );
		$this->assertStringContainsString( 'Locally roasted', $out );
		$this->assertStringContainsString( 'Community first', $out );
	}

	public function test_columns_never_declare_a_width(): void {
		$grid = new FeatureGrid();
		$out  = $grid->render( $this->content(), 'cards-3', $this->context(), null );

		// The one width state the Validator always accepts (all-missing / auto-distributed).
		$this->assertStringNotContainsString( 'width', $out );
	}

	public function test_is_correct_by_construction_with_and_without_background(): void {
		$grid = new FeatureGrid();
		$ctx  = $this->context();
		$v    = new Validator();

		$this->assertSame( array(), $v->validate( $grid->render( $this->content(), 'cards-3', $ctx, null ), $ctx ) );
		$this->assertSame( array(), $v->validate( $grid->render( $this->content(), 'cards-3', $ctx, $ctx->muted_light_slug() ), $ctx ) );
	}

	public function test_group_padding_is_symmetric(): void {
		$grid = new FeatureGrid();
		$out  = $grid->render( $this->content(), 'cards-3', $this->context(), null );

		foreach ( array( 'padding-top', 'padding-bottom', 'padding-left', 'padding-right' ) as $side ) {
			$this->assertStringContainsString( $side, $out );
		}
	}

	public function test_default_background_is_null_surface(): void {
		$grid = new FeatureGrid();
		$this->assertNull( $grid->default_background( $this->context() ) );
	}

	public function test_ignores_a_fourth_item(): void {
		$grid    = new FeatureGrid();
		$content = $this->content();
		$content['items'][] = array(
			'title' => 'Fourth',
			'body'  => 'Should not render.',
		);
		$out = $grid->render( $content, 'cards-3', $this->context(), null );

		$this->assertSame( 3, substr_count( $out, '<!-- wp:column ' ) );
		$this->assertStringNotContainsString( 'Fourth', $out );
	}

	public function test_is_deterministic(): void {
		$grid = new FeatureGrid();
		$ctx  = $this->context();
		$once = $grid->render( $this->content(), 'cards-3', $ctx, null );
		$this->assertSame( $once, $grid->render( $this->content(), 'cards-3', $ctx, null ) );
	}

	public function test_floating_cards_variant_renders_lifted_cards(): void {
		$grid = new FeatureGrid();
		$out  = $grid->render( $this->content(), 'floating-cards', $this->context(), null );

		$this->assertSame( 3, substr_count( $out, 'class="card-hover-lift' ) );
		$this->assertStringContainsString( 'Ethically sourced', $out );
	}

	public function test_cards_3_variant_stays_flat(): void {
		$grid = new FeatureGrid();
		$out  = $grid->render( $this->content(), 'cards-3', $this->context(), null );

		$this->assertStringNotContainsString( 'class="card-hover-lift', $out );
	}

	public function test_floating_cards_variant_is_correct_by_construction_with_and_without_background(): void {
		$grid = new FeatureGrid();
		$ctx  = $this->context();
		$v    = new Validator();

		$this->assertSame( array(), $v->validate( $grid->render( $this->content(), 'floating-cards', $ctx, null ), $ctx ) );
		$this->assertSame( array(), $v->validate( $grid->render( $this->content(), 'floating-cards', $ctx, $ctx->muted_light_slug() ), $ctx ) );
	}

	public function test_floating_cards_never_share_the_section_background(): void {
		$grid = new FeatureGrid();
		$ctx  = $this->context();
		$bg   = $ctx->muted_light_slug();
		$out  = $grid->render( $this->content(), 'floating-cards', $ctx, $bg );

		// On a muted-light section the cards fall back to the light slug.
		$this->assertStringNotContainsString( 'wp-block-group has-' . $bg . '-background-color', $out );
	}

	public function test_accent_bar_variant_renders_a_bar_per_item(): void {
		$grid = new FeatureGrid();
		$out  = $grid->render( $this->content(), 'accent-bar', $this->context(), null );

		$this->assertSame( 3, substr_count( $out, '<!-- wp:separator ' ) );
		$this->assertStringNotContainsString( 'class="card-hover-lift', $out );
		$this->assertStringContainsString( 'Ethically sourced', $out );
	}

	public function test_accent_bar_variant_is_correct_by_construction_with_and_without_background(): void {
		$grid = new FeatureGrid();
		$ctx  = $this->context();
		$v    = new Validator();

		$this->assertSame( array(), $v->validate( $grid->render( $this->content(), 'accent-bar', $ctx, null ), $ctx ) );
		$this->assertSame( array(), $v->validate( $grid->render( $this->content(), 'accent-bar', $ctx, $ctx->muted_light_slug() ), $ctx ) );
	}

	public function test_panel_variant_renders_one_shared_card(): void {
		$grid = new FeatureGrid();
		$out  = $grid->render( $this->content(), 'panel', $this->context(), null );

		$this->assertSame( 1, substr_count( $out, 'class="card-hover-lift' ) );
		$this->assertSame( 3, substr_count( $out, '<!-- wp:column ' ) );
	}

	public function test_panel_variant_is_correct_by_construction_with_and_without_background(): void {
		$grid = new FeatureGrid();
		$ctx  = $this->context();
		$v    = new Validator();

		$this->assertSame( array(), $v->validate( $grid->render( $this->content(), 'panel', $ctx, null ), $ctx ) );
		$this->assertSame( array(), $v->validate( $grid->render( $this->content(), 'panel', $ctx, $ctx->muted_light_slug() ), $ctx ) );
	}

	public function test_unspecified_variant_resolves_into_the_pickable_pool(): void {
		$grid = new FeatureGrid();
		$ctx  = $this->context();
		$out  = $grid->render( $this->content(), null, $ctx, null );

		$this->assertSame( $out, $grid->render( $this->content(), null, $ctx, null ) );

		$explicit = array();
		foreach ( FeatureGrid::VARIANTS as $variant ) {
			$explicit[] = $grid->render( $this->content(), $variant, $ctx, null );
		}
		$this->assertContains( $out, $explicit );
	}
}
