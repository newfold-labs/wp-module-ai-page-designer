import React from 'react';
import type { WPItem } from '../../types';
type Props = {
    loadingRecent: boolean;
    recentItems: WPItem[];
    sitePages: WPItem[];
    sitePosts: WPItem[];
    selectedItemId: number | null;
    onSelectItem: (item: WPItem) => void;
};
declare const PageDrawer: ({ loadingRecent, recentItems, sitePages, sitePosts, selectedItemId, onSelectItem, }: Props) => React.JSX.Element;
export default PageDrawer;
//# sourceMappingURL=PageDrawer.d.ts.map