import React from 'react';
import type { PublishStatus, WPItem } from '../types';
type Props = {
    open: boolean;
    selectedItem: WPItem | null;
    sitePages: WPItem[];
    sitePosts: WPItem[];
    publishing: boolean;
    publishStatus: PublishStatus;
    onClose: () => void;
    onPublish: (type: 'new_post' | 'new_page' | 'homepage') => void;
    onReplaceItem: (item: WPItem) => void;
};
declare const PublishModal: ({ open, selectedItem, sitePages, sitePosts, publishing, publishStatus, onClose, onPublish, onReplaceItem, }: Props) => React.JSX.Element | null;
export default PublishModal;
//# sourceMappingURL=PublishModal.d.ts.map