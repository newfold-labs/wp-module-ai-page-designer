<?php
/**
 * BookingForm archetype tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\PageAssembly;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\BookingForm;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( BookingForm::class )]
class BookingFormTest extends PageAssemblyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function content(): array {
		return array(
			'heading'     => 'Book a table',
			'submitLabel' => 'Reserve',
			'fields'      => array(
				array(
					'type'     => 'text',
					'name'     => 'name',
					'label'    => 'Name',
					'required' => true,
				),
				array(
					'type'  => 'email',
					'name'  => 'email',
					'label' => 'Email',
				),
				array(
					'type'    => 'select',
					'name'    => 'size',
					'label'   => 'Party size',
					'options' => array( '2', '4', '6' ),
				),
				array(
					'type'  => 'textarea',
					'name'  => 'notes',
					'label' => 'Notes',
				),
			),
		);
	}

	public function test_renders_every_field_type(): void {
		$form = new BookingForm();
		$out  = $form->render( $this->content(), 'stacked', $this->context(), null );

		$this->assertStringContainsString( 'Book a table', $out );
		$this->assertStringContainsString( '<!-- wp:html', $out );
		$this->assertStringContainsString( '<input type="text" id="name" name="name"', $out );
		$this->assertStringContainsString( '<input type="email" id="email" name="email"', $out );
		$this->assertStringContainsString( '<select id="size" name="size"', $out );
		$this->assertStringContainsString( '<option value="2">2</option>', $out );
		$this->assertStringContainsString( '<textarea id="notes" name="notes"', $out );
		$this->assertStringContainsString( 'Reserve', $out );
	}

	public function test_required_field_carries_the_required_attribute(): void {
		$form = new BookingForm();
		$out  = $form->render( $this->content(), 'stacked', $this->context(), null );
		$this->assertMatchesRegularExpression( '/<input type="text" id="name"[^>]*\brequired\b/', $out );
		$this->assertDoesNotMatchRegularExpression( '/<input type="email" id="email"[^>]*\brequired\b/', $out );
	}

	public function test_submit_button_is_never_unstyled(): void {
		$form = new BookingForm();
		$out  = $form->render( $this->content(), 'stacked', $this->context(), null );
		$this->assertMatchesRegularExpression( '/<button type="submit" style="[^"]*background(-color)?\s*:/', $out );
	}

	public function test_unknown_field_type_falls_back_to_text_input(): void {
		$form = new BookingForm();
		$out  = $form->render(
			array( 'fields' => array( array( 'type' => 'bogus', 'name' => 'x', 'label' => 'X' ) ) ),
			'stacked',
			$this->context(),
			null
		);
		$this->assertStringContainsString( '<input type="text" id="x" name="x"', $out );
	}

	public function test_defaults_submit_label_when_absent(): void {
		$form = new BookingForm();
		$out  = $form->render( array( 'fields' => array() ), 'stacked', $this->context(), null );
		$this->assertStringContainsString( '>Submit</button>', $out );
	}

	public function test_is_correct_by_construction(): void {
		$form = new BookingForm();
		$ctx  = $this->context();
		$out  = $form->render( $this->content(), 'stacked', $ctx, $ctx->muted_light_slug() );
		$this->assertSame( array(), ( new Validator() )->validate( $out, $ctx ) );
	}

	public function test_default_background_is_null_surface(): void {
		$form = new BookingForm();
		$this->assertNull( $form->default_background( $this->context() ) );
	}

	public function test_is_deterministic(): void {
		$form = new BookingForm();
		$ctx  = $this->context();
		$once = $form->render( $this->content(), 'stacked', $ctx, null );
		$this->assertSame( $once, $form->render( $this->content(), 'stacked', $ctx, null ) );
	}
}
