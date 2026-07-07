import { createReduxStore, register } from '@wordpress/data';

/**
 * @wordpress/data over zustand: it's a free WP-core global (wp-data, already
 * externalized in webpack.config.js) and @wordpress/interface — which the
 * workspace shell is built on — is itself built on it, so this is the
 * idiomatic fit rather than a second, unrelated state library.
 *
 * Not consumed by App.tsx yet — Phase 1 replaces the current `view`/
 * `selectedItem` useState pairs in App.tsx with this store.
 */

export const STORE_NAME = 'nfd-ai-page-designer/workspace';

export type SidebarTab = 'chat' | 'design' | 'history';
export type Viewport = 'desktop' | 'tablet' | 'mobile';

export type WorkspaceState = {
  pageId: number | null;
  drawerOpen: boolean;
  sidebarOpen: boolean;
  activeTab: SidebarTab;
  viewport: Viewport;
};

const DEFAULT_STATE: WorkspaceState = {
  pageId: null,
  drawerOpen: false,
  sidebarOpen: true,
  activeTab: 'chat',
  viewport: 'desktop',
};

const actions = {
  setPageId( pageId: number | null ) {
    return { type: 'SET_PAGE_ID' as const, pageId };
  },
  setDrawerOpen( drawerOpen: boolean ) {
    return { type: 'SET_DRAWER_OPEN' as const, drawerOpen };
  },
  setSidebarOpen( sidebarOpen: boolean ) {
    return { type: 'SET_SIDEBAR_OPEN' as const, sidebarOpen };
  },
  setActiveTab( activeTab: SidebarTab ) {
    return { type: 'SET_ACTIVE_TAB' as const, activeTab };
  },
  setViewport( viewport: Viewport ) {
    return { type: 'SET_VIEWPORT' as const, viewport };
  },
};

type Action = ReturnType< typeof actions[ keyof typeof actions ] >;

function reducer( state: WorkspaceState = DEFAULT_STATE, action: Action ): WorkspaceState {
  switch ( action.type ) {
    case 'SET_PAGE_ID':
      return { ...state, pageId: action.pageId };
    case 'SET_DRAWER_OPEN':
      return { ...state, drawerOpen: action.drawerOpen };
    case 'SET_SIDEBAR_OPEN':
      return { ...state, sidebarOpen: action.sidebarOpen };
    case 'SET_ACTIVE_TAB':
      return { ...state, activeTab: action.activeTab };
    case 'SET_VIEWPORT':
      return { ...state, viewport: action.viewport };
    default:
      return state;
  }
}

const selectors = {
  getPageId( state: WorkspaceState ) {
    return state.pageId;
  },
  isDrawerOpen( state: WorkspaceState ) {
    return state.drawerOpen;
  },
  isSidebarOpen( state: WorkspaceState ) {
    return state.sidebarOpen;
  },
  getActiveTab( state: WorkspaceState ) {
    return state.activeTab;
  },
  getViewport( state: WorkspaceState ) {
    return state.viewport;
  },
};

export const workspaceStore = createReduxStore( STORE_NAME, {
  reducer,
  actions,
  selectors,
} );

export function registerWorkspaceStore() {
  register( workspaceStore );
}
