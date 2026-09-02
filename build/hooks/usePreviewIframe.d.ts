import { type RefObject } from 'react';
type PreviewStylesheets = {
    blockLibrary: string;
    themeUrl: string;
    globalStyles: string;
};
export type PreviewDesignTokens = {
    colors: Record<string, string> | null;
    headingFont: string;
    bodyFont: string;
};
type UsePreviewIframeResult = {
    iframeRef: RefObject<HTMLIFrameElement>;
};
export declare const usePreviewIframe: (previewHtml: string | null, previewUrl: string, previewStylesheets?: PreviewStylesheets, isStreaming?: boolean, externalIframeRef?: RefObject<HTMLIFrameElement>, motionCss?: string, targetBlockIndex?: string | null, designTokens?: PreviewDesignTokens | null) => UsePreviewIframeResult;
export default usePreviewIframe;
//# sourceMappingURL=usePreviewIframe.d.ts.map