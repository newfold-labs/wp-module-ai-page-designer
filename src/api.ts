import apiFetch from '@wordpress/api-fetch';
import type { Message, WPItem } from './types';

export const fetchSitePages = ( apiUrl: string ) => apiFetch<WPItem[]>( {
  path: `${ apiUrl }/content/pages`,
} );

export const fetchSitePosts = ( apiUrl: string ) => apiFetch<WPItem[]>( {
  path: `${ apiUrl }/content/posts`,
} );

export const generateContent = (
  apiUrl: string,
  messages: Message[],
  currentMarkup: string
) => apiFetch<{ data: { content: string; title?: string } }>( {
  path: `${ apiUrl }/generate`,
  method: 'POST',
  data: {
    messages,
    context: {
      current_markup: currentMarkup,
    },
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

export const updateExistingItem = ( item: WPItem, content: string ) => {
  const itemType = item.type === 'post' ? 'posts' : 'pages';
  return apiFetch<any>( {
    path: `/wp/v2/${ itemType }/${ item.id }`,
    method: 'POST',
    data: { content },
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
