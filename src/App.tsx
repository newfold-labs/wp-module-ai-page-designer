import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useDispatch, useSelect } from '@wordpress/data';
import EmptyState from './components/workspace/EmptyState';
import Header from './components/workspace/Header';
import PageDrawer from './components/workspace/PageDrawer';
import WorkspaceShell from './components/workspace/WorkspaceShell';
import MetaStrip from './components/MetaStrip';
import PreviewFrame from './components/PreviewFrame';
import PublishModal from './components/PublishModal';
import RevertConfirm from './components/RevertConfirm';
import SidePanel from './components/SidePanel';
import { useAiConversation } from './hooks/useAiConversation';
import { useBlockSelection } from './hooks/useBlockSelection';
import { usePageRoute } from './hooks/usePageRoute';
import { usePreviewIframe } from './hooks/usePreviewIframe';
import { usePublishFlow } from './hooks/usePublishFlow';
import { useSiteContent } from './hooks/useSiteContent';
import { STORE_NAME } from './store/workspaceStore';
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
      previewMotionCss?: string;
    };
  }
}

const App = () => {
  const { nfdAIPageDesigner } = window;
  const [ previewHtml, setPreviewHtml ] = useState<string | null>( null );
  const [ originalPreviewHtml, setOriginalPreviewHtml ] = useState<string | null>( null );
  const [ selectedItem, setSelectedItem ] = useState<WPItem | null>( null );
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

  // Always active now — the page drawer needs the recent list regardless of
  // whether a page is currently open, unlike the old dashboard-only fetch.
  const { sitePages, sitePosts, loadingSite } = useSiteContent( nfdAIPageDesigner.apiUrl, true );
  const previewUrl = selectedItem?.link || nfdAIPageDesigner.siteUrl;
  // Owned here so it can be shared between useAiConversation and usePreviewIframe
  // without a declaration-order cycle (the preview hook needs conversation.isLoading).
  const iframeRef = useRef<HTMLIFrameElement>( null );
  // Last published preview snapshot; drives the unsaved-changes guard below.
  const publishedHtmlRef = useRef<string | null>( null );
  const { selectedBlockIndex, selectedBlockHtml, selectedBlockLabel, clearSelection } = useBlockSelection();
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

  // Declared after conversation so the preview can finalize (play animations once)
  // exactly when streaming ends, driven by conversation.isLoading.
  usePreviewIframe(
    previewHtml,
    previewUrl,
    nfdAIPageDesigner.previewStylesheets,
    conversation.isLoading,
    iframeRef,
    nfdAIPageDesigner.previewMotionCss || ''
  );

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
      publishedHtmlRef.current = previewHtml;
    },
    onPublished: ( item ) => {
      setSelectedItem( item );
      setOriginalMeta( {
        title: metaTitle,
        excerpt: metaExcerpt,
        featuredMediaId: metaFeaturedMediaId,
      } );
      // Mark the current preview as the published baseline so the
      // unsaved-changes guard goes quiet until the next edit.
      publishedHtmlRef.current = previewHtml;
    },
    appendAssistantMessage: conversation.appendAssistantMessage,
  } );

  // Warn before leaving with generated/edited content that hasn't been published.
  // publishedHtmlRef holds the last published snapshot; anything newer is unsaved.
  // Single source of truth shared by the native beforeunload handler (tab close /
  // refresh / full WP-admin navigation) and the in-app guard (New Page, page
  // switching), which reset state without triggering a page unload.
  const isDirty = () =>
    !! previewHtml &&
    ( conversation.hasAIGenerated || metaDirty ) &&
    previewHtml !== publishedHtmlRef.current &&
    ! publishFlow.publishing;

  // Confirm before an in-app action that would discard unpublished work.
  // Returns true when it's safe to proceed (clean, or the user accepted).
  const confirmDiscard = () => {
    if ( ! isDirty() ) {
      return true;
    }
    // eslint-disable-next-line no-alert
    return window.confirm(
      'You have unpublished changes that will be lost. Leave without publishing?'
    );
  };

  // dirtyRef is read inside the native beforeunload handler (no re-render needed).
  const dirtyRef = useRef( false );
  dirtyRef.current = isDirty();

  useEffect( () => {
    const handler = ( event: BeforeUnloadEvent ) => {
      if ( ! dirtyRef.current ) {
        return;
      }
      // Triggers the browser's native "Leave site? Changes you made may not be
      // saved." confirmation. The message text itself is browser-controlled.
      event.preventDefault();
      event.returnValue = '';
    };
    window.addEventListener( 'beforeunload', handler );
    return () => window.removeEventListener( 'beforeunload', handler );
  }, [] );

  const handleSelectItem = ( item: WPItem ) => {
    conversation.resetAiConversation();
    publishFlow.resetPublishState();
    setSelectedItem( item );

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
    if ( ! confirmDiscard() ) {
      return;
    }
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
    conversation.handleSend( prompt );
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

  // --- Workspace chrome: page routing + drawer/sidebar open state --------

  const { pageId: routePageId, setPageId } = usePageRoute();
  const { drawerOpen, sidebarOpen } = useSelect( ( select ) => {
    const store = select( STORE_NAME ) as any;
    return {
      drawerOpen: store.isDrawerOpen(),
      sidebarOpen: store.isSidebarOpen(),
    };
  }, [] );
  const { setDrawerOpen, setSidebarOpen } = useDispatch( STORE_NAME ) as any;

  const combinedSiteItems = useMemo(
    () => [ ...sitePages, ...sitePosts ],
    [ sitePages, sitePosts ]
  );

  // Resolve an incoming nfd_page_id (deep link, back/forward, manual edit)
  // to the matching page once site content is loaded.
  useEffect( () => {
    if ( routePageId === null || routePageId === selectedItem?.id ) {
      return;
    }
    const match = combinedSiteItems.find( ( item ) => item.id === routePageId );
    if ( match ) {
      handleSelectItem( match );
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ routePageId, combinedSiteItems ] );

  // Keep nfd_page_id in sync whenever the open page changes via the drawer, a
  // fresh generation being published, etc.
  useEffect( () => {
    setPageId( selectedItem?.id ?? null );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ selectedItem ] );

  const handleDrawerSelectItem = ( item: WPItem ) => {
    if ( ! confirmDiscard() ) {
      return;
    }
    handleSelectItem( item );
    setDrawerOpen( false );
  };

  const handleNewPage = () => {
    handleCreateNew();
    setDrawerOpen( false );
  };

  // admin-ajax.php's directory is always the wp-admin root, regardless of
  // multisite subdirectory installs — a reliable base without a new
  // localized value.
  const handleBackToAdmin = () => {
    const adminUrl = nfdAIPageDesigner.ajaxUrl.replace( /admin-ajax\.php$/, '' );
    window.location.href = adminUrl;
  };

  const pageTitle = metaTitle || conversation.publishTitle;
  const pageStatus: 'draft' | 'publish' | null = selectedItem
    ? ( selectedItem.status === 'publish' ? 'publish' : 'draft' )
    : ( conversation.hasAIGenerated ? 'draft' : null );
  const canPublish = conversation.hasAIGenerated || metaDirty;
  const hasContent = Boolean( previewHtml || selectedItem );

  const header = (
    <Header
      pageTitle={ pageTitle }
      pageStatus={ pageStatus }
      drawerOpen={ drawerOpen }
      onToggleDrawer={ () => setDrawerOpen( ! drawerOpen ) }
      sidebarOpen={ sidebarOpen }
      onToggleSidebar={ () => setSidebarOpen( ! sidebarOpen ) }
      onNewPage={ handleNewPage }
      onBackToAdmin={ handleBackToAdmin }
      canPublish={ canPublish }
      publishing={ publishFlow.publishing }
      onPublish={ handlePublishBarClick }
    />
  );

  const drawer = drawerOpen ? (
    <PageDrawer
      loading={ loadingSite }
      sitePages={ sitePages }
      sitePosts={ sitePosts }
      selectedItemId={ selectedItem?.id ?? null }
      onSelectItem={ handleDrawerSelectItem }
    />
  ) : null;

  const sidebar = sidebarOpen ? (
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
      selectedBlockLabel={ selectedBlockLabel }
      historyEntries={ conversation.historyEntries }
      previewHtml={ previewHtml }
      onInputChange={ conversation.setInput }
      onSend={ () => conversation.handleSend() }
      onClearSelection={ () => clearSelection( iframeRef ) }
      onPublish={ handlePublishBarClick }
      onRevertTo={ conversation.handleRevertToEntry }
    />
  ) : null;

  const content = (
    <div className="ai-workspace-canvas">
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
      { hasContent ? (
        <PreviewFrame
          previewHtml={ previewHtml }
          selectedItem={ selectedItem }
          iframeRef={ iframeRef }
        />
      ) : (
        <EmptyState onCreateWithPrompt={ handleCreateWithPrompt } />
      ) }
    </div>
  );

  return (
    <>
      <WorkspaceShell
        header={ header }
        drawer={ drawer }
        sidebar={ sidebar }
        content={ content }
      />

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
    </>
  );
};

export default App;
