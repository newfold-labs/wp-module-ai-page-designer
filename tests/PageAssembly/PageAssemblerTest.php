<?php
/**
 * PageAssembler tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\PageAssembler;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PageAssembler::class )]
class PageAssemblerTest extends PageAssemblyTestCase {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function two_item_plan(): array {
		return array(
			array(
				'archetype' => 'heroCover',
				'content'   => array(
					'heading'    => 'Fresh coffee, faster mornings',
					'primaryCta' => array(
						'label' => 'Order now',
						'url'   => '#',
					),
					'imageQuery' => 'coffee shop interior',
				),
			),
			array(
				'archetype' => 'featureGrid',
				'content'   => array(
					'heading' => 'Why choose us',
					'items'   => array(
						array(
							'title' => 'A',
							'body'  => 'a',
						),
						array(
							'title' => 'B',
							'body'  => 'b',
						),
						array(
							'title' => 'C',
							'body'  => 'c',
						),
					),
				),
			),
		);
	}

	public function test_assembles_a_two_item_plan_into_a_valid_page(): void {
		$ctx       = $this->context();
		$assembler = new PageAssembler( $this->fake_image_service() );
		$out       = $assembler->assemble( $this->two_item_plan(), $ctx );

		$this->assertStringContainsString( '<!-- wp:cover', $out );
		$this->assertStringContainsString( '<!-- wp:group', $out );
		$this->assertStringContainsString( 'Fresh coffee, faster mornings', $out );
		$this->assertStringContainsString( 'Why choose us', $out );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_resolves_image_query_via_injected_image_service(): void {
		$assembler = new PageAssembler( $this->fake_image_service() );
		$out       = $assembler->assemble( $this->two_item_plan(), $this->context() );

		$this->assertStringContainsString( 'https://images.example.test/', $out );
	}

	public function test_background_rhythm_alternates_plain_surface_sections(): void {
		$plan = array(
			array(
				'archetype' => 'featureGrid',
				'content'   => array(
					'heading' => 'One',
					'items'   => array(
						array(
							'title' => 'a',
							'body'  => 'a',
						),
						array(
							'title' => 'b',
							'body'  => 'b',
						),
						array(
							'title' => 'c',
							'body'  => 'c',
						),
					),
				),
			),
			array(
				'archetype' => 'featureGrid',
				'content'   => array(
					'heading' => 'Two',
					'items'   => array(
						array(
							'title' => 'a',
							'body'  => 'a',
						),
						array(
							'title' => 'b',
							'body'  => 'b',
						),
						array(
							'title' => 'c',
							'body'  => 'c',
						),
					),
				),
			),
		);

		$ctx       = $this->context();
		$assembler = new PageAssembler( $this->fake_image_service() );
		$out       = $assembler->assemble( $plan, $ctx );

		$sections = explode( '<!-- wp:group', $out );
		// [0] is markup before the first group (empty); [1] and [2] are the two sections.
		$this->assertCount( 3, $sections );
		$this->assertStringNotContainsString( 'backgroundColor', $sections[1] );
		$this->assertStringContainsString( '"backgroundColor":"' . $ctx->muted_light_slug() . '"', $sections[2] );
	}

	public function test_unknown_archetype_is_skipped_not_fatal(): void {
		$plan = array_merge(
			$this->two_item_plan(),
			array(
				array(
					'archetype' => 'notARealArchetype',
					'content'   => array( 'heading' => 'should not appear' ),
				),
			)
		);

		$assembler = new PageAssembler( $this->fake_image_service() );
		$out       = $assembler->assemble( $plan, $this->context() );

		$this->assertStringNotContainsString( 'should not appear', $out );
		$this->assertNotSame( '', trim( $out ) );
	}

	public function test_explicit_background_override_wins(): void {
		$plan = array(
			array(
				'archetype'  => 'featureGrid',
				'background' => 'accent-4',
				'content'    => array(
					'heading' => 'Overridden',
					'items'   => array(
						array(
							'title' => 'a',
							'body'  => 'a',
						),
						array(
							'title' => 'b',
							'body'  => 'b',
						),
						array(
							'title' => 'c',
							'body'  => 'c',
						),
					),
				),
			),
		);

		$assembler = new PageAssembler( $this->fake_image_service() );
		$out       = $assembler->assemble( $plan, $this->context() );

		$this->assertStringContainsString( '"backgroundColor":"accent-4"', $out );
	}

	public function test_empty_plan_returns_empty_string(): void {
		$assembler = new PageAssembler( $this->fake_image_service() );
		$this->assertSame( '', $assembler->assemble( array(), $this->context() ) );
	}

	public function test_is_deterministic(): void {
		$assembler = new PageAssembler( $this->fake_image_service() );
		$ctx       = $this->context();
		$once      = $assembler->assemble( $this->two_item_plan(), $ctx );
		$this->assertSame( $once, $assembler->assemble( $this->two_item_plan(), $ctx ) );
	}
}
