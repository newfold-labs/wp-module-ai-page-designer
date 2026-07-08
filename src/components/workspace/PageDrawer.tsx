import React, { useMemo, useState } from 'react';
import { DocumentIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import type { WPItem } from '../../types';

type Props = {
  loadingRecent: boolean;
  recentItems: WPItem[];
  sitePages: WPItem[];
  sitePosts: WPItem[];
  selectedItemId: number | null;
  onSelectItem: ( item: WPItem ) => void;
};

const SEARCH_RESULTS_LIMIT = 15;

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad|iPod/.test( navigator.platform );

const stripHtml = ( value: string ) => value.replace( /<[^>]*>/g, '' );

const formatDate = ( dateString: string ) => {
  if ( ! dateString ) return '';
  const date = new Date( dateString );
  const diffDays = Math.floor( ( Date.now() - date.getTime() ) / ( 1000 * 60 * 60 * 24 ) );
  if ( diffDays <= 0 ) return 'Today';
  if ( diffDays === 1 ) return 'Yesterday';
  if ( diffDays < 30 ) return `${ diffDays }d ago`;
  return `${ Math.floor( diffDays / 30 ) }mo ago`;
};

// Recent (server-persisted via _apd_recent_ids) when the search box is
// empty; typing switches to filtering everything already loaded for this
// site. Full server-side search lives in the Cmd+K command palette instead.
const PageDrawer = ( {
  loadingRecent,
  recentItems,
  sitePages,
  sitePosts,
  selectedItemId,
  onSelectItem,
}: Props ) => {
  const [ query, setQuery ] = useState( '' );
  const normalizedQuery = query.trim().toLowerCase();
  const isSearching = normalizedQuery.length > 0;

  const searchResults = useMemo( () => {
    if ( ! isSearching ) {
      return [];
    }
    return [ ...sitePages, ...sitePosts ]
      .filter( ( item ) => stripHtml( item.title?.rendered || '' ).toLowerCase().includes( normalizedQuery ) )
      .slice( 0, SEARCH_RESULTS_LIMIT );
  }, [ sitePages, sitePosts, isSearching, normalizedQuery ] );

  const visibleItems = isSearching ? searchResults : recentItems;
  const loading = ! isSearching && loadingRecent;

  // Reuses the global Cmd/Ctrl+K listener in App.tsx rather than prop-drilling
  // an open-palette callback through the drawer.
  const openCommandPalette = () => {
    window.dispatchEvent(
      new KeyboardEvent( 'keydown', { key: 'k', metaKey: isMac, ctrlKey: ! isMac, bubbles: true } )
    );
  };

  return (
    <div className="ai-page-drawer">
      <div className="ai-page-drawer__search">
        <MagnifyingGlassIcon className="icon-sm" />
        <input
          type="search"
          value={ query }
          onChange={ ( event ) => setQuery( event.target.value ) }
          placeholder="Search pages and posts"
          aria-label="Search pages and posts"
        />
        <button
          type="button"
          className="ai-page-drawer__search-hint"
          title="Search every page and post on this site"
          onClick={ openCommandPalette }
        >
          <kbd>{ isMac ? '⌘K' : 'Ctrl K' }</kbd>
        </button>
      </div>

      <div className="ai-page-drawer__section-label">{ isSearching ? 'Results' : 'Recent' }</div>
      <ul className="ai-page-drawer__list">
        { loading && (
          <li className="ai-page-drawer__loading">Loading...</li>
        ) }
        { ! loading && visibleItems.length === 0 && (
          <li className="ai-page-drawer__empty">Nothing found.</li>
        ) }
        { ! loading && visibleItems.map( ( item ) => (
          <li
            key={ `${ item.type }-${ item.id }` }
            className={ `ai-page-drawer__item ${ item.id === selectedItemId ? 'is-active' : '' }` }
            onClick={ () => onSelectItem( item ) }
          >
            <DocumentIcon className="icon-sm" />
            <span
              className="ai-page-drawer__item-title"
              dangerouslySetInnerHTML={ { __html: item.title?.rendered || '' } }
            />
            <span className="ai-page-drawer__item-meta">{ formatDate( item.modified || item.date || '' ) }</span>
          </li>
        ) ) }
      </ul>
    </div>
  );
};

export default PageDrawer;
