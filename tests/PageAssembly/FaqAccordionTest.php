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

	public function test_cards_variant_renders_rounded_cards(): void {
		$faq = new FaqAccordion();
		$ctx = $this->context();
		$out = $faq->render( $this->content(), 'cards', $ctx, null );

		$this->assertSame( 2, substr_count( $out, 'class="nfd-faq-card' ) );
		$this->assertStringContainsString( '"backgroundColor":"' . $ctx->muted_light_slug() . '"', $out );
		$this->assertStringContainsString( 'What is this?', $out );
	}

	public function test_stacked_variant_stays_flat(): void {
		$faq = new FaqAccordion();
		$out = $faq->render( $this->content(), 'stacked', $this->context(), null );

		$this->assertStringNotContainsString( 'border-radius:12px', $out );
		$this->assertSame( 2, substr_count( $out, '<summary>' ) );
	}

	public function test_cards_variant_is_correct_by_construction_with_and_without_background(): void {
		$faq = new FaqAccordion();
		$ctx = $this->context();
		$v   = new Validator();

		$this->assertSame( array(), $v->validate( $faq->render( $this->content(), 'cards', $ctx, null ), $ctx ) );
		$this->assertSame( array(), $v->validate( $faq->render( $this->content(), 'cards', $ctx, $ctx->muted_light_slug() ), $ctx ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function long_content(): array {
		$content = $this->content();
		$content['items'][] = array(
			'q' => 'Do you offer refunds?',
			'a' => 'Within 30 days.',
		);
		$content['items'][] = array(
			'q' => 'Where are you based?',
			'a' => 'Everywhere.',
		);
		return $content;
	}

	public function test_two_column_variant_splits_items_across_two_columns(): void {
		$faq = new FaqAccordion();
		$out = $faq->render( $this->long_content(), 'two-column', $this->context(), null );

		$this->assertSame( 2, substr_count( $out, '<!-- wp:column ' ) );
		$this->assertSame( 4, substr_count( $out, '<!-- wp:details' ) );
		$this->assertStringNotContainsString( '"width"', $out );
	}

	public function test_two_column_variant_falls_back_to_the_stack_below_four_items(): void {
		$faq = new FaqAccordion();
		$ctx = $this->context();

		$this->assertSame(
			$faq->render( $this->content(), 'cards', $ctx, null ),
			$faq->render( $this->content(), 'two-column', $ctx, null )
		);
	}

	public function test_two_column_variant_is_correct_by_construction_with_and_without_background(): void {
		$faq = new FaqAccordion();
		$ctx = $this->context();
		$v   = new Validator();

		$this->assertSame( array(), $v->validate( $faq->render( $this->long_content(), 'two-column', $ctx, null ), $ctx ) );
		$this->assertSame( array(), $v->validate( $faq->render( $this->long_content(), 'two-column', $ctx, $ctx->muted_light_slug() ), $ctx ) );
	}
}
