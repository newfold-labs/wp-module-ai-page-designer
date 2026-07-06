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
			$classes[]          = 'has-text-align-center';
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
			$classes[]      = 'has-text-align-center';
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
		// The layout attr alone only centers when WordPress generates layout CSS
		// for it — which the raw-markup preview never does and the front-end does
		// inconsistently for static markup. Emit the flex centering as classes +
		// inline styles too, so a "centered" button row is centered everywhere
		// by construction (text centered but buttons left-hugging reads broken).
		$classes = 'wp-block-buttons' . ( $center ? ' is-content-justification-center is-layout-flex wp-block-buttons-is-layout-flex' : '' );
		$style   = $center ? ' style="display:flex;flex-wrap:wrap;gap:0.5em;align-items:center;justify-content:center"' : '';
		return $this->comment_wrap( 'buttons', $attrs, '<div class="' . $classes . '"' . $style . '>' . $buttons_html . '</div>' );
	}

	/**
	 * Wrap `core/column` blocks in a `core/columns` row with a comfortable gap
	 * between the columns and (optionally) vertical centering. WordPress applies
	 * a column's default gap via generated layout CSS that the raw-markup preview
	 * doesn't render, so the gap and alignment are ALSO emitted as inline styles
	 * here — otherwise the columns render flush against each other in the preview
	 * (the "no spacing between the image and text" issue). Kept as an explicit
	 * inline `gap`/`align-items` so preview and published front-end match (WYSIWYG).
	 *
	 * @param string  $columns_html      Concatenated `core/column` block markup.
	 * @param Context $ctx               Theme/conformance context.
	 * @param bool    $vertically_center Whether to vertically centre the columns.
	 * @param string  $gap_size          Logical spacing size for the inter-column gap.
	 * @param bool    $center_group      Whether to horizontally centre the column row as a group.
	 * @return string
	 */
	private function render_columns_wrap( string $columns_html, Context $ctx, bool $vertically_center = true, string $gap_size = 'md', bool $center_group = false ): string {
		$attrs   = array(
			'style' => array(
				'spacing' => array(
					'blockGap' => $ctx->spacing_attr( $gap_size ),
				),
			),
		);
		$classes = array( 'wp-block-columns' );
		$style   = 'gap:' . $ctx->spacing_css( $gap_size );
		if ( $vertically_center ) {
			$attrs['verticalAlignment'] = 'center';
			$classes[]                  = 'are-vertically-aligned-center';
			$style                     .= ';align-items:center';
		}
		if ( $center_group ) {
			$style .= ';justify-content:center';
		}
		return $this->comment_wrap( 'columns', $attrs, '<div class="' . implode( ' ', $classes ) . '" style="' . $style . '">' . $columns_html . '</div>' );
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

		$group_attrs   = array(
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
			$group_style                   .= ';background-color:var(--wp--preset--color--' . $background_slug . ')';
		}
		if ( null !== $text_slug ) {
			$group_attrs['textColor'] = $text_slug;
			$group_classes[]          = 'has-' . $text_slug . '-color';
			$group_classes[]          = 'has-text-color';
			$group_style             .= ';color:var(--wp--preset--color--' . $text_slug . ')';
		}

		$content = '';
		if ( ! empty( $heading ) ) {
			$content .= $this->render_heading( $heading, 2, null, true );
		}
		if ( ! empty( $intro ) ) {
			$content .= $this->render_paragraph( $intro, null, true );
		}
		$content .= $inner;

		// data-aos: scroll-triggered entrance from the canonical motion
		// vocabulary — the preview iframe and the published front-end both run
		// an IntersectionObserver for it, so the section fades up once when it
		// enters the viewport on BOTH surfaces (WYSIWYG), and never loops.
		return $this->comment_wrap(
			'group',
			$group_attrs,
			'<div class="' . implode( ' ', $group_classes ) . '" style="' . $group_style . '" data-aos="fade-up">' . $content . '</div>'
		);
	}

	/**
	 * A `background:linear-gradient(...)` declaration built **only from real,
	 * registered theme palette slugs** — never an invented/derived color. Picks
	 * the theme's OWN swatch that is closest in brightness to the section's
	 * background (a subtle, same-tone pairing — e.g. two dark theme colors),
	 * so the gradient can never introduce a color the theme didn't already
	 * choose. If the theme has no other swatch close enough in tone (many
	 * themes register only one dark and one light color), no gradient is
	 * rendered at all — a flat, correct theme color beats an invented shade or
	 * a jarring role jump (e.g. dark-to-accent, which reads as "colors that
	 * don't match the theme" against some palettes).
	 *
	 * Deliberately never set as a `backgroundColor` *attribute* (only ever as a
	 * raw inline `style` declaration) — {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator::check_non_solid_colors()}
	 * only inspects the `textColor`/`backgroundColor` JSON attrs for non-solid
	 * values, never arbitrary inline CSS, so this is safe by construction as
	 * long as the block's own `backgroundColor` attr/class still carries a real
	 * solid slug (for editor/recolor compatibility) and this declaration is
	 * emitted *after* that solid `background-color:` declaration in the same
	 * style string, so it wins the cascade.
	 *
	 * @param Context     $ctx     Theme/conformance context.
	 * @param string|null $bg_slug Anchor background slug, or null for the dark fallback.
	 * @return string Empty string when no close-enough second theme color exists.
	 */
	private function gradient_style_declaration( Context $ctx, ?string $bg_slug ): string {
		if ( ! $ctx->has_palette() ) {
			return '';
		}
		$slug = $bg_slug ?? $ctx->dark_slug();
		$hex  = null !== $slug ? $ctx->color_for_slug( $slug ) : null;
		if ( null === $slug || null === $hex || ! Context::is_solid_color( $hex ) ) {
			return '';
		}

		$base_brightness = $ctx->brightness( $hex );
		$best_slug       = null;
		$best_delta      = null;
		// A same-tone pairing only — anything further apart is a different role
		// (e.g. dark vs. accent), which is the exact jump we're avoiding here.
		$max_delta = 60.0;

		foreach ( array( $ctx->dark_slug(), $ctx->light_slug(), $ctx->accent_slug(), $ctx->muted_light_slug() ) as $candidate ) {
			if ( null === $candidate || $candidate === $slug ) {
				continue;
			}
			$candidate_hex = $ctx->color_for_slug( $candidate );
			if ( null === $candidate_hex || ! Context::is_solid_color( $candidate_hex ) ) {
				continue;
			}
			$delta = abs( $ctx->brightness( $candidate_hex ) - $base_brightness );
			if ( $delta > $max_delta ) {
				continue;
			}
			if ( null === $best_delta || $delta < $best_delta ) {
				$best_slug  = $candidate;
				$best_delta = $delta;
			}
		}

		if ( null === $best_slug ) {
			return '';
		}

		return 'background:linear-gradient(135deg, var(--wp--preset--color--' . $slug . ') 0%, var(--wp--preset--color--' . $best_slug . ') 100%)';
	}

	/**
	 * Render a wide `core/group` section shell with symmetric padding and a
	 * solid background slug overlaid with a {@see gradient_style_declaration()}
	 * gradient — the shared "modern" section backdrop used by the `split` hero
	 * and `floating-card` CTA variants. Unlike {@see render_section()}, this
	 * has no heading/intro slots of its own; callers supply the full inner body.
	 *
	 * @param string      $inner           Rendered inner block markup.
	 * @param Context     $ctx             Theme/conformance context.
	 * @param string|null $background_slug Anchor background slug (the solid color under the gradient).
	 * @return string
	 */
	private function render_gradient_section( string $inner, Context $ctx, ?string $background_slug ): string {
		$group_attrs   = array(
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

		$text_slug = $this->text_slug_for_background( $ctx, $background_slug );

		if ( null !== $background_slug ) {
			$group_attrs['backgroundColor'] = $background_slug;
			$group_classes[]                = 'has-' . $background_slug . '-background-color';
			$group_classes[]                = 'has-background';
			$group_style                   .= ';background-color:var(--wp--preset--color--' . $background_slug . ')';
		}
		if ( null !== $text_slug ) {
			$group_attrs['textColor'] = $text_slug;
			$group_classes[]          = 'has-' . $text_slug . '-color';
			$group_classes[]          = 'has-text-color';
			$group_style             .= ';color:var(--wp--preset--color--' . $text_slug . ')';
		}

		$gradient = $this->gradient_style_declaration( $ctx, $background_slug );
		if ( '' !== $gradient ) {
			$group_style .= ';' . $gradient;
		}

		// Same scroll-triggered entrance as render_section() — see there.
		return $this->comment_wrap(
			'group',
			$group_attrs,
			'<div class="' . implode( ' ', $group_classes ) . '" style="' . $group_style . '" data-aos="fade-up">' . $inner . '</div>'
		);
	}

	/**
	 * Render a "floating card": a solid-background `core/group` with symmetric
	 * padding, rounded corners, and a drop shadow — a generalization of
	 * {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\PricingTiers::render_card()}'s
	 * proven shape, extended with the visual "card" treatment for the `split`
	 * hero and `floating-card` CTA variants.
	 *
	 * @param string      $inner     Rendered inner block markup.
	 * @param Context     $ctx       Theme/conformance context.
	 * @param string|null $card_slug Card background slug, or null for no background.
	 * @param string|null $text_slug Card text color slug, or null for the default.
	 * @param int|null    $max_width Optional max-width in px, centered via auto margins (e.g. so a card floats centered inside a wider section rather than stretching full width).
	 * @return string
	 */
	private function render_floating_card( string $inner, Context $ctx, ?string $card_slug, ?string $text_slug, ?int $max_width = null ): string {
		$group_attrs   = array(
			// card-hover-lift: hover-gated motion from the canonical vocabulary
			// (subtle lift + shadow on hover, defined by get_motion_css() for
			// both preview and front-end). Hover-only — never plays on load,
			// never loops.
			'className' => 'card-hover-lift',
			'style'     => array(
				'spacing' => array(
					'padding' => array(
						'top'    => $ctx->spacing_attr( 'md' ),
						'bottom' => $ctx->spacing_attr( 'md' ),
						'left'   => $ctx->spacing_attr( 'md' ),
						'right'  => $ctx->spacing_attr( 'md' ),
					),
				),
			),
		);
		$group_classes = array( 'wp-block-group', 'card-hover-lift' );
		$group_style   = 'padding-top:' . $ctx->spacing_css( 'md' ) . ';padding-bottom:' . $ctx->spacing_css( 'md' )
			. ';padding-left:' . $ctx->spacing_css( 'md' ) . ';padding-right:' . $ctx->spacing_css( 'md' )
			. ';border-radius:16px;box-shadow:0 20px 60px -15px rgba(0,0,0,.35);overflow:hidden';
		if ( null !== $max_width ) {
			$group_style .= ';max-width:' . $max_width . 'px;margin-left:auto;margin-right:auto';
		}

		if ( null !== $card_slug ) {
			$group_attrs['backgroundColor'] = $card_slug;
			$group_classes[]                = 'has-' . $card_slug . '-background-color';
			$group_classes[]                = 'has-background';
			$group_style                   .= ';background-color:var(--wp--preset--color--' . $card_slug . ')';
		}
		if ( null !== $text_slug ) {
			$group_attrs['textColor'] = $text_slug;
			$group_classes[]          = 'has-' . $text_slug . '-color';
			$group_classes[]          = 'has-text-color';
			$group_style             .= ';color:var(--wp--preset--color--' . $text_slug . ')';
		}

		return $this->comment_wrap(
			'group',
			$group_attrs,
			'<div class="' . implode( ' ', $group_classes ) . '" style="' . $group_style . '">' . $inner . '</div>'
		);
	}

	/**
	 * Render a `core/image` block.
	 *
	 * @param string $image_url Resolved image URL.
	 * @param bool   $rounded   Whether to round the corners (the modern "card image" look).
	 * @return string
	 */
	private function render_image_block( string $image_url, bool $rounded = false ): string {
		if ( '' === $image_url ) {
			return '';
		}
		$img_style = 'width:100%;height:100%;object-fit:cover' . ( $rounded ? ';border-radius:16px' : '' );
		$img       = '<img src="' . $this->esc_url( $image_url ) . '" alt="" style="' . $img_style . '"/>';
		return $this->comment_wrap(
			'image',
			array( 'sizeSlug' => 'large' ),
			'<figure class="wp-block-image size-large"' . ( $rounded ? ' style="border-radius:16px;overflow:hidden;box-shadow:0 12px 32px -12px rgba(0,0,0,.25)"' : '' ) . '>' . $img . '</figure>'
		);
	}

	/**
	 * The background slug for a light "lifted card" sitting inside a section —
	 * the quiet counterpart to {@see contrasting_slug()} (which picks the LOUD
	 * accent, right for CTAs but garish when every grid item is a card). Prefers
	 * the muted-light swatch, falls back to the light slug, and returns null
	 * when nothing differs from the section's own background (the card then
	 * relies on its border-radius/shadow alone — still visually lifted, and a
	 * transparent card can never collide with anything by construction).
	 *
	 * @param Context     $ctx             Theme/conformance context.
	 * @param string|null $background_slug The section's own background slug.
	 * @return string|null
	 */
	private function card_slug_for_section( Context $ctx, ?string $background_slug ): ?string {
		foreach ( array( $ctx->muted_light_slug(), $ctx->light_slug() ) as $candidate ) {
			if ( null !== $candidate && $candidate !== $background_slug ) {
				return $candidate;
			}
		}
		return null;
	}
}
