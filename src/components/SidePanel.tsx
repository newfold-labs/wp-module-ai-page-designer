import React, { useState, type RefObject } from 'react';
import { ChatBubbleLeftRightIcon, ClockIcon, SwatchIcon } from '@heroicons/react/24/outline';
import ChatPanel from './ChatPanel';
import DesignTab from './workspace/DesignTab';
import HistoryPane from './HistoryPane';
import type { DesignPalette } from '../designTokens';
import type { HistoryEntry, Message, WPItem } from '../types';

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
  historyEntries: HistoryEntry[];
  previewHtml: string | null;
  selectedPaletteId: string | null;
  selectedFontPairingId: string;
  themePalettes: DesignPalette[];
  canSuggestPalette: boolean;
  suggestingPalette: boolean;
  onInputChange: ( value: string ) => void;
  onSend: () => void;
  onClearSelection: () => void;
  onPublish: () => void;
  onRevertTo: ( id: string ) => void;
  onSelectPalette: ( paletteId: string | null ) => void;
  onSelectFontPairing: ( fontPairingId: string ) => void;
  onSuggestPalette: () => void;
};

const TABS = [
  { id: 'chat', label: 'Chat', Icon: ChatBubbleLeftRightIcon },
  { id: 'design', label: 'Design', Icon: SwatchIcon },
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
  selectedBlockLabel,
  historyEntries,
  previewHtml,
  selectedPaletteId,
  selectedFontPairingId,
  themePalettes,
  canSuggestPalette,
  suggestingPalette,
  onInputChange,
  onSend,
  onClearSelection,
  onPublish,
  onRevertTo,
  onSelectPalette,
  onSelectFontPairing,
  onSuggestPalette,
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
            selectedBlockLabel={ selectedBlockLabel }
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
          <DesignTab
            selectedPaletteId={ selectedPaletteId }
            selectedFontPairingId={ selectedFontPairingId }
            themePalettes={ themePalettes }
            canSuggest={ canSuggestPalette }
            suggesting={ suggestingPalette }
            onSelectPalette={ onSelectPalette }
            onSelectFontPairing={ onSelectFontPairing }
            onSuggestPalette={ onSuggestPalette }
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
