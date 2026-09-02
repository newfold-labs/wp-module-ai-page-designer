<?php
/**
 * UnrenderableContentFallback rule tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Harness;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\UnrenderableContentFallback;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( UnrenderableContentFallback::class )]
class UnrenderableContentFallbackTest extends MarkupHarnessTestCase {

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
		$out = ( new UnrenderableContentFallback() )->apply( $this->forminator_section(), $this->context() );

		$this->assertStringNotContainsString( 'forminator', $out, 'the unrenderable block is gone' );
		$this->assertStringContainsString( '<!-- wp:html', $out, 'replaced by a core/html block' );
		$this->assertStringContainsString( '<form>', $out );
		$this->assertStringContainsString( 'name="email"', $out );
		$this->assertStringContainsString( 'name="message"', $out );
		$this->assertStringContainsString( 'Send Message', $out );
	}

	public function test_keeps_the_surrounding_section_and_its_copy(): void {
		$out = ( new UnrenderableContentFallback() )->apply( $this->forminator_section(), $this->context() );

		$this->assertStringContainsString( 'Get in Touch', $out );
		$this->assertStringContainsString( 'We would love to hear from you.', $out );
		// A nested replacement stays bare — no second section wrapped around
		// the form inside the model's own section.
		$this->assertSame( 1, substr_count( $out, '<!-- wp:group' ) );
	}

	public function test_top_level_form_block_gets_a_full_section(): void {
		$out = ( new UnrenderableContentFallback() )->apply( '<!-- wp:wpforms/form-selector {"formId":"7"} /-->', $this->context() );

		$this->assertStringContainsString( '<!-- wp:group', $out, 'wrapped in a padded section' );
		$this->assertStringContainsString( '<form>', $out );
	}

	public function test_drops_an_unrenderable_non_form_block(): void {
		$markup = '<!-- wp:paragraph -->' . "\n" . '<p>Before</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n"
			. '<!-- wp:some-plugin/slider {"id":3} /-->' . "\n"
			. '<!-- wp:paragraph -->' . "\n" . '<p>After</p>' . "\n" . '<!-- /wp:paragraph -->';

		$out = ( new UnrenderableContentFallback() )->apply( $markup, $this->context() );

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

		$this->assertStringContainsString( 'Heads up', ( new UnrenderableContentFallback() )->apply( $markup, $this->context() ) );
	}

	public function test_leaves_core_blocks_untouched(): void {
		$markup = '<!-- wp:spacer {"height":"48px"} -->' . "\n"
			. '<div style="height:48px" aria-hidden="true" class="wp-block-spacer"></div>' . "\n"
			. '<!-- /wp:spacer -->';

		$this->assertStringContainsString( 'wp:spacer', ( new UnrenderableContentFallback() )->apply( $markup, $this->context() ) );
	}

	/**
	 * A stock site: the usual core blocks registered, no plugins.
	 *
	 * @param array<string, array> $extra Extra environment (see {@see FakeRenderSupport}).
	 * @return FakeRenderSupport
	 */
	private function stock_site( array $extra = array() ): FakeRenderSupport {
		return new FakeRenderSupport(
			array_merge(
				array(
					'blocks'     => array( 'core/paragraph', 'core/heading', 'core/group', 'core/html', 'core/block', 'core/pattern', 'core/template-part', 'core/navigation' ),
					'shortcodes' => array( 'gallery', 'caption' ),
				),
				$extra
			)
		);
	}

	public function test_replaces_an_unregistered_core_block(): void {
		// core/form ships with WordPress but is experimental and unregistered
		// on a stock site, so it fails exactly like a plugin block.
		$rule = new UnrenderableContentFallback( $this->stock_site() );

		$out = $rule->apply( '<!-- wp:core/form {"submissionMethod":"email"} /-->', $this->context() );

		$this->assertStringNotContainsString( 'wp:core/form', $out );
		$this->assertStringContainsString( '<form>', $out );
	}

	public function test_does_not_condemn_a_page_when_the_registry_is_empty(): void {
		// Sentinel guard: with nothing registered at all, only the namespace is
		// trusted, so core markup survives. (The real RenderSupport, not a
		// double — this is the guard against blanking a page.)
		$rule = new UnrenderableContentFallback();

		$markup = '<!-- wp:paragraph -->' . "\n" . '<p>Kept</p>' . "\n" . '<!-- /wp:paragraph -->';
		$this->assertStringContainsString( '<p>Kept</p>', $rule->apply( $markup, $this->context() ) );
	}

	public function test_drops_a_pattern_block_whose_pattern_is_not_registered(): void {
		// The same instinct as reaching for a plugin: the model knows theme
		// pattern slugs from training, and this site never registered one.
		$rule = new UnrenderableContentFallback( $this->stock_site( array( 'patterns' => array( 'mytheme/hero' ) ) ) );

		$markup = '<!-- wp:pattern {"slug":"twentytwentyfour/hero"} /-->' . "\n"
			. '<!-- wp:paragraph -->' . "\n" . '<p>Kept</p>' . "\n" . '<!-- /wp:paragraph -->';

		$out = $rule->apply( $markup, $this->context() );

		$this->assertStringNotContainsString( 'twentytwentyfour/hero', $out );
		$this->assertStringContainsString( '<p>Kept</p>', $out );
	}

	public function test_keeps_a_pattern_block_whose_pattern_exists(): void {
		$rule   = new UnrenderableContentFallback( $this->stock_site( array( 'patterns' => array( 'mytheme/hero' ) ) ) );
		$markup = '<!-- wp:pattern {"slug":"mytheme/hero"} /-->';

		$this->assertStringContainsString( 'mytheme/hero', $rule->apply( $markup, $this->context() ) );
	}

	public function test_drops_a_synced_pattern_and_menu_reference_that_do_not_exist(): void {
		$rule = new UnrenderableContentFallback( $this->stock_site( array( 'posts' => array( 7 => 'wp_block' ) ) ) );

		$markup = '<!-- wp:block {"ref":9999} /-->' . "\n" . '<!-- wp:navigation {"ref":4321} /-->';
		$out    = $rule->apply( $markup, $this->context() );

		$this->assertStringNotContainsString( 'wp:block', $out );
		$this->assertStringNotContainsString( 'wp:navigation', $out );
		// The one that does exist survives.
		$this->assertStringContainsString( 'wp:block', $rule->apply( '<!-- wp:block {"ref":7} /-->', $this->context() ) );
	}

	public function test_drops_a_template_part_that_does_not_resolve(): void {
		$rule = new UnrenderableContentFallback( $this->stock_site( array( 'template_parts' => array( 'footer' ) ) ) );

		$this->assertStringNotContainsString( 'wp:template-part', $rule->apply( '<!-- wp:template-part {"slug":"header"} /-->', $this->context() ) );
		$this->assertStringContainsString( 'wp:template-part', $rule->apply( '<!-- wp:template-part {"slug":"footer"} /-->', $this->context() ) );
	}

	public function test_leaves_a_navigation_block_with_no_ref_alone(): void {
		// core/navigation with no ref falls back to a page list — it renders.
		$rule = new UnrenderableContentFallback( $this->stock_site() );

		$this->assertStringContainsString( 'wp:navigation', $rule->apply( '<!-- wp:navigation /-->', $this->context() ) );
	}

	public function test_replaces_a_form_block_whose_form_id_does_not_exist(): void {
		// The plugin IS installed — the block is registered — but the model
		// invented the form ID, so the plugin renders "form not found".
		$rule = new UnrenderableContentFallback(
			$this->stock_site(
				array(
					'blocks' => array( 'core/paragraph', 'forminator/forms' ),
					'posts'  => array( 12 => 'forminator_forms' ),
				)
			)
		);

		$out = $rule->apply( '<!-- wp:forminator/forms {"module_id":"999"} /-->', $this->context() );

		$this->assertStringNotContainsString( 'forminator', $out );
		$this->assertStringContainsString( '<form>', $out );
	}

	public function test_keeps_a_form_block_whose_form_id_exists(): void {
		$rule = new UnrenderableContentFallback(
			$this->stock_site(
				array(
					'blocks' => array( 'core/paragraph', 'forminator/forms' ),
					'posts'  => array( 12 => 'forminator_forms' ),
				)
			)
		);

		$out = $rule->apply( '<!-- wp:forminator/forms {"module_id":"12"} /-->', $this->context() );

		$this->assertStringContainsString( 'forminator', $out, 'a real form is left to the plugin' );
		$this->assertStringNotContainsString( '<form>', $out );
	}

	public function test_replaces_a_shortcode_whose_form_id_does_not_exist(): void {
		$rule = new UnrenderableContentFallback(
			$this->stock_site(
				array(
					'shortcodes' => array( 'gallery', 'contact-form-7' ),
					'posts'      => array( 12 => 'wpcf7_contact_form' ),
				)
			)
		);

		$markup = '<!-- wp:paragraph -->' . "\n" . '<p>[contact-form-7 id="999"]</p>' . "\n" . '<!-- /wp:paragraph -->';
		$out    = $rule->apply( $markup, $this->context() );

		$this->assertStringNotContainsString( 'contact-form-7', $out );
		$this->assertStringContainsString( '<form>', $out );

		// The same shortcode naming a form that really exists is left alone.
		$real = '<!-- wp:paragraph -->' . "\n" . '<p>[contact-form-7 id="12"]</p>' . "\n" . '<!-- /wp:paragraph -->';
		$this->assertSame( $real, $rule->apply( $real, $this->context() ) );
	}

	public function test_uncertainty_is_never_treated_as_absence(): void {
		// Ninja Forms keeps forms in its own tables, so this module cannot
		// verify the ID. "Cannot tell" must leave the markup alone.
		$rule = new UnrenderableContentFallback(
			$this->stock_site( array( 'shortcodes' => array( 'gallery', 'ninja_form' ) ) )
		);

		$markup = '<!-- wp:paragraph -->' . "\n" . '<p>[ninja_form id="3"]</p>' . "\n" . '<!-- /wp:paragraph -->';
		$this->assertSame( $markup, $rule->apply( $markup, $this->context() ) );
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

		$out = ( new UnrenderableContentFallback() )->apply( $markup, $this->context() );

		$this->assertStringNotContainsString( 'jetpack/contact-form', $out );
		// The form lands inside the SECOND column, not hoisted into the first.
		$this->assertMatchesRegularExpression( '/<p>Left<\/p>.*<form>/s', $out );
		$this->assertSame( 2, substr_count( $out, '<!-- /wp:column -->' ), 'both columns survive' );
	}

	/**
	 * The second reported shape: the model wrote the form as a plugin
	 * SHORTCODE instead of a block, which prints verbatim to the visitor.
	 *
	 * @return string
	 */
	private function shortcode_section(): string {
		return '<!-- wp:group {"align":"wide"} -->' . "\n"
			. '<div class="wp-block-group alignwide">' . "\n"
			. '<!-- wp:heading -->' . "\n"
			. '<h2 class="wp-block-heading">Send us a message</h2>' . "\n"
			. '<!-- /wp:heading -->' . "\n"
			. '<!-- wp:paragraph -->' . "\n"
			. '<p>Questions about bookings, events, or catering?</p>' . "\n"
			. '<!-- /wp:paragraph -->' . "\n"
			. '<!-- wp:paragraph -->' . "\n"
			. '<p>[contact-form-7 id="0" title="Contact form"]</p>' . "\n"
			. '<!-- /wp:paragraph -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:group -->';
	}

	public function test_replaces_a_plugin_form_shortcode_with_a_native_form(): void {
		$out = ( new UnrenderableContentFallback() )->apply( $this->shortcode_section(), $this->context() );

		$this->assertStringNotContainsString( 'contact-form-7', $out, 'the raw shortcode is gone' );
		$this->assertStringContainsString( '<form>', $out );
		$this->assertStringContainsString( 'name="email"', $out );
		// The paragraph existed only to carry the shortcode — it goes with it.
		$this->assertStringNotContainsString( '<p></p>', $out );
		$this->assertStringContainsString( 'Send us a message', $out );
		$this->assertStringContainsString( 'Questions about bookings, events, or catering?', $out );
	}

	public function test_keeps_prose_written_around_an_inline_form_shortcode(): void {
		$markup = '<!-- wp:paragraph -->' . "\n"
			. '<p>Reach us here: [contact-form-7 id="0" title="Contact form"] — we reply within a day.</p>' . "\n"
			. '<!-- /wp:paragraph -->';

		$out = ( new UnrenderableContentFallback() )->apply( $markup, $this->context() );

		$this->assertStringNotContainsString( 'contact-form-7', $out );
		$this->assertStringContainsString( 'Reach us here:', $out );
		$this->assertStringContainsString( 'we reply within a day.', $out );
		$this->assertStringContainsString( '<form>', $out );
	}

	public function test_leaves_core_and_unknown_non_form_shortcodes_alone(): void {
		$markup = '<!-- wp:paragraph -->' . "\n"
			. '<p>[gallery ids="1,2"] and [some_slider id="3"]</p>' . "\n"
			. '<!-- /wp:paragraph -->';

		$this->assertSame( $markup, ( new UnrenderableContentFallback() )->apply( $markup, $this->context() ) );
	}

	public function test_never_eats_bracketed_prose(): void {
		// Deleting a visitor-visible sentence is worse than one stray tag, so
		// anything that is not unambiguously a form shortcode is left alone.
		$markup = '<!-- wp:paragraph -->' . "\n"
			. '<p>[10:00 AM] Welcome [see below] and [Contact the team] for details.</p>' . "\n"
			. '<!-- /wp:paragraph -->';

		$this->assertSame( $markup, ( new UnrenderableContentFallback() )->apply( $markup, $this->context() ) );
	}

	public function test_is_idempotent(): void {
		$rule = new UnrenderableContentFallback();
		$once = $rule->apply( $this->forminator_section(), $this->context() );
		$this->assertSame( $once, $rule->apply( $once, $this->context() ) );
	}

	public function test_is_idempotent_for_shortcodes(): void {
		$rule = new UnrenderableContentFallback();
		$once = $rule->apply( $this->shortcode_section(), $this->context() );
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

		$out = ( new UnrenderableContentFallback() )->apply( $this->forminator_section(), $ctx );
		$this->assertSame( array(), $validator->validate( $out, $ctx ) );
	}

	public function test_validator_flags_then_passes_for_shortcodes(): void {
		$ctx       = $this->context();
		$validator = new Validator();

		$this->assertContains( 'unsupported_shortcode:contact-form-7', $validator->validate( $this->shortcode_section(), $ctx ) );

		$out = ( new UnrenderableContentFallback() )->apply( $this->shortcode_section(), $ctx );
		$this->assertSame( array(), $validator->validate( $out, $ctx ) );
	}

	public function test_shortcode_full_pipeline_is_stable(): void {
		$harness = new Harness();
		$ctx     = $this->context();

		$previewed = $harness->conform( $this->shortcode_section(), $ctx );

		$this->assertStringContainsString( '<form>', $previewed );
		$this->assertSame( array(), $harness->validate( $previewed, $ctx ) );
		$this->assertSame( $previewed, $harness->conform( $previewed, $ctx ), 'saved == previewed' );
	}
}
