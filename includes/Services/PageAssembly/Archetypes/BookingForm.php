<?php
/**
 * Booking/contact form section archetype: typed fields rendered as an accessible, styled form.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a {@see RendersMarkup::render_section()} surface section containing
 * a `core/html` block with a real `<form>` — every field and the submit
 * button carry explicit theme-derived inline styles, so the raw-HTML form
 * defect class ({@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\StyleRawFormButtons}
 * repairs unstyled buttons after the fact) is structurally impossible here —
 * nothing is ever unstyled to begin with. `core/html` is used because
 * Gutenberg has no native form-field block; it's the correct block for
 * arbitrary HTML content and parses/serializes cleanly.
 *
 * Content shape:
 * ```
 * [
 *   'heading'     => string|null,
 *   'intro'       => string|null,
 *   'fields'      => [ [
 *     'type'     => 'text'|'email'|'tel'|'date'|'time'|'number'|'select'|'textarea',
 *     'name'     => string,
 *     'label'    => string,
 *     'required' => bool|null,
 *     'options'  => string[]|null (for type "select"),
 *   ], ... ],
 *   'submitLabel' => string|null (defaults to "Submit"),
 * ]
 * ```
 *
 * v1 supports a single variant, `stacked`.
 */
class BookingForm implements Archetype {

	use RendersMarkup;

	/**
	 * Field types rendered as a plain `<input type="...">` — every other
	 * listed type ("textarea", "select") gets its own tag.
	 *
	 * @var string[]
	 */
	private const INPUT_TYPES = array( 'text', 'email', 'tel', 'date', 'time', 'number' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'bookingForm';
	}

	/**
	 * {@inheritDoc}
	 *
	 * No fixed default — see {@see FeatureGrid::default_background()} for why.
	 */
	public function default_background( Context $ctx ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $content, ?string $variant, Context $ctx, ?string $background_slug ): string {
		$heading      = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$intro        = isset( $content['intro'] ) ? (string) $content['intro'] : '';
		$fields       = isset( $content['fields'] ) && is_array( $content['fields'] ) ? $content['fields'] : array();
		$submit_label = isset( $content['submitLabel'] ) && '' !== trim( (string) $content['submitLabel'] )
			? (string) $content['submitLabel']
			: 'Submit';

		$field_html = '';
		foreach ( $fields as $field ) {
			if ( is_array( $field ) ) {
				$field_html .= $this->render_field( $field, $ctx, $background_slug );
			}
		}

		$button_bg    = $this->contrasting_slug( $ctx, $background_slug );
		$button_text  = $this->text_slug_for_background( $ctx, $button_bg );
		$button_style = 'background-color:' . ( null !== $button_bg ? 'var(--wp--preset--color--' . $button_bg . ')' : '#000' ) . ';'
			. 'color:' . ( null !== $button_text ? 'var(--wp--preset--color--' . $button_text . ')' : '#fff' ) . ';'
			. 'border:none;border-radius:4px;cursor:pointer;padding:' . $ctx->spacing_css( 'sm' ) . ' ' . $ctx->spacing_css( 'md' ) . ';';

		$form = '<form>' . $field_html
			. '<button type="submit" style="' . $button_style . '">' . $this->esc_html( $submit_label ) . '</button>'
			. '</form>';

		$inner = $this->comment_wrap( 'html', array(), $form );

		return $this->render_section( $heading, $intro, $inner, $ctx, $background_slug );
	}

	/**
	 * Render a single form field: label + input/textarea/select, all carrying
	 * explicit theme-derived inline styles.
	 *
	 * @param array<string, mixed> $field           Field definition.
	 * @param Context               $ctx             Theme/conformance context.
	 * @param string|null           $background_slug The section's own background slug.
	 * @return string
	 */
	private function render_field( array $field, Context $ctx, ?string $background_slug ): string {
		$type     = isset( $field['type'] ) ? strtolower( (string) $field['type'] ) : 'text';
		$name     = isset( $field['name'] ) ? (string) $field['name'] : 'field';
		$label    = isset( $field['label'] ) ? (string) $field['label'] : $name;
		$required = ! empty( $field['required'] );
		$options  = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

		$border_slug = $this->text_slug_for_background( $ctx, $background_slug );
		$border_css  = null !== $border_slug ? 'var(--wp--preset--color--' . $border_slug . ')' : 'currentColor';
		$field_style = 'display:block;width:100%;box-sizing:border-box;'
			. 'padding:' . $ctx->spacing_css( 'sm' ) . ';'
			. 'margin-bottom:' . $ctx->spacing_css( 'sm' ) . ';'
			. 'border:1px solid ' . $border_css . ';border-radius:4px;'
			. 'color:' . $border_css . ';';

		$label_html = '<label for="' . $this->esc_attr( $name ) . '" style="display:block;margin-bottom:' . $ctx->spacing_css( 'sm' ) . ';font-weight:600">'
			. $this->esc_html( $label ) . ( $required ? ' *' : '' ) . '</label>';

		$required_attr = $required ? ' required' : '';

		if ( 'textarea' === $type ) {
			$field_html = '<textarea id="' . $this->esc_attr( $name ) . '" name="' . $this->esc_attr( $name ) . '" rows="4" style="' . $field_style . '"' . $required_attr . '></textarea>';
		} elseif ( 'select' === $type ) {
			$options_html = '';
			foreach ( $options as $option ) {
				$options_html .= '<option value="' . $this->esc_attr( (string) $option ) . '">' . $this->esc_html( (string) $option ) . '</option>';
			}
			$field_html = '<select id="' . $this->esc_attr( $name ) . '" name="' . $this->esc_attr( $name ) . '" style="' . $field_style . '"' . $required_attr . '>' . $options_html . '</select>';
		} else {
			$input_type = in_array( $type, self::INPUT_TYPES, true ) ? $type : 'text';
			$field_html = '<input type="' . $this->esc_attr( $input_type ) . '" id="' . $this->esc_attr( $name ) . '" name="' . $this->esc_attr( $name ) . '" style="' . $field_style . '"' . $required_attr . '/>';
		}

		return '<div>' . $label_html . $field_html . '</div>';
	}
}
