import { useCallback, useState } from 'react';
import type { PersistedDesignTokens } from '../designTokens';
import type { Message, PublishStatus, WPItem } from '../types';
import { publishNewContent, saveDesignTokens, setHomepage, updateExistingItem } from '../api';
import { stripLocalStyles } from '../util/aiDesignerHelpers';

// Only worth a follow-up save when there's an actual non-default selection —
// a brand-new page with nothing chosen in the Design tab shouldn't write an
// empty meta row.
const hasNonDefaultSelection = ( tokens: PersistedDesignTokens | null | undefined ): boolean =>
  !! tokens && ( tokens.paletteId !== null || tokens.fontPairingId !== 'default' );

type UsePublishFlowOptions = {
  apiUrl: string;
  previewHtml: string | null;
  publishTitle: string;
  metaTitle?: string;
  metaExcerpt?: string;
  metaFeaturedMediaId?: number | null;
  designTokens?: PersistedDesignTokens | null;
  onMetaUpdated?: (item: WPItem) => void;
  onPublished?: (item: WPItem) => void;
  appendAssistantMessage: (message: Message) => void;
};

type UsePublishFlowResult = {
  publishing: boolean;
  publishStatus: PublishStatus;
  showPublishModal: boolean;
  showRevertConfirm: boolean;
  publishedUrl: string | null;
  openPublishModal: () => void;
  closePublishModal: () => void;
  openRevertConfirm: () => void;
  closeRevertConfirm: () => void;
  handlePublish: (type: 'new_post' | 'new_page' | 'homepage') => Promise<void>;
  handleReplaceItem: (item: WPItem) => Promise<void>;
  resetPublishState: () => void;
};

export const usePublishFlow = ( options: UsePublishFlowOptions ): UsePublishFlowResult => {
  const {
    apiUrl,
    previewHtml,
    publishTitle,
    metaTitle,
    metaExcerpt,
    metaFeaturedMediaId,
    designTokens,
    onMetaUpdated,
    onPublished,
    appendAssistantMessage,
  } = options;

  const [ publishing, setPublishing ] = useState( false );
  const [ publishStatus, setPublishStatus ] = useState<PublishStatus>( null );
  const [ showPublishModal, setShowPublishModal ] = useState( false );
  const [ showRevertConfirm, setShowRevertConfirm ] = useState( false );
  const [ publishedUrl, setPublishedUrl ] = useState<string | null>( null );

  const resetPublishState = useCallback( () => {
    setPublishing( false );
    setPublishStatus( null );
    setShowPublishModal( false );
    setShowRevertConfirm( false );
    setPublishedUrl( null );
  }, [] );

  const openPublishModal = useCallback( () => {
    setShowPublishModal( true );
  }, [] );

  const closePublishModal = useCallback( () => {
    setShowPublishModal( false );
    setPublishStatus( null );
    setPublishedUrl( null );
  }, [] );

  const openRevertConfirm = useCallback( () => {
    setShowRevertConfirm( true );
  }, [] );

  const closeRevertConfirm = useCallback( () => {
    setShowRevertConfirm( false );
  }, [] );

  const handlePublish = useCallback( async ( type: 'new_post' | 'new_page' | 'homepage' ) => {
    if ( ! previewHtml ) {
      return;
    }
    setPublishing( true );
    setPublishStatus( null );
    setPublishedUrl( null );
    try {
      const trimmedMeta =
        typeof metaTitle === 'string' ? metaTitle.trim() : '';
      const title =
        trimmedMeta ||
        publishTitle.trim() ||
        'AI Generated Page';
      const publishType = type === 'homepage' ? 'new_page' : type;
      const result = await publishNewContent( publishType, title, stripLocalStyles( previewHtml ) );
      if ( 'homepage' === type && result?.id ) {
        await setHomepage( result.id );
      }
      if ( result?.id && hasNonDefaultSelection( designTokens ) ) {
        // publishNewContent hits WP core's /wp/v2/posts|pages directly, which
        // has no design_tokens field — save it through the module's own
        // endpoint now that the post exists. Best-effort: a failure here
        // shouldn't block a publish that otherwise succeeded.
        saveDesignTokens(
          apiUrl,
          publishType === 'new_page' ? 'page' : 'post',
          result.id,
          designTokens ?? null
        ).catch( ( error ) => console.error( 'Failed to save design tokens:', error ) );
      }
      const url = result?.link || null;
      setPublishedUrl( url );
      setPublishStatus( { type: 'success', message: 'Published successfully!' } );
      if ( result && typeof onPublished === 'function' ) {
        onPublished( { ...result, type: publishType === 'new_page' ? 'page' : 'post' } as WPItem );
      }
      setTimeout( () => {
        setShowPublishModal( false );
        setPublishStatus( null );
        if ( url ) {
          appendAssistantMessage( {
            role: 'assistant',
            content: 'Your page has been published successfully!',
            link: url,
          } );
        }
      }, 1500 );
    } catch ( error: any ) {
      setPublishStatus( { type: 'error', message: error.message || 'Failed to publish' } );
    } finally {
      setPublishing( false );
    }
  }, [ apiUrl, appendAssistantMessage, designTokens, metaTitle, onPublished, previewHtml, publishTitle ] );

  const handleReplaceItem = useCallback( async ( item: WPItem ) => {
    if ( ! previewHtml ) {
      return;
    }
    setPublishing( true );
    setPublishStatus( null );
    setPublishedUrl( null );
    try {
      const response = await updateExistingItem( apiUrl, item, stripLocalStyles( previewHtml ), {
        title: typeof metaTitle === 'string' ? metaTitle : undefined,
        excerpt: typeof metaExcerpt === 'string' ? metaExcerpt : undefined,
        featuredMedia: typeof metaFeaturedMediaId === 'number' ? metaFeaturedMediaId : undefined,
        designTokens,
      } );
      const url = item.link || null;
      if ( response && typeof onMetaUpdated === 'function' ) {
        onMetaUpdated( response as WPItem );
      }
      setPublishedUrl( url );
      setPublishStatus( { type: 'success', message: `"${ item.title.rendered }" updated!` } );
      setTimeout( () => {
        setShowPublishModal( false );
        setPublishStatus( null );
        if ( url ) {
          appendAssistantMessage( {
            role: 'assistant',
            content: `"${ item.title.rendered }" has been updated successfully!`,
            link: url,
          } );
        }
      }, 1500 );
    } catch ( error: any ) {
      setPublishStatus( { type: 'error', message: error.message || 'Failed to update' } );
    } finally {
      setPublishing( false );
    }
  }, [
    apiUrl,
    appendAssistantMessage,
    designTokens,
    metaExcerpt,
    metaFeaturedMediaId,
    metaTitle,
    onMetaUpdated,
    previewHtml,
  ] );

  return {
    publishing,
    publishStatus,
    showPublishModal,
    showRevertConfirm,
    publishedUrl,
    openPublishModal,
    closePublishModal,
    openRevertConfirm,
    closeRevertConfirm,
    handlePublish,
    handleReplaceItem,
    resetPublishState,
  };
};

export default usePublishFlow;
