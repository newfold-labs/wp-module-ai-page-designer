import React, { useState, type RefObject } from 'react';
import { ChatBubbleLeftRightIcon, SwatchIcon, ClockIcon } from '@heroicons/react/24/outline';
import ChatPanel from './ChatPanel';
import ColorPane from './ColorPane';
import HistoryPane from './HistoryPane';
import type { HistoryEntry, Message, WPItem } from '../types';

type ColorSwatch = {
  slug: string;
  name: string;
  color: string;
};

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
  historyEntries: HistoryEntry[];
  colorPalette: ColorSwatch[];
  previewHtml: string | null;
  onInputChange: ( value: string ) => void;
  onSend: () => void;
  onClearSelection: () => void;
  onPublish: () => void;
  onRevertTo: ( id: string ) => void;
  onApplyDirectChange: ( html: string, label: string ) => void;
};

const TABS = [
  { id: 'chat', label: 'Chat', Icon: ChatBubbleLeftRightIcon },
  { id: 'colors', label: 'Colors', Icon: SwatchIcon },
  { id: 'history', label: 'History', Icon: ClockIcon },
];

const SidePanel = ( {
  messages,
  chatMessagesRef,
  isLoading,
  hasAIGenerated,
  metaDirty,
  publishing,
  selectedItem,
  input,
  selectedBlockIndex,
  historyEntries,
  colorPalette,
  previewHtml,
  onInputChange,
  onSend,
  onClearSelection,
  onPublish,
  onRevertTo,
  onApplyDirectChange,
}: Props ) => {
  const [ activeTab, setActiveTab ] = useState( 0 );

  const getTransform = ( panelIndex: number ) => {
    if ( panelIndex < activeTab ) {
      return 'translateX(-100%)';
    }
    if ( panelIndex > activeTab ) {
      return 'translateX(100%)';
    }
    return 'translateX(0)';
  };

  return (
    <div className="ai-side-panel">
      <div className="ai-side-panel__tabs">
        { TABS.map( ( { id, label, Icon }, index ) => (
          <button
            key={ id }
            type="button"
            className={ `ai-side-panel__tab ${ activeTab === index ? 'active' : '' }` }
            onClick={ () => setActiveTab( index ) }
          >
            <Icon className="icon-sm" />
            { label }
          </button>
        ) ) }
      </div>
      <div className="ai-side-panel__body">
        <div
          className="ai-side-panel__panel"
          style={ { transform: getTransform( 0 ) } }
          aria-hidden={ activeTab !== 0 }
        >
          <ChatPanel
            messages={ messages }
            chatMessagesRef={ chatMessagesRef }
            isLoading={ isLoading }
            hasAIGenerated={ hasAIGenerated }
            metaDirty={ metaDirty }
            publishing={ publishing }
            selectedItem={ selectedItem }
            input={ input }
            selectedBlockIndex={ selectedBlockIndex }
            onInputChange={ onInputChange }
            onSend={ onSend }
            onClearSelection={ onClearSelection }
            onPublish={ onPublish }
          />
        </div>
        <div
          className="ai-side-panel__panel"
          style={ { transform: getTransform( 1 ) } }
          aria-hidden={ activeTab !== 1 }
        >
          <ColorPane
            colorPalette={ colorPalette }
            previewHtml={ previewHtml }
            onApplyDirectChange={ onApplyDirectChange }
          />
        </div>
        <div
          className="ai-side-panel__panel"
          style={ { transform: getTransform( 2 ) } }
          aria-hidden={ activeTab !== 2 }
        >
          <HistoryPane
            historyEntries={ historyEntries }
            onRevertTo={ onRevertTo }
          />
        </div>
      </div>
    </div>
  );
};

export default SidePanel;
