import React from 'react';
type Props = {
    visible: boolean;
    title: string;
    excerpt: string;
    featuredImageUrl: string | null;
    featuredMediaId: number | null;
    supportsThumbnail: boolean;
    canUseMedia: boolean;
    onChangeTitle: (value: string) => void;
    onChangeExcerpt: (value: string) => void;
    onPickImage: () => void;
    onRemoveImage: () => void;
};
declare const MetaStrip: ({ visible, title, excerpt, featuredImageUrl, featuredMediaId, supportsThumbnail, canUseMedia, onChangeTitle, onChangeExcerpt, onPickImage, onRemoveImage, }: Props) => React.JSX.Element | null;
export default MetaStrip;
//# sourceMappingURL=MetaStrip.d.ts.map