<?php
/**
 * Archetype variant-registry tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\Archetype;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\FeatureGrid;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\PageAssembler;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The contract every archetype's variant registry must satisfy — the
 * auto-pick pool ({@see Archetype::variants()}) drives both the crc32
 * variant resolution and the planner prompt's generated variant hints, so
 * a malformed registry would corrupt both.
 */
#[CoversClass( PageAssembler::class )]
class ArchetypeRegistryTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, Archetype>
	 */
	private function catalogue(): array {
		return ( new PageAssembler( $this->fake_image_service() ) )->archetypes();
	}

	public function test_every_archetype_declares_a_nonempty_pickable_pool(): void {
		foreach ( $this->catalogue() as $name => $archetype ) {
			$variants = $archetype->variants();
			$this->assertNotEmpty( $variants, "{$name} must declare at least one auto-pickable variant" );
			foreach ( $variants as $variant ) {
				$this->assertIsString( $variant, "{$name} variant names must be strings" );
				$this->assertNotSame( '', $variant, "{$name} variant names must be non-empty" );
			}
		}
	}

	public function test_legacy_variants_never_overlap_the_pickable_pool(): void {
		foreach ( $this->catalogue() as $name => $archetype ) {
			$overlap = array_intersect( $archetype->variants(), $archetype->legacy_variants() );
			$this->assertSame( array(), $overlap, "{$name} must not list a variant as both pickable and legacy" );
		}
	}

	public function test_variant_names_are_unique_within_an_archetype(): void {
		foreach ( $this->catalogue() as $name => $archetype ) {
			$all = array_merge( $archetype->variants(), $archetype->legacy_variants() );
			$this->assertSame( count( $all ), count( array_unique( $all ) ), "{$name} declares a duplicate variant name" );
		}
	}

	public function test_an_unrecognized_variant_resolves_like_an_omitted_one(): void {
		$grid    = new FeatureGrid();
		$ctx     = $this->context();
		$content = array(
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
		);

		$this->assertSame(
			$grid->render( $content, null, $ctx, null ),
			$grid->render( $content, 'not-a-real-variant', $ctx, null )
		);
	}

	/**
	 * Every "big statement heading" surface — HeroCover's four variants and
	 * ParallaxBanner's text-bearing one — must render in the fancy display
	 * face. Asserted here across the whole catalogue rather than per-archetype
	 * so adding a new HeroCover variant (or a new hero-class archetype) that
	 * forgets {@see RendersMarkup::render_heading()}'s `$fancy` flag fails
	 * loudly instead of silently shipping a plain theme-font hero.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function hero_heading_surface_provider(): array {
		$hero_content = array(
			'heading'    => 'Fresh coffee, faster mornings',
			'primaryCta' => array(
				'label' => 'Order now',
				'url'   => '#',
			),
			'imageUrl'   => 'https://images.unsplash.com/photo-1',
		);

		return array(
			'heroCover/split'          => array( 'heroCover', 'split', $hero_content ),
			'heroCover/image-bg'       => array( 'heroCover', 'image-bg', $hero_content ),
			'heroCover/centered'       => array( 'heroCover', 'centered', $hero_content ),
			'heroCover/stacked'        => array( 'heroCover', 'stacked', $hero_content ),
			'parallaxBanner/heading'   => array(
				'parallaxBanner',
				'heading',
				array(
					'heading'  => 'Rooted in the neighborhood',
					'imageUrl' => 'https://images.unsplash.com/photo-2',
				),
			),
		);
	}

	/**
	 * @dataProvider hero_heading_surface_provider
	 * @param string               $archetype_name Registered archetype name.
	 * @param string               $variant        Variant under test.
	 * @param array<string, mixed> $content        Slot content.
	 */
	public function test_hero_class_headings_use_the_fancy_display_face( string $archetype_name, string $variant, array $content ): void {
		$catalogue = $this->catalogue();
		$this->assertArrayHasKey( $archetype_name, $catalogue );

		$archetype = $catalogue[ $archetype_name ];
		$ctx       = $this->context();
		$out       = $archetype->render( $content, $variant, $ctx, $archetype->default_background( $ctx ) );

		$this->assertStringContainsString( '"className":"nfd-fancy-heading"', $out, "{$archetype_name}/{$variant} lost the fancy-heading attribute" );
		$this->assertStringContainsString( 'class="nfd-fancy-heading', $out, "{$archetype_name}/{$variant} lost the fancy-heading class" );
	}

	/**
	 * A highlighted phrase must always clear <mark>'s user-agent yellow
	 * highlighter background. Colour alone doesn't reset it, so an accent
	 * headline would otherwise render as highlighter-pen text on the published
	 * page and in the preview alike.
	 */
	public function test_heading_highlight_clears_the_mark_user_agent_background(): void {
		$catalogue = $this->catalogue();
		$ctx       = $this->context();
		$hero      = $catalogue['heroCover'];

		foreach ( array( 'split', 'image-bg', 'centered', 'stacked' ) as $variant ) {
			$out = $hero->render(
				array(
					'heading'          => 'Fair-trade coffee, local flavor,',
					'headingHighlight' => 'Asheville community',
					'primaryCta'       => array(
						'label' => 'Order now',
						'url'   => '#',
					),
					'imageUrl'         => 'https://images.unsplash.com/photo-1',
				),
				$variant,
				$ctx,
				$hero->default_background( $ctx )
			);

			$this->assertStringContainsString( '<mark', $out, "variant: {$variant}" );
			$this->assertStringContainsString( 'background-color:transparent', $out, "variant: {$variant}" );
		}
	}

	public function test_a_legacy_variant_is_honored_when_named_explicitly(): void {
		$grid    = new FeatureGrid();
		$ctx     = $this->context();
		$content = array(
			'heading' => 'Why choose us',
			'items'   => array(
				array(
					'title' => 'A',
					'body'  => 'a',
				),
			),
		);

		$legacy = $grid->render( $content, 'cards-3', $ctx, null );
		$this->assertNotSame( $grid->render( $content, null, $ctx, null ), $legacy );
		$this->assertStringNotContainsString( 'class="card-hover-lift', $legacy );
	}
}
