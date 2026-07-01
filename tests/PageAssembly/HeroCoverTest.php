<?php
/**
 * HeroCover archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\HeroCover;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( HeroCover::class )]
class HeroCoverTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'eyebrow'      => 'New for 2026',
			'heading'      => 'Fresh coffee, faster mornings',
			'subheading'   => 'Ethically sourced, locally roasted.',
			'primaryCta'   => array(
				'label' => 'Order now',
				'url'   => 'https://example.com/order',
			),
			'secondaryCta' => array(
				'label' => 'View menu',
				'url'   => 'https://example.com/menu',
			),
			'imageUrl'     => 'https://images.unsplash.com/photo-1',
		);
	}

	public function test_renders_expected_slots(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$out  = $hero->render( $this->content(), 'image-bg', $ctx, $hero->default_background( $ctx ) );

		$this->assertStringContainsString( '<!-- wp:cover', $out );
		$this->assertStringContainsString( 'Fresh coffee, faster mornings', $out );
		$this->assertStringContainsString( 'New for 2026', $out );
		$this->assertStringContainsString( 'Ethically sourced, locally roasted.', $out );
		$this->assertStringContainsString( 'Order now', $out );
		$this->assertStringContainsString( 'View menu', $out );
		$this->assertStringContainsString( 'wp-block-cover__image-background', $out );
		$this->assertStringContainsString( 'https://images.unsplash.com/photo-1', $out );
	}

	public function test_is_correct_by_construction(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$out  = $hero->render( $this->content(), 'image-bg', $ctx, $hero->default_background( $ctx ) );

		// The key proof: zero validator violations with NO repair pass applied.
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_cta_buttons_never_collide_with_cover_background(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$bg   = $hero->default_background( $ctx );
		$out  = $hero->render( $this->content(), 'image-bg', $ctx, $bg );

		$this->assertNotNull( $bg );
		// Neither button's rendered background class matches the cover's own.
		$this->assertStringNotContainsString( 'wp-block-button__link has-' . $bg . '-background-color', $out );
	}

	public function test_default_background_is_dark_slug(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$this->assertSame( $ctx->dark_slug(), $hero->default_background( $ctx ) );
	}

	public function test_omits_optional_slots_cleanly(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$out  = $hero->render(
			array(
				'heading'    => 'Just a heading',
				'primaryCta' => array(
					'label' => 'Go',
					'url'   => '#',
				),
				'imageUrl'   => 'https://images.unsplash.com/photo-2',
			),
			null,
			$ctx,
			$hero->default_background( $ctx )
		);

		$this->assertStringContainsString( 'Just a heading', $out );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_is_deterministic(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$bg   = $hero->default_background( $ctx );
		$once = $hero->render( $this->content(), 'image-bg', $ctx, $bg );
		$this->assertSame( $once, $hero->render( $this->content(), 'image-bg', $ctx, $bg ) );
	}
}
