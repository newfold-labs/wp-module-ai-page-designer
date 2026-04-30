export type ColorTarget = 'text' | 'background';

const TEXT_COLOR_BLOCKS = new Set( [
  'paragraph', 'heading', 'list-item', 'quote', 'pullquote', 'verse', 'preformatted', 'code', 'button',
] );

const BG_COLOR_BLOCKS = new Set( [
  'group', 'cover', 'column', 'columns', 'button', 'buttons', 'media-text',
] );

export function applyColorToBlocks(
  markup: string,
  colorSlug: string,
  target: ColorTarget
): string {
  const jsonAttrKey = target === 'text' ? 'textColor' : 'backgroundColor';
  const targetBlocks = target === 'text' ? TEXT_COLOR_BLOCKS : BG_COLOR_BLOCKS;
  const newColorClass = target === 'text' ? `has-${ colorSlug }-color` : `has-${ colorSlug }-background-color`;
  const hasTypeClass = target === 'text' ? 'has-text-color' : 'has-background';
  const cssProp = target === 'text' ? 'color' : 'background-color';

  // Step 1: Update block comment JSON attributes.
  let result = markup.replace(
    /<!--\s*wp:([\w/-]+)(\s+(\{(?:[^{}]|\{[^{}]*\})*\}))?\s*-->/g,
    ( match, blockType, _, jsonStr ) => {
      if ( ! targetBlocks.has( blockType ) ) {
        return match;
      }
      try {
        const attrs = jsonStr ? JSON.parse( jsonStr.trim() ) : {};
        attrs[ jsonAttrKey ] = colorSlug;
        return `<!-- wp:${ blockType } ${ JSON.stringify( attrs ) } -->`;
      } catch {
        return match;
      }
    }
  );

  // Step 2: Update class attributes — remove old color class, add new one.
  result = result.replace( /class="([^"]*)"/g, ( match, classStr ) => {
    const parts = classStr.split( /\s+/ ).filter( Boolean );

    const filtered = parts.filter( ( cls: string ) => {
      if ( target === 'text' ) {
        return ! ( /^has-[a-z0-9-]+-color$/.test( cls ) && ! cls.endsWith( '-background-color' ) );
      }
      return ! /^has-[a-z0-9-]+-background-color$/.test( cls );
    } );

    if ( filtered.includes( hasTypeClass ) && ! filtered.includes( newColorClass ) ) {
      filtered.push( newColorClass );
    }

    return `class="${ filtered.join( ' ' ) }"`;
  } );

  // Step 3: Update inline CSS property values.
  result = result.replace( /style="([^"]*)"/g, ( match, styleStr ) => {
    const propPattern = target === 'text'
      ? /\bcolor:\s*[^;]+/g
      : /\bbackground-color:\s*[^;]+/g;

    if ( ! propPattern.test( styleStr ) ) {
      return match;
    }

    const updated = styleStr.replace(
      target === 'text' ? /\bcolor:\s*[^;]+/g : /\bbackground-color:\s*[^;]+/g,
      `${ cssProp }: var(--wp--preset--color--${ colorSlug })`
    );

    return `style="${ updated }"`;
  } );

  return result;
}
