<?php
/**
 * Harness end-to-end tests (pipeline + validator gate + idempotency).
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Harness;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Harness::class )]
class HarnessTest extends MarkupHarnessTestCase {

	/**
	 * A page combining all three real defects.
	 *
	 * @return string
	 */
	private function defective_page(): string {
		return $this->cta_section() . "\n\n" . $this->form_grid() . "\n\n" . $this->bare_button();
	}

	public function test_conform_resolves_all_violations(): void {
		$harness   = new Harness();
		$validator = new Validator();
		$ctx       = $this->context();

		$this->assertNotSame( array(), $validator->validate( $this->defective_page(), $ctx ), 'raw page is invalid' );

		$conformed = $harness->conform( $this->defective_page(), $ctx );

		$this->assertSame(
			array(),
			$validator->validate( $conformed, $ctx ),
			'conformed page passes the validator gate'
		);
	}

	public function test_conform_is_idempotent(): void {
		$harness = new Harness();
		$ctx     = $this->context();

		$once  = $harness->conform( $this->defective_page(), $ctx );
		$twice = $harness->conform( $once, $ctx );

		// WYSIWYG: re-conform on save must not diverge from the previewed markup.
		$this->assertSame( $once, $twice );
	}

	public function test_empty_markup_passes_through(): void {
		$harness = new Harness();
		$this->assertSame( '', $harness->conform( '', $this->context() ) );
		$this->assertSame( '   ', $harness->conform( '   ', $this->context() ) );
	}
}
