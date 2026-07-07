export const extractHtml = ( content: string ): string | null => {
  if ( ! content || content.trim() === '' ) {
    return null;
  }

  // Clean up any markdown formatting the AI might have erroneously included.
  let cleanContent = content.trim();
  if ( cleanContent.startsWith( '```html' ) ) {
    cleanContent = cleanContent.substring( 7 );
    if ( cleanContent.endsWith( '```' ) ) {
      cleanContent = cleanContent.substring( 0, cleanContent.length - 3 );
    }
  } else if ( cleanContent.startsWith( '```' ) ) {
    cleanContent = cleanContent.substring( 3 );
    if ( cleanContent.endsWith( '```' ) ) {
      cleanContent = cleanContent.substring( 0, cleanContent.length - 3 );
    }
  }

  return cleanContent.trim();
};

export const stripLocalStyles = ( html: string ): string =>
  html.replace( /<style data-nfd-local-style="true">[\s\S]*?<\/style>\n?/gu, '' );

// WP entity-escapes rendered strings ("&#038;" for "&") since they're meant
// for inline HTML output. Decoding via a <textarea>'s value (not
// innerHTML/dangerouslySetInnerHTML rendering) parses entities as text
// only — nothing here is ever interpreted as markup — so it's safe for
// plain-text display of API-sourced strings (search results, titles, etc).
export const decodeHtmlEntities = ( value: string ): string => {
  const textarea = document.createElement( 'textarea' );
  textarea.innerHTML = value;
  return textarea.value;
};

/**
 * Convert plain HTML to Gutenberg block markup using wp.blocks.rawHandler.
 * Returns the original HTML unchanged if wp.blocks is unavailable or conversion fails.
 */
export const convertHtmlToGutenberg = ( html: string ): string => {
  const wp = ( window as any )?.wp;
  if ( ! wp?.blocks?.rawHandler || ! wp?.blocks?.serialize ) {
    return html;
  }
  try {
    const blocks = wp.blocks.rawHandler( { HTML: html } );
    if ( ! blocks || blocks.length === 0 ) {
      return html;
    }
    const serialized = wp.blocks.serialize( blocks );
    return serialized || html;
  } catch {
    return html;
  }
};

export const hasGutenbergMarkers = ( html: string ): boolean =>
  /<!--\s*\/?wp:/i.test( html );

