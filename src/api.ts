import apiFetch from '@wordpress/api-fetch';
import type { Message, WPItem } from './types';

export const fetchSitePages = ( apiUrl: string ) => apiFetch<WPItem[]>( {
  path: `${ apiUrl }/content/pages`,
} );

export const fetchSitePosts = ( apiUrl: string ) => apiFetch<WPItem[]>( {
  path: `${ apiUrl }/content/posts`,
} );

export type GenerateContentContext = {
  current_markup: string;
  post_id?: number;
  conversation_id?: string;
  content_type?: 'page' | 'post';
  selected_block_markup?: string;
};

export type GenerateContentResponse = {
  data: {
    content: string;
    title?: string;
    excerpt?: string;
    summary?: string;
    featured_image_url?: string;
    message?: string;
    response_id?: string;
    conversation_id?: string;
    conversation_key?: string;
    is_metadata_only?: boolean;
  };
};

export type StreamEvent =
  | { type: 'delta'; text: string }
  | { type: 'snapshot'; text: string }
  | { type: 'result'; data: GenerateContentResponse['data'] }
  | { type: 'error'; message: string };

export const generateContent = (
  apiUrl: string,
  messages: Message[],
  context: GenerateContentContext
) => apiFetch<GenerateContentResponse>( {
  path: `${ apiUrl }/generate`,
  method: 'POST',
  data: {
    messages,
    context,
  },
} );

export const generateContentStream = async (
  apiUrl: string,
  messages: Message[],
  context: GenerateContentContext,
  onEvent: ( event: StreamEvent ) => void
) => {
  const rawApiUrl = apiUrl || '';
  const normalizedApiUrl = rawApiUrl.replace( /^https?:\/\/[^/]+/, '' );
  const apiRoot = ( window as any )?.nfdAIPageDesigner?.apiRoot || '/wp-json/';
  const hasWpJson = normalizedApiUrl.startsWith( '/wp-json/' );
  const basePath = hasWpJson
    ? normalizedApiUrl
    : `${ apiRoot.replace( /\/?$/, '/' ) }newfold-ai-page-designer/v1`;
  const baseUrl = apiRoot.startsWith( 'http' ) ? '' : window.location.origin;
  const streamUrl = `${ baseUrl }${ basePath }/generate?stream=1`;
  const response = await fetch( streamUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': ( window as any )?.nfdAIPageDesigner?.nonce || '',
    },
    body: JSON.stringify( {
      messages,
      context,
      stream: true,
    } ),
  } );

  if ( ! response.ok || ! response.body ) {
    throw new Error( 'Streaming request failed.' );
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  while ( true ) {
    const { value, done } = await reader.read();
    if ( done ) {
      break;
    }
    buffer += decoder.decode( value, { stream: true } );

    let boundaryIndex;
    while ( ( boundaryIndex = buffer.indexOf( '\n\n' ) ) !== -1 ) {
      const eventBlock = buffer.slice( 0, boundaryIndex ).trim();
      buffer = buffer.slice( boundaryIndex + 2 );

      if ( ! eventBlock ) {
        continue;
      }

      const lines = eventBlock.split( /\r?\n/ );
      let eventType = 'message';
      const dataLines: string[] = [];

      lines.forEach( ( line ) => {
        if ( line.startsWith( 'event:' ) ) {
          eventType = line.replace( 'event:', '' ).trim();
          return;
        }
        if ( line.startsWith( 'data:' ) ) {
          dataLines.push( line.replace( 'data:', '' ).trim() );
        }
      } );

      if ( dataLines.length === 0 ) {
        continue;
      }

      const dataPayload = dataLines.join( '\n' );
      try {
        const parsed = JSON.parse( dataPayload );
        if ( eventType === 'delta' ) {
          onEvent( { type: 'delta', text: parsed.text || '' } );
        } else if ( eventType === 'snapshot' ) {
          onEvent( { type: 'snapshot', text: parsed.text || '' } );
        } else if ( eventType === 'result' ) {
          onEvent( { type: 'result', data: parsed } );
        } else if ( eventType === 'error' ) {
          onEvent( { type: 'error', message: parsed.message || 'Streaming error.' } );
        }
      } catch ( err ) {
        onEvent( { type: 'error', message: 'Streaming parse error.' } );
      }
    }
  }
};

export const publishNewContent = (
  type: 'new_post' | 'new_page',
  title: string,
  content: string
) => {
  const endpoint = type === 'new_post' ? '/wp/v2/posts' : '/wp/v2/pages';
  return apiFetch<any>( {
    path: endpoint,
    method: 'POST',
    data: { title, content, status: 'publish' },
  } );
};

type UpdateExistingMeta = {
  title?: string;
  excerpt?: string;
  featuredMedia?: number;
};

export const updateExistingItem = (
  apiUrl: string,
  item: WPItem,
  content: string,
  meta: UpdateExistingMeta = {}
) => {
  const itemType = item.type === 'post' ? 'posts' : 'pages';
  const data: Record<string, any> = { content };

  if ( typeof meta.title === 'string' ) {
    data.title = meta.title;
  }
  if ( typeof meta.excerpt === 'string' ) {
    data.excerpt = meta.excerpt;
  }
  if ( typeof meta.featuredMedia === 'number' ) {
    data.featured_media = meta.featuredMedia;
  }

  return apiFetch<any>( {
    path: `${ apiUrl }/content/${ itemType }/${ item.id }`,
    method: 'POST',
    data,
  } );
};

export const setHomepage = ( pageId: number ) => apiFetch( {
  path: '/wp/v2/settings',
  method: 'POST',
  data: {
    page_on_front: pageId,
    show_on_front: 'page',
  },
} );
