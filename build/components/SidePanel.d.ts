import React, { type RefObject } from 'react';
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
    historyEntries: HistoryEntry[];
    previewHtml: string | null;
    onInputChange: (value: string) => void;
    onSend: () => void;
    onClearSelection: () => void;
    onPublish: () => void;
    onRevertTo: (id: string) => void;
};
declare const SidePanel: ({ messages, chatMessagesRef, isLoading, hasAIGenerated, metaDirty, publishing, selectedItem, input, selectedBlockIndex, historyEntries, previewHtml, onInputChange, onSend, onClearSelection, onPublish, onRevertTo, }: Props) => React.JSX.Element;
export default SidePanel;
//# sourceMappingURL=SidePanel.d.ts.map