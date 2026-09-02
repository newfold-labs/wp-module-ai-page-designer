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

	public function test_split_is_a_reachable_auto_picked_variant(): void {
		$hero    = new HeroCover();
		$ctx     = $this->context();
		$content = $this->content();
		// This specific heading is chosen only because it happens to hash to
		// "split" in the auto-pick pool (see resolve_variant()) — the point of
		// this test is that the unspecified-variant path CAN reach "split" on
		// its own, not that this exact string is special.
		$content['heading'] = 'Your neighborhood coffee spot';
		$out                = $hero->render( $content, null, $ctx, $hero->default_background( $ctx ) );

		$this->assertStringContainsString( '<!-- wp:columns', $out );
		$this->assertStringNotContainsString( '<!-- wp:cover', $out );
		$this->assertStringContainsString( 'Your neighborhood coffee spot', $out );
		$this->assertStringContainsString( 'New for 2026', $out );
		$this->assertStringContainsString( 'Order now', $out );
		$this->assertStringContainsString( 'View menu', $out );
		$this->assertStringContainsString( 'https://images.unsplash.com/photo-1', $out );
	}

	public function test_split_variant_is_correct_by_construction(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$out  = $hero->render( $this->content(), 'split', $ctx, $hero->default_background( $ctx ) );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_split_variant_cta_never_collides_with_section_background(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$bg   = $hero->default_background( $ctx );
		$out  = $hero->render( $this->content(), 'split', $ctx, $bg );

		$this->assertNotNull( $bg );
		$this->assertStringNotContainsString( 'wp-block-button__link has-' . $bg . '-background-color', $out );
	}

	public function test_split_variant_is_deterministic(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$bg   = $hero->default_background( $ctx );
		$once = $hero->render( $this->content(), 'split', $ctx, $bg );
		$this->assertSame( $once, $hero->render( $this->content(), 'split', $ctx, $bg ) );
	}

	public function test_split_variant_omits_optional_slots_cleanly(): void {
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

	public function test_centered_variant_renders_expected_slots_without_an_image(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$out  = $hero->render( $this->content(), 'centered', $ctx, $hero->default_background( $ctx ) );

		$this->assertStringNotContainsString( '<!-- wp:columns', $out );
		$this->assertStringNotContainsString( '<!-- wp:cover', $out );
		$this->assertStringNotContainsString( 'https://images.unsplash.com/photo-1', $out );
		$this->assertStringContainsString( 'Fresh coffee, faster mornings', $out );
		$this->assertStringContainsString( 'New for 2026', $out );
		$this->assertStringContainsString( 'Order now', $out );
		$this->assertStringContainsString( 'View menu', $out );
	}

	public function test_centered_variant_is_correct_by_construction(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$out  = $hero->render( $this->content(), 'centered', $ctx, $hero->default_background( $ctx ) );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_centered_variant_is_deterministic(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$bg   = $hero->default_background( $ctx );
		$once = $hero->render( $this->content(), 'centered', $ctx, $bg );
		$this->assertSame( $once, $hero->render( $this->content(), 'centered', $ctx, $bg ) );
	}

	public function test_stacked_variant_renders_expected_slots(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$out  = $hero->render( $this->content(), 'stacked', $ctx, $hero->default_background( $ctx ) );

		$this->assertStringContainsString( '<!-- wp:image', $out );
		$this->assertStringNotContainsString( '<!-- wp:columns', $out );
		$this->assertStringNotContainsString( '<!-- wp:cover', $out );
		$this->assertStringContainsString( 'Fresh coffee, faster mornings', $out );
		$this->assertStringContainsString( 'https://images.unsplash.com/photo-1', $out );
		$this->assertStringContainsString( 'Order now', $out );
	}

	public function test_stacked_variant_is_correct_by_construction(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$out  = $hero->render( $this->content(), 'stacked', $ctx, $hero->default_background( $ctx ) );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_stacked_variant_is_deterministic(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$bg   = $hero->default_background( $ctx );
		$once = $hero->render( $this->content(), 'stacked', $ctx, $bg );
		$this->assertSame( $once, $hero->render( $this->content(), 'stacked', $ctx, $bg ) );
	}

	public function test_unspecified_variant_is_deterministic_per_heading(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$bg   = $hero->default_background( $ctx );
		$once = $hero->render( $this->content(), null, $ctx, $bg );
		$this->assertSame( $once, $hero->render( $this->content(), null, $ctx, $bg ) );
	}

	public function test_unspecified_variant_varies_by_heading(): void {
		$hero    = new HeroCover();
		$ctx     = $this->context();
		$bg      = $hero->default_background( $ctx );
		$content = $this->content();

		$shapes = array();
		foreach ( array( 'Welcome to our shop', 'Your neighborhood coffee spot', 'Handcrafted brews, every day' ) as $heading ) {
			$content['heading'] = $heading;
			$out                = $hero->render( $content, null, $ctx, $bg );
			$shapes[]           = strpos( $out, '<!-- wp:columns' ) !== false
				? 'split'
				: ( strpos( $out, '<!-- wp:image' ) !== false
					? 'stacked'
					: ( strpos( $out, 'has-parallax' ) !== false
						? 'parallax'
						: ( strpos( $out, '<!-- wp:cover' ) !== false ? 'image-bg' : 'centered' ) ) );
		}

		// Not every heading needs to land on a different variant, but they
		// shouldn't all be identical — that's the exact bug being fixed.
		$this->assertGreaterThan( 1, count( array_unique( $shapes ) ) );
	}

	public function test_an_unrecognized_variant_falls_back_to_the_heading_hash(): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$bg   = $hero->default_background( $ctx );
		$out  = $hero->render( $this->content(), 'not-a-real-variant', $ctx, $bg );
		$this->assertSame( $out, $hero->render( $this->content(), null, $ctx, $bg ) );
	}

	/**
	 * @return string[] The four recognized variant names.
	 */
	public static function variant_provider(): array {
		return array(
			'split'    => array( 'split' ),
			'image-bg' => array( 'image-bg' ),
			'centered' => array( 'centered' ),
			'stacked'  => array( 'stacked' ),
		);
	}

	/**
	 * @dataProvider variant_provider
	 * @param string $variant Variant name under test.
	 */
	public function test_heading_uses_the_fancy_display_face( string $variant ): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$out  = $hero->render( $this->content(), $variant, $ctx, $hero->default_background( $ctx ) );

		$this->assertStringContainsString( '"className":"nfd-fancy-heading"', $out );
		$this->assertStringContainsString( 'nfd-fancy-heading', $out );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	/**
	 * @dataProvider variant_provider
	 * @param string $variant Variant name under test.
	 */
	public function test_eyebrow_uses_the_tracked_uppercase_label_style( string $variant ): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$out  = $hero->render( $this->content(), $variant, $ctx, $hero->default_background( $ctx ) );

		$this->assertStringContainsString( 'New for 2026', $out );
		$this->assertStringContainsString( 'text-transform:uppercase', $out );
		$this->assertStringContainsString( 'letter-spacing:0.08em', $out );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	/**
	 * @dataProvider variant_provider
	 * @param string $variant Variant name under test.
	 */
	public function test_heading_highlight_renders_as_an_inline_accent_mark( string $variant ): void {
		$hero    = new HeroCover();
		$ctx     = $this->context();
		$content = $this->content();

		$content['headingHighlight'] = 'actually works';
		$out                         = $hero->render( $content, $variant, $ctx, $hero->default_background( $ctx ) );

		$this->assertStringContainsString( '<mark', $out );
		$this->assertStringContainsString( 'has-inline-color', $out );
		$this->assertStringContainsString( 'actually works</mark>', $out );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	/**
	 * @dataProvider variant_provider
	 * @param string $variant Variant name under test.
	 */
	public function test_omitting_heading_highlight_renders_no_mark( string $variant ): void {
		$hero = new HeroCover();
		$ctx  = $this->context();
		$out  = $hero->render( $this->content(), $variant, $ctx, $hero->default_background( $ctx ) );

		$this->assertStringNotContainsString( '<mark', $out );
	}
}
