import React, { type RefObject } from 'react';
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
    onInputChange: (value: string) => void;
    onSend: () => void;
    onClearSelection: () => void;
    onPublish: () => void;
};
declare const ChatPanel: ({ messages, chatMessagesRef, isLoading, hasAIGenerated, metaDirty, publishing, selectedItem, input, selectedBlockIndex, onInputChange, onSend, onClearSelection, onPublish, }: Props) => React.JSX.Element;
export default ChatPanel;
//# sourceMappingURL=ChatPanel.d.ts.map