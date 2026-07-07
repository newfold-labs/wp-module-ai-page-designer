import type { WPItem } from '../types';
type UseRecentItemsResult = {
    recentItems: WPItem[];
    loadingRecent: boolean;
    touchRecent: (id: number) => Promise<void>;
};
export declare const useRecentItems: (apiUrl: string) => UseRecentItemsResult;
export default useRecentItems;
//# sourceMappingURL=useRecentItems.d.ts.map