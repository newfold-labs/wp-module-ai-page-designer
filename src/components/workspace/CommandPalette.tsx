import React, { useEffect, useRef, useState } from 'react';
import { DocumentIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import { fetchContentItem, searchSite, type SearchResult } from '../../api';
import { decodeHtmlEntities } from '../../util/aiDesignerHelpers';
import type { WPItem } from '../../types';

type Props = {
  open: boolean;
  apiUrl: string;
  onClose: () => void;
  onSelectItem: ( item: WPItem ) => void;
};

const DEBOUNCE_MS = 250;

const CommandPalette = ( { open, apiUrl, onClose, onSelectItem }: Props ) => {
  const [ query, setQuery ] = useState( '' );
  const [ results, setResults ] = useState<SearchResult[]>( [] );
  const [ loading, setLoading ] = useState( false );
  const [ resolvingId, setResolvingId ] = useState<number | null>( null );
  const inputRef = useRef<HTMLInputElement>( null );

  useEffect( () => {
    if ( open ) {
      setQuery( '' );
      setResults( [] );
      // Let the overlay mount before focusing.
      requestAnimationFrame( () => inputRef.current?.focus() );
    }
  }, [ open ] );

  useEffect( () => {
    if ( ! open ) {
      return;
    }
    const trimmed = query.trim();
    if ( ! trimmed ) {
      setResults( [] );
      setLoading( false );
      return;
    }
    setLoading( true );
    const timeoutId = window.setTimeout( () => {
      searchSite( trimmed )
        .then( ( items ) => setResults( items || [] ) )
        .catch( ( error ) => console.error( 'Site search failed:', error ) )
        .finally( () => setLoading( false ) );
    }, DEBOUNCE_MS );
    return () => window.clearTimeout( timeoutId );
  }, [ query, open ] );

  const handleSelect = ( result: SearchResult ) => {
    setResolvingId( result.id );
    fetchContentItem( apiUrl, result.subtype, result.id )
      .then( ( item ) => {
        onSelectItem( item );
        onClose();
      } )
      .catch( ( error ) => console.error( 'Failed to open search result:', error ) )
      .finally( () => setResolvingId( null ) );
  };

  if ( ! open ) {
    return null;
  }

  return (
    <div className="ai-command-palette-overlay" onClick={ onClose }>
      <div className="ai-command-palette" onClick={ ( event ) => event.stopPropagation() }>
        <div className="ai-command-palette__search">
          <MagnifyingGlassIcon className="icon-sm" />
          <input
            ref={ inputRef }
            type="text"
            value={ query }
            onChange={ ( event ) => setQuery( event.target.value ) }
            onKeyDown={ ( event ) => {
              if ( event.key === 'Escape' ) {
                onClose();
              }
            } }
            placeholder="Search pages and posts"
            aria-label="Search all pages and posts"
          />
          <kbd className="ai-command-palette__hint">Esc</kbd>
        </div>
        <ul className="ai-command-palette__list">
          { loading && <li className="ai-command-palette__status">Searching...</li> }
          { ! loading && query.trim() && results.length === 0 && (
            <li className="ai-command-palette__status">No results for &ldquo;{ query.trim() }&rdquo;.</li>
          ) }
          { ! loading && ! query.trim() && (
            <li className="ai-command-palette__status">Type to search every page and post on this site.</li>
          ) }
          { ! loading && results.map( ( result ) => (
            <li
              key={ `${ result.subtype }-${ result.id }` }
              className="ai-command-palette__item"
              onClick={ () => handleSelect( result ) }
            >
              <DocumentIcon className="icon-sm" />
              <span className="ai-command-palette__item-title">{ decodeHtmlEntities( result.title ) || 'Untitled' }</span>
              <span className="ai-command-palette__item-type">{ result.subtype }</span>
              { resolvingId === result.id && <span className="ai-command-palette__item-loading">Opening...</span> }
            </li>
          ) ) }
        </ul>
      </div>
    </div>
  );
};

export default CommandPalette;
