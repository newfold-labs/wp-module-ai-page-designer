<?php
/**
 * Repair illegible block colors (non-solid or low-contrast text / background).
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * The model can apply a functional palette token (e.g. Twenty Twenty-Five's
 * accent-6 = color-mix(... 20%, transparent)) as a solid text or background
 * color, which renders invisible — or pair two solid colors with no contrast.
 *
 * For every block carrying a textColor / backgroundColor:
 *  - a non-solid background is swapped for a solid accent;
 *  - text that is non-solid, or low-contrast against the (resolved) background,
 *    is swapped for the legible role color (light on dark, dark on light).
 *
 * Both the block JSON attributes and the rendered element (classes + inline
 * style) are patched so the markup stays valid and WYSIWYG holds. Uses native
 * parse_blocks/serialize_blocks; no-ops if WordPress is unavailable.
 */
class ColorLegibility implements Rule {

	/**
	 * Minimum brightness gap (0-255) for text to be considered legible on a bg.
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

		$blocks = $this->process_blocks( parse_blocks( $markup ), $ctx );
		return serialize_blocks( $blocks );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'color_legibility';
	}

	/**
	 * Recursively repair a list of blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param Context                          $ctx    Context.
	 * @return array<int, array<string, mixed>>
	 */
	private function process_blocks( array $blocks, Context $ctx ): array {
		foreach ( $blocks as &$block ) {
			$block = $this->repair_block( $block, $ctx );
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->process_blocks( $block['innerBlocks'], $ctx );
			}
		}
		unset( $block );
		return $blocks;
	}

	/**
	 * Repair a single block's colors (attrs + rendered element).
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param Context              $ctx   Context.
	 * @return array<string, mixed>
	 */
	private function repair_block( array $block, Context $ctx ): array {
		$attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();
		$bg    = isset( $attrs['backgroundColor'] ) ? $attrs['backgroundColor'] : null;
		$text  = isset( $attrs['textColor'] ) ? $attrs['textColor'] : null;

		if ( null === $bg && null === $text ) {
			return $block;
		}

		list( $new_bg, $new_text ) = $this->resolve_legible_pair( $bg, $text, $ctx );

		$bg_changed   = null !== $bg && null !== $new_bg && $new_bg !== $bg;
		$text_changed = null !== $text && null !== $new_text && $new_text !== $text;
		if ( ! $bg_changed && ! $text_changed ) {
			return $block;
		}

		if ( $bg_changed ) {
			$block['attrs']['backgroundColor'] = $new_bg;
		}
		if ( $text_changed ) {
			$block['attrs']['textColor'] = $new_text;
		}

		$old_bg   = $bg_changed ? $bg : null;
		$old_text = $text_changed ? $text : null;
		$to_bg    = $bg_changed ? $new_bg : null;
		$to_text  = $text_changed ? $new_text : null;

		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$block['innerHTML'] = $this->swap_color_tokens( $block['innerHTML'], $old_bg, $to_bg, $old_text, $to_text );
		}
		if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			$block['innerContent'] = array_map(
				function ( $chunk ) use ( $old_bg, $to_bg, $old_text, $to_text ) {
					return is_string( $chunk )
						? $this->swap_color_tokens( $chunk, $old_bg, $to_bg, $old_text, $to_text )
						: $chunk;
				},
				$block['innerContent']
			);
		}

		return $block;
	}

	/**
	 * Decide the legible (backgroundColor, textColor) slug pair.
	 *
	 * @param string|null $bg_slug   Current background slug.
	 * @param string|null $text_slug Current text slug.
	 * @param Context     $ctx       Context.
	 * @return array{0:?string,1:?string} New (bg, text) slugs.
	 */
	private function resolve_legible_pair( $bg_slug, $text_slug, Context $ctx ): array {
		$new_bg = $bg_slug;

		// A non-solid background renders invisible — swap to a solid accent.
		if ( null !== $bg_slug && ! $ctx->is_solid_slug( $bg_slug ) ) {
			$replacement = $ctx->accent_slug();
			if ( null === $replacement ) {
				$replacement = $ctx->dark_slug();
			}
			if ( null !== $replacement ) {
				$new_bg = $replacement;
			}
		}

		$new_text = $text_slug;
		if ( null !== $text_slug ) {
			$bg_is_dark = ( null !== $new_bg && $ctx->is_solid_slug( $new_bg ) )
				? $ctx->is_dark_slug( $new_bg )
				: false; // No solid bg -> assume a light page background.

			if ( $this->text_is_illegible( $text_slug, $new_bg, $ctx ) ) {
				$desired = $bg_is_dark ? $ctx->light_slug() : $ctx->dark_slug();
				if ( null !== $desired ) {
					$new_text = $desired;
				}
			}
		}

		return array( $new_bg, $new_text );
	}

	/**
	 * Whether a text slug is illegible: non-solid, or low-contrast against the bg.
	 *
	 * @param string      $text_slug Text slug.
	 * @param string|null $bg_slug   Resolved background slug (or null).
	 * @param Context     $ctx       Context.
	 * @return bool
	 */
	private function text_is_illegible( string $text_slug, $bg_slug, Context $ctx ): bool {
		if ( ! $ctx->is_solid_slug( $text_slug ) ) {
			return true;
		}
		// Contrast only meaningfully checked against a known solid background.
		if ( null === $bg_slug || ! $ctx->is_solid_slug( $bg_slug ) ) {
			return false;
		}
		$text_color = $ctx->color_for_slug( $text_slug );
		$bg_color   = $ctx->color_for_slug( $bg_slug );
		if ( null === $text_color || null === $bg_color ) {
			return false;
		}
		return abs( $ctx->brightness( $text_color ) - $ctx->brightness( $bg_color ) ) < self::MIN_CONTRAST;
	}

	/**
	 * Replace a block's old color slug tokens (classes + inline) with new ones.
	 *
	 * @param string      $chunk    Rendered HTML chunk.
	 * @param string|null $old_bg   Old background slug.
	 * @param string|null $new_bg   New background slug.
	 * @param string|null $old_text Old text slug.
	 * @param string|null $new_text New text slug.
	 * @return string
	 */
	private function swap_color_tokens( string $chunk, $old_bg, $new_bg, $old_text, $new_text ): string {
		// Background first — its inline declaration contains the "color:" substring.
		if ( null !== $old_bg && null !== $new_bg && $old_bg !== $new_bg ) {
			$chunk = str_replace(
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

		if ( null !== $old_text && null !== $new_text && $old_text !== $new_text ) {
			$chunk = str_replace( "has-{$old_text}-color", "has-{$new_text}-color", $chunk );
			// Anchor so "background-color:var(...old_text)" is never matched.
			$chunk = preg_replace(
				'/(?<![a-z-])color:var\(--wp--preset--color--' . preg_quote( $old_text, '/' ) . '\)/',
				"color:var(--wp--preset--color--{$new_text})",
				$chunk
			);
		}

		return $chunk;
	}
}
