import React from 'react';
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
  onCreateNew: () => void;
  onSelectItem: (item: WPItem) => void;
  onPagesSearchChange: (value: string) => void;
  onPostsSearchChange: (value: string) => void;
  onTogglePagesExpanded: () => void;
  onTogglePostsExpanded: () => void;
};

const normalizeTitle = ( title: string ) => title.replace( /<[^>]*>/g, '' ).toLowerCase();

const DashboardView = ( {
  loadingSite,
  sitePages,
  sitePosts,
  pagesSearchQuery,
  postsSearchQuery,
  pagesExpanded,
  postsExpanded,
  onCreateNew,
  onSelectItem,
  onPagesSearchChange,
  onPostsSearchChange,
  onTogglePagesExpanded,
  onTogglePostsExpanded,
}: Props ) => {
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

  return (
    <div className="ai-dashboard">
      <div className="ai-dashboard-actions">
        <div className="ai-dashboard-action-card" onClick={ onCreateNew }>
          <div className="ai-dashboard-action-icon ai-dashboard-action-icon--primary">
            <SparklesIcon className="icon" />
          </div>
          <h3>Create New Page with AI</h3>
          <p>Design a brand new page or post from scratch</p>
        </div>
      </div>

      <div className="ai-dashboard-divider">
        <div className="ai-dashboard-divider-line"></div>
        <span>OR</span>
        <div className="ai-dashboard-divider-line"></div>
      </div>

      <p className="ai-dashboard-description">
        Select an existing page or post to enhance with AI:
      </p>

      <div className="ai-dashboard-content">
        <div className="ai-dashboard-content-card">
          <div className="ai-dashboard-content-header">
            <DocumentIcon className="icon" />
            <h3>Pages</h3>
            <div className="ai-dashboard-search ai-dashboard-search--inline ai-dashboard-search--pages">
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
            <span className="ai-dashboard-badge">{ pagesBadgeText }</span>
          </div>
          <ul className="ai-dashboard-list">
            { loadingSite ? (
              <li className="ai-dashboard-loading">Loading...</li>
            ) : (
              <>
                { ( isSearchingPages ? filteredPages : ( pagesExpanded ? filteredPages : filteredPages.slice( 0, 5 ) ) ).map( ( page ) => (
                  <li key={ page.id } className="ai-dashboard-list-item" onClick={ () => onSelectItem( page ) }>
                    <DocumentIcon className="icon-sm" />
                    <span className="ai-dashboard-item-title">{ page.title.rendered }</span>
                    <span className="ai-dashboard-status">{ page.status }</span>
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
            <div className="ai-dashboard-search ai-dashboard-search--inline ai-dashboard-search--posts">
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
            <span className="ai-dashboard-badge">{ postsBadgeText }</span>
          </div>
          <ul className="ai-dashboard-list">
            { loadingSite ? (
              <li className="ai-dashboard-loading">Loading...</li>
            ) : (
              <>
                { ( isSearchingPosts ? filteredPosts : ( postsExpanded ? filteredPosts : filteredPosts.slice( 0, 5 ) ) ).map( ( post ) => (
                  <li key={ post.id } className="ai-dashboard-list-item" onClick={ () => onSelectItem( post ) }>
                    <BookOpenIcon className="icon-sm" />
                    <span className="ai-dashboard-item-title">{ post.title.rendered }</span>
                    <span className="ai-dashboard-status">{ post.status }</span>
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
