export declare const extractHtml: (content: string) => string | null;
export declare const stripLocalStyles: (html: string) => string;
/**
 * Convert plain HTML to Gutenberg block markup using wp.blocks.rawHandler.
 * Returns the original HTML unchanged if wp.blocks is unavailable or conversion fails.
 */
export declare const convertHtmlToGutenberg: (html: string) => string;
export declare const hasGutenbergMarkers: (html: string) => boolean;
//# sourceMappingURL=aiDesignerHelpers.d.ts.map