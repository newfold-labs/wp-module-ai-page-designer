// Newfold's Blueprint theme.json schema exposes a fixed, site-independent set
// of 10 color roles (base/contrast pair + 6 accents + midtone variants of
// each), each backed by a `--wp--preset--color--{slug}` CSS custom property
// that generated block markup already references directly (inline
// `background-color:var(--wp--preset--color--accent_4)` etc., alongside the
// matching `has-{slug}-color` utility classes). Overriding these 10
// properties at the preview root is enough to re-color a generated page
// instantly, with zero LLM calls — the same variables the theme itself
// already threads through every block.
export type ColorSlug =
  | 'base'
  | 'contrast'
  | 'accent_1'
  | 'accent_2'
  | 'accent_3'
  | 'accent_4'
  | 'accent_5'
  | 'accent_6'
  | 'base_midtone'
  | 'contrast_midtone';

export type DesignPalette = {
  id: string;
  name: string;
  // Swatches shown in the palette grid preview (background, contrast, 2 accents).
  preview: [ string, string, string, string ];
  colors: Record<ColorSlug, string>;
};

// Persisted per-page (post meta, see WordPressProxyController::DESIGN_TOKENS_META_KEY).
// Carries both the ids (so the Design tab can restore which swatch/pairing was
// selected) and the resolved values (so the PHP-side published-page render
// doesn't need to know the curated palette definitions at all).
export type PersistedDesignTokens = {
  paletteId: string | null;
  fontPairingId: string;
  colors: Record<ColorSlug, string> | null;
  headingFont: string;
  bodyFont: string;
};

export const CURATED_PALETTES: DesignPalette[] = [
  {
    id: 'midnight',
    name: 'Midnight',
    preview: [ '#f5f3ff', '#0f0b1f', '#6d28d9', '#a78bfa' ],
    colors: {
      base: '#f5f3ff',
      contrast: '#4c1d95',
      accent_1: '#6d28d9',
      accent_2: '#7c3aed',
      accent_3: '#8b5cf6',
      accent_4: '#a78bfa',
      accent_5: '#c4b5fd',
      accent_6: '#ddd6fe',
      base_midtone: '#ede9fe',
      contrast_midtone: '#0f0b1f',
    },
  },
  {
    id: 'sunset',
    name: 'Sunset',
    preview: [ '#fff7ed', '#7c2d12', '#ea580c', '#fb923c' ],
    colors: {
      base: '#fff7ed',
      contrast: '#7c2d12',
      accent_1: '#c2410c',
      accent_2: '#ea580c',
      accent_3: '#f97316',
      accent_4: '#fb923c',
      accent_5: '#fdba74',
      accent_6: '#fed7aa',
      base_midtone: '#ffedd5',
      contrast_midtone: '#431407',
    },
  },
  {
    id: 'forest',
    name: 'Forest',
    preview: [ '#f0fdf4', '#14532d', '#15803d', '#4ade80' ],
    colors: {
      base: '#f0fdf4',
      contrast: '#14532d',
      accent_1: '#166534',
      accent_2: '#15803d',
      accent_3: '#16a34a',
      accent_4: '#4ade80',
      accent_5: '#86efac',
      accent_6: '#bbf7d0',
      base_midtone: '#dcfce7',
      contrast_midtone: '#052e16',
    },
  },
  {
    id: 'ocean',
    name: 'Ocean',
    preview: [ '#f0f9ff', '#0c4a6e', '#0284c7', '#38bdf8' ],
    colors: {
      base: '#f0f9ff',
      contrast: '#0c4a6e',
      accent_1: '#075985',
      accent_2: '#0284c7',
      accent_3: '#0ea5e9',
      accent_4: '#38bdf8',
      accent_5: '#7dd3fc',
      accent_6: '#bae6fd',
      base_midtone: '#e0f2fe',
      contrast_midtone: '#082f49',
    },
  },
  {
    id: 'berry',
    name: 'Berry',
    preview: [ '#fdf2f8', '#831843', '#be185d', '#f472b6' ],
    colors: {
      base: '#fdf2f8',
      contrast: '#831843',
      accent_1: '#9d174d',
      accent_2: '#be185d',
      accent_3: '#db2777',
      accent_4: '#f472b6',
      accent_5: '#f9a8d4',
      accent_6: '#fbcfe8',
      base_midtone: '#fce7f3',
      contrast_midtone: '#500724',
    },
  },
  {
    id: 'slate',
    name: 'Slate',
    preview: [ '#f8fafc', '#0f172a', '#334155', '#64748b' ],
    colors: {
      base: '#f8fafc',
      contrast: '#0f172a',
      accent_1: '#1e293b',
      accent_2: '#334155',
      accent_3: '#475569',
      accent_4: '#64748b',
      accent_5: '#94a3b8',
      accent_6: '#cbd5e1',
      base_midtone: '#f1f5f9',
      contrast_midtone: '#020617',
    },
  },
  {
    id: 'amber',
    name: 'Amber',
    preview: [ '#fffbeb', '#78350f', '#b45309', '#fbbf24' ],
    colors: {
      base: '#fffbeb',
      contrast: '#78350f',
      accent_1: '#92400e',
      accent_2: '#b45309',
      accent_3: '#d97706',
      accent_4: '#fbbf24',
      accent_5: '#fcd34d',
      accent_6: '#fde68a',
      base_midtone: '#fef3c7',
      contrast_midtone: '#451a03',
    },
  },
];

const hexToRgb = ( hex: string ): [ number, number, number ] => {
  const clean = hex.replace( '#', '' );
  const normalized = clean.length === 3
    ? clean.split( '' ).map( ( c ) => c + c ).join( '' )
    : clean;
  const bigint = parseInt( normalized, 16 );
  return [ ( bigint >> 16 ) & 255, ( bigint >> 8 ) & 255, bigint & 255 ];
};

const rgbToHex = ( r: number, g: number, b: number ): string =>
  '#' + [ r, g, b ]
    .map( ( v ) => Math.max( 0, Math.min( 255, Math.round( v ) ) ).toString( 16 ).padStart( 2, '0' ) )
    .join( '' );

const rgbToHsl = ( r: number, g: number, b: number ): [ number, number, number ] => {
  r /= 255; g /= 255; b /= 255;
  const max = Math.max( r, g, b );
  const min = Math.min( r, g, b );
  let h = 0;
  let s = 0;
  const l = ( max + min ) / 2;
  if ( max !== min ) {
    const d = max - min;
    s = l > 0.5 ? d / ( 2 - max - min ) : d / ( max + min );
    switch ( max ) {
      case r: h = ( g - b ) / d + ( g < b ? 6 : 0 ); break;
      case g: h = ( b - r ) / d + 2; break;
      default: h = ( r - g ) / d + 4; break;
    }
    h /= 6;
  }
  return [ h * 360, s * 100, l * 100 ];
};

const hslToRgb = ( h: number, s: number, l: number ): [ number, number, number ] => {
  h /= 360; s /= 100; l /= 100;
  if ( s === 0 ) {
    const v = l * 255;
    return [ v, v, v ];
  }
  const hue2rgb = ( p: number, q: number, t: number ) => {
    let tt = t;
    if ( tt < 0 ) tt += 1;
    if ( tt > 1 ) tt -= 1;
    if ( tt < 1 / 6 ) return p + ( q - p ) * 6 * tt;
    if ( tt < 1 / 2 ) return q;
    if ( tt < 2 / 3 ) return p + ( q - p ) * ( 2 / 3 - tt ) * 6;
    return p;
  };
  const q = l < 0.5 ? l * ( 1 + s ) : l + s - l * s;
  const p = 2 * l - q;
  return [
    hue2rgb( p, q, h + 1 / 3 ) * 255,
    hue2rgb( p, q, h ) * 255,
    hue2rgb( p, q, h - 1 / 3 ) * 255,
  ];
};

// Nudges a hex color's lightness/saturation by relative HSL percentage-point
// deltas. Used to derive palette variants from the site's own theme.json
// colors below without pulling in a color-manipulation dependency.
const adjustHex = ( hex: string, lightnessDelta: number, saturationDelta = 0 ): string => {
  const [ r, g, b ] = hexToRgb( hex );
  const [ h, s, l ] = rgbToHsl( r, g, b );
  const newS = Math.max( 0, Math.min( 100, s + saturationDelta ) );
  const newL = Math.max( 0, Math.min( 100, l + lightnessDelta ) );
  const [ nr, ng, nb ] = hslToRgb( h, newS, newL );
  return rgbToHex( nr, ng, nb );
};

const THEME_PALETTE_PREVIEW_SLUGS: ColorSlug[] = [ 'base', 'contrast_midtone', 'accent_1', 'accent_6' ];

const previewFor = ( colors: Record<ColorSlug, string> ): [ string, string, string, string ] => [
  colors[ THEME_PALETTE_PREVIEW_SLUGS[ 0 ] ],
  colors[ THEME_PALETTE_PREVIEW_SLUGS[ 1 ] ],
  colors[ THEME_PALETTE_PREVIEW_SLUGS[ 2 ] ],
  colors[ THEME_PALETTE_PREVIEW_SLUGS[ 3 ] ],
];

// Derives 3 palettes from the site's own active theme.json colors (as
// localized in `nfdAIPageDesigner.colorPalette`) rather than only offering
// the hand-picked CURATED_PALETTES above — an on-brand alternative for sites
// that want variation without leaving their theme's color family. Returns
// an empty array for themes that don't expose the full 10-role Blueprint
// schema (see the ColorSlug comment above), so the Design tab silently falls
// back to curated-only.
export const buildThemePalettes = (
  themeSwatches?: Array<{ slug: string; name: string; color: string }>
): DesignPalette[] => {
  if ( ! themeSwatches || themeSwatches.length === 0 ) {
    return [];
  }
  const bySlug: Partial<Record<ColorSlug, string>> = {};
  themeSwatches.forEach( ( swatch ) => {
    bySlug[ swatch.slug as ColorSlug ] = swatch.color;
  } );
  const requiredSlugs: ColorSlug[] = [
    'base', 'contrast', 'accent_1', 'accent_2', 'accent_3',
    'accent_4', 'accent_5', 'accent_6', 'base_midtone', 'contrast_midtone',
  ];
  if ( requiredSlugs.some( ( slug ) => ! bySlug[ slug ] ) ) {
    return [];
  }
  const siteColors = bySlug as Record<ColorSlug, string>;

  const classic: DesignPalette = {
    id: 'theme-classic',
    name: 'Site colors',
    preview: previewFor( siteColors ),
    colors: siteColors,
  };

  const boldColors: Record<ColorSlug, string> = {
    base: siteColors.base,
    contrast: adjustHex( siteColors.contrast, -8, 10 ),
    accent_1: adjustHex( siteColors.accent_1, -10, 15 ),
    accent_2: adjustHex( siteColors.accent_2, -6, 12 ),
    accent_3: adjustHex( siteColors.accent_3, -4, 10 ),
    accent_4: adjustHex( siteColors.accent_4, 0, 10 ),
    accent_5: adjustHex( siteColors.accent_5, 4, 10 ),
    accent_6: adjustHex( siteColors.accent_6, 8, 10 ),
    base_midtone: siteColors.base_midtone,
    contrast_midtone: adjustHex( siteColors.contrast_midtone, -6, 5 ),
  };
  const bold: DesignPalette = {
    id: 'theme-bold',
    name: 'Site bold',
    preview: previewFor( boldColors ),
    colors: boldColors,
  };

  const softColors: Record<ColorSlug, string> = {
    base: adjustHex( siteColors.base, 2, -5 ),
    contrast: adjustHex( siteColors.contrast, 10, -15 ),
    accent_1: adjustHex( siteColors.accent_1, 14, -18 ),
    accent_2: adjustHex( siteColors.accent_2, 12, -15 ),
    accent_3: adjustHex( siteColors.accent_3, 10, -12 ),
    accent_4: adjustHex( siteColors.accent_4, 8, -10 ),
    accent_5: adjustHex( siteColors.accent_5, 6, -8 ),
    accent_6: adjustHex( siteColors.accent_6, 4, -6 ),
    base_midtone: adjustHex( siteColors.base_midtone, 2, -5 ),
    contrast_midtone: adjustHex( siteColors.contrast_midtone, 6, -10 ),
  };
  const soft: DesignPalette = {
    id: 'theme-soft',
    name: 'Site soft',
    preview: previewFor( softColors ),
    colors: softColors,
  };

  return [ classic, bold, soft ];
};

export type FontPairing = {
  id: string;
  name: string;
  headingFont: string;
  bodyFont: string;
};

// Limited to fonts the preview iframe already loads (usePreviewIframe.ts'
// Google Fonts <link>) plus the app's own bundled Inter/Manrope, so a
// selection never depends on a font that hasn't been fetched yet.
export const CURATED_FONT_PAIRINGS: FontPairing[] = [
  {
    id: 'default',
    name: 'Theme default',
    headingFont: '',
    bodyFont: '',
  },
  {
    id: 'editorial',
    name: 'Editorial',
    headingFont: `'Playfair Display', serif`,
    bodyFont: `'Lora', serif`,
  },
  {
    id: 'modern',
    name: 'Modern',
    headingFont: `'Manrope', sans-serif`,
    bodyFont: `'Inter', sans-serif`,
  },
  {
    id: 'bold',
    name: 'Bold',
    headingFont: `'Montserrat', sans-serif`,
    bodyFont: `'Raleway', sans-serif`,
  },
  {
    id: 'classic',
    name: 'Classic',
    headingFont: `'Playfair Display', serif`,
    bodyFont: `'Raleway', sans-serif`,
  },
];
