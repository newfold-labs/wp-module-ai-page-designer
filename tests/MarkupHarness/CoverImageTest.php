<?php
/**
 * CoverImage rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\CoverImage;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CoverImage::class )]
class CoverImageTest extends MarkupHarnessTestCase {

	protected function setUp(): void {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			$this->markTestSkipped( 'parse_blocks/serialize_blocks unavailable (no WordPress install found).' );
		}
	}

	public function test_injects_image_element_when_url_present_but_unrendered(): void {
		$out = ( new CoverImage() )->apply( $this->cover_missing_image(), $this->context() );

		$this->assertStringContainsString( 'wp-block-cover__image-background', $out, 'cover image element injected' );
		$this->assertStringContainsString( 'src="https://images.unsplash.com/photo-123"', $out, 'image uses the cover url' );
		// Injected right after the opening cover wrapper, before the inner container.
		$this->assertMatchesRegularExpression( '/wp-block-cover[^>]*>\s*<img class="wp-block-cover__image-background"/', $out );
	}

	public function test_leaves_cover_with_existing_image_untouched(): void {
		$markup = '<!-- wp:cover {"url":"https://images.unsplash.com/photo-123"} -->' . "\n"
			. '<div class="wp-block-cover has-background-dim">' . "\n"
			. '<img class="wp-block-cover__image-background" alt="" src="https://images.unsplash.com/photo-123" data-object-fit="cover"/>' . "\n"
			. '<div class="wp-block-cover__inner-container"><p>Hi</p></div>' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:cover -->';

		$this->assertSame( $markup, ( new CoverImage() )->apply( $markup, $this->context() ) );
	}

	public function test_leaves_solid_color_cover_without_url_untouched(): void {
		$markup = '<!-- wp:cover {"dimRatio":60,"backgroundColor":"contrast"} -->' . "\n"
			. '<div class="wp-block-cover has-background-dim has-contrast-background-color has-background">' . "\n"
			. '<div class="wp-block-cover__inner-container"><p>Hi</p></div>' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:cover -->';

		$this->assertSame( $markup, ( new CoverImage() )->apply( $markup, $this->context() ) );
	}

	public function test_is_idempotent(): void {
		$rule = new CoverImage();
		$once = $rule->apply( $this->cover_missing_image(), $this->context() );
		$this->assertSame( $once, $rule->apply( $once, $this->context() ) );
	}

	public function test_validator_flags_then_passes(): void {
		$ctx       = $this->context();
		$validator = new Validator();

		$this->assertContains( 'cover_missing_image', $validator->validate( $this->cover_missing_image(), $ctx ) );

		$out = ( new CoverImage() )->apply( $this->cover_missing_image(), $ctx );
		$this->assertSame( array(), $validator->validate( $out, $ctx ) );
	}
}
