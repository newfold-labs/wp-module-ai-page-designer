import { type RefObject } from 'react';
import type { HistoryEntry, Message, WPItem } from '../types';
type UseAiConversationOptions = {
    apiUrl: string;
    previewHtml: string | null;
    originalPreviewHtml: string | null;
    publishTitle: string;
    selectedItem: WPItem | null;
    selectedBlockIndex: string | null;
    selectedBlockHtml: string | null;
    iframeRef: RefObject<HTMLIFrameElement>;
    setPreviewHtml: (value: string | null) => void;
    setPublishTitle: (value: string) => void;
    setMetaTitle: (value: string) => void;
    setMetaExcerpt: (value: string) => void;
    setMetaFeaturedImageUrl: (value: string | null) => void;
    clearSelection: (iframeRef?: RefObject<HTMLIFrameElement>) => void;
};
type UseAiConversationResult = {
    messages: Message[];
    input: string;
    isLoading: boolean;
    historyEntries: HistoryEntry[];
    isHistoryOpen: boolean;
    hasAIGenerated: boolean;
    publishTitle: string;
    chatMessagesRef: RefObject<HTMLDivElement>;
    setInput: (value: string) => void;
    setIsHistoryOpen: (value: boolean | ((prev: boolean) => boolean)) => void;
    setPublishTitle: (value: string) => void;
    handleSend: (overrideText?: string) => Promise<void>;
    handleConfirmRevertChanges: () => void;
    handleRevertToEntry: (id: string) => void;
    resetAiConversation: () => void;
    appendAssistantMessage: (message: Message) => void;
};
export declare const useAiConversation: (options: UseAiConversationOptions) => UseAiConversationResult;
export default useAiConversation;
//# sourceMappingURL=useAiConversation.d.ts.map