import React from 'react';
import {
  ArrowPathIcon,
  ArrowTopRightOnSquareIcon,
  DocumentIcon,
  HomeIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline';
import type { PublishStatus, WPItem } from '../types';

type Props = {
  open: boolean;
  selectedItem: WPItem | null;
  sitePages: WPItem[];
  sitePosts: WPItem[];
  publishing: boolean;
  publishStatus: PublishStatus;
  onClose: () => void;
  onPublish: (type: 'new_post' | 'new_page' | 'homepage') => void;
  onReplaceItem: (item: WPItem) => void;
};

const PublishModal = ( {
  open,
  selectedItem,
  sitePages,
  sitePosts,
  publishing,
  publishStatus,
  onClose,
  onPublish,
  onReplaceItem,
}: Props ) => {
  if ( ! open || selectedItem ) {
    return null;
  }

  return (
    <div className="publish-modal-overlay" onClick={ onClose }>
      <div className="publish-modal" onClick={ ( event ) => event.stopPropagation() }>
        <div className="publish-modal-header">
          <div>
            <h2>Publish Options</h2>
            <p>Choose how to publish this content to WordPress.</p>
          </div>
          <button className="publish-modal-close" onClick={ onClose }>
            <XMarkIcon className="icon" />
          </button>
        </div>

        { publishStatus && (
          <div className={ `publish-status publish-status--${ publishStatus.type }` }>
            { publishStatus.message }
          </div>
        ) }

        <div className="publish-modal-options">
          <button className="publish-option" onClick={ () => onPublish( 'new_post' ) } disabled={ publishing }>
            <div className="publish-option-icon">
              <ArrowTopRightOnSquareIcon className="icon" />
            </div>
            <div className="publish-option-text">
              <strong>Blog Post</strong>
              <span>Publish as a new blog post</span>
            </div>
          </button>
          <button className="publish-option" onClick={ () => onPublish( 'new_page' ) } disabled={ publishing }>
            <div className="publish-option-icon">
              <DocumentIcon className="icon" />
            </div>
            <div className="publish-option-text">
              <strong>New Page</strong>
              <span>Publish as a standalone page</span>
            </div>
          </button>
          <button className="publish-option" onClick={ () => onPublish( 'homepage' ) } disabled={ publishing }>
            <div className="publish-option-icon">
              <HomeIcon className="icon" />
            </div>
            <div className="publish-option-text">
              <strong>Set as Homepage</strong>
              <span>Create page &amp; set as site front page</span>
            </div>
          </button>
        </div>

        <div className="publish-modal-section">
          <h4 className="publish-modal-section-title">PAGES</h4>
          <ul className="publish-modal-list">
            { sitePages.map( ( page ) => (
              <li
                key={ page.id }
                className="publish-modal-list-item"
                onClick={ () => ! publishing && onReplaceItem( page ) }
              >
                <ArrowPathIcon className="icon" />
                <span>{ page.title.rendered }</span>
              </li>
            ) ) }
          </ul>
        </div>

        <div className="publish-modal-section">
          <h4 className="publish-modal-section-title">POSTS</h4>
          <ul className="publish-modal-list">
            { sitePosts.map( ( post ) => (
              <li
                key={ post.id }
                className="publish-modal-list-item"
                onClick={ () => ! publishing && onReplaceItem( post ) }
              >
                <ArrowPathIcon className="icon" />
                <span>{ post.title.rendered }</span>
              </li>
            ) ) }
          </ul>
        </div>
      </div>
    </div>
  );
};

export default PublishModal;
