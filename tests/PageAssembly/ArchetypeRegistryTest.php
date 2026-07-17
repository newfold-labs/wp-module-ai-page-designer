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
		$this->assertStringNotContainsString( 'border-radius:16px', $legacy );
	}
}
