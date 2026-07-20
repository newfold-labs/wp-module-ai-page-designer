<?php
/**
 * CtaBanner archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\CtaBanner;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CtaBanner::class )]
class CtaBannerTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'heading'    => 'Ready to start?',
			'subheading' => 'Join today.',
			'cta'        => array(
				'label' => 'Sign up',
				'url'   => 'https://example.com/signup',
			),
		);
	}

	public function test_renders_expected_slots(): void {
		$cta = new CtaBanner();
		$ctx = $this->context();
		$out = $cta->render( $this->content(), 'accent-band', $ctx, $cta->default_background( $ctx ) );

		$this->assertStringContainsString( 'Ready to start?', $out );
		$this->assertStringContainsString( 'Join today.', $out );
		$this->assertStringContainsString( 'Sign up', $out );
		$this->assertStringContainsString( 'https://example.com/signup', $out );
	}

	public function test_is_correct_by_construction(): void {
		$cta = new CtaBanner();
		$ctx = $this->context();
		$out = $cta->render( $this->content(), 'accent-band', $ctx, $cta->default_background( $ctx ) );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_button_never_collides_with_section_background(): void {
		$cta = new CtaBanner();
		$ctx = $this->context();
		$bg  = $cta->default_background( $ctx );
		$out = $cta->render( $this->content(), 'accent-band', $ctx, $bg );

		$this->assertNotNull( $bg );
		$this->assertStringNotContainsString( 'wp-block-button__link has-' . $bg . '-background-color', $out );
	}

	public function test_default_background_is_accent(): void {
		$cta = new CtaBanner();
		$ctx = $this->context();
		$this->assertSame( $ctx->accent_slug(), $cta->default_background( $ctx ) );
	}

	public function test_is_deterministic(): void {
		$cta  = new CtaBanner();
		$ctx  = $this->context();
		$bg   = $cta->default_background( $ctx );
		$once = $cta->render( $this->content(), 'accent-band', $ctx, $bg );
		$this->assertSame( $once, $cta->render( $this->content(), 'accent-band', $ctx, $bg ) );
	}

	public function test_floating_card_variant_renders_a_lifted_card(): void {
		$cta = new CtaBanner();
		$ctx = $this->context();
		$out = $cta->render( $this->content(), 'floating-card', $ctx, $cta->default_background( $ctx ) );

		$this->assertStringContainsString( 'class="card-hover-lift', $out );
		$this->assertStringContainsString( 'Ready to start?', $out );
		$this->assertStringContainsString( 'Join today.', $out );
		$this->assertStringContainsString( 'Sign up', $out );
	}

	public function test_split_variant_renders_text_and_button_columns(): void {
		$cta = new CtaBanner();
		$ctx = $this->context();
		$out = $cta->render( $this->content(), 'split', $ctx, $cta->default_background( $ctx ) );

		$this->assertSame( 2, substr_count( $out, '<!-- wp:column ' ) );
		$this->assertStringNotContainsString( 'class="card-hover-lift', $out );
		$this->assertStringNotContainsString( '"width"', $out );
		$this->assertStringContainsString( 'Ready to start?', $out );
		$this->assertStringContainsString( 'Sign up', $out );
	}

	public function test_split_variant_is_correct_by_construction(): void {
		$cta = new CtaBanner();
		$ctx = $this->context();
		$out = $cta->render( $this->content(), 'split', $ctx, $cta->default_background( $ctx ) );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_split_variant_button_never_collides_with_band_background(): void {
		$cta = new CtaBanner();
		$ctx = $this->context();
		$bg  = $cta->default_background( $ctx );
		$out = $cta->render( $this->content(), 'split', $ctx, $bg );

		$this->assertNotNull( $bg );
		$this->assertStringNotContainsString( 'wp-block-button__link wp-element-button has-' . $bg . '-background-color', $out );
	}

	public function test_unspecified_variant_resolves_into_the_pickable_pool(): void {
		$cta = new CtaBanner();
		$ctx = $this->context();
		$bg  = $cta->default_background( $ctx );
		$out = $cta->render( $this->content(), null, $ctx, $bg );

		$this->assertSame( $out, $cta->render( $this->content(), null, $ctx, $bg ) );

		$explicit = array();
		foreach ( CtaBanner::VARIANTS as $variant ) {
			$explicit[] = $cta->render( $this->content(), $variant, $ctx, $bg );
		}
		$this->assertContains( $out, $explicit );
	}

	public function test_floating_card_variant_is_correct_by_construction(): void {
		$cta = new CtaBanner();
		$ctx = $this->context();
		$out = $cta->render( $this->content(), 'floating-card', $ctx, $cta->default_background( $ctx ) );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_floating_card_variant_button_never_collides_with_card_background(): void {
		$cta       = new CtaBanner();
		$ctx       = $this->context();
		$bg        = $cta->default_background( $ctx );
		$out       = $cta->render( $this->content(), 'floating-card', $ctx, $bg );
		$card_slug = $ctx->accent_slug() === $bg ? $ctx->light_slug() : $ctx->accent_slug();

		$this->assertNotNull( $card_slug );
		$this->assertStringNotContainsString( 'wp-block-button__link has-' . $card_slug . '-background-color', $out );
	}

	public function test_floating_card_variant_is_deterministic(): void {
		$cta  = new CtaBanner();
		$ctx  = $this->context();
		$bg   = $cta->default_background( $ctx );
		$once = $cta->render( $this->content(), 'floating-card', $ctx, $bg );
		$this->assertSame( $once, $cta->render( $this->content(), 'floating-card', $ctx, $bg ) );
	}
}
