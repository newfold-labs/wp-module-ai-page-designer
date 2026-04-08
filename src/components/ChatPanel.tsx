import React, { type RefObject } from 'react';
import {
  ArrowTopRightOnSquareIcon,
  ChatBubbleLeftRightIcon,
} from '@heroicons/react/24/outline';
import HistoryDrawer from './HistoryDrawer';
import type { HistoryEntry, Message, WPItem } from '../types';

type Props = {
  messages: Message[];
  chatMessagesRef: RefObject<HTMLDivElement>;
  isLoading: boolean;
  historyEntries: HistoryEntry[];
  isHistoryOpen: boolean;
  hasAIGenerated: boolean;
  metaDirty: boolean;
  publishing: boolean;
  selectedItem: WPItem | null;
  onToggleHistoryOpen: () => void;
  onRevertTo: (id: string) => void;
  onPublish: () => void;
};

const ChatPanel = ( {
  messages,
  chatMessagesRef,
  isLoading,
  historyEntries,
  isHistoryOpen,
  hasAIGenerated,
  metaDirty,
  publishing,
  selectedItem,
  onToggleHistoryOpen,
  onRevertTo,
  onPublish,
}: Props ) => {
  const isBlockMarkup = ( content?: string ) =>
    Boolean( content?.includes( '<!-- wp:' ) || content?.includes( '<!-- /wp:' ) );

  return (
    <div className="ai-chat-panel">
      <div className="chat-header">
        <h3>AI Chat</h3>
      </div>
      <div className="chat-messages" ref={ chatMessagesRef }>
        { messages.length === 0 && (
          <div className="chat-empty-state">
            <div className="chat-empty-state-icon">
              <ChatBubbleLeftRightIcon className="icon" />
            </div>
            <p>Describe the page you'd like to create</p>
            <p>The AI will generate content you can publish directly to WordPress.</p>
          </div>
        ) }
        { messages.map( ( msg, i ) => {
          const showCode = msg.role === 'assistant' && isBlockMarkup( msg.code );
          return (
            <div key={ i } className={ `chat-message ${ msg.role }-message` }>
              <div className="message-bubble">
                { msg.role === 'assistant' && showCode ? (
                  <>
                    <p>{ msg.summary || msg.content }</p>
                    <details className="message-code-toggle" style={ { marginTop: '8px' } }>
                      <summary style={ { cursor: 'pointer' } }>View generated code</summary>
                      <pre
                        className="message-code"
                        style={ {
                          whiteSpace: 'pre-wrap',
                          wordBreak: 'break-word',
                          maxHeight: '240px',
                          overflow: 'auto',
                        } }
                      >
                        { msg.code }
                      </pre>
                    </details>
                  </>
                ) : (
                  <>
                    { msg.content.substring( 0, 500 ) }
                    { msg.content.length > 500 && '...' }
                  </>
                ) }
                { msg.link && (
                  <a
                    href={ msg.link }
                    target="_blank"
                    rel="noopener noreferrer"
                    className="message-preview-link"
                  >
                    <ArrowTopRightOnSquareIcon className="icon-xs" />
                    View published page
                  </a>
                ) }
              </div>
            </div>
          );
        } ) }
        { isLoading && (
          <div className="chat-message assistant-message">
            <div className="message-loading-bubble">
              <div className="loading-dots">
                <span></span>
                <span></span>
                <span></span>
              </div>
            </div>
          </div>
        ) }
      </div>

      <HistoryDrawer
        historyEntries={ historyEntries }
        isOpen={ isHistoryOpen }
        onToggleOpen={ onToggleHistoryOpen }
        onRevertTo={ onRevertTo }
      />

      { ( hasAIGenerated || metaDirty ) && (
        <div className="chat-publish-bar">
          <button
            type="button"
            className="ai-btn ai-btn--primary chat-publish-bar__btn"
            onClick={ onPublish }
            disabled={ publishing }
          >
            { publishing ? 'Publishing...' : ( selectedItem ? 'Update in WordPress' : 'Publish Page' ) }
          </button>
        </div>
      ) }
    </div>
  );
};

export default ChatPanel;
