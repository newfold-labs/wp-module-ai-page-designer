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
}
