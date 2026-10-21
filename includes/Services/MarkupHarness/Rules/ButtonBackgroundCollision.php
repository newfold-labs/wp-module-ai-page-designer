<?php
/**
 * Make a button visible when its background matches the section behind it.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * The model sometimes gives a button the same backgroundColor as the section it
 * sits in (e.g. an accent button inside an accent banner), so the button blends
 * into the background and is invisible. When a button's background slug equals
 * the nearest ancestor section's background slug, swap the button to a
 * contrasting slug and give it legible text, patching both the block JSON
 * attributes and the rendered classes/inline style.
 *
 * Uses native parse_blocks/serialize_blocks; no-ops if WordPress or a palette is
 * unavailable. Idempotent: the swapped button no longer matches its section.
 */
class ButtonBackgroundCollision implements Rule {

	/**
	 * Minimum brightness gap (0-255) for two colors to be considered contrasting.
	 *
	 * @var int
	 */
	const MIN_CONTRAST = 90;

	/**
	 * {@inheritDoc}
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Conformance context.
	 * @return string
	 */
	public function apply( string $markup, Context $ctx ): string {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $markup;
		}
		if ( ! $ctx->has_palette() ) {
			return $markup;
		}

		$blocks  = parse_blocks( $markup );
		$changed = $this->process( $blocks, null, $ctx );

		if ( ! $changed ) {
			return $markup;
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'button_background_collision';
	}

	/**
	 * Walk the tree tracking the inherited section background, fixing collisions.
	 *
	 * @param array<int, array<string, mixed>> $blocks       Parsed blocks (by reference).
	 * @param string|null                      $inherited_bg Nearest ancestor solid bg slug.
	 * @param Context                          $ctx          Context.
	 * @return bool Whether any block changed.
	 */
	private function process( array &$blocks, $inherited_bg, Context $ctx ): bool {
		$changed = false;
		foreach ( $blocks as &$block ) {
			$own_bg = isset( $block['attrs']['backgroundColor'] ) ? $block['attrs']['backgroundColor'] : null;

			if ( isset( $block['blockName'] ) && 'core/button' === $block['blockName']
				&& null !== $own_bg && null !== $inherited_bg && $own_bg === $inherited_bg ) {
				if ( $this->fix_button( $block, $inherited_bg, $ctx ) ) {
					$changed = true;
				}
				// Re-read after the fix so descendants inherit the new value.
				$own_bg = isset( $block['attrs']['backgroundColor'] ) ? $block['attrs']['backgroundColor'] : null;
			}

			// A block's own solid background becomes the inherited bg for its children.
			$applied_bg = ( null !== $own_bg && $ctx->is_solid_slug( $own_bg ) ) ? $own_bg : $inherited_bg;

			if ( ! empty( $block['innerBlocks'] ) ) {
				if ( $this->process( $block['innerBlocks'], $applied_bg, $ctx ) ) {
					$changed = true;
				}
			}
		}
		unset( $block );
		return $changed;
	}

	/**
	 * Swap a colliding button's background + text and patch its rendered element.
	 *
	 * @param array<string, mixed> $button     Button block (by reference).
	 * @param string               $section_bg The section background slug it collides with.
	 * @param Context              $ctx        Context.
	 * @return bool Whether the button changed.
	 */
	private function fix_button( array &$button, string $section_bg, Context $ctx ): bool {
		$new_bg = $this->contrasting_slug( $section_bg, $ctx );
		if ( null === $new_bg || $new_bg === $section_bg ) {
			return false;
		}

		$old_bg   = $button['attrs']['backgroundColor'];
		$old_text = isset( $button['attrs']['textColor'] ) ? $button['attrs']['textColor'] : null;

		// Legible text on the new button background.
		$new_text = $ctx->is_dark_slug( $new_bg ) ? $ctx->light_slug() : $ctx->dark_slug();

		$button['attrs']['backgroundColor'] = $new_bg;
		if ( null !== $new_text ) {
			$button['attrs']['textColor'] = $new_text;
		}

		$text_from = null !== $old_text ? $old_text : null;
		$text_to   = ( null !== $new_text && $new_text !== $old_text ) ? $new_text : null;

		if ( ! empty( $button['innerContent'] ) && is_array( $button['innerContent'] ) ) {
			$button['innerContent'] = array_map(
				function ( $chunk ) use ( $old_bg, $new_bg, $text_from, $text_to ) {
					if ( ! is_string( $chunk ) ) {
						return $chunk;
					}
					$chunk = $this->swap_bg( $chunk, $old_bg, $new_bg );
					if ( null !== $text_from && null !== $text_to ) {
						$chunk = $this->swap_text( $chunk, $text_from, $text_to );
					} elseif ( null !== $text_to ) {
						$chunk = $this->add_text( $chunk, $text_to );
					}
					return $chunk;
				},
				$button['innerContent']
			);
		}

		return true;
	}

	/**
	 * Pick a solid slug that visibly contrasts with the section background.
	 *
	 * @param string  $section_bg Section background slug.
	 * @param Context $ctx        Context.
	 * @return string|null
	 */
	private function contrasting_slug( string $section_bg, Context $ctx ) {
		$accent = $ctx->accent_slug();
		if ( null !== $accent && $accent !== $section_bg && $this->contrasts( $accent, $section_bg, $ctx ) ) {
			return $accent;
		}

		$opposite = $ctx->is_dark_slug( $section_bg ) ? $ctx->light_slug() : $ctx->dark_slug();
		if ( null !== $opposite && $opposite !== $section_bg ) {
			return $opposite;
		}

		// Fallback: whichever of light/dark differs from the section.
		foreach ( array( $ctx->dark_slug(), $ctx->light_slug() ) as $candidate ) {
			if ( null !== $candidate && $candidate !== $section_bg ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Whether two solid slugs differ enough in brightness to contrast.
	 *
	 * @param string  $a   First slug.
	 * @param string  $b   Second slug.
	 * @param Context $ctx Context.
	 * @return bool
	 */
	private function contrasts( string $a, string $b, Context $ctx ): bool {
		$color_a = $ctx->color_for_slug( $a );
		$color_b = $ctx->color_for_slug( $b );
		if ( null === $color_a || null === $color_b ) {
			return false;
		}
		return abs( $ctx->brightness( $color_a ) - $ctx->brightness( $color_b ) ) >= self::MIN_CONTRAST;
	}

	/**
	 * Swap a background slug's class + inline declaration.
	 *
	 * @param string $chunk   HTML chunk.
	 * @param string $old_bg  Old slug.
	 * @param string $new_bg  New slug.
	 * @return string
	 */
	private function swap_bg( string $chunk, string $old_bg, string $new_bg ): string {
		return str_replace(
			array(
				"has-{$old_bg}-background-color",
				"background-color:var(--wp--preset--color--{$old_bg})",
			),
			array(
				"has-{$new_bg}-background-color",
				"background-color:var(--wp--preset--color--{$new_bg})",
			),
			$chunk
		);
	}

	/**
	 * Swap a text slug's class + inline declaration.
	 *
	 * @param string $chunk     HTML chunk.
	 * @param string $old_text  Old slug.
	 * @param string $new_text  New slug.
	 * @return string
	 */
	private function swap_text( string $chunk, string $old_text, string $new_text ): string {
		$chunk = str_replace( "has-{$old_text}-color", "has-{$new_text}-color", $chunk );
		return preg_replace(
			'/(?<![a-z-])color:var\(--wp--preset--color--' . preg_quote( $old_text, '/' ) . '\)/',
			"color:var(--wp--preset--color--{$new_text})",
			$chunk
		);
	}

	/**
	 * Add a text color class + inline declaration to a button link missing one.
	 *
	 * @param string $chunk    HTML chunk.
	 * @param string $new_text New text slug.
	 * @return string
	 */
	private function add_text( string $chunk, string $new_text ): string {
		return preg_replace_callback(
			'/<a\b[^>]*\bwp-block-button__link\b[^>]*>/i',
			function ( $matches ) use ( $new_text ) {
				$tag = $matches[0];

				if ( false === stripos( $tag, 'has-text-color' ) ) {
					$tag = preg_replace( '/class="([^"]*)"/i', 'class="$1 has-' . $new_text . '-color has-text-color"', $tag, 1 );
				}

				$declaration = 'color:var(--wp--preset--color--' . $new_text . ')';
				if ( preg_match( '/\sstyle="/i', $tag ) ) {
					$tag = preg_replace( '/(\sstyle=")/i', '$1' . $declaration . ';', $tag, 1 );
				} else {
					$tag = preg_replace( '/(<a\b)/i', '$1 style="' . $declaration . '"', $tag, 1 );
				}

				return $tag;
			},
			$chunk,
			1
		);
	}
}
