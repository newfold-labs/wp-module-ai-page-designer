import { type RefObject } from 'react';
type PreviewStylesheets = {
    blockLibrary: string;
    themeUrl: string;
    globalStyles: string;
};
type UsePreviewIframeResult = {
    iframeRef: RefObject<HTMLIFrameElement>;
};
export declare const usePreviewIframe: (previewHtml: string | null, previewUrl: string, previewStylesheets?: PreviewStylesheets, isStreaming?: boolean, externalIframeRef?: RefObject<HTMLIFrameElement>, motionCss?: string) => UsePreviewIframeResult;
export default usePreviewIframe;
//# sourceMappingURL=usePreviewIframe.d.ts.map