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
	 * Resolve a plan item's requested variant against this archetype's
	 * registry ({@see Archetype::variants()} / {@see Archetype::legacy_variants()}).
	 * A recognized name wins; anything else (null, a typo, an unknown name)
	 * hashes the seed into the auto-pickable pool — deterministically, never
	 * randomly, because archetypes are pure functions (see PageAssembler's
	 * "archetypes stay pure functions" design note): identical content always
	 * yields the identical variant, while real pages still vary since no two
	 * pages share a heading. Legacy variants are reachable only by explicit
	 * request, never by the hash.
	 *
	 * @param string|null $variant Requested variant from the plan item, or null.
	 * @param string      $seed    Deterministic seed (typically the section heading).
	 * @return string A member of variants(), or an explicitly requested legacy variant.
	 */
	private function resolve_variant( ?string $variant, string $seed ): string {
		$pickable = $this->variants();
		if ( null !== $variant && ( in_array( $variant, $pickable, true ) || in_array( $variant, $this->legacy_variants(), true ) ) ) {
			return $variant;
		}
		return $pickable[ crc32( $seed ) % count( $pickable ) ];
	}

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
	 * @param bool        $fancy     Whether to render this heading in the "fancy" display face
	 *                               (Cormorant Garamond, italic) — see the `nfd-fancy-heading` class
	 *                               in `get_motion_css()`. Not a theme-preset `fontFamily` attribute:
	 *                               this WP version's Font block-support only offers theme.json-
	 *                               registered families in its own UI (confirmed live — no free-text
	 *                               custom font entry), so a specific, always-available Google Font
	 *                               is applied via `className` (a real, validated attribute) instead.
	 * @return string
	 */
	private function render_heading( string $text, int $level, ?string $text_slug, bool $center = false, bool $fancy = false ): string {
		// Class order and the absence of any inline style here are load-bearing:
		// WordPress's own core/heading save() never inlines text-align or a
		// named/preset text color — both are class-only — and it puts the
		// align class BEFORE "wp-block-heading". Anything else fails Gutenberg's
		// own re-validation in the editor ("Block contains unexpected or invalid
		// content") even though the rendered result looks identical, because the
		// stored HTML no longer matches what save() would regenerate from these
		// attrs. Confirmed against this WP version's actual validator output.
		$classes = array();
		$attrs   = array( 'level' => $level );
		if ( $fancy ) {
			// Custom className always first — matches this file's own convention
			// elsewhere (e.g. "nfd-scroll-fade wp-block-group...", "card-hover-lift
			// wp-block-group..."); confirmed live that class order isn't actually
			// enforced by this WP version's validator, but staying consistent avoids
			// two different conventions in the same codebase.
			$classes[]           = 'nfd-fancy-heading';
			$attrs['className'] = 'nfd-fancy-heading';
		}
		if ( $center ) {
			$classes[]          = 'has-text-align-center';
			$attrs['textAlign'] = 'center';
		}
		$classes[] = 'wp-block-heading';
		if ( null !== $text_slug ) {
			$classes[]          = 'has-' . $text_slug . '-color';
			$classes[]          = 'has-text-color';
			$attrs['textColor'] = $text_slug;
		}
		$tag = 'h' . $level;
		return $this->comment_wrap(
			'heading',
			$attrs,
			"<{$tag} class=\"" . implode( ' ', $classes ) . '">' . $this->esc_html( $text ) . "</{$tag}>"
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
		// No inline style here either — see render_heading()'s note; core/paragraph's
		// save() is class-only for align/textColor too, and this class order
		// (align, then color) already matches its output.
		$classes = array();
		$attrs   = array();
		if ( $center ) {
			$classes[]      = 'has-text-align-center';
			$attrs['align'] = 'center';
		}
		if ( null !== $text_slug ) {
			$classes[]          = 'has-' . $text_slug . '-color';
			$classes[]          = 'has-text-color';
			$attrs['textColor'] = $text_slug;
		}
		$class_attr = empty( $classes ) ? '' : ' class="' . implode( ' ', $classes ) . '"';
		return $this->comment_wrap(
			'paragraph',
			$attrs,
			"<p{$class_attr}>" . $this->esc_html( $text ) . '</p>'
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
		// No inline style, and this exact class order (link class, then text-color
		// slug, then background-color slug, then the two "has-*" markers, then
		// wp-element-button last; outline modifier before "wp-block-button" on the
		// wrapper) — matches core/button's actual save() output for named/preset
		// colors. See render_heading()'s note.
		$classes = array( 'wp-block-button__link' );
		$attrs   = array();

		if ( $outline ) {
			$attrs['className'] = 'is-style-outline';
		}
		if ( null !== $text_slug ) {
			$classes[]          = 'has-' . $text_slug . '-color';
			$attrs['textColor'] = $text_slug;
		}
		if ( null !== $bg_slug ) {
			$classes[]                = 'has-' . $bg_slug . '-background-color';
			$attrs['backgroundColor'] = $bg_slug;
		}
		if ( null !== $text_slug ) {
			$classes[] = 'has-text-color';
		}
		if ( null !== $bg_slug ) {
			$classes[] = 'has-background';
		}
		$classes[] = 'wp-element-button';

		$wrapper_class = ( $outline ? 'is-style-outline ' : '' ) . 'wp-block-button';

		$link = '<a class="' . implode( ' ', $classes ) . '" href="' . $this->esc_url( $url ) . '">' . $this->esc_html( $label ) . '</a>';

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
		$attrs = array(
			'layout' => array( 'type' => 'flex' ),
		);
		if ( $center ) {
			$attrs['layout']['justifyContent'] = 'center';
		}
		// No inline style, and this exact class order — core/buttons' actual
		// save() output for a flex layout never inlines display/gap/justify-content
		// (WordPress generates that CSS separately at render time from the
		// `layout` attr); it only emits these classes, in this order, with
		// "wp-block-buttons" LAST. See render_heading()'s note. The raw-markup
		// preview iframe doesn't get that generated CSS (block themes load it
		// per-page), so usePreviewIframe.ts's fallback stylesheet reproduces the
		// same flex/gap visuals there instead of inlining them into saved markup.
		$classes = 'is-layout-flex wp-block-buttons-is-layout-flex' . ( $center ? ' is-content-justification-center' : '' ) . ' wp-block-buttons';
		return $this->comment_wrap( 'buttons', $attrs, '<div class="' . $classes . '">' . $buttons_html . '</div>' );
	}

	/**
	 * Wrap `core/column` blocks in a `core/columns` row with a comfortable gap
	 * between the columns and (optionally) vertical centering.
	 *
	 * @param string  $columns_html      Concatenated `core/column` block markup.
	 * @param Context $ctx               Theme/conformance context.
	 * @param bool    $vertically_center Whether to vertically centre the columns.
	 * @param string  $gap_size          Logical spacing size for the inter-column gap.
	 * @param bool    $center_group      Currently a no-op: core/columns has no WordPress-native
	 *                                   way to horizontally centre an under-filled row without
	 *                                   an inline style that fails Gutenberg's own block
	 *                                   validation (see render_heading()'s note), so this no
	 *                                   longer affects the saved markup. Kept in the signature
	 *                                   since callers still pass it.
	 * @return string
	 */
	private function render_columns_wrap( string $columns_html, Context $ctx, bool $vertically_center = true, string $gap_size = 'md', bool $center_group = false ): string {
		// No inline style here either — core/columns' actual save() output never
		// inlines the blockGap/flex/align-items CSS (WordPress generates it
		// separately at render time from the `style.spacing.blockGap` /
		// `verticalAlignment` attrs); it's class-only. See render_heading()'s
		// note. usePreviewIframe.ts's fallback stylesheet covers the raw-markup
		// preview instead. $center_group has no WordPress attrs equivalent for a
		// columns row, so it no longer affects the saved markup.
		$attrs   = array(
			'style' => array(
				'spacing' => array(
					'blockGap' => $ctx->spacing_attr( $gap_size ),
				),
			),
		);
		$classes = array( 'wp-block-columns' );
		if ( $vertically_center ) {
			$attrs['verticalAlignment'] = 'center';
			$classes[]                  = 'are-vertically-aligned-center';
		}
		return $this->comment_wrap( 'columns', $attrs, '<div class="' . implode( ' ', $classes ) . '">' . $columns_html . '</div>' );
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

		// No inline background-color/color, and padding declared in top, right,
		// bottom, left order — see render_heading()'s note; core/group's actual
		// save() output for named/preset colors is class-only, and inlines
		// padding (the one property it does inline) in that exact order.
		// className "nfd-scroll-fade" (always first in the class list, before
		// "wp-block-group" — matches how a custom className is actually
		// serialized) replaces the old `data-aos="fade-up"` attribute: a raw
		// data-* attribute has no block-attribute backing and fails Gutenberg's
		// own validation just like the inline styles did, but a declared
		// className is a real, validated block attribute. The preview iframe
		// and the published front-end both still run an IntersectionObserver
		// keyed off this class (see get_motion_css()/usePreviewIframe.ts), so
		// the scroll-triggered fade-up entrance is unchanged visually.
		$group_attrs   = array(
			'className' => 'nfd-scroll-fade',
			'align'     => 'wide',
			'style'     => array(
				'spacing' => array(
					'padding' => array(
						'top'    => $ctx->spacing_attr( 'lg' ),
						'right'  => $ctx->spacing_attr( 'md' ),
						'bottom' => $ctx->spacing_attr( 'lg' ),
						'left'   => $ctx->spacing_attr( 'md' ),
					),
				),
			),
		);
		$group_classes = array( 'nfd-scroll-fade', 'wp-block-group', 'alignwide' );
		$group_style   = 'padding-top:' . $ctx->spacing_css( 'lg' ) . ';padding-right:' . $ctx->spacing_css( 'md' )
			. ';padding-bottom:' . $ctx->spacing_css( 'lg' ) . ';padding-left:' . $ctx->spacing_css( 'md' );

		if ( null !== $text_slug ) {
			$group_attrs['textColor'] = $text_slug;
			$group_classes[]          = 'has-' . $text_slug . '-color';
		}
		if ( null !== $background_slug ) {
			$group_attrs['backgroundColor'] = $background_slug;
			$group_classes[]                = 'has-' . $background_slug . '-background-color';
		}
		if ( null !== $text_slug ) {
			$group_classes[] = 'has-text-color';
		}
		if ( null !== $background_slug ) {
			$group_classes[] = 'has-background';
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
	 * Deliberately never set as a `backgroundColor` *attribute* — {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator::check_non_solid_colors()}
	 * only inspects the `textColor`/`backgroundColor` JSON attrs for non-solid
	 * values, never `style.color.gradient`, so this is safe by construction.
	 * It IS set as a real `style.color.gradient` block attribute (not a bare
	 * inline style with no attrs backing) — confirmed against this WP version's
	 * actual block validator, `core/group`'s save() output inlines a *custom*
	 * (non-preset) gradient value from that attr verbatim, so this round-trips
	 * cleanly through Gutenberg's own re-validation, unlike an unbacked inline
	 * style (see render_heading()'s note).
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
		// Same no-inline-color / top,right,bottom,left padding order / class
		// order / className-based scroll-fade rationale as render_section().
		$group_attrs   = array(
			'className' => 'nfd-scroll-fade',
			'align'     => 'wide',
			'style'     => array(
				'spacing' => array(
					'padding' => array(
						'top'    => $ctx->spacing_attr( 'lg' ),
						'right'  => $ctx->spacing_attr( 'md' ),
						'bottom' => $ctx->spacing_attr( 'lg' ),
						'left'   => $ctx->spacing_attr( 'md' ),
					),
				),
			),
		);
		$group_classes = array( 'nfd-scroll-fade', 'wp-block-group', 'alignwide' );

		$text_slug = $this->text_slug_for_background( $ctx, $background_slug );
		$gradient  = $this->gradient_style_declaration( $ctx, $background_slug );

		if ( null !== $text_slug ) {
			$group_attrs['textColor'] = $text_slug;
			$group_classes[]          = 'has-' . $text_slug . '-color';
		}
		// A gradient replaces the solid backgroundColor attr/class entirely —
		// confirmed against the real block validator, core/group's save()
		// drops the solid color class whenever style.color.gradient is set, so
		// declaring both would itself be a mismatch.
		if ( '' !== $gradient ) {
			$group_attrs['style']['color'] = array( 'gradient' => $gradient );
		} elseif ( null !== $background_slug ) {
			$group_attrs['backgroundColor'] = $background_slug;
			$group_classes[]                = 'has-' . $background_slug . '-background-color';
		}
		if ( null !== $text_slug ) {
			$group_classes[] = 'has-text-color';
		}
		if ( '' !== $gradient || null !== $background_slug ) {
			$group_classes[] = 'has-background';
		}

		// No inline background-color here even in the solid-color fallback —
		// same as render_section(): a named/preset backgroundColor is class-only
		// in core/group's actual save() output. Only the gradient (a genuinely
		// custom value) and padding are ever inlined.
		$group_style  = '' !== $gradient ? $gradient . ';' : '';
		$group_style .= 'padding-top:' . $ctx->spacing_css( 'lg' ) . ';padding-right:' . $ctx->spacing_css( 'md' )
			. ';padding-bottom:' . $ctx->spacing_css( 'lg' ) . ';padding-left:' . $ctx->spacing_css( 'md' );

		return $this->comment_wrap(
			'group',
			$group_attrs,
			'<div class="' . implode( ' ', $group_classes ) . '" style="' . $group_style . '">' . $inner . '</div>'
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
		// className "card-hover-lift" (always first in the class list — see
		// render_heading()'s note on custom className ordering) is real,
		// enqueued CSS: AIPageDesigner.php's get_motion_css() carries the
		// hover-gated lift/shadow transition (both preview and front-end), and
		// its own resting-state rule (border-radius/shadow/overflow — see the
		// same stylesheet) replaces what used to be inlined here directly.
		// $max_width maps to a small fixed set of "nfd-max-w-{n}" classes for
		// the same reason: WordPress's own core/group save() never inlines
		// max-width/margin for a bare group with no matching attr, so an
		// unbacked inline style here would fail Gutenberg's re-validation just
		// like the color/align ones did.
		$classes = array( 'card-hover-lift' );
		if ( null !== $max_width ) {
			$classes[] = 'nfd-max-w-' . $max_width;
		}
		$group_attrs   = array(
			'className' => implode( ' ', $classes ),
			'style'     => array(
				'spacing' => array(
					'padding' => array(
						'top'    => $ctx->spacing_attr( 'md' ),
						'right'  => $ctx->spacing_attr( 'md' ),
						'bottom' => $ctx->spacing_attr( 'md' ),
						'left'   => $ctx->spacing_attr( 'md' ),
					),
				),
			),
		);
		$group_classes = array_merge( $classes, array( 'wp-block-group' ) );
		$group_style   = 'padding-top:' . $ctx->spacing_css( 'md' ) . ';padding-right:' . $ctx->spacing_css( 'md' )
			. ';padding-bottom:' . $ctx->spacing_css( 'md' ) . ';padding-left:' . $ctx->spacing_css( 'md' );

		if ( null !== $text_slug ) {
			$group_attrs['textColor'] = $text_slug;
			$group_classes[]          = 'has-' . $text_slug . '-color';
		}
		if ( null !== $card_slug ) {
			$group_attrs['backgroundColor'] = $card_slug;
			$group_classes[]                = 'has-' . $card_slug . '-background-color';
		}
		if ( null !== $text_slug ) {
			$group_classes[] = 'has-text-color';
		}
		if ( null !== $card_slug ) {
			$group_classes[] = 'has-background';
		}

		return $this->comment_wrap(
			'group',
			$group_attrs,
			'<div class="' . implode( ' ', $group_classes ) . '" style="' . $group_style . '">' . $inner . '</div>'
		);
	}

	/**
	 * Render a short accent bar — a small colored `core/separator` used as a
	 * visual anchor above a column's title (the `accent-bar` grid treatment).
	 * Deliberately carries ONLY a background color, never a text color: a
	 * separator has no text, and pairing `textColor` with an identical
	 * `backgroundColor` (as WordPress itself does for colored separators)
	 * reads as a zero-contrast text/bg pair to legibility checks.
	 *
	 * @param Context     $ctx      Theme/conformance context.
	 * @param string|null $bar_slug Bar color slug, or null to render nothing.
	 * @param bool        $center   Whether to center the bar (for centered column content).
	 * @return string
	 */
	private function render_accent_bar( Context $ctx, ?string $bar_slug, bool $center = false ): string {
		if ( null === $bar_slug ) {
			return '';
		}
		// No inline style: core/separator's actual save() output for a
		// backgroundColor attr is class-only — see render_heading()'s note. It
		// also automatically pairs a matching textColor class of its own
		// (WordPress's own quirk for colored separators), which is why the
		// docblock above only ever sets backgroundColor. The 48x4 bar sizing
		// and centering have no WordPress-native representation, so they move
		// to real CSS keyed off "nfd-accent-bar"/"nfd-accent-bar-center"
		// (className is a real, validated block attribute; an unbacked inline
		// style is not — same reasoning as render_floating_card()'s max-width).
		$classes = array( 'nfd-accent-bar' );
		if ( $center ) {
			$classes[] = 'nfd-accent-bar-center';
		}
		$class_name = implode( ' ', $classes );
		return $this->comment_wrap(
			'separator',
			array(
				'backgroundColor' => $bar_slug,
				'className'       => $class_name,
			),
			'<hr class="' . $class_name . ' wp-block-separator has-text-color has-' . $bar_slug . '-color has-alpha-channel-opacity has-' . $bar_slug . '-background-color has-background"/>'
		);
	}

	/**
	 * Render a `core/cover` background image element for the `hasParallax`
	 * case — a plain `<div>`, not an `<img>`: real `core/cover` `save()`
	 * output drops the `<img class="wp-block-cover__image-background">`
	 * element entirely once `hasParallax` is true and instead paints the
	 * image via inline `background-image` on that same class (now a `<div>`),
	 * with `has-parallax` appended to its class list (and to the outer
	 * wrapper's). `background-position:50% 50%` is always present too —
	 * WordPress's own save() emits the centered default even with no
	 * `focalPoint` attribute set. Shared by every hasParallax cover archetype
	 * ({@see HeroCover}, {@see ParallaxBanner}) — confirmed live by
	 * round-tripping this exact markup through `wp.blocks.parse()`/
	 * `getSaveContent()` in this WP version's block editor.
	 *
	 * @param string $image_url Resolved image URL.
	 * @return string
	 */
	private function render_parallax_image( string $image_url ): string {
		if ( '' === $image_url ) {
			return '';
		}
		return '<div class="wp-block-cover__image-background has-parallax" style="background-position:50% 50%;background-image:url(' . $this->esc_url( $image_url ) . ')"></div>';
	}

	/**
	 * Render a `core/cover` dim overlay span for a given dim ratio, including
	 * `dimRatio:0` (a fully-transparent overlay, e.g. for a clean-photo
	 * variant) — the numeric `has-background-dim-{ratio}` class is present at
	 * every ratio including 0, confirmed live via the block editor's Code
	 * editor (the one ratio WordPress omits the numeric suffix for is exactly
	 * 50, its own default — never used by any caller here, so unhandled).
	 *
	 * @param int $dim_ratio Dim ratio percentage (0-100).
	 * @return string
	 */
	private function render_cover_dim_span( int $dim_ratio ): string {
		return '<span aria-hidden="true" class="wp-block-cover__background has-background-dim-' . $dim_ratio . ' has-background-dim"></span>';
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
		// core/image's actual save() output never inlines figure/img style —
		// see render_heading()'s note. The rounded "card image" look (rounded
		// corners, cropped fill, drop shadow) has no WordPress-native
		// representation, so it moves to real CSS keyed off "nfd-rounded-img"
		// (className is a real, validated block attribute).
		$attrs = array( 'sizeSlug' => 'large' );
		$class = 'wp-block-image size-large';
		if ( $rounded ) {
			$attrs['className'] = 'nfd-rounded-img';
			$class              = 'nfd-rounded-img ' . $class;
		}
		$img = '<img src="' . $this->esc_url( $image_url ) . '" alt=""/>';
		return $this->comment_wrap(
			'image',
			$attrs,
			'<figure class="' . $class . '">' . $img . '</figure>'
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
