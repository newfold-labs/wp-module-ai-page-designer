export type LocalStyleChange = {
  css: string;
  label: string;
  message: string;
  colorValue: string | null;
  isFontReset: boolean;
} | null;

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

export const applyLocalStyle = ( html: string, cssText: string ) => {
  const styleTag = `<style data-nfd-local-style="true">${ cssText }</style>`;
  if ( html.includes( 'data-nfd-local-style="true"' ) ) {
    return html.replace(
      /<style data-nfd-local-style="true">[\s\S]*?<\/style>/u,
      styleTag
    );
  }

  return `${ styleTag }\n${ html }`;
};

export const getLocalStyleChange = ( text: string, targetSelector: string ): LocalStyleChange => {
  const normalized = text.toLowerCase();
  const cssParts: string[] = [];
  const labels: string[] = [];
  let colorValue: string | null = null;
  let isFontReset = false;

  if ( /\bdark mode\b|\bdark theme\b/u.test( normalized ) ) {
    cssParts.push( 'body{background:#0f1115;color:#f1f1f1;}' );
    labels.push( 'dark mode' );
  }

  if ( /\blight mode\b|\blight theme\b/u.test( normalized ) ) {
    cssParts.push( 'body{background:#ffffff;color:#111111;}' );
    labels.push( 'light mode' );
  }

  const colorMap: Record<string, string> = {
    black: '#000000',
    white: '#ffffff',
    gray: '#6b7280',
    grey: '#6b7280',
    red: '#ef4444',
    orange: '#f97316',
    yellow: '#eab308',
    green: '#22c55e',
    blue: '#3b82f6',
    purple: '#a855f7',
    pink: '#ec4899',
    brown: '#92400e',
    navy: '#1e3a8a',
    teal: '#14b8a6',
  };

  const matchedColor = Object.keys( colorMap ).find( ( color ) =>
    new RegExp( `\\b${ color }\\b`, 'u' ).test( normalized )
  );

  if ( /\bfont\b/u.test( normalized ) ) {
    if ( /\b(remove|clear|reset)\b.*\bfont\b.*\bcolor\b|\bremove\b.*\bcolor\b/u.test( normalized ) ) {
      cssParts.push( `${ targetSelector }{color:inherit !important;}` );
      labels.push( 'font color reset' );
      isFontReset = true;
    }

    if ( matchedColor ) {
      cssParts.push( `${ targetSelector }{color:${ colorMap[matchedColor] } !important;}` );
      labels.push( `${ matchedColor } font` );
      colorValue = colorMap[matchedColor];
    }
  }

  if (
    matchedColor &&
    /\b(change|set|update)\b.*\bcolor\b|\bcolor\b.*\b(change|set|update)\b/u.test( normalized ) &&
    !/\bbackground\b|\bpage\b|\btheme\b|\bmode\b/u.test( normalized )
  ) {
    cssParts.push( `${ targetSelector }{color:${ colorMap[matchedColor] } !important;}` );
    labels.push( `${ matchedColor } color` );
    colorValue = colorMap[matchedColor];
  }

  if ( cssParts.length === 0 ) {
    return null;
  }

  return {
    css: cssParts.join( '\n' ),
    label: labels.join( ' + ' ),
    message: 'Applied style changes to the preview.',
    colorValue,
    isFontReset,
  };
};
