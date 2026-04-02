import React from 'react';
import type { WPItem } from '../types';
type Props = {
    loadingSite: boolean;
    sitePages: WPItem[];
    sitePosts: WPItem[];
    pagesSearchQuery: string;
    postsSearchQuery: string;
    pagesExpanded: boolean;
    postsExpanded: boolean;
    onCreateWithPrompt: (prompt: string) => void;
    onSelectItem: (item: WPItem) => void;
    onPagesSearchChange: (value: string) => void;
    onPostsSearchChange: (value: string) => void;
    onTogglePagesExpanded: () => void;
    onTogglePostsExpanded: () => void;
};
declare const DashboardView: ({ loadingSite, sitePages, sitePosts, pagesSearchQuery, postsSearchQuery, pagesExpanded, postsExpanded, onCreateWithPrompt, onSelectItem, onPagesSearchChange, onPostsSearchChange, onTogglePagesExpanded, onTogglePostsExpanded, }: Props) => React.JSX.Element;
export default DashboardView;
//# sourceMappingURL=DashboardView.d.ts.map