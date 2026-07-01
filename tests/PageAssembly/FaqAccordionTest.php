<?php
/**
 * FaqAccordion archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\FaqAccordion;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( FaqAccordion::class )]
class FaqAccordionTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'heading' => 'FAQ',
			'items'   => array(
				array(
					'q' => 'What is this?',
					'a' => 'A test fixture.',
				),
				array(
					'q' => 'How much does it cost?',
					'a' => 'Nothing.',
				),
			),
		);
	}

	public function test_renders_expected_slots(): void {
		$faq = new FaqAccordion();
		$out = $faq->render( $this->content(), 'stacked', $this->context(), null );

		$this->assertStringContainsString( 'FAQ', $out );
		$this->assertStringContainsString( 'What is this?', $out );
		$this->assertStringContainsString( 'A test fixture.', $out );
		$this->assertSame( 2, substr_count( $out, '<!-- wp:details ' ) );
		$this->assertSame( 2, substr_count( $out, '<summary>' ) );
	}

	public function test_is_correct_by_construction(): void {
		$faq = new FaqAccordion();
		$ctx = $this->context();
		$out = $faq->render( $this->content(), 'stacked', $ctx, null );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_default_background_is_null_surface(): void {
		$faq = new FaqAccordion();
		$this->assertNull( $faq->default_background( $this->context() ) );
	}

	public function test_is_deterministic(): void {
		$faq  = new FaqAccordion();
		$ctx  = $this->context();
		$once = $faq->render( $this->content(), 'stacked', $ctx, null );
		$this->assertSame( $once, $faq->render( $this->content(), 'stacked', $ctx, null ) );
	}
}
