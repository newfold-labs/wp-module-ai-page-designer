<?php
/**
 * RichText archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\RichText;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RichText::class )]
class RichTextTest extends PageAssemblyTestCase {

	public function test_renders_expected_slots(): void {
		$rich = new RichText();
		$out  = $rich->render(
			array(
				'heading' => 'About us',
				'body'    => 'We are a company that does things.',
				'cta'     => array(
					'label' => 'Learn more',
					'url'   => 'https://example.com/about',
				),
			),
			'default',
			$this->context(),
			null
		);

		$this->assertStringContainsString( 'About us', $out );
		$this->assertStringContainsString( 'We are a company that does things.', $out );
		$this->assertStringContainsString( 'Learn more', $out );
		$this->assertStringContainsString( 'https://example.com/about', $out );
	}

	public function test_renders_without_optional_slots(): void {
		$rich = new RichText();
		$ctx  = $this->context();
		$out  = $rich->render( array( 'body' => 'Just body text.' ), null, $ctx, null );

		$this->assertStringContainsString( 'Just body text.', $out );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_is_correct_by_construction(): void {
		$rich = new RichText();
		$ctx  = $this->context();
		$out  = $rich->render(
			array(
				'heading' => 'About us',
				'body'    => 'We are a company.',
				'cta'     => array(
					'label' => 'Learn more',
					'url'   => '#',
				),
			),
			null,
			$ctx,
			$ctx->muted_light_slug()
		);
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_default_background_is_null_surface(): void {
		$rich = new RichText();
		$this->assertNull( $rich->default_background( $this->context() ) );
	}

	public function test_body_renders_left_aligned_with_a_drop_cap(): void {
		$rich = new RichText();
		$ctx  = $this->context();
		$out  = $rich->render(
			array(
				'heading' => 'About us',
				'body'    => 'We are a company that does things.',
			),
			null,
			$ctx,
			null
		);

		$this->assertStringContainsString( '"dropCap":true', $out );
		// The body paragraph itself starts with "has-drop-cap" (no align class
		// before it, per RendersMarkup::render_paragraph()'s "align, then
		// drop-cap" order) — unlike the section's own heading, which
		// render_section() still centers, so a bare "has-text-align-center"
		// absence check across the whole string would be a false negative.
		$this->assertStringContainsString( '<p class="has-drop-cap', $out );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}
}
