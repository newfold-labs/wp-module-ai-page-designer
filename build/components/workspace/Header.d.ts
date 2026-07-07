import React from 'react';
type PageStatus = 'draft' | 'publish' | null;
type Viewport = 'desktop' | 'tablet' | 'mobile';
type Props = {
    pageTitle: string;
    pageStatus: PageStatus;
    drawerOpen: boolean;
    onToggleDrawer: () => void;
    sidebarOpen: boolean;
    onToggleSidebar: () => void;
    onNewPage: () => void;
    onBackToAdmin: () => void;
    canPublish: boolean;
    publishing: boolean;
    onPublish: () => void;
    viewport: Viewport;
    onViewportChange: (viewport: Viewport) => void;
};
declare const Header: ({ pageTitle, pageStatus, drawerOpen, onToggleDrawer, sidebarOpen, onToggleSidebar, onNewPage, onBackToAdmin, canPublish, publishing, onPublish, viewport, onViewportChange, }: Props) => React.JSX.Element;
export default Header;
//# sourceMappingURL=Header.d.ts.map