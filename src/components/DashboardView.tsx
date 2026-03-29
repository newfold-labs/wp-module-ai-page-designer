import React, { useState } from 'react';
import {
  BookOpenIcon,
  DocumentIcon,
  MagnifyingGlassIcon,
  SparklesIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline';
import type { WPItem } from '../types';

type Props = {
  loadingSite: boolean;
  sitePages: WPItem[];
  sitePosts: WPItem[];
  pagesSearchQuery: string;
  postsSearchQuery: string;
  pagesExpanded: boolean;
  postsExpanded: boolean;
  onCreateWithPrompt: (prompt: string) => void;
  onSelectItem: (item: WPItem) => void;
  onPagesSearchChange: (value: string) => void;
  onPostsSearchChange: (value: string) => void;
  onTogglePagesExpanded: () => void;
  onTogglePostsExpanded: () => void;
};

const normalizeTitle = ( title: string ) => title.replace( /<[^>]*>/g, '' ).toLowerCase();

const formatDate = ( dateString: string ) => {
  if ( ! dateString ) return '';
  const date = new Date( dateString );
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffDays = Math.floor( diffMs / ( 1000 * 60 * 60 * 24 ) );
  if ( diffDays === 0 ) return 'Today';
  if ( diffDays === 1 ) return 'Yesterday';
  if ( diffDays < 30 ) return `${ diffDays }d ago`;
  const diffMonths = Math.floor( diffDays / 30 );
  if ( diffMonths < 12 ) return `${ diffMonths }mo ago`;
  return `${ Math.floor( diffMonths / 12 ) }y ago`;
};

const DashboardView = ( {
  loadingSite,
  sitePages,
  sitePosts,
  pagesSearchQuery,
  postsSearchQuery,
  pagesExpanded,
  postsExpanded,
  onCreateWithPrompt,
  onSelectItem,
  onPagesSearchChange,
  onPostsSearchChange,
  onTogglePagesExpanded,
  onTogglePostsExpanded,
}: Props ) => {
  const [ heroPrompt, setHeroPrompt ] = useState( '' );

  const normalizedPagesQuery = pagesSearchQuery.trim().toLowerCase();
  const normalizedPostsQuery = postsSearchQuery.trim().toLowerCase();
  const isSearchingPages = normalizedPagesQuery.length > 0;
  const isSearchingPosts = normalizedPostsQuery.length > 0;
  const filteredPages = normalizedPagesQuery
    ? sitePages.filter( ( page ) =>
        normalizeTitle( page.title?.rendered || '' ).includes( normalizedPagesQuery )
      )
    : sitePages;
  const filteredPosts = normalizedPostsQuery
    ? sitePosts.filter( ( post ) =>
        normalizeTitle( post.title?.rendered || '' ).includes( normalizedPostsQuery )
      )
    : sitePosts;
  const pagesBadgeText = isSearchingPages
    ? `${ filteredPages.length } of ${ sitePages.length }`
    : `${ sitePages.length } total`;
  const postsBadgeText = isSearchingPosts
    ? `${ filteredPosts.length } of ${ sitePosts.length }`
    : `${ sitePosts.length } total`;

  const handleGenerate = () => {
    const prompt = heroPrompt.trim();
    if ( ! prompt ) return;
    onCreateWithPrompt( prompt );
  };

  const handleHeroKeyDown = ( e: React.KeyboardEvent<HTMLInputElement> ) => {
    if ( e.key === 'Enter' && heroPrompt.trim() ) {
      handleGenerate();
    }
  };

  return (
    <div className="ai-dashboard">
      <div className="ai-hero">
        <div className="ai-hero__content">
          <div className="ai-hero__chip">Intelligence Canvas</div>
          <h2 className="ai-hero__heading">Create New Page with AI</h2>
          <p className="ai-hero__sub">Leverage AI to generate high-converting layouts in seconds.</p>
          <div className="ai-hero__input-wrap">
            <input
              type="text"
              className="ai-hero__input"
              placeholder="Describe your dream page..."
              value={ heroPrompt }
              onChange={ ( e ) => setHeroPrompt( e.target.value ) }
              onKeyDown={ handleHeroKeyDown }
            />
            <button
              type="button"
              className="ai-hero__generate-btn"
              onClick={ handleGenerate }
              disabled={ ! heroPrompt.trim() }
            >
              <SparklesIcon className="icon-sm" />
              Generate
            </button>
          </div>
        </div>
        <div className="ai-hero__deco-icon" aria-hidden="true">
          <SparklesIcon style={ { color: '#ffffff', stroke: '#ffffff' } } />
        </div>
        <div className="ai-hero__deco-glow" aria-hidden="true"></div>
      </div>

      <div className="ai-dashboard-grid">
        <div className="ai-dashboard-content-card">
          <div className="ai-dashboard-content-header">
            <DocumentIcon className="icon" />
            <h3>Pages</h3>
            <span className="ai-dashboard-badge">{ pagesBadgeText }</span>
            <div className="ai-dashboard-search ai-dashboard-search--inline">
              <MagnifyingGlassIcon className="icon" />
              <input
                type="search"
                value={ pagesSearchQuery }
                onChange={ ( event ) => onPagesSearchChange( event.target.value ) }
                placeholder="Search pages"
                aria-label="Search pages"
              />
              { pagesSearchQuery && (
                <button
                  type="button"
                  className="ai-dashboard-search-clear"
                  onClick={ () => onPagesSearchChange( '' ) }
                  aria-label="Clear pages search"
                >
                  <XMarkIcon className="icon-sm" />
                </button>
              ) }
            </div>
          </div>
          <ul className="ai-dashboard-list">
            { loadingSite ? (
              <li className="ai-dashboard-loading">Loading...</li>
            ) : (
              <>
                { ( isSearchingPages ? filteredPages : ( pagesExpanded ? filteredPages : filteredPages.slice( 0, 5 ) ) ).map( ( page ) => (
                  <li key={ page.id } className="ai-dashboard-list-item" onClick={ () => onSelectItem( page ) }>
                    <DocumentIcon className="icon-sm" />
                    <span className="ai-dashboard-item-title" dangerouslySetInnerHTML={ { __html: page.title.rendered } } />
                    <span className="ai-dashboard-item-meta">{ formatDate( page.modified || page.date || '' ) }</span>
                    <span className={ `ai-badge ${ page.status === 'publish' ? 'ai-badge--published' : 'ai-badge--draft' }` }>
                      { page.status === 'publish' ? 'Published' : 'Draft' }
                    </span>
                  </li>
                ) ) }
                { ! loadingSite && filteredPages.length === 0 && (
                  <li className="ai-dashboard-empty">No pages found.</li>
                ) }
                { ! isSearchingPages && filteredPages.length > 5 && (
                  <li className="ai-dashboard-more" onClick={ onTogglePagesExpanded }>
                    { pagesExpanded ? 'Show less' : `+${ filteredPages.length - 5 } more` }
                  </li>
                ) }
              </>
            ) }
          </ul>
        </div>

        <div className="ai-dashboard-content-card">
          <div className="ai-dashboard-content-header">
            <BookOpenIcon className="icon" />
            <h3>Posts</h3>
            <span className="ai-dashboard-badge">{ postsBadgeText }</span>
            <div className="ai-dashboard-search ai-dashboard-search--inline">
              <MagnifyingGlassIcon className="icon" />
              <input
                type="search"
                value={ postsSearchQuery }
                onChange={ ( event ) => onPostsSearchChange( event.target.value ) }
                placeholder="Search posts"
                aria-label="Search posts"
              />
              { postsSearchQuery && (
                <button
                  type="button"
                  className="ai-dashboard-search-clear"
                  onClick={ () => onPostsSearchChange( '' ) }
                  aria-label="Clear posts search"
                >
                  <XMarkIcon className="icon-sm" />
                </button>
              ) }
            </div>
          </div>
          <ul className="ai-dashboard-list">
            { loadingSite ? (
              <li className="ai-dashboard-loading">Loading...</li>
            ) : (
              <>
                { ( isSearchingPosts ? filteredPosts : ( postsExpanded ? filteredPosts : filteredPosts.slice( 0, 5 ) ) ).map( ( post ) => (
                  <li key={ post.id } className="ai-dashboard-list-item" onClick={ () => onSelectItem( post ) }>
                    <BookOpenIcon className="icon-sm" />
                    <span className="ai-dashboard-item-title" dangerouslySetInnerHTML={ { __html: post.title.rendered } } />
                    <span className="ai-dashboard-item-meta">{ formatDate( post.modified || post.date || '' ) }</span>
                    <span className={ `ai-badge ${ post.status === 'publish' ? 'ai-badge--published' : 'ai-badge--draft' }` }>
                      { post.status === 'publish' ? 'Published' : 'Draft' }
                    </span>
                  </li>
                ) ) }
                { ! loadingSite && filteredPosts.length === 0 && (
                  <li className="ai-dashboard-empty">No posts found.</li>
                ) }
                { ! isSearchingPosts && filteredPosts.length > 5 && (
                  <li className="ai-dashboard-more" onClick={ onTogglePostsExpanded }>
                    { postsExpanded ? 'Show less' : `+${ filteredPosts.length - 5 } more` }
                  </li>
                ) }
              </>
            ) }
          </ul>
        </div>
      </div>
    </div>
  );
};

export default DashboardView;
