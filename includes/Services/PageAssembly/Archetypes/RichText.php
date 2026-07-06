<?php
/**
 * Rich text section archetype: a plain heading + prose section, with an optional CTA.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * The escape hatch: a {@see RendersMarkup::render_section()} surface section
 * with just a body paragraph and an optional CTA button — for content that
 * doesn't fit any other archetype's shape.
 *
 * Content shape:
 * ```
 * [
 *   'heading' => string|null,
 *   'body'    => string (required),
 *   'cta'     => [ 'label' => string, 'url' => string ]|null,
 * ]
 * ```
 *
 * v1 supports a single variant, `default`.
 */
class RichText implements Archetype {

	use RendersMarkup;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'richText';
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
		$heading = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$body    = isset( $content['body'] ) ? (string) $content['body'] : '';
		$cta     = isset( $content['cta'] ) && is_array( $content['cta'] ) ? $content['cta'] : null;

		$inner = '';
		if ( null !== $cta && ! empty( $cta['label'] ) ) {
			$button_bg   = $this->contrasting_slug( $ctx, $background_slug );
			$button_text = $this->text_slug_for_background( $ctx, $button_bg );
			$inner       = $this->render_buttons_wrap( $this->render_button( (string) $cta['label'], isset( $cta['url'] ) ? (string) $cta['url'] : '#', $button_bg, $button_text ), false );
		}

		return $this->render_section( $heading, $body, $inner, $ctx, $background_slug );
	}
}
