/**
 * Deep-links the open page via the `nfd_page_id` query param, not the URL
 * hash — the parent wp-plugin-web app's HashRouter owns `location.hash`
 * (exact-matches `#/ai-designer` with no wildcard route), so writing our own
 * `#/page/:id` there stomps its route and un-mounts this app entirely.
 */
export declare function parsePageIdFromLocation(): number | null;
export declare function writePageIdToLocation(pageId: number | null): void;
//# sourceMappingURL=pageRoute.d.ts.map