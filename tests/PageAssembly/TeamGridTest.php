<?php
/**
 * TeamGrid archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\TeamGrid;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( TeamGrid::class )]
class TeamGridTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'heading' => 'Meet the team',
			'members' => array(
				array(
					'name'      => 'Ana Silva',
					'role'      => 'Head Roaster',
					'bio'       => 'Fifteen years of sourcing beans.',
					'avatarUrl' => 'https://images.example.test/ana',
				),
				array(
					'name' => 'Ben Ortiz',
					'role' => 'Barista Lead',
				),
				array(
					'name'      => 'Chi Tran',
					'role'      => 'Events',
					'avatarUrl' => 'https://images.example.test/chi',
				),
			),
		);
	}

	public function test_renders_expected_slots(): void {
		$team = new TeamGrid();
		$ctx  = $this->context();
		$out  = $team->render( $this->content(), 'cards', $ctx, null );

		$this->assertStringContainsString( 'Meet the team', $out );
		$this->assertStringContainsString( 'Ana Silva', $out );
		$this->assertStringContainsString( 'Head Roaster', $out );
		$this->assertStringContainsString( 'Fifteen years of sourcing beans.', $out );
		$this->assertStringContainsString( 'Ben Ortiz', $out );
		$this->assertStringContainsString( 'https://images.example.test/ana', $out );
	}

	public function test_avatars_are_circular(): void {
		$team = new TeamGrid();
		$out  = $team->render( $this->content(), 'cards', $this->context(), null );

		$this->assertStringContainsString( 'border-radius:9999px', $out );
	}

	public function test_members_render_as_floating_cards(): void {
		$team = new TeamGrid();
		$out  = $team->render( $this->content(), 'cards', $this->context(), null );

		$this->assertSame( 3, substr_count( $out, 'class="card-hover-lift' ) );
	}

	public function test_is_correct_by_construction(): void {
		$team = new TeamGrid();
		$ctx  = $this->context();
		$out  = $team->render( $this->content(), 'cards', $ctx, null );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_is_deterministic(): void {
		$team = new TeamGrid();
		$ctx  = $this->context();
		$once = $team->render( $this->content(), 'cards', $ctx, null );

		$this->assertSame( $once, $team->render( $this->content(), 'cards', $ctx, null ) );
	}

	public function test_minimal_variant_drops_the_cards_but_keeps_the_people(): void {
		$team = new TeamGrid();
		$out  = $team->render( $this->content(), 'minimal', $this->context(), null );

		$this->assertStringNotContainsString( 'class="card-hover-lift', $out );
		$this->assertStringContainsString( 'Ana Silva', $out );
		$this->assertStringContainsString( 'border-radius:9999px', $out );
	}

	public function test_minimal_variant_is_correct_by_construction_with_and_without_background(): void {
		$team = new TeamGrid();
		$ctx  = $this->context();
		$v    = new Validator();

		$this->assertSame( array(), $v->validate( $team->render( $this->content(), 'minimal', $ctx, null ), $ctx ) );
		$this->assertSame( array(), $v->validate( $team->render( $this->content(), 'minimal', $ctx, $ctx->muted_light_slug() ), $ctx ) );
	}

	public function test_unspecified_variant_resolves_into_the_pickable_pool(): void {
		$team = new TeamGrid();
		$ctx  = $this->context();
		$out  = $team->render( $this->content(), null, $ctx, null );

		$this->assertSame( $out, $team->render( $this->content(), null, $ctx, null ) );

		$explicit = array();
		foreach ( TeamGrid::VARIANTS as $variant ) {
			$explicit[] = $team->render( $this->content(), $variant, $ctx, null );
		}
		$this->assertContains( $out, $explicit );
	}
}
