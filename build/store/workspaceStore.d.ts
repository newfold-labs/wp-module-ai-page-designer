/**
 * @wordpress/data over zustand: it's a free WP-core global (wp-data, already
 * externalized in webpack.config.js) and @wordpress/interface — which the
 * workspace shell is built on — is itself built on it, so this is the
 * idiomatic fit rather than a second, unrelated state library.
 *
 * Not consumed by App.tsx yet — Phase 1 replaces the current `view`/
 * `selectedItem` useState pairs in App.tsx with this store.
 */
export declare const STORE_NAME = "nfd-ai-page-designer/workspace";
export type SidebarTab = 'chat' | 'design' | 'history';
export type Viewport = 'desktop' | 'tablet' | 'mobile';
export type WorkspaceState = {
    pageId: number | null;
    drawerOpen: boolean;
    sidebarOpen: boolean;
    activeTab: SidebarTab;
    viewport: Viewport;
};
export declare const workspaceStore: import("@wordpress/data").StoreDescriptor<import("@wordpress/data").ReduxStoreConfig<unknown, {
    setPageId(pageId: number | null): {
        type: "SET_PAGE_ID";
        pageId: number | null;
    };
    setDrawerOpen(drawerOpen: boolean): {
        type: "SET_DRAWER_OPEN";
        drawerOpen: boolean;
    };
    setSidebarOpen(sidebarOpen: boolean): {
        type: "SET_SIDEBAR_OPEN";
        sidebarOpen: boolean;
    };
    setActiveTab(activeTab: SidebarTab): {
        type: "SET_ACTIVE_TAB";
        activeTab: SidebarTab;
    };
    setViewport(viewport: Viewport): {
        type: "SET_VIEWPORT";
        viewport: Viewport;
    };
}, {
    getPageId(state: WorkspaceState): number | null;
    isDrawerOpen(state: WorkspaceState): boolean;
    isSidebarOpen(state: WorkspaceState): boolean;
    getActiveTab(state: WorkspaceState): SidebarTab;
    getViewport(state: WorkspaceState): Viewport;
}>>;
export declare function registerWorkspaceStore(): void;
//# sourceMappingURL=workspaceStore.d.ts.map