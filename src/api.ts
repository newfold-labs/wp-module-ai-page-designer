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
};

export type GenerateContentResponse = {
  data: {
    content: string;
    title?: string;
    message?: string;
    response_id?: string;
    conversation_id?: string;
    conversation_key?: string;
  };
};

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
