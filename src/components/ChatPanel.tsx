import React, { type RefObject } from 'react';
import {
  ArrowPathIcon,
  ArrowTopRightOnSquareIcon,
  ArrowUpTrayIcon,
  ChatBubbleLeftRightIcon,
  CpuChipIcon,
  UserIcon,
} from '@heroicons/react/24/outline';
import HistoryDrawer from './HistoryDrawer';
import type { HistoryEntry, Message, WPItem } from '../types';

type Props = {
  messages: Message[];
  chatMessagesRef: RefObject<HTMLDivElement>;
  isLoading: boolean;
  historyEntries: HistoryEntry[];
  selectedHistoryIds: string[];
  isHistoryOpen: boolean;
  onToggleHistoryOpen: () => void;
  onToggleHistorySelection: (id: string) => void;
  onRevertSelected: () => void;
  onClearSelectedHistory: () => void;
  hasAIGenerated: boolean;
  publishing: boolean;
  selectedItem: WPItem | null;
  onPublish: () => void;
  onRevertChanges: () => void;
};

const ChatPanel = ( {
  messages,
  chatMessagesRef,
  isLoading,
  historyEntries,
  selectedHistoryIds,
  isHistoryOpen,
  onToggleHistoryOpen,
  onToggleHistorySelection,
  onRevertSelected,
  onClearSelectedHistory,
  hasAIGenerated,
  publishing,
  selectedItem,
  onPublish,
  onRevertChanges,
}: Props ) => {
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
            <p>Describe the page or content you'd like to create</p>
            <p>The AI will generate HTML you can publish directly to WordPress.</p>
          </div>
        ) }
        { messages.map( ( msg, i ) => (
          <div key={ i } className={ `chat-message ${ msg.role }-message` }>
            <div className="message-avatar">
              { 'user' === msg.role ? (
                <UserIcon className="icon" />
              ) : (
                <CpuChipIcon className="icon" />
              ) }
            </div>
            <div className="message-content">
              <div className="message-role">{ 'user' === msg.role ? 'You' : 'AI Assistant' }</div>
              <div className="message-text">
                { msg.content.substring( 0, 500 ) }
                { msg.content.length > 500 && '...' }
              </div>
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
        ) ) }
        { isLoading && (
          <div className="chat-message assistant-message">
            <div className="message-avatar">
              <CpuChipIcon className="icon" />
            </div>
            <div className="message-loading">
              <strong>AI Assistant:</strong>
              <span>Generating</span>
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
        selectedHistoryIds={ selectedHistoryIds }
        isOpen={ isHistoryOpen }
        onToggleOpen={ onToggleHistoryOpen }
        onToggleSelection={ onToggleHistorySelection }
        onRevertSelected={ onRevertSelected }
        onClearSelection={ onClearSelectedHistory }
      />

      { hasAIGenerated && ! isLoading && (
        <div className="publish-bar">
          <div className="publish-bar-actions">
            <button
              className="publish-bar-button"
              disabled={ publishing }
              onClick={ onPublish }
            >
              <ArrowUpTrayIcon className="icon" />
              { selectedItem
                ? ( publishing ? 'Saving...' : 'Update in WordPress' )
                : 'Publish to WordPress' }
            </button>
            <button
              className="publish-bar-button publish-bar-button--secondary"
              type="button"
              onClick={ onRevertChanges }
            >
              <ArrowPathIcon className="icon" />
              Revert AI changes
            </button>
          </div>
        </div>
      ) }

    </div>
  );
};

export default ChatPanel;
