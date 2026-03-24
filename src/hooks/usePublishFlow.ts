import { useCallback, useState } from 'react';
import type { Message, PublishStatus, WPItem } from '../types';
import { publishNewContent, setHomepage, updateExistingItem } from '../api';

type UsePublishFlowOptions = {
  previewHtml: string | null;
  publishTitle: string;
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
    previewHtml,
    publishTitle,
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
      const title = publishTitle || 'AI Generated Page';
      const publishType = type === 'homepage' ? 'new_page' : type;
      const result = await publishNewContent( publishType, title, previewHtml );
      if ( 'homepage' === type && result?.id ) {
        await setHomepage( result.id );
      }
      const url = result?.link || null;
      setPublishedUrl( url );
      setPublishStatus( { type: 'success', message: 'Published successfully!' } );
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
  }, [ appendAssistantMessage, previewHtml, publishTitle ] );

  const handleReplaceItem = useCallback( async ( item: WPItem ) => {
    if ( ! previewHtml ) {
      return;
    }
    setPublishing( true );
    setPublishStatus( null );
    setPublishedUrl( null );
    try {
      await updateExistingItem( item, previewHtml );
      const url = item.link || null;
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
  }, [ appendAssistantMessage, previewHtml ] );

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
