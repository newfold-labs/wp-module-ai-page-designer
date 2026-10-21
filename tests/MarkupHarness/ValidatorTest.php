<?php
/**
 * Validator (oracle) tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Validator::class )]
class ValidatorTest extends MarkupHarnessTestCase {

	public function test_flags_each_known_defect(): void {
		$validator = new Validator();
		$ctx       = $this->context();

		$this->assertContains( 'asymmetric_padding:group', $validator->validate( $this->cta_section(), $ctx ) );
		$this->assertContains( 'invalid_grid:run_together_fr', $validator->validate( $this->form_grid(), $ctx ) );
		$this->assertContains( 'unstyled_form_button:<button>', $validator->validate( $this->bare_button(), $ctx ) );
		$this->assertContains( 'invalid_css:bare_unit', $validator->validate( '<div style="padding-top:px">x</div>', $ctx ) );
		$this->assertContains( 'document_wrapper:<script>', $validator->validate( '<script>alert(1)</script>', $ctx ) );
	}

	public function test_clean_markup_has_no_violations(): void {
		$validator = new Validator();
		$clean     = '<!-- wp:paragraph --><p>Hello world.</p><!-- /wp:paragraph -->';
		$this->assertSame( array(), $validator->validate( $clean, $this->context() ) );
		$this->assertTrue( $validator->is_valid( $clean, $this->context() ) );
	}

	public function test_empty_markup_is_valid(): void {
		$validator = new Validator();
		$this->assertSame( array(), $validator->validate( '   ', $this->context() ) );
	}
}
