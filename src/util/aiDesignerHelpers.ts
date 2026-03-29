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


