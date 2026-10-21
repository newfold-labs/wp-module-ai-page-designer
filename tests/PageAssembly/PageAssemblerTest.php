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

		$this->assertStringContainsString( '<!-- wp:columns', $out );
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

		// Assert on the TOP-LEVEL section groups' own opening attrs only —
		// featureGrid's default floating-card variant nests further wp:group
		// cards (which legitimately carry their own backgroundColor), so
		// counting/inspecting every group would misfire.
		preg_match_all( '/<!-- wp:group \{"className":"nfd-scroll-fade","align":"wide".*? -->/', $out, $matches );
		$section_opens = $matches[0];
		$this->assertCount( 2, $section_opens );
		$this->assertStringNotContainsString( '"backgroundColor"', $section_opens[0] );
		$this->assertStringContainsString( '"backgroundColor":"' . $ctx->muted_light_slug() . '"', $section_opens[1] );
	}

	public function test_full_13_archetype_page_is_correct_by_construction(): void {
		$plan = array(
			array(
				'archetype' => 'heroCover',
				'content'   => array(
					'heading'    => 'H',
					'primaryCta' => array(
						'label' => 'Go',
						'url'   => '#',
					),
					'imageQuery' => 'coffee',
				),
			),
			array(
				'archetype' => 'featureGrid',
				'content'   => array(
					'items' => array(
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
				'archetype' => 'alternatingMediaText',
				'content'   => array(
					'rows' => array(
						array(
							'heading'    => 'r',
							'body'       => 'b',
							'imageQuery' => 'beans',
						),
					),
				),
			),
			array(
				'archetype' => 'galleryGrid',
				'content'   => array(
					'images' => array(
						array( 'imageQuery' => 'latte art' ),
						array( 'imageQuery' => 'cafe interior' ),
						array( 'imageQuery' => 'espresso' ),
					),
				),
			),
			array(
				'archetype' => 'teamGrid',
				'content'   => array(
					'members' => array(
						array(
							'name'        => 'Ana',
							'role'        => 'Roaster',
							'avatarQuery' => 'barista headshot',
						),
						array(
							'name' => 'Ben',
							'role' => 'Lead',
						),
					),
				),
			),
			array(
				'archetype' => 'processSteps',
				'content'   => array(
					'steps' => array(
						array(
							'title' => 'One',
							'body'  => 'x',
						),
						array(
							'title' => 'Two',
							'body'  => 'y',
						),
						array(
							'title' => 'Three',
							'body'  => 'z',
						),
					),
				),
			),
			array(
				'archetype' => 'testimonials',
				'content'   => array(
					'quotes' => array(
						array(
							'quote'  => 'q',
							'author' => 'a',
						),
					),
				),
			),
			array(
				'archetype' => 'pricingTiers',
				'content'   => array(
					'tiers' => array(
						array(
							'name'     => 't',
							'price'    => '$1',
							'features' => array( 'f' ),
							'cta'      => array(
								'label' => 'Buy',
								'url'   => '#',
							),
						),
					),
				),
			),
			array(
				'archetype' => 'statsBar',
				'content'   => array(
					'items' => array(
						array(
							'value' => '1',
							'label' => 'l',
						),
					),
				),
			),
			array(
				'archetype' => 'faqAccordion',
				'content'   => array(
					'items' => array(
						array(
							'q' => 'q',
							'a' => 'a',
						),
					),
				),
			),
			array(
				'archetype' => 'bookingForm',
				'content'   => array(
					'fields' => array(
						array(
							'type'  => 'email',
							'name'  => 'email',
							'label' => 'Email',
						),
					),
				),
			),
			array(
				'archetype' => 'richText',
				'content'   => array( 'body' => 'prose' ),
			),
			array(
				'archetype' => 'ctaBanner',
				'content'   => array(
					'heading' => 'Go',
					'cta'     => array(
						'label' => 'Now',
						'url'   => '#',
					),
				),
			),
		);

		$ctx       = $this->context();
		$assembler = new PageAssembler( $this->fake_image_service() );
		$out       = $assembler->assemble( $plan, $ctx );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
		// Idempotent under a second conform pass (WYSIWYG-critical).
		$this->assertSame( $out, ( new \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Harness() )->conform( $out ) );
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
