import React from 'react';
import type { WPItem } from '../types';
type Props = {
    previewHtml: string | null;
    selectedItem: WPItem | null;
    iframeRef: React.RefObject<HTMLIFrameElement>;
};
declare const PreviewFrame: ({ previewHtml, selectedItem, iframeRef }: Props) => React.JSX.Element;
export default PreviewFrame;
//# sourceMappingURL=PreviewFrame.d.ts.map