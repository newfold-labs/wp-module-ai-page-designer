<?php
/**
 * ProcessSteps archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\ProcessSteps;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ProcessSteps::class )]
class ProcessStepsTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'heading' => 'How it works',
			'intro'   => 'Three simple steps.',
			'steps'   => array(
				array(
					'title' => 'Pick your beans',
					'body'  => 'Choose from our seasonal roasts.',
				),
				array(
					'title' => 'We brew',
					'body'  => 'Fresh, to order, every time.',
				),
				array(
					'title' => 'You enjoy',
					'body'  => 'In-house or to go.',
				),
			),
		);
	}

	public function test_renders_expected_slots(): void {
		$steps = new ProcessSteps();
		$ctx   = $this->context();
		$out   = $steps->render( $this->content(), 'numbered', $ctx, null );

		$this->assertStringContainsString( 'How it works', $out );
		$this->assertStringContainsString( 'Three simple steps.', $out );
		$this->assertStringContainsString( 'Pick your beans', $out );
		$this->assertStringContainsString( 'We brew', $out );
		$this->assertStringContainsString( 'You enjoy', $out );
	}

	public function test_renders_sequential_number_badges(): void {
		$steps = new ProcessSteps();
		$out   = $steps->render( $this->content(), 'numbered', $this->context(), null );

		// Circular badges numbered 1..3.
		$this->assertSame( 3, substr_count( $out, 'class="nfd-step-badge' ) );
		$this->assertStringContainsString( '>1</p>', $out );
		$this->assertStringContainsString( '>2</p>', $out );
		$this->assertStringContainsString( '>3</p>', $out );
	}

	public function test_badge_is_a_real_paragraph_block_with_color_attrs(): void {
		$steps = new ProcessSteps();
		$ctx   = $this->context();
		$out   = $steps->render( $this->content(), 'numbered', $ctx, null );

		// The badge carries block-level color attrs (selectable + recolorable),
		// contrasting against the section (accent on a surface section).
		$this->assertStringContainsString( '"backgroundColor":"' . $ctx->accent_slug() . '"', $out );
	}

	public function test_is_correct_by_construction(): void {
		$steps = new ProcessSteps();
		$ctx   = $this->context();
		$out   = $steps->render( $this->content(), 'numbered', $ctx, null );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_is_deterministic(): void {
		$steps = new ProcessSteps();
		$ctx   = $this->context();
		$once  = $steps->render( $this->content(), 'numbered', $ctx, null );

		$this->assertSame( $once, $steps->render( $this->content(), 'numbered', $ctx, null ) );
	}

	public function test_vertical_variant_stacks_constrained_rows_instead_of_columns(): void {
		$steps = new ProcessSteps();
		$out   = $steps->render( $this->content(), 'vertical', $this->context(), null );

		$this->assertStringNotContainsString( '<!-- wp:columns', $out );
		$this->assertSame( 3, substr_count( $out, 'class="nfd-max-w-720' ) );
		// Badges stay sequential real paragraph blocks.
		$this->assertStringContainsString( '>1</p>', $out );
		$this->assertStringContainsString( '>3</p>', $out );
	}

	public function test_vertical_variant_is_correct_by_construction_with_and_without_background(): void {
		$steps = new ProcessSteps();
		$ctx   = $this->context();
		$v     = new Validator();

		$this->assertSame( array(), $v->validate( $steps->render( $this->content(), 'vertical', $ctx, null ), $ctx ) );
		$this->assertSame( array(), $v->validate( $steps->render( $this->content(), 'vertical', $ctx, $ctx->muted_light_slug() ), $ctx ) );
	}

	public function test_unspecified_variant_resolves_into_the_pickable_pool(): void {
		$steps = new ProcessSteps();
		$ctx   = $this->context();
		$out   = $steps->render( $this->content(), null, $ctx, null );

		$this->assertSame( $out, $steps->render( $this->content(), null, $ctx, null ) );

		$explicit = array();
		foreach ( ProcessSteps::VARIANTS as $variant ) {
			$explicit[] = $steps->render( $this->content(), $variant, $ctx, null );
		}
		$this->assertContains( $out, $explicit );
	}
}
