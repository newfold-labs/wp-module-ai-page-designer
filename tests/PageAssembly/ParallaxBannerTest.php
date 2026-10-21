<?php
/**
 * ParallaxBanner archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\ParallaxBanner;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ParallaxBanner::class )]
class ParallaxBannerTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'heading'  => 'Crafted for every kitchen',
			'imageUrl' => 'https://images.unsplash.com/photo-1',
		);
	}

	public function test_image_variant_renders_a_clean_photo_with_no_text(): void {
		$banner = new ParallaxBanner();
		$ctx    = $this->context();
		$out    = $banner->render( $this->content(), 'image', $ctx, $banner->default_background( $ctx ) );

		$this->assertStringContainsString( '<!-- wp:cover', $out );
		$this->assertStringContainsString( '"hasParallax":true', $out );
		$this->assertStringContainsString( '"dimRatio":0', $out );
		$this->assertStringContainsString( 'has-background-dim-0', $out );
		$this->assertStringContainsString( 'has-parallax', $out );
		$this->assertStringContainsString( 'background-image:url(https://images.unsplash.com/photo-1)', $out );
		$this->assertStringNotContainsString( '<img', $out );
		$this->assertStringNotContainsString( 'Crafted for every kitchen', $out );
	}

	public function test_image_variant_is_correct_by_construction(): void {
		$banner = new ParallaxBanner();
		$ctx    = $this->context();
		$out    = $banner->render( $this->content(), 'image', $ctx, $banner->default_background( $ctx ) );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_image_variant_is_deterministic(): void {
		$banner = new ParallaxBanner();
		$ctx    = $this->context();
		$bg     = $banner->default_background( $ctx );
		$once   = $banner->render( $this->content(), 'image', $ctx, $bg );
		$this->assertSame( $once, $banner->render( $this->content(), 'image', $ctx, $bg ) );
	}

	public function test_heading_variant_renders_the_heading_over_a_legible_dim(): void {
		$banner = new ParallaxBanner();
		$ctx    = $this->context();
		$out    = $banner->render( $this->content(), 'heading', $ctx, $banner->default_background( $ctx ) );

		$this->assertStringContainsString( '<!-- wp:cover', $out );
		$this->assertStringContainsString( '"hasParallax":true', $out );
		$this->assertStringContainsString( '"dimRatio":60', $out );
		$this->assertStringContainsString( 'has-background-dim-60', $out );
		$this->assertStringContainsString( 'Crafted for every kitchen', $out );
		$this->assertStringContainsString( '<!-- wp:heading', $out );
	}

	public function test_heading_variant_is_correct_by_construction(): void {
		$banner = new ParallaxBanner();
		$ctx    = $this->context();
		$out    = $banner->render( $this->content(), 'heading', $ctx, $banner->default_background( $ctx ) );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_heading_variant_is_deterministic(): void {
		$banner = new ParallaxBanner();
		$ctx    = $this->context();
		$bg     = $banner->default_background( $ctx );
		$once   = $banner->render( $this->content(), 'heading', $ctx, $bg );
		$this->assertSame( $once, $banner->render( $this->content(), 'heading', $ctx, $bg ) );
	}

	public function test_heading_variant_omits_heading_cleanly_when_absent(): void {
		$banner = new ParallaxBanner();
		$ctx    = $this->context();
		$out    = $banner->render(
			array( 'imageUrl' => 'https://images.unsplash.com/photo-2' ),
			'heading',
			$ctx,
			$banner->default_background( $ctx )
		);

		$this->assertStringNotContainsString( '<!-- wp:heading', $out );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_heading_uses_the_fancy_display_face(): void {
		$banner = new ParallaxBanner();
		$ctx    = $this->context();
		$out    = $banner->render( $this->content(), 'heading', $ctx, $banner->default_background( $ctx ) );

		$this->assertStringContainsString( '"className":"nfd-fancy-heading"', $out );
		$this->assertStringContainsString( 'nfd-fancy-heading', $out );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_default_background_is_dark_slug(): void {
		$banner = new ParallaxBanner();
		$ctx    = $this->context();
		$this->assertSame( $ctx->dark_slug(), $banner->default_background( $ctx ) );
	}

	public function test_an_unrecognized_variant_falls_back_to_the_image_url_hash(): void {
		$banner = new ParallaxBanner();
		$ctx    = $this->context();
		$bg     = $banner->default_background( $ctx );
		$out    = $banner->render( $this->content(), 'not-a-real-variant', $ctx, $bg );
		$this->assertSame( $out, $banner->render( $this->content(), null, $ctx, $bg ) );
	}

	public function test_unspecified_variant_varies_by_image_url(): void {
		$banner  = new ParallaxBanner();
		$ctx     = $this->context();
		$bg      = $banner->default_background( $ctx );
		$content = $this->content();

		$shapes = array();
		foreach ( array( 'https://images.unsplash.com/photo-1', 'https://images.unsplash.com/photo-2', 'https://images.unsplash.com/photo-4' ) as $image_url ) {
			$content['imageUrl'] = $image_url;
			$out                 = $banner->render( $content, null, $ctx, $bg );
			$shapes[]            = strpos( $out, '"dimRatio":0' ) !== false ? 'image' : 'heading';
		}

		// Not every image URL needs to land on a different variant, but they
		// shouldn't all be identical — the exact same "no variety" bug
		// HeroCoverTest guards against for its own auto-pick pool.
		$this->assertGreaterThan( 1, count( array_unique( $shapes ) ) );
	}
}
