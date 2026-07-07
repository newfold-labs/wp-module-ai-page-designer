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
