import React, { useMemo, useState } from 'react';
import { DocumentIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import type { WPItem } from '../../types';

type Props = {
  loading: boolean;
  sitePages: WPItem[];
  sitePosts: WPItem[];
  selectedItemId: number | null;
  onSelectItem: ( item: WPItem ) => void;
};

const RECENT_LIMIT = 15;

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

// True per-user server-persisted recents (_apd_recent_ids) land in a later
// slice once the backend endpoint exists — this sorts what's already loaded
// by modified date so the drawer is useful today without blocking on that.
const PageDrawer = ( { loading, sitePages, sitePosts, selectedItemId, onSelectItem }: Props ) => {
  const [ query, setQuery ] = useState( '' );

  const recent = useMemo( () => {
    const merged = [ ...sitePages, ...sitePosts ]
      .slice()
      .sort( ( a, b ) => {
        const aTime = new Date( a.modified || a.date || 0 ).getTime();
        const bTime = new Date( b.modified || b.date || 0 ).getTime();
        return bTime - aTime;
      } );

    const normalizedQuery = query.trim().toLowerCase();
    const filtered = normalizedQuery
      ? merged.filter( ( item ) => stripHtml( item.title?.rendered || '' ).toLowerCase().includes( normalizedQuery ) )
      : merged;

    return filtered.slice( 0, RECENT_LIMIT );
  }, [ sitePages, sitePosts, query ] );

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
      </div>

      <div className="ai-page-drawer__section-label">Recent</div>
      <ul className="ai-page-drawer__list">
        { loading && (
          <li className="ai-page-drawer__loading">Loading...</li>
        ) }
        { ! loading && recent.length === 0 && (
          <li className="ai-page-drawer__empty">Nothing found.</li>
        ) }
        { ! loading && recent.map( ( item ) => (
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
