import type { Message, PublishStatus, WPItem } from '../types';
type UsePublishFlowOptions = {
    apiUrl: string;
    previewHtml: string | null;
    publishTitle: string;
    metaTitle?: string;
    metaExcerpt?: string;
    metaFeaturedMediaId?: number | null;
    onMetaUpdated?: (item: WPItem) => void;
    onPublished?: (item: WPItem) => void;
    appendAssistantMessage: (message: Message) => void;
};
type UsePublishFlowResult = {
    publishing: boolean;
    publishStatus: PublishStatus;
    showPublishModal: boolean;
    showRevertConfirm: boolean;
    publishedUrl: string | null;
    openPublishModal: () => void;
    closePublishModal: () => void;
    openRevertConfirm: () => void;
    closeRevertConfirm: () => void;
    handlePublish: (type: 'new_post' | 'new_page' | 'homepage') => Promise<void>;
    handleReplaceItem: (item: WPItem) => Promise<void>;
    resetPublishState: () => void;
};
export declare const usePublishFlow: (options: UsePublishFlowOptions) => UsePublishFlowResult;
export default usePublishFlow;
//# sourceMappingURL=usePublishFlow.d.ts.map