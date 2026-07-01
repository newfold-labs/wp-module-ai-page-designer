<?php
/**
 * Shared markup-building helpers for archetypes.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Conveniences common to every archetype: Gutenberg comment-delimiter
 * wrapping, attribute JSON encoding, escaping (each falling back to a
 * plain-PHP equivalent when WordPress isn't loaded, mirroring the existing
 * pattern in {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\CoverImage::escape_url()}),
 * plus the section-wrapper and button/contrast logic every "wide group with
 * heading + intro + content" archetype (FeatureGrid, CtaBanner, StatsBar,
 * Testimonials, PricingTiers, FaqAccordion, AlternatingMediaText) needs. Kept
 * as one trait rather than a base class so archetypes stay free to extend
 * whatever shape fits them (e.g. HeroCover's `core/cover` doesn't use
 * {@see RendersMarkup::render_section()} at all).
 */
trait RendersMarkup {

	/**
	 * Wrap rendered HTML in a Gutenberg block comment delimiter pair.
	 *
	 * @param string               $block_name Block name without the `core/` prefix.
	 * @param array<string, mixed> $attrs      Block JSON attributes (empty array omits the JSON blob).
	 * @param string               $html       Rendered inner HTML.
	 * @return string
	 */
	private function comment_wrap( string $block_name, array $attrs, string $html ): string {
		$json = empty( $attrs ) ? '' : $this->json_encode( $attrs ) . ' ';
		return "<!-- wp:{$block_name} {$json}-->\n{$html}\n<!-- /wp:{$block_name} -->\n\n";
	}

	/**
	 * JSON-encode block attributes, using WordPress's encoder when available.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return string
	 */
	private function json_encode( array $attrs ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $attrs );
		}
		return json_encode( $attrs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Escape a URL for an HTML attribute, using WordPress when available.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function esc_url( string $url ): string {
		if ( function_exists( 'esc_url' ) ) {
			return esc_url( $url );
		}
		return str_replace( array( '"', '<', '>' ), array( '%22', '%3C', '%3E' ), $url );
	}

	/**
	 * Escape text for HTML output, using WordPress when available.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function esc_html( string $text ): string {
		if ( function_exists( 'esc_html' ) ) {
			return esc_html( $text );
		}
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Escape a value for an HTML attribute, using WordPress when available.
	 *
	 * @param string $value Attribute value.
	 * @return string
	 */
	private function esc_attr( string $value ): string {
		if ( function_exists( 'esc_attr' ) ) {
			return esc_attr( $value );
		}
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * The legible text slug for content sitting on a given background — light
	 * text on a dark background, dark text on anything else (including no
	 * background at all, i.e. the page's own light surface).
	 *
	 * @param Context     $ctx     Theme/conformance context.
	 * @param string|null $bg_slug Background slug, or null for the page's own surface.
	 * @return string|null
	 */
	private function text_slug_for_background( Context $ctx, ?string $bg_slug ): ?string {
		return ( null !== $bg_slug && $ctx->is_dark_slug( $bg_slug ) ) ? $ctx->light_slug() : $ctx->dark_slug();
	}

	/**
	 * Pick a slug that visibly contrasts with a given background — the theme
	 * accent when it differs, otherwise whichever of light/dark differs. Used
	 * for CTA buttons and highlighted elements so they never collide with the
	 * section behind them (the defect {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\ButtonBackgroundCollision}
	 * repairs — avoided here by construction instead).
	 *
	 * @param Context     $ctx     Theme/conformance context.
	 * @param string|null $bg_slug The background slug to contrast against.
	 * @return string|null
	 */
	private function contrasting_slug( Context $ctx, ?string $bg_slug ): ?string {
		$accent = $ctx->accent_slug();
		if ( null !== $accent && $accent !== $bg_slug ) {
			return $accent;
		}
		foreach ( array( $ctx->light_slug(), $ctx->dark_slug() ) as $candidate ) {
			if ( null !== $candidate && $candidate !== $bg_slug ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Render a heading.
	 *
	 * @param string      $text      Heading text.
	 * @param int         $level     Heading level (1-6).
	 * @param string|null $text_slug Text color slug, or null for the default.
	 * @param bool        $center    Whether to center-align the heading.
	 * @return string
	 */
	private function render_heading( string $text, int $level, ?string $text_slug, bool $center = false ): string {
		$classes = array( 'wp-block-heading' );
		$attrs   = array( 'level' => $level );
		$style   = '';
		if ( $center ) {
			$classes[]         = 'has-text-align-center';
			$attrs['textAlign'] = 'center';
		}
		if ( null !== $text_slug ) {
			$classes[]          = 'has-' . $text_slug . '-color';
			$classes[]          = 'has-text-color';
			$attrs['textColor'] = $text_slug;
			$style              = ' style="color:var(--wp--preset--color--' . $text_slug . ')"';
		}
		$tag = 'h' . $level;
		return $this->comment_wrap(
			'heading',
			$attrs,
			"<{$tag} class=\"" . implode( ' ', $classes ) . "\"{$style}>" . $this->esc_html( $text ) . "</{$tag}>"
		);
	}

	/**
	 * Render a paragraph.
	 *
	 * @param string      $text      Paragraph text.
	 * @param string|null $text_slug Text color slug, or null for the default.
	 * @param bool        $center    Whether to center-align the paragraph.
	 * @return string
	 */
	private function render_paragraph( string $text, ?string $text_slug, bool $center = false ): string {
		$classes = array();
		$attrs   = array();
		$style   = '';
		if ( $center ) {
			$classes[]       = 'has-text-align-center';
			$attrs['align'] = 'center';
		}
		if ( null !== $text_slug ) {
			$classes[]          = 'has-' . $text_slug . '-color';
			$classes[]          = 'has-text-color';
			$attrs['textColor'] = $text_slug;
			$style              = ' style="color:var(--wp--preset--color--' . $text_slug . ')"';
		}
		$class_attr = empty( $classes ) ? '' : ' class="' . implode( ' ', $classes ) . '"';
		return $this->comment_wrap(
			'paragraph',
			$attrs,
			"<p{$class_attr}{$style}>" . $this->esc_html( $text ) . '</p>'
		);
	}

	/**
	 * Render a single button.
	 *
	 * @param string      $label     Button label.
	 * @param string      $url       Button URL.
	 * @param string|null $bg_slug   Background slug, or null for no background (outline style).
	 * @param string|null $text_slug Text color slug.
	 * @param bool        $outline   Whether to render as an outline-style button.
	 * @return string
	 */
	private function render_button( string $label, string $url, ?string $bg_slug, ?string $text_slug, bool $outline = false ): string {
		$classes = array( 'wp-block-button__link', 'wp-element-button' );
		$attrs   = array();
		$style   = array();

		if ( $outline ) {
			$attrs['className'] = 'is-style-outline';
		}
		if ( null !== $bg_slug ) {
			$classes[]                = 'has-' . $bg_slug . '-background-color';
			$classes[]                = 'has-background';
			$attrs['backgroundColor'] = $bg_slug;
			$style[]                  = 'background-color:var(--wp--preset--color--' . $bg_slug . ')';
		}
		if ( null !== $text_slug ) {
			$classes[]          = 'has-' . $text_slug . '-color';
			$classes[]          = 'has-text-color';
			$attrs['textColor'] = $text_slug;
			$style[]            = 'color:var(--wp--preset--color--' . $text_slug . ')';
		}

		$style_attr    = empty( $style ) ? '' : ' style="' . implode( ';', $style ) . '"';
		$wrapper_class = 'wp-block-button' . ( $outline ? ' is-style-outline' : '' );

		$link = '<a class="' . implode( ' ', $classes ) . '" href="' . $this->esc_url( $url ) . '"' . $style_attr . '>' . $this->esc_html( $label ) . '</a>';

		return $this->comment_wrap( 'button', $attrs, '<div class="' . $wrapper_class . '">' . $link . '</div>' );
	}

	/**
	 * Render a `core/buttons` wrapper around one or more rendered buttons.
	 *
	 * @param string $buttons_html Concatenated `render_button()` output.
	 * @param bool   $center       Whether to center the button row.
	 * @return string
	 */
	private function render_buttons_wrap( string $buttons_html, bool $center = true ): string {
		$attrs = $center
			? array(
				'layout' => array(
					'type'           => 'flex',
					'justifyContent' => 'center',
				),
			)
			: array();
		return $this->comment_wrap( 'buttons', $attrs, '<div class="wp-block-buttons">' . $buttons_html . '</div>' );
	}

	/**
	 * Render a wide `core/group` section: optional centered heading + intro,
	 * then arbitrary inner content, with symmetric padding on all four sides
	 * (avoiding `asymmetric_padding:group`) and an optional background/text
	 * color pair. This is the shared shape behind every archetype that isn't
	 * a hero cover.
	 *
	 * @param string|null $heading         Section heading, or null/empty to omit.
	 * @param string|null $intro           Section intro paragraph, or null/empty to omit.
	 * @param string      $inner           Already-rendered inner block markup (columns, buttons, etc.).
	 * @param Context     $ctx             Theme/conformance context.
	 * @param string|null $background_slug Background slug, or null for the page's own surface.
	 * @return string
	 */
	private function render_section( ?string $heading, ?string $intro, string $inner, Context $ctx, ?string $background_slug ): string {
		$text_slug = $this->text_slug_for_background( $ctx, $background_slug );

		$group_attrs = array(
			'align' => 'wide',
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => $ctx->spacing_attr( 'lg' ),
						'bottom' => $ctx->spacing_attr( 'lg' ),
						'left'   => $ctx->spacing_attr( 'md' ),
						'right'  => $ctx->spacing_attr( 'md' ),
					),
				),
			),
		);
		$group_classes = array( 'wp-block-group', 'alignwide' );
		$group_style   = 'padding-top:' . $ctx->spacing_css( 'lg' ) . ';padding-bottom:' . $ctx->spacing_css( 'lg' )
			. ';padding-left:' . $ctx->spacing_css( 'md' ) . ';padding-right:' . $ctx->spacing_css( 'md' );

		if ( null !== $background_slug ) {
			$group_attrs['backgroundColor'] = $background_slug;
			$group_classes[]                = 'has-' . $background_slug . '-background-color';
			$group_classes[]                = 'has-background';
			$group_style                    .= ';background-color:var(--wp--preset--color--' . $background_slug . ')';
		}
		if ( null !== $text_slug ) {
			$group_attrs['textColor'] = $text_slug;
			$group_classes[]          = 'has-' . $text_slug . '-color';
			$group_classes[]          = 'has-text-color';
			$group_style              .= ';color:var(--wp--preset--color--' . $text_slug . ')';
		}

		$content = '';
		if ( ! empty( $heading ) ) {
			$content .= $this->render_heading( $heading, 2, null, true );
		}
		if ( ! empty( $intro ) ) {
			$content .= $this->render_paragraph( $intro, null, true );
		}
		$content .= $inner;

		return $this->comment_wrap(
			'group',
			$group_attrs,
			'<div class="' . implode( ' ', $group_classes ) . '" style="' . $group_style . '">' . $content . '</div>'
		);
	}
}
