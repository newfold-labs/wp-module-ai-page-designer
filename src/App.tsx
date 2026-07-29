import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useDispatch, useSelect } from '@wordpress/data';
import CommandPalette from './components/workspace/CommandPalette';
import EmptyState from './components/workspace/EmptyState';
import Header from './components/workspace/Header';
import PageDrawer from './components/workspace/PageDrawer';
import WorkspaceShell from './components/workspace/WorkspaceShell';
import MetaStrip from './components/MetaStrip';
import PreviewFrame from './components/PreviewFrame';
import PublishModal from './components/PublishModal';
import RevertConfirm from './components/RevertConfirm';
import SidePanel from './components/SidePanel';
import { buildThemePalettes, CURATED_FONT_PAIRINGS, CURATED_PALETTES, type PersistedDesignTokens } from './designTokens';
import { useAiConversation } from './hooks/useAiConversation';
import { useBlockSelection } from './hooks/useBlockSelection';
import { usePageRoute } from './hooks/usePageRoute';
import { usePreviewIframe } from './hooks/usePreviewIframe';
import { usePublishFlow } from './hooks/usePublishFlow';
import { useRecentItems } from './hooks/useRecentItems';
import { useSiteContent } from './hooks/useSiteContent';
import { STORE_NAME } from './store/workspaceStore';
import { convertHtmlToGutenberg, decodeHtmlEntities, hasGutenbergMarkers } from './util/aiDesignerHelpers';
import { createPageSessionCache, type PageSession } from './util/pageSessionCache';
import { suggestPalette } from './util/intentClassifier';
import type { WPItem } from './types';

const DEFAULT_FONT_PAIRING_ID = 'default';

// Auto-picked for brand-new pages instead of always starting on
// DEFAULT_FONT_PAIRING_ID (theme default, no override) — otherwise every
// generated page looks like plain, undifferentiated theme type by default,
// and the curated pairings (see designTokens.ts) go undiscovered unless a
// user happens to open the Design tab. Deterministic per site (hashes the
// site URL, never randomly) rather than always the same pairing, mirroring
// HeroCover::resolve_variant()'s crc32-hash approach on the PHP side — so
// sites still diverge from each other instead of all converging on one look.
const AUTO_FONT_PAIRING_IDS = CURATED_FONT_PAIRINGS
  .map( ( pairing ) => pairing.id )
  .filter( ( id ) => id !== DEFAULT_FONT_PAIRING_ID );

function pickAutoFontPairingId( seed: string ): string {
  let hash = 0;
  for ( let i = 0; i < seed.length; i++ ) {
    hash = ( hash * 31 + seed.charCodeAt( i ) ) >>> 0;
  }
  return AUTO_FONT_PAIRING_IDS[ hash % AUTO_FONT_PAIRING_IDS.length ];
}

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
  // Design tab: per-page palette/font selection. null palette = theme default
  // (no CSS-var override); 'default' font pairing = theme default fonts.
  const [ selectedPaletteId, setSelectedPaletteId ] = useState<string | null>( null );
  const [ selectedFontPairingId, setSelectedFontPairingId ] = useState( DEFAULT_FONT_PAIRING_ID );
  // Baseline to diff selectedPaletteId/selectedFontPairingId against for
  // dirtiness (mirrors originalMeta) — null means "no saved selection to
  // compare against" (new page, or an existing page that's never had one).
  const [ originalDesignTokens, setOriginalDesignTokens ] = useState<{
    paletteId: string | null;
    fontPairingId: string;
  } | null>( null );
  const [ suggestingPalette, setSuggestingPalette ] = useState( false );

  // get_the_title() entity-escapes ("&#038;" for "&") since it's meant for
  // inline HTML output — decode so plain-text fields like the meta title
  // input show the real character instead of the raw entity.
  const stripHtml = ( value: string ) => decodeHtmlEntities( value.replace( /<[^>]*>/g, '' ) );

  // Always active now — the page drawer needs the recent list regardless of
  // whether a page is currently open, unlike the old dashboard-only fetch.
  const { sitePages, sitePosts } = useSiteContent( nfdAIPageDesigner.apiUrl, true );
  const { recentItems, loadingRecent, touchRecent } = useRecentItems( nfdAIPageDesigner.apiUrl );
  const previewUrl = selectedItem?.link || nfdAIPageDesigner.siteUrl;
  // On-brand alternative to the hand-picked CURATED_PALETTES, derived from
  // this site's own theme.json colors (empty if the active theme doesn't
  // expose the full 10-role Blueprint schema — Design tab falls back to
  // curated-only in that case). nfdAIPageDesigner is a stable window global
  // localized once on page load, so this never needs to recompute.
  const themePalettes = useMemo(
    () => buildThemePalettes( nfdAIPageDesigner.colorPalette ),
    [ nfdAIPageDesigner.colorPalette ]
  );
  const allPalettes = useMemo(
    () => [ ...themePalettes, ...CURATED_PALETTES ],
    [ themePalettes ]
  );
  // Owned here so it can be shared between useAiConversation and usePreviewIframe
  // without a declaration-order cycle (the preview hook needs conversation.isLoading).
  const iframeRef = useRef<HTMLIFrameElement>( null );
  // Last published preview snapshot; drives the unsaved-changes guard below.
  // Per-page (not a single global baseline) via the session cache below —
  // otherwise publishing page B would make a clean, already-published page A
  // look dirty the next time it's reopened.
  const publishedHtmlRef = useRef<string | null>( null );
  // Last ~5 visited pages' full editing state, so switching back is instant
  // instead of re-fetching/resetting (plan section 3: Client state).
  const sessionCacheRef = useRef( createPageSessionCache() );
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
  // Whether the current selection differs from what's saved (or, with no
  // baseline yet, whether anything non-default is selected at all).
  const designTokensDirty = useMemo( () => {
    if ( ! originalDesignTokens ) {
      return selectedPaletteId !== null || selectedFontPairingId !== DEFAULT_FONT_PAIRING_ID;
    }
    return (
      selectedPaletteId !== originalDesignTokens.paletteId ||
      selectedFontPairingId !== originalDesignTokens.fontPairingId
    );
  }, [ originalDesignTokens, selectedFontPairingId, selectedPaletteId ] );

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
  // Non-null only while a scoped (selected-block) edit is actively streaming —
  // clearSelection() isn't called until the response settles, so selectedBlockIndex
  // stays populated for the whole generation and doubles as the "which section is
  // this edit targeting" signal for the skeleton highlight.
  const streamingTargetBlockIndex =
    conversation.isLoading && selectedBlockIndex !== null ? selectedBlockIndex : null;

  // Memoized so usePreviewIframe's design-tokens effect (and usePublishFlow's
  // save-on-publish) only re-fire when the actual selection changes, not on
  // every unrelated App render. Carries paletteId/fontPairingId (for
  // persistence/UI restore) alongside the resolved colors/fonts (for the
  // live preview swap) — see PersistedDesignTokens.
  const designTokens: PersistedDesignTokens = useMemo( () => {
    const palette = selectedPaletteId
      ? allPalettes.find( ( item ) => item.id === selectedPaletteId )
      : null;
    const fontPairing = CURATED_FONT_PAIRINGS.find( ( item ) => item.id === selectedFontPairingId );
    return {
      paletteId: selectedPaletteId,
      fontPairingId: selectedFontPairingId,
      colors: palette?.colors ?? null,
      headingFont: fontPairing?.headingFont || '',
      bodyFont: fontPairing?.bodyFont || '',
    };
  }, [ selectedPaletteId, selectedFontPairingId, allPalettes ] );

  usePreviewIframe(
    previewHtml,
    previewUrl,
    nfdAIPageDesigner.previewStylesheets,
    conversation.isLoading,
    iframeRef,
    nfdAIPageDesigner.previewMotionCss || '',
    streamingTargetBlockIndex,
    designTokens
  );

  const publishFlow = usePublishFlow( {
    apiUrl: nfdAIPageDesigner.apiUrl,
    previewHtml,
    publishTitle: conversation.publishTitle,
    metaTitle,
    metaExcerpt,
    metaFeaturedMediaId,
    designTokens,
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
      // Design tokens were saved alongside this update (usePublishFlow
      // includes them in the same request) — the current selection is now
      // the saved baseline, so the publish gate goes quiet until it changes.
      setOriginalDesignTokens( { paletteId: selectedPaletteId, fontPairingId: selectedFontPairingId } );
      publishedHtmlRef.current = previewHtml;
    },
    onPublished: ( item ) => {
      setSelectedItem( item );
      setOriginalMeta( {
        title: metaTitle,
        excerpt: metaExcerpt,
        featuredMediaId: metaFeaturedMediaId,
      } );
      setOriginalDesignTokens( { paletteId: selectedPaletteId, fontPairingId: selectedFontPairingId } );
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
    ! publishFlow.publishing &&
    (
      // Content/meta dirty only counts once the markup itself has actually
      // moved away from the last published snapshot.
      ( !! previewHtml && ( conversation.hasAIGenerated || metaDirty ) && previewHtml !== publishedHtmlRef.current ) ||
      // Design tokens are a presentation overlay, not a markup change — they
      // never move previewHtml, so they need their own independent check
      // rather than being ANDed against the previewHtml-changed condition.
      designTokensDirty
    );

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

  // Snapshot the page being left (if it's a real, previously-published item —
  // an unsaved new-page draft has no stable id to cache under and is
  // intentionally lost on navigating away, same as before this feature).
  const snapshotOutgoingPage = () => {
    if ( ! selectedItem ) {
      return;
    }
    sessionCacheRef.current.set( selectedItem.id, {
      previewHtml,
      originalPreviewHtml,
      metaTitle,
      metaExcerpt,
      metaFeaturedMediaId,
      metaFeaturedImageUrl,
      publishTitle,
      originalMeta,
      publishedHtml: publishedHtmlRef.current,
      conversation: {
        messages: conversation.messages,
        historyEntries: conversation.historyEntries,
        hasAIGenerated: conversation.hasAIGenerated,
        conversationId: conversation.conversationId,
        responseId: conversation.responseId,
      },
      selectedPaletteId,
      selectedFontPairingId,
      originalDesignTokens,
    } );
  };

  const restorePageSession = ( session: PageSession ) => {
    conversation.restoreConversation( session.conversation );
    publishedHtmlRef.current = session.publishedHtml;
    setOriginalPreviewHtml( session.originalPreviewHtml );
    setPreviewHtml( session.previewHtml );
    setMetaTitle( session.metaTitle );
    setMetaExcerpt( session.metaExcerpt );
    setMetaFeaturedMediaId( session.metaFeaturedMediaId );
    setMetaFeaturedImageUrl( session.metaFeaturedImageUrl );
    setOriginalMeta( session.originalMeta );
    setPublishTitle( session.publishTitle );
    setSelectedPaletteId( session.selectedPaletteId );
    setSelectedFontPairingId( session.selectedFontPairingId );
    setOriginalDesignTokens( session.originalDesignTokens );
  };

  const handleSelectItem = ( item: WPItem ) => {
    snapshotOutgoingPage();
    publishFlow.resetPublishState();
    setSelectedItem( item );
    touchRecent( item.id );

    const cached = sessionCacheRef.current.get( item.id );
    if ( cached ) {
      restorePageSession( cached );
      return;
    }

    conversation.resetAiConversation();
    publishedHtmlRef.current = null;
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
    // Restore the page's saved Design tab selection (post meta, per
    // WordPressProxyController::DESIGN_TOKENS_META_KEY) rather than always
    // resetting to theme default — otherwise a saved palette would silently
    // "disappear" from the UI (though not the published page) every time
    // the page is reopened in a fresh session.
    setSelectedPaletteId( item.design_tokens?.paletteId ?? null );
    setSelectedFontPairingId( item.design_tokens?.fontPairingId ?? DEFAULT_FONT_PAIRING_ID );
    setOriginalDesignTokens( {
      paletteId: item.design_tokens?.paletteId ?? null,
      fontPairingId: item.design_tokens?.fontPairingId ?? DEFAULT_FONT_PAIRING_ID,
    } );
  };

  const handleCreateNew = () => {
    if ( ! confirmDiscard() ) {
      return;
    }
    snapshotOutgoingPage();
    conversation.resetAiConversation();
    publishFlow.resetPublishState();
    publishedHtmlRef.current = null;
    setSelectedItem( null );
    setPreviewHtml( null );
    setOriginalPreviewHtml( null );
    setMetaTitle( '' );
    setMetaExcerpt( '' );
    setMetaFeaturedMediaId( null );
    setMetaFeaturedImageUrl( null );
    setOriginalMeta( null );
    setPublishTitle( '' );
    setSelectedPaletteId( null );
    // Auto-pick a font pairing (see pickAutoFontPairingId's note) and seed
    // the baseline with the SAME value — otherwise the auto-pick would
    // immediately read as an unsaved user change (designTokensDirty diffs
    // against DEFAULT_FONT_PAIRING_ID with no baseline), firing the
    // unpublished-changes guard on a page nobody has touched yet.
    const autoFontPairingId = pickAutoFontPairingId( nfdAIPageDesigner.siteUrl );
    setSelectedFontPairingId( autoFontPairingId );
    setOriginalDesignTokens( { paletteId: null, fontPairingId: autoFontPairingId } );
  };

  const handleCreateWithPrompt = ( prompt: string ) => {
    snapshotOutgoingPage();
    conversation.resetAiConversation();
    publishFlow.resetPublishState();
    publishedHtmlRef.current = null;
    setSelectedItem( null );
    setPreviewHtml( null );
    setOriginalPreviewHtml( null );
    setMetaTitle( '' );
    setMetaExcerpt( '' );
    setMetaFeaturedMediaId( null );
    setMetaFeaturedImageUrl( null );
    setOriginalMeta( null );
    setPublishTitle( '' );
    setSelectedPaletteId( null );
    // See the matching note in handleCreateNew().
    const autoFontPairingId = pickAutoFontPairingId( nfdAIPageDesigner.siteUrl );
    setSelectedFontPairingId( autoFontPairingId );
    setOriginalDesignTokens( { paletteId: null, fontPairingId: autoFontPairingId } );
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
  const { drawerOpen, sidebarOpen, viewport } = useSelect( ( select ) => {
    const store = select( STORE_NAME ) as any;
    return {
      drawerOpen: store.isDrawerOpen(),
      sidebarOpen: store.isSidebarOpen(),
      viewport: store.getViewport(),
    };
  }, [] );
  const { setDrawerOpen, setSidebarOpen, setViewport } = useDispatch( STORE_NAME ) as any;
  const [ commandPaletteOpen, setCommandPaletteOpen ] = useState( false );

  // Cmd/Ctrl+K opens the command palette from anywhere in the workspace
  // (plan section 2: <CommandPalette /> — server-side search). Captured
  // ahead of bubble-phase listeners and stopped there so WP core's own
  // Cmd+K admin command palette doesn't also fire underneath our fullscreen
  // shell — the two would otherwise show a confusing double overlay.
  useEffect( () => {
    const handler = ( event: KeyboardEvent ) => {
      if ( ( event.metaKey || event.ctrlKey ) && event.key.toLowerCase() === 'k' ) {
        event.preventDefault();
        event.stopPropagation();
        setCommandPaletteOpen( true );
      }
    };
    window.addEventListener( 'keydown', handler, { capture: true } );
    return () => window.removeEventListener( 'keydown', handler, { capture: true } );
  }, [] );

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

  const handleCommandPaletteSelectItem = ( item: WPItem ) => {
    if ( ! confirmDiscard() ) {
      return;
    }
    handleSelectItem( item );
  };

  const handleSelectPalette = ( paletteId: string | null ) => {
    setSelectedPaletteId( paletteId );
    const palette = paletteId ? allPalettes.find( ( item ) => item.id === paletteId ) : null;
    conversation.addHistoryEntry( `Palette → ${ palette ? palette.name : 'Theme default' }` );
  };

  const handleSuggestPalette = async () => {
    if ( ! previewHtml || suggestingPalette ) {
      return;
    }
    setSuggestingPalette( true );
    try {
      const paletteId = await suggestPalette(
        nfdAIPageDesigner.apiUrl,
        previewHtml,
        allPalettes.map( ( palette ) => ( { id: palette.id, name: palette.name } ) )
      );
      if ( paletteId ) {
        handleSelectPalette( paletteId );
      }
    } finally {
      setSuggestingPalette( false );
    }
  };

  const handleSelectFontPairing = ( fontPairingId: string ) => {
    setSelectedFontPairingId( fontPairingId );
    const pairing = CURATED_FONT_PAIRINGS.find( ( item ) => item.id === fontPairingId );
    conversation.addHistoryEntry( `Typography → ${ pairing ? pairing.name : 'Theme default' }` );
  };

  // admin-ajax.php's directory is always the wp-admin root, regardless of
  // multisite subdirectory installs — a reliable base without a new
  // localized value. `page=web#/home` is the parent plugin's own "Home" menu
  // item (registered in wp-plugin-web's Admin::subpages()), so this returns
  // to the Network Solutions dashboard rather than generic wp-admin.
  const handleBackToAdmin = () => {
    const adminRoot = nfdAIPageDesigner.ajaxUrl.replace( /admin-ajax\.php$/, '' );
    window.location.href = `${ adminRoot }admin.php?page=web#/home`;
  };

  const pageTitle = metaTitle || conversation.publishTitle;
  const pageStatus: 'draft' | 'publish' | null = selectedItem
    ? ( selectedItem.status === 'publish' ? 'publish' : 'draft' )
    : ( conversation.hasAIGenerated ? 'draft' : null );
  const canPublish = conversation.hasAIGenerated || metaDirty || designTokensDirty;
  // conversation.isLoading is included so submitting a prompt swaps the
  // canvas away from the empty-state hero immediately — without it,
  // hasContent stays false (nothing loaded yet) until the AI's first
  // streamed content actually lands, leaving the hero (and its input box)
  // sitting on screen for the whole generation instead of going away on submit.
  const hasContent = Boolean( previewHtml || selectedItem || conversation.isLoading );

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
      viewport={ viewport }
      onViewportChange={ setViewport }
    />
  );

  const drawer = drawerOpen ? (
    <PageDrawer
      loadingRecent={ loadingRecent }
      recentItems={ recentItems }
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
      metaDirty={ metaDirty || designTokensDirty }
      publishing={ publishFlow.publishing }
      selectedItem={ selectedItem }
      input={ conversation.input }
      selectedBlockIndex={ selectedBlockIndex }
      selectedBlockLabel={ selectedBlockLabel }
      historyEntries={ conversation.historyEntries }
      previewHtml={ previewHtml }
      selectedPaletteId={ selectedPaletteId }
      selectedFontPairingId={ selectedFontPairingId }
      themePalettes={ themePalettes }
      onInputChange={ conversation.setInput }
      onSend={ () => conversation.handleSend() }
      onClearSelection={ () => clearSelection( iframeRef ) }
      onPublish={ handlePublishBarClick }
      onRevertTo={ conversation.handleRevertToEntry }
      onSelectPalette={ handleSelectPalette }
      onSelectFontPairing={ handleSelectFontPairing }
      canSuggestPalette={ Boolean( previewHtml ) }
      suggestingPalette={ suggestingPalette }
      onSuggestPalette={ handleSuggestPalette }
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
          viewport={ viewport }
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

      <CommandPalette
        open={ commandPaletteOpen }
        apiUrl={ nfdAIPageDesigner.apiUrl }
        onClose={ () => setCommandPaletteOpen( false ) }
        onSelectItem={ handleCommandPaletteSelectItem }
      />
    </>
  );
};

export default App;
