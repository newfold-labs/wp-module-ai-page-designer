<?php
/**
 * GalleryGrid archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\GalleryGrid;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( GalleryGrid::class )]
class GalleryGridTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'heading' => 'Inside the café',
			'intro'   => 'A look around.',
			'images'  => array(
				array( 'imageUrl' => 'https://images.example.test/a' ),
				array( 'imageUrl' => 'https://images.example.test/b' ),
				array( 'imageUrl' => 'https://images.example.test/c' ),
				array( 'imageUrl' => 'https://images.example.test/d' ),
			),
		);
	}

	public function test_renders_expected_slots(): void {
		$gallery = new GalleryGrid();
		$ctx     = $this->context();
		$out     = $gallery->render( $this->content(), null, $ctx, null );

		$this->assertStringContainsString( 'Inside the café', $out );
		$this->assertStringContainsString( 'A look around.', $out );
		foreach ( array( 'a', 'b', 'c', 'd' ) as $suffix ) {
			$this->assertStringContainsString( 'https://images.example.test/' . $suffix, $out );
		}
	}

	public function test_chunks_into_rows_of_three(): void {
		$gallery = new GalleryGrid();
		$out     = $gallery->render( $this->content(), null, $this->context(), null );

		// 4 images -> a row of 3 + a row of 1.
		$this->assertSame( 2, substr_count( $out, '<!-- wp:columns' ) );
		$this->assertSame( 4, substr_count( $out, '<!-- wp:image' ) );
	}

	public function test_images_are_rounded(): void {
		$gallery = new GalleryGrid();
		$out     = $gallery->render( $this->content(), null, $this->context(), null );

		$this->assertStringContainsString( 'border-radius:16px', $out );
	}

	public function test_is_correct_by_construction(): void {
		$gallery = new GalleryGrid();
		$ctx     = $this->context();
		$out     = $gallery->render( $this->content(), null, $ctx, null );

		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_is_deterministic(): void {
		$gallery = new GalleryGrid();
		$ctx     = $this->context();
		$once    = $gallery->render( $this->content(), null, $ctx, null );

		$this->assertSame( $once, $gallery->render( $this->content(), null, $ctx, null ) );
	}

	public function test_caps_at_six_images_and_skips_empty_urls(): void {
		$gallery = new GalleryGrid();
		$content = array(
			'images' => array_merge(
				array_fill( 0, 7, array( 'imageUrl' => 'https://images.example.test/x' ) ),
				array( array( 'imageUrl' => '' ) )
			),
		);
		$out     = $gallery->render( $content, null, $this->context(), null );

		$this->assertSame( 6, substr_count( $out, '<!-- wp:image' ) );
	}
}
