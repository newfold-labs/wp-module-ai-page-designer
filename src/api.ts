import apiFetch from '@wordpress/api-fetch';
import type { Message, WPItem } from './types';

export const fetchSitePages = ( apiUrl: string ) => apiFetch<WPItem[]>( {
  path: `${ apiUrl }/content/pages`,
} );

export const fetchSitePosts = ( apiUrl: string ) => apiFetch<WPItem[]>( {
  path: `${ apiUrl }/content/posts`,
} );

// Server-persisted per user (_apd_recent_ids) so the workspace drawer's
// Recent list follows the user across devices.
export const fetchRecentItems = ( apiUrl: string ) => apiFetch<WPItem[]>( {
  path: `${ apiUrl }/recent`,
} );

export const touchRecentItem = ( apiUrl: string, id: number ) => apiFetch<WPItem[]>( {
  path: `${ apiUrl }/recent`,
  method: 'POST',
  data: { id },
} );

export type SearchResult = {
  id: number;
  title: string;
  url: string;
  type: string;
  subtype: string;
};

// WP core's search endpoint returns a lightweight shape (no content/status) —
// selecting a result fetches the full item via fetchContentItem below.
export const searchSite = ( query: string ) => apiFetch<SearchResult[]>( {
  path: `/wp/v2/search?search=${ encodeURIComponent( query ) }&per_page=20&subtype=page,post`,
} );

export const fetchContentItem = ( apiUrl: string, subtype: string, id: number ) => {
  const type = 'page' === subtype ? 'pages' : 'posts';
  return apiFetch<WPItem>( {
    path: `${ apiUrl }/content/${ type }/${ id }`,
  } );
};

export type GenerateContentContext = {
  current_markup: string;
  post_id?: number;
  conversation_id?: string;
  content_type?: 'page' | 'post';
  selected_block_markup?: string;
  single_block_edit?: boolean;
  // src of the specific image clicked inside a multi-image block, so only it is replaced.
  selected_image_url?: string;
  page_title?: string;
  page_excerpt?: string;
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

/**
 * WordPress REST errors return JSON: { code, message, data }. Surface `message` in the UI
 * instead of a generic streaming failure when the stream never starts (e.g. 401/403).
 */
async function getStreamingHttpErrorMessage( response: Response ): Promise<string> {
  try {
    const text = await response.text();
    if ( text ) {
      const parsed = JSON.parse( text ) as { message?: string };
      if ( typeof parsed.message === 'string' && parsed.message.trim() !== '' ) {
        return parsed.message.trim();
      }
    }
  } catch {
    // Non-JSON body (e.g. HTML error page).
  }

  const status = response.status;
  const statusText = response.statusText?.trim();
  if ( statusText ) {
    return `Request failed (${ status } ${ statusText }).`;
  }

  return `Request failed (${ status }).`;
}

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
  const nonce = ( window as any )?.nfdAIPageDesigner?.nonce || '';

  const startUrl = `${ baseUrl }${ basePath }/generate/poll/start`;
  const startResponse = await fetch( startUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
    body: JSON.stringify( { messages, context } ),
  } );

  if ( ! startResponse.ok ) {
    const message = await getStreamingHttpErrorMessage( startResponse );
    onEvent( { type: 'error', message } );
    return;
  }

  const { generation_id } = await startResponse.json();
  const authHeaders = { 'X-WP-Nonce': nonce };

  let offset = 0;
  let attempts = 0;
  // 250 ms cadence keeps the preview filling in quickly; maxAttempts holds the overall
  // timeout at ~3 min (720 * 250 ms).
  const pollIntervalMs = 250;
  const maxAttempts = 720;

  while ( attempts < maxAttempts ) {
    await new Promise( ( resolve ) => setTimeout( resolve, pollIntervalMs ) );
    attempts++;

    const pollUrl = `${ baseUrl }${ basePath }/generate/poll/${ generation_id }?offset=${ offset }`;
    const pollResponse = await fetch( pollUrl, { credentials: 'same-origin', headers: authHeaders } );

    if ( ! pollResponse.ok ) {
      const message = await getStreamingHttpErrorMessage( pollResponse );
      onEvent( { type: 'error', message } );
      fetch( `${ baseUrl }${ basePath }/generate/poll/${ generation_id }`, { method: 'DELETE', credentials: 'same-origin', headers: authHeaders } );
      return;
    }

    const data = await pollResponse.json();

    for ( const chunk of data.chunks ) {
      onEvent( { type: 'delta', text: chunk } );
      offset++;
    }

    if ( data.status === 'completed' ) {
      if ( data.result ) {
        onEvent( { type: 'result', data: data.result } );
      }
      fetch( `${ baseUrl }${ basePath }/generate/poll/${ generation_id }`, { method: 'DELETE', credentials: 'same-origin', headers: authHeaders } );
      return;
    }

    if ( data.status === 'error' ) {
      onEvent( { type: 'error', message: data.error_message || 'Generation failed.' } );
      fetch( `${ baseUrl }${ basePath }/generate/poll/${ generation_id }`, { method: 'DELETE', credentials: 'same-origin', headers: authHeaders } );
      return;
    }
  }

  onEvent( { type: 'error', message: 'Generation timed out.' } );
  fetch( `${ baseUrl }${ basePath }/generate/poll/${ generation_id }`, { method: 'DELETE', credentials: 'same-origin', headers: authHeaders } );
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
