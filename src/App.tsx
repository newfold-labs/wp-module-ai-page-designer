import React, { useState } from 'react';
import ChatPanel from './components/ChatPanel';
import DashboardView from './components/DashboardView';
import DesignerTabs from './components/DesignerTabs';
import PreviewFrame from './components/PreviewFrame';
import PublishModal from './components/PublishModal';
import RevertConfirm from './components/RevertConfirm';
import { useAiConversation } from './hooks/useAiConversation';
import { useBlockSelection } from './hooks/useBlockSelection';
import { usePreviewIframe } from './hooks/usePreviewIframe';
import { usePublishFlow } from './hooks/usePublishFlow';
import { useSiteContent } from './hooks/useSiteContent';
import type { WPItem } from './types';

declare global {
  interface Window {
    nfdAIPageDesigner: {
      apiUrl: string;
      apiRoot: string;
      nonce: string;
      siteUrl: string;
      hasAISiteGen: boolean;
      currentUserId: number;
      ajaxUrl: string;
      previewStylesheets?: {
        blockLibrary: string;
        themeUrl: string;
        globalStyles: string;
      };
    };
  }
}

const { nfdAIPageDesigner } = window;

const App = () => {
  const [ previewHtml, setPreviewHtml ] = useState<string | null>( null );
  const [ originalPreviewHtml, setOriginalPreviewHtml ] = useState<string | null>( null );
  const [ selectedItem, setSelectedItem ] = useState<WPItem | null>( null );
  const [ view, setView ] = useState<'dashboard' | 'designer'>( 'dashboard' );
  const [ pagesExpanded, setPagesExpanded ] = useState( false );
  const [ postsExpanded, setPostsExpanded ] = useState( false );
  const [ pagesSearchQuery, setPagesSearchQuery ] = useState( '' );
  const [ postsSearchQuery, setPostsSearchQuery ] = useState( '' );
  const [ publishTitle, setPublishTitle ] = useState( '' );

  const { sitePages, sitePosts, loadingSite } = useSiteContent(
    nfdAIPageDesigner.apiUrl,
    'dashboard' === view
  );
  const { iframeRef } = usePreviewIframe(
    previewHtml,
    nfdAIPageDesigner.siteUrl,
    nfdAIPageDesigner.previewStylesheets
  );
  const { selectedBlockIndex, selectedBlockHtml, clearSelection } = useBlockSelection();

  const conversation = useAiConversation( {
    apiUrl: nfdAIPageDesigner.apiUrl,
    previewHtml,
    originalPreviewHtml,
    publishTitle,
    selectedBlockIndex,
    selectedBlockHtml,
    iframeRef,
    setPreviewHtml,
    setPublishTitle,
    clearSelection,
  } );

  const publishFlow = usePublishFlow( {
    previewHtml,
    publishTitle: conversation.publishTitle,
    appendAssistantMessage: conversation.appendAssistantMessage,
  } );

  const handleSelectItem = ( item: WPItem ) => {
    conversation.resetAiConversation();
    publishFlow.resetPublishState();
    setSelectedItem( item );
    setView( 'designer' );

    const baseHtml = item.content?.raw || item.content?.rendered || '';
    setOriginalPreviewHtml( baseHtml );
    setPreviewHtml( baseHtml );
    setPublishTitle( '' );
  };

  const handleCreateNew = () => {
    conversation.resetAiConversation();
    publishFlow.resetPublishState();
    setSelectedItem( null );
    setPreviewHtml( null );
    setOriginalPreviewHtml( null );
    setPublishTitle( '' );
    setView( 'designer' );
  };

  const handleShowDashboard = () => {
    conversation.resetAiConversation();
    publishFlow.resetPublishState();
    setSelectedItem( null );
    setPreviewHtml( null );
    setOriginalPreviewHtml( null );
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
  };

  const handlePublishBarClick = () => {
    if ( selectedItem ) {
      publishFlow.handleReplaceItem( selectedItem );
      return;
    }
    publishFlow.openPublishModal();
  };

  if ( 'dashboard' === view ) {
    return (
      <div className="ai-designer-container">
        <DesignerTabs
          view={ view }
          onDashboard={ handleShowDashboard }
          onDesigner={ handleShowDesigner }
        />
        <div className="ai-designer-body">
          <DashboardView
            loadingSite={ loadingSite }
            sitePages={ sitePages }
            sitePosts={ sitePosts }
            pagesSearchQuery={ pagesSearchQuery }
            postsSearchQuery={ postsSearchQuery }
            pagesExpanded={ pagesExpanded }
            postsExpanded={ postsExpanded }
            onCreateNew={ handleCreateNew }
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
    <div className="ai-designer-container">
      <DesignerTabs
        view={ view }
        onDashboard={ handleShowDashboard }
        onDesigner={ handleShowDesigner }
      />
      <div className="ai-designer-body">
        <div className="ai-designer-main">
          <div className="ai-designer-content">
            <ChatPanel
              messages={ conversation.messages }
              chatMessagesRef={ conversation.chatMessagesRef }
              isLoading={ conversation.isLoading }
              historyEntries={ conversation.historyEntries }
              selectedHistoryIds={ conversation.selectedHistoryIds }
              isHistoryOpen={ conversation.isHistoryOpen }
              onToggleHistoryOpen={ () => conversation.setIsHistoryOpen( ( prev ) => ! prev ) }
              onToggleHistorySelection={ conversation.handleToggleHistorySelection }
              onRevertSelected={ conversation.handleRevertSelectedHistory }
              onClearSelectedHistory={ () => conversation.setSelectedHistoryIds( [] ) }
              hasAIGenerated={ conversation.hasAIGenerated }
              publishing={ publishFlow.publishing }
              selectedItem={ selectedItem }
              onPublish={ handlePublishBarClick }
              onRevertChanges={ publishFlow.openRevertConfirm }
            />

            <PreviewFrame
              previewHtml={ previewHtml }
              selectedItem={ selectedItem }
              iframeRef={ iframeRef }
            />
          </div>

          <div className="chat-input-area">
            { selectedBlockIndex !== null && (
              <div className="selected-block-indicator">
                <div className="selected-block-indicator__content">
                  <div className="selected-block-indicator__dot"></div>
                  <span className="selected-block-indicator__text">
                    <strong>Targeted Edit.</strong> Prompt affects only the highlighted section.
                  </span>
                </div>
                <button
                  type="button"
                  onClick={ () => clearSelection( iframeRef ) }
                  className="selected-block-indicator__button"
                >
                  Cancel
                </button>
              </div>
            ) }
            <div className="chat-input-wrapper">
              <textarea
                value={ conversation.input }
                onChange={ ( e ) => conversation.setInput( ( e.target as HTMLTextAreaElement ).value ) }
                onKeyDown={ ( e ) => {
                  if ( e.key === 'Enter' && ! e.shiftKey ) {
                    e.preventDefault();
                    conversation.handleSend();
                  }
                } }
                placeholder="Describe your design idea... (Press Enter to send, Shift+Enter for new line)"
                className="chat-textarea"
              />
              <button
                onClick={ conversation.handleSend }
                disabled={ ! conversation.input.trim() || conversation.isLoading }
                className="chat-send-button"
              >
                { conversation.isLoading ? 'Generating...' : 'Send' }
              </button>
            </div>
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
