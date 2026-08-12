<?php
/**
 * UnsupportedBlockFallback rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Harness;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\UnsupportedBlockFallback;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( UnsupportedBlockFallback::class )]
class UnsupportedBlockFallbackTest extends MarkupHarnessTestCase {

	protected function setUp(): void {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			$this->markTestSkipped( 'parse_blocks/serialize_blocks unavailable (no WordPress install found).' );
		}
	}

	/**
	 * The reported defect: a contact section whose heading and intro render
	 * while the form block — from a plugin the site does not have — renders
	 * nothing at all.
	 *
	 * @return string
	 */
	private function forminator_section(): string {
		return '<!-- wp:group {"align":"wide"} -->' . "\n"
			. '<div class="wp-block-group alignwide">' . "\n"
			. '<!-- wp:heading -->' . "\n"
			. '<h2 class="wp-block-heading">Get in Touch</h2>' . "\n"
			. '<!-- /wp:heading -->' . "\n"
			. '<!-- wp:paragraph -->' . "\n"
			. '<p>We would love to hear from you.</p>' . "\n"
			. '<!-- /wp:paragraph -->' . "\n"
			. '<!-- wp:forminator/contact-form {"id":42} /-->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:group -->';
	}

	public function test_replaces_plugin_form_block_with_a_native_form(): void {
		$out = ( new UnsupportedBlockFallback() )->apply( $this->forminator_section(), $this->context() );

		$this->assertStringNotContainsString( 'forminator', $out, 'the unrenderable block is gone' );
		$this->assertStringContainsString( '<!-- wp:html', $out, 'replaced by a core/html block' );
		$this->assertStringContainsString( '<form>', $out );
		$this->assertStringContainsString( 'name="email"', $out );
		$this->assertStringContainsString( 'name="message"', $out );
		$this->assertStringContainsString( 'Send Message', $out );
	}

	public function test_keeps_the_surrounding_section_and_its_copy(): void {
		$out = ( new UnsupportedBlockFallback() )->apply( $this->forminator_section(), $this->context() );

		$this->assertStringContainsString( 'Get in Touch', $out );
		$this->assertStringContainsString( 'We would love to hear from you.', $out );
		// A nested replacement stays bare — no second section wrapped around
		// the form inside the model's own section.
		$this->assertSame( 1, substr_count( $out, '<!-- wp:group' ) );
	}

	public function test_top_level_form_block_gets_a_full_section(): void {
		$out = ( new UnsupportedBlockFallback() )->apply( '<!-- wp:wpforms/form-selector {"formId":"7"} /-->', $this->context() );

		$this->assertStringContainsString( '<!-- wp:group', $out, 'wrapped in a padded section' );
		$this->assertStringContainsString( '<form>', $out );
	}

	public function test_drops_an_unrenderable_non_form_block(): void {
		$markup = '<!-- wp:paragraph -->' . "\n" . '<p>Before</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n"
			. '<!-- wp:some-plugin/slider {"id":3} /-->' . "\n"
			. '<!-- wp:paragraph -->' . "\n" . '<p>After</p>' . "\n" . '<!-- /wp:paragraph -->';

		$out = ( new UnsupportedBlockFallback() )->apply( $markup, $this->context() );

		$this->assertStringNotContainsString( 'some-plugin/slider', $out );
		$this->assertStringContainsString( '<p>Before</p>', $out );
		$this->assertStringContainsString( '<p>After</p>', $out );
		$this->assertStringNotContainsString( '<form>', $out, 'a slider is not a form' );
	}

	public function test_keeps_an_unregistered_block_that_still_renders_its_saved_html(): void {
		// Plugins that register their block in JS only never appear in the PHP
		// registry, yet their saved static markup renders fine.
		$markup = '<!-- wp:some-plugin/notice -->' . "\n"
			. '<div class="wp-block-some-plugin-notice"><p>Heads up</p></div>' . "\n"
			. '<!-- /wp:some-plugin/notice -->';

		$this->assertStringContainsString( 'Heads up', ( new UnsupportedBlockFallback() )->apply( $markup, $this->context() ) );
	}

	public function test_leaves_core_blocks_untouched(): void {
		$markup = '<!-- wp:spacer {"height":"48px"} -->' . "\n"
			. '<div style="height:48px" aria-hidden="true" class="wp-block-spacer"></div>' . "\n"
			. '<!-- /wp:spacer -->';

		$this->assertStringContainsString( 'wp:spacer', ( new UnsupportedBlockFallback() )->apply( $markup, $this->context() ) );
	}

	public function test_replaces_an_unregistered_core_block(): void {
		// core/form ships with WordPress but is experimental and unregistered
		// on a stock site, so it fails exactly like a plugin block.
		$rule = new UnsupportedBlockFallback(
			static function ( $block_name ) {
				return 'core/form' !== $block_name && 0 === strpos( $block_name, 'core/' );
			}
		);

		$out = $rule->apply( '<!-- wp:core/form {"submissionMethod":"email"} /-->', $this->context() );

		$this->assertStringNotContainsString( 'wp:core/form', $out );
		$this->assertStringContainsString( '<form>', $out );
	}

	public function test_does_not_condemn_a_page_when_the_registry_is_empty(): void {
		// Sentinel guard: with nothing registered at all, only the namespace is
		// trusted, so core markup survives.
		$rule = new UnsupportedBlockFallback(
			static function ( $block_name ) {
				return 0 === strpos( $block_name, 'core/' );
			}
		);

		$markup = '<!-- wp:paragraph -->' . "\n" . '<p>Kept</p>' . "\n" . '<!-- /wp:paragraph -->';
		$this->assertStringContainsString( '<p>Kept</p>', $rule->apply( $markup, $this->context() ) );
	}

	public function test_preserves_sibling_order_when_replacing_inside_columns(): void {
		$markup = '<!-- wp:columns -->' . "\n"
			. '<div class="wp-block-columns">' . "\n"
			. '<!-- wp:column -->' . "\n"
			. '<div class="wp-block-column"><!-- wp:paragraph --><p>Left</p><!-- /wp:paragraph --></div>' . "\n"
			. '<!-- /wp:column -->' . "\n"
			. '<!-- wp:column -->' . "\n"
			. '<div class="wp-block-column"><!-- wp:jetpack/contact-form /--></div>' . "\n"
			. '<!-- /wp:column -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:columns -->';

		$out = ( new UnsupportedBlockFallback() )->apply( $markup, $this->context() );

		$this->assertStringNotContainsString( 'jetpack/contact-form', $out );
		// The form lands inside the SECOND column, not hoisted into the first.
		$this->assertMatchesRegularExpression( '/<p>Left<\/p>.*<form>/s', $out );
		$this->assertSame( 2, substr_count( $out, '<!-- /wp:column -->' ), 'both columns survive' );
	}

	public function test_is_idempotent(): void {
		$rule = new UnsupportedBlockFallback();
		$once = $rule->apply( $this->forminator_section(), $this->context() );
		$this->assertSame( $once, $rule->apply( $once, $this->context() ) );
	}

	public function test_full_pipeline_is_stable_so_saved_matches_previewed(): void {
		// The substituted markup has to be conformed by the rest of the
		// pipeline in the same pass. If it is not, the second conform (on save)
		// finishes the job and the saved page differs from the previewed one.
		$harness = new Harness();
		$ctx     = $this->context();

		$previewed = $harness->conform( $this->forminator_section(), $ctx );

		$this->assertStringContainsString( '<form>', $previewed );
		$this->assertSame( array(), $harness->validate( $previewed, $ctx ) );
		$this->assertSame( $previewed, $harness->conform( $previewed, $ctx ), 'saved == previewed' );
	}

	public function test_validator_flags_then_passes(): void {
		$ctx       = $this->context();
		$validator = new Validator();

		$this->assertContains( 'unsupported_block:forminator/contact-form', $validator->validate( $this->forminator_section(), $ctx ) );

		$out = ( new UnsupportedBlockFallback() )->apply( $this->forminator_section(), $ctx );
		$this->assertSame( array(), $validator->validate( $out, $ctx ) );
	}
}
