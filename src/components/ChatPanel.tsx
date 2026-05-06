import React, { useMemo, type RefObject } from 'react';
import {
  ArrowTopRightOnSquareIcon,
  ChatBubbleLeftRightIcon,
} from '@heroicons/react/24/outline';
import {
  CHAT_EXISTING_PAGE_PROMPT_CHIPS,
  CHAT_NEW_PAGE_PROMPT_CHIPS,
  CHAT_SELECTED_BLOCK_PROMPT_CHIPS,
  pickRandomPromptChips,
} from '../promptChips';
import type { Message, WPItem } from '../types';

type Props = {
  messages: Message[];
  chatMessagesRef: RefObject<HTMLDivElement>;
  isLoading: boolean;
  hasAIGenerated: boolean;
  metaDirty: boolean;
  publishing: boolean;
  selectedItem: WPItem | null;
  input: string;
  selectedBlockIndex: string | null;
  selectedBlockLabel: string | null;
  onInputChange: ( value: string ) => void;
  onSend: () => void;
  onClearSelection: () => void;
  onPublish: () => void;
};

const ChatPanel = ( {
  messages,
  chatMessagesRef,
  isLoading,
  hasAIGenerated,
  metaDirty,
  publishing,
  selectedItem,
  input,
  selectedBlockIndex,
  selectedBlockLabel,
  onInputChange,
  onSend,
  onClearSelection,
  onPublish,
}: Props ) => {
  const isBlockMarkup = ( content?: string ) =>
    Boolean( content?.includes( '<!-- wp:' ) || content?.includes( '<!-- /wp:' ) );

  const stripHtml = ( value: string ) => value.replace( /<[^>]*>/g, '' );
  const selectedTitle = stripHtml( selectedItem?.title?.rendered || '' );
  const promptSuggestions = useMemo( () => {
    const pool =
      selectedBlockIndex !== null
        ? CHAT_SELECTED_BLOCK_PROMPT_CHIPS
        : selectedItem
          ? CHAT_EXISTING_PAGE_PROMPT_CHIPS( selectedTitle )
          : CHAT_NEW_PAGE_PROMPT_CHIPS;

    return pickRandomPromptChips( pool, 3 );
  }, [ selectedBlockIndex, selectedItem, selectedTitle ] );

  return (
    <div className="ai-chat-panel">
      <div className="chat-messages" ref={ chatMessagesRef }>
        { messages.length === 0 && (
          <div className="chat-empty-state">
            <div className="chat-empty-state-icon">
              <ChatBubbleLeftRightIcon className="icon" />
            </div>
            <p>Describe the page you&apos;d like to create</p>
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

      <div className="chat-input-area">
        { selectedBlockIndex !== null && (
          <div className="selected-block-indicator">
            <div className="selected-block-indicator__content">
              <div className="selected-block-indicator__dot"></div>
              <span className="selected-block-indicator__text">
                <strong>Targeted Edit.</strong>
                { ' ' }
                { selectedBlockLabel ? (
                  <>
                    You&apos;re editing a <span className="selected-block-indicator__label">{ selectedBlockLabel }</span> block only.
                  </>
                ) : 'Prompt affects only the highlighted section.' }
              </span>
            </div>
            <button
              type="button"
              onClick={ onClearSelection }
              className="selected-block-indicator__button"
            >
              Cancel
            </button>
          </div>
        ) }
        <div className="chat-input-wrapper">
          <textarea
            value={ input }
            onChange={ ( e ) => onInputChange( ( e.target as HTMLTextAreaElement ).value ) }
            onKeyDown={ ( e ) => {
              if ( e.key === 'Enter' && ! e.shiftKey ) {
                e.preventDefault();
                onSend();
              }
            } }
            placeholder="Describe your design idea..."
            className="chat-textarea"
            rows={ 2 }
          />
          <button
            onClick={ onSend }
            disabled={ ! input.trim() || isLoading }
            className="chat-send-button"
            aria-label="Send"
          >
            { isLoading ? '...' : (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <line x1="22" y1="2" x2="11" y2="13"></line>
                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
              </svg>
            ) }
          </button>
        </div>
        <div className="chat-input-suggestion">
          <span className="chat-input-suggestion__label">Try:</span>
          { promptSuggestions.map( ( suggestion ) => (
            <button
              key={ suggestion }
              type="button"
              className="chat-input-suggestion__pill"
              onClick={ () => onInputChange( suggestion ) }
            >
              { suggestion }
            </button>
          ) ) }
        </div>
      </div>
    </div>
  );
};

export default ChatPanel;
