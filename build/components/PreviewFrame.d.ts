import React from 'react';
import type { WPItem } from '../types';
type Viewport = 'desktop' | 'tablet' | 'mobile';
type Props = {
    previewHtml: string | null;
    selectedItem: WPItem | null;
    iframeRef: React.RefObject<HTMLIFrameElement>;
    viewport?: Viewport;
};
declare const PreviewFrame: ({ previewHtml, selectedItem, iframeRef, viewport }: Props) => React.JSX.Element;
export default PreviewFrame;
//# sourceMappingURL=PreviewFrame.d.ts.map