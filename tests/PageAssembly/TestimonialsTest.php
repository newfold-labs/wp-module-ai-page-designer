<?php
/**
 * Testimonials archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\Testimonials;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Testimonials::class )]
class TestimonialsTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'heading' => 'What people say',
			'quotes'  => array(
				array(
					'quote'     => 'This changed everything for us.',
					'author'    => 'Alice',
					'role'      => 'CEO, Acme',
					'avatarUrl' => 'https://images.unsplash.com/photo-a',
				),
				array(
					'quote'  => 'Loved working with this team.',
					'author' => 'Bob',
				),
			),
		);
	}

	public function test_renders_expected_slots(): void {
		$testimonials = new Testimonials();
		$out          = $testimonials->render( $this->content(), 'grid-3', $this->context(), null );

		$this->assertStringContainsString( 'What people say', $out );
		$this->assertStringContainsString( 'This changed everything for us.', $out );
		$this->assertStringContainsString( 'Alice, CEO, Acme', $out );
		$this->assertStringContainsString( 'Bob', $out );
		$this->assertStringContainsString( 'https://images.unsplash.com/photo-a', $out );
		$this->assertSame( 2, substr_count( $out, '<!-- wp:quote ' ) );
	}

	public function test_omits_citation_details_cleanly_when_absent(): void {
		$testimonials = new Testimonials();
		$out          = $testimonials->render(
			array( 'quotes' => array( array( 'quote' => 'Nice.', 'author' => 'Cara' ) ) ),
			'grid-3',
			$this->context(),
			null
		);
		$this->assertStringNotContainsString( 'Cara, ', $out );
		$this->assertStringContainsString( 'Cara', $out );
	}

	public function test_is_correct_by_construction(): void {
		$testimonials = new Testimonials();
		$ctx          = $this->context();
		$out          = $testimonials->render( $this->content(), 'grid-3', $ctx, $ctx->muted_light_slug() );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_default_background_is_null_surface(): void {
		$testimonials = new Testimonials();
		$this->assertNull( $testimonials->default_background( $this->context() ) );
	}

	public function test_is_deterministic(): void {
		$testimonials = new Testimonials();
		$ctx          = $this->context();
		$once         = $testimonials->render( $this->content(), 'grid-3', $ctx, null );
		$this->assertSame( $once, $testimonials->render( $this->content(), 'grid-3', $ctx, null ) );
	}

	public function test_cards_is_the_default_variant(): void {
		$testimonials = new Testimonials();
		$out          = $testimonials->render( $this->content(), null, $this->context(), null );

		$this->assertSame( 2, substr_count( $out, 'border-radius:16px' ) );
		$this->assertStringContainsString( 'This changed everything for us.', $out );
	}

	public function test_grid_3_variant_stays_flat(): void {
		$testimonials = new Testimonials();
		$out          = $testimonials->render( $this->content(), 'grid-3', $this->context(), null );

		$this->assertStringNotContainsString( 'border-radius:16px', $out );
	}

	public function test_cards_variant_is_correct_by_construction(): void {
		$testimonials = new Testimonials();
		$ctx          = $this->context();
		$v            = new Validator();

		$this->assertSame( array(), $v->validate( $testimonials->render( $this->content(), null, $ctx, null ), $ctx ) );
		$this->assertSame( array(), $v->validate( $testimonials->render( $this->content(), null, $ctx, $ctx->muted_light_slug() ), $ctx ) );
	}
}
