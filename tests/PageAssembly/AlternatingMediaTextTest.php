<?php
/**
 * AlternatingMediaText archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\AlternatingMediaText;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( AlternatingMediaText::class )]
class AlternatingMediaTextTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'heading' => 'Our Story',
			'rows'    => array(
				array(
					'heading'  => 'Chapter one',
					'body'     => 'It began with an idea.',
					'imageUrl' => 'https://images.unsplash.com/photo-1',
					'cta'      => array(
						'label' => 'Read more',
						'url'   => '#',
					),
				),
				array(
					'heading'  => 'Chapter two',
					'body'     => 'Then it grew.',
					'imageUrl' => 'https://images.unsplash.com/photo-2',
				),
			),
		);
	}

	public function test_renders_expected_slots(): void {
		$alt = new AlternatingMediaText();
		$out = $alt->render( $this->content(), null, $this->context(), null );

		$this->assertStringContainsString( 'Our Story', $out );
		$this->assertStringContainsString( 'Chapter one', $out );
		$this->assertStringContainsString( 'It began with an idea.', $out );
		$this->assertStringContainsString( 'https://images.unsplash.com/photo-1', $out );
		$this->assertStringContainsString( 'Read more', $out );
		$this->assertSame( 2, substr_count( $out, '<!-- wp:columns ' ) );
	}

	public function test_rows_alternate_image_side(): void {
		$alt = new AlternatingMediaText();
		$out = $alt->render( $this->content(), null, $this->context(), null );

		$rows = explode( '<!-- wp:columns ', $out );
		// [0] is the section-level markup before the first row.
		$this->assertLessThan(
			strpos( $rows[1], 'Chapter one' ),
			strpos( $rows[1], '<!-- wp:image' ),
			'Row 0 (even) should render the image column before the text column.'
		);
		$this->assertGreaterThan(
			strpos( $rows[2], 'Chapter two' ),
			strpos( $rows[2], '<!-- wp:image' ),
			'Row 1 (odd) should render the text column before the image column.'
		);
	}

	public function test_columns_never_declare_a_width(): void {
		$alt = new AlternatingMediaText();
		$out = $alt->render( $this->content(), null, $this->context(), null );
		// No block-attr "width" anywhere (columns stay auto-distributed — the
		// one width state the Validator always accepts). The rounded image's
		// inline `width:100%` CSS is fine and deliberately not matched here.
		$this->assertStringNotContainsString( '"width"', $out );
	}

	public function test_is_correct_by_construction(): void {
		$alt = new AlternatingMediaText();
		$ctx = $this->context();
		$out = $alt->render( $this->content(), null, $ctx, null );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_default_background_is_null_surface(): void {
		$alt = new AlternatingMediaText();
		$this->assertNull( $alt->default_background( $this->context() ) );
	}

	public function test_is_deterministic(): void {
		$alt  = new AlternatingMediaText();
		$ctx  = $this->context();
		$once = $alt->render( $this->content(), null, $ctx, null );
		$this->assertSame( $once, $alt->render( $this->content(), null, $ctx, null ) );
	}

	public function test_floating_media_is_the_default_variant(): void {
		$alt = new AlternatingMediaText();
		$out = $alt->render( $this->content(), null, $this->context(), null );

		$this->assertStringContainsString( 'border-radius:16px', $out );
		$this->assertStringContainsString( 'box-shadow', $out );
	}

	public function test_flat_variant_keeps_unstyled_images(): void {
		$alt = new AlternatingMediaText();
		$out = $alt->render( $this->content(), 'flat', $this->context(), null );

		$this->assertStringNotContainsString( 'border-radius:16px', $out );
		$this->assertStringNotContainsString( 'box-shadow', $out );
	}

	public function test_flat_variant_is_correct_by_construction(): void {
		$alt = new AlternatingMediaText();
		$ctx = $this->context();
		$out = $alt->render( $this->content(), 'flat', $ctx, null );

		$this->assertSame( array(), ( new \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator() )->validate( $out, $ctx ) );
	}
}
