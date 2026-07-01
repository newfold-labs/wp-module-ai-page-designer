<?php
/**
 * Theme/conformance context for the markup harness.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness;

/**
 * Resolves theme-derived roles (dark/light/accent palette slugs, fonts) once so
 * rules and the validator share a single source of truth.
 *
 * Constructable from an explicit palette array (for unit tests) or from the
 * active theme via {@see Context::from_theme()}.
 */
class Context {

	/**
	 * Deduplicated palette: list of array{ slug:string, color:string, name:string }.
	 *
	 * @var array<int, array<string, string>>
	 */
	private $palette;

	/**
	 * Dark text / dark background slug (lowest brightness).
	 *
	 * @var string|null
	 */
	private $dark_slug;

	/**
	 * Light text / light background slug (highest brightness).
	 *
	 * @var string|null
	 */
	private $light_slug;

	/**
	 * Brand accent slug (mid brightness, distinct from page bg and text).
	 *
	 * @var string|null
	 */
	private $accent_slug;

	/**
	 * Secondary light-ish slug distinct from dark/light/accent, used for the
	 * surface/surface-alt background rhythm between sections. Null when the
	 * palette has no spare swatch to use for it.
	 *
	 * @var string|null
	 */
	private $muted_light_slug;

	/**
	 * Default horizontal section padding.
	 *
	 * @var string
	 */
	private $section_padding_x = '32px';

	/**
	 * Theme spacing preset slugs (from theme.json spacingSizes), ascending by size.
	 *
	 * @var string[]
	 */
	private $spacing_slugs = array();

	/**
	 * Fallback horizontal padding scale (px) used when the theme has no
	 * spacingSizes preset scale to resolve tokens from.
	 *
	 * @var array<string, string>
	 */
	private const FALLBACK_SPACING = array(
		'sm' => '24px',
		'md' => '48px',
		'lg' => '80px',
		'xl' => '120px',
	);

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, string>> $palette       Raw palette of { slug, color, name }.
	 * @param string[]                          $spacing_slugs Theme spacing preset slugs (ascending by size).
	 */
	public function __construct( array $palette = array(), array $spacing_slugs = array() ) {
		$this->palette = $this->deduplicate_palette(
			array_values(
				array_map(
					static function ( $swatch ) {
						// Normalize underscores to hyphens so lookups match the
						// rendered `has-<slug>-color` classes WordPress emits.
						$swatch['slug'] = str_replace( '_', '-', $swatch['slug'] );
						return $swatch;
					},
					array_filter(
						$palette,
						static function ( $swatch ) {
							return ! empty( $swatch['slug'] ) && ! empty( $swatch['color'] );
						}
					)
				)
			)
		);
		$this->spacing_slugs = array_values( $spacing_slugs );

		$this->resolve_roles();
	}

	/**
	 * Build a Context from the active theme's global settings (theme.json).
	 *
	 * @return Context
	 */
	public static function from_theme(): Context {
		$palette       = array();
		$spacing_slugs = array();

		if ( function_exists( 'wp_get_global_settings' ) ) {
			$settings = wp_get_global_settings();
			$groups   = isset( $settings['color']['palette'] ) ? $settings['color']['palette'] : array();
			// Prefer theme, then default, then custom — first occurrence of a slug wins.
			foreach ( array( 'theme', 'default', 'custom' ) as $origin ) {
				if ( ! empty( $groups[ $origin ] ) && is_array( $groups[ $origin ] ) ) {
					foreach ( $groups[ $origin ] as $swatch ) {
						$palette[] = $swatch;
					}
				}
			}

			$sizes = isset( $settings['spacing']['spacingSizes'] ) ? $settings['spacing']['spacingSizes'] : array();
			foreach ( array( 'theme', 'default', 'custom' ) as $origin ) {
				if ( ! empty( $sizes[ $origin ] ) && is_array( $sizes[ $origin ] ) ) {
					foreach ( $sizes[ $origin ] as $size ) {
						if ( ! empty( $size['slug'] ) ) {
							$spacing_slugs[] = (string) $size['slug'];
						}
					}
					break; // First non-empty origin wins — avoid mixing scales.
				}
			}
		}

		return new self( $palette, $spacing_slugs );
	}

	/**
	 * Resolve dark/light/accent slugs by brightness, mirroring the prompt's role logic.
	 *
	 * @return void
	 */
	private function resolve_roles() {
		if ( empty( $this->palette ) ) {
			return;
		}

		// Only SOLID colors can serve as a role. Functional tokens (color-mix,
		// var, transparent) render invisible when applied as a solid text or
		// background color, so they must never be chosen as dark/light/accent —
		// e.g. Twenty Twenty-Five's accent-6 = color-mix(... 20%, transparent).
		$solid = array_values(
			array_filter(
				$this->palette,
				static function ( $swatch ) {
					return self::is_solid_color( $swatch['color'] );
				}
			)
		);
		if ( empty( $solid ) ) {
			return;
		}

		$sorted = $solid;
		usort(
			$sorted,
			function ( $a, $b ) {
				return $this->hex_brightness( $a['color'] ) <=> $this->hex_brightness( $b['color'] );
			}
		);

		$this->dark_slug  = $sorted[0]['slug'];
		$this->light_slug = $sorted[ count( $sorted ) - 1 ]['slug'];

		// Brand accent: mid-range swatch that is not a light page background.
		$mids = array_values(
			array_filter(
				array_slice( $sorted, 1, max( 0, count( $sorted ) - 2 ) ),
				function ( $swatch ) {
					return $this->hex_brightness( $swatch['color'] ) < 180;
				}
			)
		);

		if ( ! empty( $mids ) ) {
			$this->accent_slug = $mids[ (int) floor( count( $mids ) / 2 ) ]['slug'];
		} elseif ( count( $sorted ) > 1 ) {
			$this->accent_slug = $sorted[1]['slug'];
		} else {
			$this->accent_slug = null;
		}

		// Muted-light: a light-ish swatch (brightness >= 180) distinct from both
		// light_slug (the brightest, excluded via the slice) and accent_slug, used
		// to alternate section backgrounds without repeating the page's own bg.
		$muted_candidates = array_values(
			array_filter(
				array_slice( $sorted, 0, count( $sorted ) - 1 ),
				function ( $swatch ) {
					return $this->hex_brightness( $swatch['color'] ) >= 180
						&& $swatch['slug'] !== $this->accent_slug;
				}
			)
		);
		$this->muted_light_slug = empty( $muted_candidates )
			? null
			: $muted_candidates[ count( $muted_candidates ) - 1 ]['slug'];
	}

	/**
	 * Deduplicate palette by hex value, keeping the first occurrence.
	 *
	 * @param array<int, array<string, string>> $palette Raw palette.
	 * @return array<int, array<string, string>>
	 */
	private function deduplicate_palette( array $palette ): array {
		$seen   = array();
		$result = array();
		foreach ( $palette as $swatch ) {
			$key = strtolower( $swatch['color'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$result[]     = $swatch;
		}
		return $result;
	}

	/**
	 * Perceived brightness (0-255) of a hex color.
	 *
	 * @param string $hex Hex color.
	 * @return float
	 */
	private function hex_brightness( string $hex ): float {
		$clean = ltrim( strtolower( trim( $hex ) ), '#' );
		// Expand 3-digit shorthand (#fff -> ffffff).
		if ( 3 === strlen( $clean ) || 4 === strlen( $clean ) ) {
			$clean = preg_replace( '/(.)/', '$1$1', substr( $clean, 0, 3 ) );
		}
		if ( strlen( $clean ) < 6 || ! ctype_xdigit( substr( $clean, 0, 6 ) ) ) {
			return 128.0;
		}
		$r = hexdec( substr( $clean, 0, 2 ) );
		$g = hexdec( substr( $clean, 2, 2 ) );
		$b = hexdec( substr( $clean, 4, 2 ) );
		return ( $r * 299 + $g * 587 + $b * 114 ) / 1000;
	}

	/**
	 * Whether a CSS color value is a solid, opaque color usable as a background
	 * or text color. Rejects functional tokens (color-mix, var, gradients),
	 * transparent, and alpha'd values that would render invisible.
	 *
	 * @param string $color CSS color value.
	 * @return bool
	 */
	public static function is_solid_color( string $color ): bool {
		$color = strtolower( trim( $color ) );

		if ( '' === $color ) {
			return false;
		}
		if ( false !== strpos( $color, 'color-mix' )
			|| false !== strpos( $color, 'var(' )
			|| false !== strpos( $color, 'gradient' )
			|| false !== strpos( $color, 'currentcolor' )
			|| 'transparent' === $color ) {
			return false;
		}
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $color ) ) {
			return true;
		}
		if ( preg_match( '/^#([0-9a-f]{4}|[0-9a-f]{8})$/', $color ) ) {
			$hex   = ltrim( $color, '#' );
			$alpha = 4 === strlen( $hex )
				? hexdec( str_repeat( substr( $hex, 3, 1 ), 2 ) )
				: hexdec( substr( $hex, 6, 2 ) );
			return $alpha >= 0xF0;
		}
		if ( preg_match( '/^(rgb|hsl)\(/', $color ) ) {
			return true;
		}
		if ( preg_match( '/^(rgba|hsla)\(.*,\s*([0-9.]+)\s*\)$/', $color, $matches ) ) {
			return (float) $matches[2] >= 0.9;
		}
		if ( preg_match( '/^[a-z]+$/', $color ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Resolve a palette slug to its color value.
	 *
	 * @param string $slug Palette slug (hyphenated).
	 * @return string|null Color value, or null when the slug is unknown.
	 */
	public function color_for_slug( string $slug ) {
		$slug = str_replace( '_', '-', $slug );
		foreach ( $this->palette as $swatch ) {
			if ( $swatch['slug'] === $slug ) {
				return $swatch['color'];
			}
		}
		return null;
	}

	/**
	 * Whether a palette slug resolves to a solid, usable color.
	 *
	 * @param string $slug Palette slug.
	 * @return bool
	 */
	public function is_solid_slug( string $slug ): bool {
		$color = $this->color_for_slug( $slug );
		return null !== $color && self::is_solid_color( $color );
	}

	/**
	 * Whether a palette slug resolves to a dark color (brightness < 128).
	 *
	 * @param string $slug Palette slug.
	 * @return bool
	 */
	public function is_dark_slug( string $slug ): bool {
		$color = $this->color_for_slug( $slug );
		if ( null === $color ) {
			return false;
		}
		return $this->hex_brightness( $color ) < 128;
	}

	/**
	 * Perceived brightness (0-255) of a color value (hex; non-hex returns 128).
	 *
	 * @param string $color Color value.
	 * @return float
	 */
	public function brightness( string $color ): float {
		return $this->hex_brightness( $color );
	}

	/**
	 * Whether a usable palette is available.
	 *
	 * @return bool
	 */
	public function has_palette(): bool {
		return ! empty( $this->palette );
	}

	/**
	 * Dark slug accessor.
	 *
	 * @return string|null
	 */
	public function dark_slug() {
		return $this->dark_slug;
	}

	/**
	 * Light slug accessor.
	 *
	 * @return string|null
	 */
	public function light_slug() {
		return $this->light_slug;
	}

	/**
	 * Accent slug accessor.
	 *
	 * @return string|null
	 */
	public function accent_slug() {
		return $this->accent_slug;
	}

	/**
	 * Secondary light-ish slug for surface/surface-alt background rhythm, or
	 * null when the palette has no spare swatch to use for it.
	 *
	 * @return string|null
	 */
	public function muted_light_slug() {
		return $this->muted_light_slug;
	}

	/**
	 * Resolve a logical spacing size to a value usable in a block-attribute
	 * JSON blob (e.g. `"padding":{"top":"var:preset|spacing|60"}`).
	 *
	 * Falls back to a literal px value when the theme has no spacing scale, so
	 * callers never need to branch on availability.
	 *
	 * @param string $size Logical size: 'sm'|'md'|'lg'|'xl'.
	 * @return string
	 */
	public function spacing_attr( string $size ): string {
		$slug = $this->resolve_spacing_slug( $size );
		return null === $slug ? self::FALLBACK_SPACING[ $size ] : 'var:preset|spacing|' . $slug;
	}

	/**
	 * Resolve a logical spacing size to a value usable directly in inline CSS
	 * (e.g. `padding-top:var(--wp--preset--spacing--60)`).
	 *
	 * Falls back to the same literal px value as {@see spacing_attr()} when the
	 * theme has no spacing scale, so the two always agree.
	 *
	 * @param string $size Logical size: 'sm'|'md'|'lg'|'xl'.
	 * @return string
	 */
	public function spacing_css( string $size ): string {
		$slug = $this->resolve_spacing_slug( $size );
		return null === $slug ? self::FALLBACK_SPACING[ $size ] : 'var(--wp--preset--spacing--' . $slug . ')';
	}

	/**
	 * Map a logical spacing size to a theme spacing-scale slug by relative
	 * position (sm=25%, md=50%, lg=75%, xl=last), or null when the theme has
	 * no spacing scale to resolve from.
	 *
	 * @param string $size Logical size: 'sm'|'md'|'lg'|'xl'.
	 * @return string|null
	 */
	private function resolve_spacing_slug( string $size ) {
		if ( empty( $this->spacing_slugs ) ) {
			return null;
		}
		$count = count( $this->spacing_slugs );
		$index = array(
			'sm' => (int) round( 0.25 * ( $count - 1 ) ),
			'md' => (int) round( 0.5 * ( $count - 1 ) ),
			'lg' => (int) round( 0.75 * ( $count - 1 ) ),
			'xl' => $count - 1,
		);
		return isset( $index[ $size ] ) ? $this->spacing_slugs[ $index[ $size ] ] : null;
	}

	/**
	 * Default horizontal section padding value.
	 *
	 * @return string
	 */
	public function section_padding_x(): string {
		return $this->section_padding_x;
	}
}
