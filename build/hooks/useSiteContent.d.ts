import type { WPItem } from '../types';
type UseSiteContentResult = {
    sitePages: WPItem[];
    sitePosts: WPItem[];
    loadingSite: boolean;
    fetchSiteContent: () => Promise<void>;
};
export declare const useSiteContent: (apiUrl: string, active: boolean) => UseSiteContentResult;
export default useSiteContent;
//# sourceMappingURL=useSiteContent.d.ts.map