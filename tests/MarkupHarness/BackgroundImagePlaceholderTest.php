<?php
/**
 * BackgroundImagePlaceholder rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\BackgroundImagePlaceholder;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( BackgroundImagePlaceholder::class )]
class BackgroundImagePlaceholderTest extends MarkupHarnessTestCase {

	/**
	 * The reported bug: a section group carrying a real image AND a trailing
	 * placeholder, where the placeholder wins by CSS last-declaration order.
	 *
	 * @return string
	 */
	private function real_plus_placeholder(): string {
		return '<!-- wp:group -->' . "\n"
			. '<div class="wp-block-group" style="background-color:var(--wp--preset--color--base);'
			. 'background-image: url(https://images.unsplash.com/photo-1?q=80&w=1080);'
			. 'padding-top:64px;padding-left:32px;'
			. "background-image:url('https://placehold.co/1200x600');background-size:cover\"><p>x</p></div>" . "\n"
			. '<!-- /wp:group -->';
	}

	public function test_drops_placeholder_when_real_image_present(): void {
		$rule = new BackgroundImagePlaceholder();
		$out  = $rule->apply( $this->real_plus_placeholder(), $this->context() );
		$this->assertStringNotContainsString( 'placehold.co', $out );
		$this->assertStringContainsString( 'images.unsplash.com', $out );
		// Surrounding declarations survive.
		$this->assertStringContainsString( 'padding-top:64px', $out );
		$this->assertStringContainsString( 'background-size:cover', $out );
	}

	public function test_leaves_lone_placeholder_untouched(): void {
		$rule   = new BackgroundImagePlaceholder();
		$markup = '<div style="background-image:url(\'https://placehold.co/1200x600\');background-size:cover">x</div>';
		$this->assertSame( $markup, $rule->apply( $markup, $this->context() ) );
	}

	public function test_leaves_real_only_untouched(): void {
		$rule   = new BackgroundImagePlaceholder();
		$markup = '<div style="background-image:url(https://images.unsplash.com/x);color:red">x</div>';
		$this->assertSame( $markup, $rule->apply( $markup, $this->context() ) );
	}

	public function test_preserves_data_uri_background(): void {
		$rule   = new BackgroundImagePlaceholder();
		$markup = '<div style="background-image:url(\'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=\');'
			. 'background-image:url(https://placehold.co/1200x600)">x</div>';
		$out    = $rule->apply( $markup, $this->context() );
		$this->assertStringContainsString( 'base64,PHN2Zz48L3N2Zz4=', $out );
		$this->assertStringNotContainsString( 'placehold.co', $out );
	}

	public function test_is_idempotent(): void {
		$rule = new BackgroundImagePlaceholder();
		$once = $rule->apply( $this->real_plus_placeholder(), $this->context() );
		$this->assertSame( $once, $rule->apply( $once, $this->context() ) );
	}
}
