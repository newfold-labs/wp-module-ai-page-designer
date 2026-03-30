import type { Message, WPItem } from './types';
export declare const fetchSitePages: (apiUrl: string) => Promise<WPItem[]>;
export declare const fetchSitePosts: (apiUrl: string) => Promise<WPItem[]>;
export type GenerateContentContext = {
    current_markup: string;
    post_id?: number;
    conversation_id?: string;
    content_type?: 'page' | 'post';
};
export type GenerateContentResponse = {
    data: {
        content: string;
        title?: string;
        message?: string;
        response_id?: string;
        conversation_id?: string;
        conversation_key?: string;
    };
};
export declare const generateContent: (apiUrl: string, messages: Message[], context: GenerateContentContext) => Promise<GenerateContentResponse>;
export declare const publishNewContent: (type: "new_post" | "new_page", title: string, content: string) => Promise<any>;
type UpdateExistingMeta = {
    title?: string;
    excerpt?: string;
    featuredMedia?: number;
};
export declare const updateExistingItem: (apiUrl: string, item: WPItem, content: string, meta?: UpdateExistingMeta) => Promise<any>;
export declare const setHomepage: (pageId: number) => Promise<unknown>;
export {};
//# sourceMappingURL=api.d.ts.map