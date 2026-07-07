import type { ConversationSnapshot } from '../hooks/useAiConversation';
export type PageSession = {
    previewHtml: string | null;
    originalPreviewHtml: string | null;
    metaTitle: string;
    metaExcerpt: string;
    metaFeaturedMediaId: number | null;
    metaFeaturedImageUrl: string | null;
    publishTitle: string;
    originalMeta: {
        title: string;
        excerpt: string;
        featuredMediaId: number | null;
    } | null;
    publishedHtml: string | null;
    conversation: ConversationSnapshot;
};
export declare function createPageSessionCache(): {
    get(id: number): PageSession | undefined;
    set(id: number, session: PageSession): void;
};
//# sourceMappingURL=pageSessionCache.d.ts.map