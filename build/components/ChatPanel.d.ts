import React, { type RefObject } from 'react';
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
declare const ChatPanel: ({ messages, chatMessagesRef, isLoading, historyEntries, isHistoryOpen, hasAIGenerated, metaDirty, publishing, selectedItem, onToggleHistoryOpen, onRevertTo, onPublish, }: Props) => React.JSX.Element;
export default ChatPanel;
//# sourceMappingURL=ChatPanel.d.ts.map