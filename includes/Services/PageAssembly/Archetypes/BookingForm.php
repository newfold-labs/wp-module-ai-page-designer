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
 * Auto-pickable variants:
 *  - `card` (default): the form centered inside a
 *    {@see RendersMarkup::render_floating_card()} card (max-width 640, quiet
 *    {@see RendersMarkup::card_slug_for_section()} swatch) — field borders,
 *    text, and the submit button all derive from the CARD's background, so
 *    legibility holds by construction one level down, same chaining as
 *    {@see PricingTiers}'s highlighted tier.
 *  - `split`: heading + intro in the left column, the form card in the
 *    right — the classic contact-page layout. Same card/legibility chaining
 *    as `card`; the column constrains the card's width instead of a
 *    max-width.
 *
 * Legacy (explicit-only):
 *  - `stacked`: the original full-width flat form, reachable only via an
 *    explicit `variant: "stacked"` plan item.
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
	 * Auto-pickable variant names — see the class docblock.
	 *
	 * @var string[]
	 */
	const VARIANTS = array( 'card', 'split' );

	/**
	 * Explicit-only legacy variants, never auto-picked.
	 *
	 * @var string[]
	 */
	const LEGACY_VARIANTS = array( 'stacked' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'bookingForm';
	}

	/**
	 * {@inheritDoc}
	 */
	public function variants(): array {
		return self::VARIANTS;
	}

	/**
	 * {@inheritDoc}
	 */
	public function legacy_variants(): array {
		return self::LEGACY_VARIANTS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * No fixed default — see {@see FeatureGrid::default_background()} for why.
	 *
	 * @param Context $ctx Theme/conformance context.
	 * @return string|null
	 */
	public function default_background( Context $ctx ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $content         Fully-resolved slot content (see the concrete class docblock for its shape).
	 * @param string|null          $variant         Requested variant, or null for the archetype's default.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug Palette slug to use as this section's background, or null for none.
	 * @return string Gutenberg block markup for one section.
	 */
	public function render( array $content, ?string $variant, Context $ctx, ?string $background_slug ): string {
		$heading      = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$intro        = isset( $content['intro'] ) ? (string) $content['intro'] : '';
		$fields       = isset( $content['fields'] ) && is_array( $content['fields'] ) ? $content['fields'] : array();
		$submit_label = isset( $content['submitLabel'] ) && '' !== trim( (string) $content['submitLabel'] )
			? (string) $content['submitLabel']
			: 'Submit';

		$variant = $this->resolve_variant( $variant, $heading );

		$as_card   = 'stacked' !== $variant;
		$card_slug = $as_card ? $this->card_slug_for_section( $ctx, $background_slug ) : null;
		$card_text = null !== $card_slug ? $this->text_slug_for_background( $ctx, $card_slug ) : null;
		// Everything inside the form colors against the surface it actually
		// sits on: the card when present, the section otherwise.
		$form_surface = $as_card && null !== $card_slug ? $card_slug : $background_slug;

		$inner = $this->render_form_block( $fields, $submit_label, $ctx, $form_surface );

		if ( 'split' === $variant ) {
			$text_slug = $this->text_slug_for_background( $ctx, $background_slug );

			$left_inner = '';
			if ( '' !== $heading ) {
				$left_inner .= $this->render_heading( $heading, 2, $text_slug );
			}
			if ( '' !== $intro ) {
				$left_inner .= $this->render_paragraph( $intro, $text_slug );
			}

			$right_inner = $this->render_floating_card( $inner, $ctx, $card_slug, $card_text );

			$columns  = $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $left_inner . '</div>' );
			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $right_inner . '</div>' );

			return $this->render_section( null, null, $this->render_columns_wrap( $columns, $ctx ), $ctx, $background_slug );
		}

		if ( $as_card ) {
			$inner = $this->render_floating_card( $inner, $ctx, $card_slug, $card_text, 640 );
		}

		return $this->render_section( $heading, $intro, $inner, $ctx, $background_slug );
	}

	/**
	 * Render the `core/html` form block: every field and the submit button
	 * carry explicit theme-derived inline styles computed against the surface
	 * the form actually sits on.
	 *
	 * Public because it is also the single definition of "what a form looks
	 * like" for {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\UnrenderableContentFallback},
	 * which substitutes a bare form for a plugin form block the site cannot
	 * render. That rule needs the form WITHOUT the surrounding section (it is
	 * replacing a block nested inside the model's own section), so it cannot go
	 * through {@see BookingForm::render()}.
	 *
	 * @param array<int, mixed> $fields       Field definitions.
	 * @param string            $submit_label Submit button label.
	 * @param Context           $ctx          Theme/conformance context.
	 * @param string|null       $form_surface Slug of the surface behind the form (card or section).
	 * @return string
	 */
	public function render_form_block( array $fields, string $submit_label, Context $ctx, ?string $form_surface ): string {
		$field_html = '';
		foreach ( $fields as $field ) {
			if ( is_array( $field ) ) {
				$field_html .= $this->render_field( $field, $ctx, $form_surface );
			}
		}

		$button_bg    = $this->contrasting_slug( $ctx, $form_surface );
		$button_text  = $this->text_slug_for_background( $ctx, $button_bg );
		$button_style = 'background-color:' . ( null !== $button_bg ? 'var(--wp--preset--color--' . $button_bg . ')' : '#000' ) . ';'
			. 'color:' . ( null !== $button_text ? 'var(--wp--preset--color--' . $button_text . ')' : '#fff' ) . ';'
			. 'border:none;border-radius:4px;cursor:pointer;padding:' . $ctx->spacing_css( 'sm' ) . ' ' . $ctx->spacing_css( 'md' ) . ';';

		$form = '<form>' . $field_html
			. '<button type="submit" style="' . $button_style . '">' . $this->esc_html( $submit_label ) . '</button>'
			. '</form>';

		return $this->comment_wrap( 'html', array(), $form );
	}

	/**
	 * Render a single form field: label + input/textarea/select, all carrying
	 * explicit theme-derived inline styles.
	 *
	 * @param array<string, mixed> $field           Field definition.
	 * @param Context              $ctx             Theme/conformance context.
	 * @param string|null          $background_slug The section's own background slug.
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
