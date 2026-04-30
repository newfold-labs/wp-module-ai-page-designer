import React, { useEffect, useMemo, useState } from 'react';
import { PlusIcon, SparklesIcon, Squares2X2Icon } from '@heroicons/react/24/outline';
import DashboardView from './components/DashboardView';
import MetaStrip from './components/MetaStrip';
import PreviewFrame from './components/PreviewFrame';
import PublishModal from './components/PublishModal';
import RevertConfirm from './components/RevertConfirm';
import SidePanel from './components/SidePanel';
import { useAiConversation } from './hooks/useAiConversation';
import { useBlockSelection } from './hooks/useBlockSelection';
import { usePreviewIframe } from './hooks/usePreviewIframe';
import { usePublishFlow } from './hooks/usePublishFlow';
import { useSiteContent } from './hooks/useSiteContent';
import { convertHtmlToGutenberg, hasGutenbergMarkers } from './util/aiDesignerHelpers';
import type { WPItem } from './types';

declare global {
  interface Window {
    nfdAIPageDesigner: {
      apiUrl: string;
      apiRoot: string;
      nonce: string;
      siteUrl: string;
      canAccessAI: boolean;
      currentUserId: number;
      ajaxUrl: string;
      enableStreaming?: boolean;
      previewStylesheets?: {
        blockLibrary: string;
        themeUrl: string;
        globalStyles: string;
      };
      colorPalette?: Array<{ slug: string; name: string; color: string }>;
    };
  }
}

const App = () => {
  const { nfdAIPageDesigner } = window;
  const [ previewHtml, setPreviewHtml ] = useState<string | null>( null );
  const [ originalPreviewHtml, setOriginalPreviewHtml ] = useState<string | null>( null );
  const [ selectedItem, setSelectedItem ] = useState<WPItem | null>( null );
  const [ view, setView ] = useState<'dashboard' | 'designer'>( 'dashboard' );
  const [ pagesExpanded, setPagesExpanded ] = useState( false );
  const [ postsExpanded, setPostsExpanded ] = useState( false );
  const [ pagesSearchQuery, setPagesSearchQuery ] = useState( '' );
  const [ postsSearchQuery, setPostsSearchQuery ] = useState( '' );
  const [ publishTitle, setPublishTitle ] = useState( '' );
  const [ metaTitle, setMetaTitle ] = useState( '' );
  const [ metaExcerpt, setMetaExcerpt ] = useState( '' );
  const [ metaFeaturedMediaId, setMetaFeaturedMediaId ] = useState<number | null>( null );
  const [ metaFeaturedImageUrl, setMetaFeaturedImageUrl ] = useState<string | null>( null );
  const [ originalMeta, setOriginalMeta ] = useState<{
    title: string;
    excerpt: string;
    featuredMediaId: number | null;
  } | null>( null );

  const stripHtml = ( value: string ) => value.replace( /<[^>]*>/g, '' );

  const { sitePages, sitePosts, loadingSite } = useSiteContent(
    nfdAIPageDesigner.apiUrl,
    'dashboard' === view
  );
  const previewUrl = selectedItem?.link || nfdAIPageDesigner.siteUrl;
  const { iframeRef } = usePreviewIframe(
    previewHtml,
    previewUrl,
    nfdAIPageDesigner.previewStylesheets
  );
  const { selectedBlockIndex, selectedBlockHtml, clearSelection } = useBlockSelection();
  const canUseMedia = Boolean( ( window as any )?.wp?.media );
  const supportsThumbnail = selectedItem?.supports_thumbnail !== false;
  const metaDirty = useMemo( () => {
    if ( ! selectedItem || ! originalMeta ) {
      return false;
    }
    return (
      metaTitle !== originalMeta.title ||
      metaExcerpt !== originalMeta.excerpt ||
      metaFeaturedMediaId !== originalMeta.featuredMediaId
    );
  }, [ metaExcerpt, metaFeaturedMediaId, metaTitle, originalMeta, selectedItem ] );

  const conversation = useAiConversation( {
    apiUrl: nfdAIPageDesigner.apiUrl,
    previewHtml,
    originalPreviewHtml,
    publishTitle,
    metaTitle,
    metaExcerpt,
    selectedItem,
    selectedBlockIndex,
    selectedBlockHtml,
    iframeRef,
    setPreviewHtml,
    setPublishTitle,
    setMetaTitle,
    setMetaExcerpt,
    setMetaFeaturedImageUrl,
    clearSelection,
  } );

  const publishFlow = usePublishFlow( {
    apiUrl: nfdAIPageDesigner.apiUrl,
    previewHtml,
    publishTitle: conversation.publishTitle,
    metaTitle,
    metaExcerpt,
    metaFeaturedMediaId,
    onMetaUpdated: ( item ) => {
      const nextTitle = stripHtml( item.title?.rendered || '' );
      const nextExcerpt = item.excerpt?.raw || '';
      const nextFeaturedMediaId = typeof item.featured_media === 'number' ? item.featured_media : 0;
      const nextFeaturedImageUrl = item.featured_image_url || null;
      setMetaTitle( nextTitle );
      setMetaExcerpt( nextExcerpt );
      setMetaFeaturedMediaId( nextFeaturedMediaId );
      setMetaFeaturedImageUrl( nextFeaturedImageUrl );
      setOriginalMeta( {
        title: nextTitle,
        excerpt: nextExcerpt,
        featuredMediaId: nextFeaturedMediaId,
      } );
    },
    onPublished: ( item ) => {
      setSelectedItem( item );
      setOriginalMeta( {
        title: metaTitle,
        excerpt: metaExcerpt,
        featuredMediaId: metaFeaturedMediaId,
      } );
    },
    appendAssistantMessage: conversation.appendAssistantMessage,
  } );

  const handleSelectItem = ( item: WPItem ) => {
    conversation.resetAiConversation();
    publishFlow.resetPublishState();
    setSelectedItem( item );
    setView( 'designer' );

    const rawHtml = item.content?.raw || item.content?.rendered || '';
    const baseHtml = rawHtml && ! hasGutenbergMarkers( rawHtml )
      ? convertHtmlToGutenberg( rawHtml )
      : rawHtml;
    setOriginalPreviewHtml( baseHtml );
    setPreviewHtml( baseHtml );
    const nextTitle = stripHtml( item.title?.rendered || '' );
    const nextExcerpt = item.excerpt?.raw || '';
    const nextFeaturedMediaId = typeof item.featured_media === 'number' ? item.featured_media : 0;
    setMetaTitle( nextTitle );
    setMetaExcerpt( nextExcerpt );
    setMetaFeaturedMediaId( nextFeaturedMediaId );
    setMetaFeaturedImageUrl( item.featured_image_url || null );
    setOriginalMeta( {
      title: nextTitle,
      excerpt: nextExcerpt,
      featuredMediaId: nextFeaturedMediaId,
    } );
    setPublishTitle( '' );
  };

  const handleCreateNew = () => {
    conversation.resetAiConversation();
    publishFlow.resetPublishState();
    setSelectedItem( null );
    setPreviewHtml( null );
    setOriginalPreviewHtml( null );
    setMetaTitle( '' );
    setMetaExcerpt( '' );
    setMetaFeaturedMediaId( null );
    setMetaFeaturedImageUrl( null );
    setOriginalMeta( null );
    setPublishTitle( '' );
    setView( 'designer' );
  };

  const handleCreateWithPrompt = ( prompt: string ) => {
    conversation.resetAiConversation();
    publishFlow.resetPublishState();
    setSelectedItem( null );
    setPreviewHtml( null );
    setOriginalPreviewHtml( null );
    setMetaTitle( '' );
    setMetaExcerpt( '' );
    setMetaFeaturedMediaId( null );
    setMetaFeaturedImageUrl( null );
    setOriginalMeta( null );
    setPublishTitle( '' );
    setView( 'designer' );
    conversation.handleSend( prompt );
  };

  const handleShowDashboard = () => {
    conversation.resetAiConversation();
    publishFlow.resetPublishState();
    setSelectedItem( null );
    setPreviewHtml( null );
    setOriginalPreviewHtml( null );
    setMetaTitle( '' );
    setMetaExcerpt( '' );
    setMetaFeaturedMediaId( null );
    setMetaFeaturedImageUrl( null );
    setOriginalMeta( null );
    setPublishTitle( '' );
    setView( 'dashboard' );
  };

  const handleShowDesigner = () => {
    conversation.resetAiConversation();
    publishFlow.resetPublishState();
    setView( 'designer' );
  };

  const handleRevertConfirm = () => {
    conversation.handleConfirmRevertChanges();
    publishFlow.closeRevertConfirm();
    if ( originalMeta ) {
      setMetaTitle( originalMeta.title );
      setMetaExcerpt( originalMeta.excerpt );
      setMetaFeaturedMediaId( originalMeta.featuredMediaId );
      setMetaFeaturedImageUrl( selectedItem?.featured_image_url || null );
    }
  };

  const handlePublishBarClick = () => {
    if ( selectedItem ) {
      publishFlow.handleReplaceItem( selectedItem );
      return;
    }
    publishFlow.openPublishModal();
  };

  const handlePickImage = () => {
    const wpMedia = ( window as any )?.wp?.media;
    if ( ! wpMedia ) {
      return;
    }
    const frame = wpMedia( {
      title: 'Select featured image',
      button: { text: 'Use image' },
      library: { type: 'image' },
      multiple: false,
    } );

    frame.on( 'select', () => {
      const attachment = frame.state().get( 'selection' ).first()?.toJSON();
      if ( attachment?.id ) {
        const url =
          attachment?.sizes?.medium?.url ||
          attachment?.sizes?.large?.url ||
          attachment?.url ||
          '';
        setMetaFeaturedMediaId( attachment.id );
        setMetaFeaturedImageUrl( url || null );
      }
    } );

    frame.open();
  };

  const handleRemoveImage = () => {
    setMetaFeaturedMediaId( 0 );
    setMetaFeaturedImageUrl( null );
  };

  useEffect( () => {
    if ( ! selectedItem ) {
      return;
    }
    setMetaFeaturedImageUrl( selectedItem.featured_image_url || null );
  }, [ selectedItem ] );

  const header = (
    <header className="ai-designer-header">
      <div className="ai-designer-header__left">
        <span className="ai-designer-header__logo">AI Page Designer</span>
        <nav className="ai-designer-header__nav">
          <button
            type="button"
            className={ `ai-designer-header__nav-btn ${ view === 'dashboard' ? 'active' : '' }` }
            onClick={ handleShowDashboard }
          >
            <Squares2X2Icon className="icon" />
            Dashboard
          </button>
          <button
            type="button"
            className={ `ai-designer-header__nav-btn ${ view === 'designer' ? 'active' : '' }` }
            onClick={ handleShowDesigner }
          >
            <SparklesIcon className="icon" />
            Designer
          </button>
        </nav>
      </div>
      <div className="ai-designer-header__right">
        { view === 'designer' && ( conversation.hasAIGenerated || metaDirty ) && (
          <button
            type="button"
            className="ai-btn ai-btn--primary"
            onClick={ handlePublishBarClick }
            disabled={ publishFlow.publishing }
          >
            { publishFlow.publishing ? 'Publishing...' : 'Publish Page' }
          </button>
        ) }
        <button
          type="button"
          className="ai-btn ai-btn--primary ai-btn--create-new"
          onClick={ handleCreateNew }
        >
          <PlusIcon className="icon" />
          Create New
        </button>
      </div>
    </header>
  );

  if ( 'dashboard' === view ) {
    return (
      <div id="nfd-ai-page-designer-root" className="ai-designer-container">
        { header }
        <div className="ai-designer-body">
          <DashboardView
            loadingSite={ loadingSite }
            sitePages={ sitePages }
            sitePosts={ sitePosts }
            pagesSearchQuery={ pagesSearchQuery }
            postsSearchQuery={ postsSearchQuery }
            pagesExpanded={ pagesExpanded }
            postsExpanded={ postsExpanded }
            onCreateWithPrompt={ handleCreateWithPrompt }
            onSelectItem={ handleSelectItem }
            onPagesSearchChange={ setPagesSearchQuery }
            onPostsSearchChange={ setPostsSearchQuery }
            onTogglePagesExpanded={ () => setPagesExpanded( ( value ) => ! value ) }
            onTogglePostsExpanded={ () => setPostsExpanded( ( value ) => ! value ) }
          />
        </div>
      </div>
    );
  }

  return (
    <div id="nfd-ai-page-designer-root" className="ai-designer-container">
      { header }
      <div className="ai-designer-body">
        <div className="ai-designer-main">
          <MetaStrip
            visible={ Boolean( selectedItem ) || conversation.hasAIGenerated }
            title={ metaTitle }
            excerpt={ metaExcerpt }
            featuredImageUrl={ metaFeaturedImageUrl }
            featuredMediaId={ metaFeaturedMediaId }
            supportsThumbnail={ supportsThumbnail }
            canUseMedia={ canUseMedia }
            onChangeTitle={ setMetaTitle }
            onChangeExcerpt={ setMetaExcerpt }
            onPickImage={ handlePickImage }
            onRemoveImage={ handleRemoveImage }
          />
          <div className="ai-designer-content">
            <SidePanel
              messages={ conversation.messages }
              chatMessagesRef={ conversation.chatMessagesRef }
              isLoading={ conversation.isLoading }
              hasAIGenerated={ conversation.hasAIGenerated }
              metaDirty={ metaDirty }
              publishing={ publishFlow.publishing }
              selectedItem={ selectedItem }
              input={ conversation.input }
              selectedBlockIndex={ selectedBlockIndex }
              historyEntries={ conversation.historyEntries }
              colorPalette={ nfdAIPageDesigner.colorPalette || [] }
              previewHtml={ previewHtml }
              onInputChange={ conversation.setInput }
              onSend={ () => conversation.handleSend() }
              onClearSelection={ () => clearSelection( iframeRef ) }
              onPublish={ handlePublishBarClick }
              onRevertTo={ conversation.handleRevertToEntry }
              onApplyDirectChange={ conversation.applyDirectChange }
            />

            <PreviewFrame
              previewHtml={ previewHtml }
              selectedItem={ selectedItem }
              iframeRef={ iframeRef }
            />
          </div>
        </div>
      </div>

      <RevertConfirm
        open={ publishFlow.showRevertConfirm }
        selectedItem={ selectedItem }
        onClose={ publishFlow.closeRevertConfirm }
        onConfirm={ handleRevertConfirm }
      />

      <PublishModal
        open={ publishFlow.showPublishModal }
        selectedItem={ selectedItem }
        sitePages={ sitePages }
        sitePosts={ sitePosts }
        publishing={ publishFlow.publishing }
        publishStatus={ publishFlow.publishStatus }
        onClose={ publishFlow.closePublishModal }
        onPublish={ publishFlow.handlePublish }
        onReplaceItem={ publishFlow.handleReplaceItem }
      />
    </div>
  );
};

export default App;
