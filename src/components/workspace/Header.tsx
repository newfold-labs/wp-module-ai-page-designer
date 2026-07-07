import React from 'react';
import { Button } from '@wordpress/components';
import {
  ArrowLeftIcon,
  Bars3Icon,
  ComputerDesktopIcon,
  DevicePhoneMobileIcon,
  DeviceTabletIcon,
  PlusIcon,
  ViewColumnsIcon,
} from '@heroicons/react/24/outline';

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
  onViewportChange: ( viewport: Viewport ) => void;
};

const Header = ( {
  pageTitle,
  pageStatus,
  drawerOpen,
  onToggleDrawer,
  sidebarOpen,
  onToggleSidebar,
  onNewPage,
  onBackToAdmin,
  canPublish,
  publishing,
  onPublish,
  viewport,
  onViewportChange,
}: Props ) => (
  <div className="ai-workspace-header">
    <div className="ai-workspace-header__left">
      <Button
        className="ai-workspace-header__back"
        icon={ () => <ArrowLeftIcon className="icon-sm" /> }
        label="Back to Dashboard"
        onClick={ onBackToAdmin }
      />
      <Button
        className="ai-workspace-header__drawer-toggle"
        icon={ () => <Bars3Icon className="icon-sm" /> }
        label={ drawerOpen ? 'Close pages drawer' : 'Open pages drawer' }
        isPressed={ drawerOpen }
        onClick={ onToggleDrawer }
      />
      <Button
        variant="primary"
        className="ai-workspace-header__new-page"
        icon={ () => <PlusIcon className="icon-sm" /> }
        onClick={ onNewPage }
      >
        New Page
      </Button>
    </div>

    <div className="ai-workspace-header__center">
      <span className="ai-workspace-header__title">{ pageTitle || 'Untitled' }</span>
      { pageStatus && (
        <span className={ `ai-badge ai-badge--${ pageStatus === 'publish' ? 'published' : 'draft' }` }>
          { pageStatus === 'publish' ? 'Published' : 'Draft' }
        </span>
      ) }
    </div>

    <div className="ai-workspace-header__right">
      <div className="ai-workspace-header__viewport-toggle" role="group" aria-label="Preview viewport">
        <Button
          className={ viewport === 'desktop' ? 'is-active' : '' }
          icon={ () => <ComputerDesktopIcon className="icon-sm" /> }
          label="Desktop preview"
          isPressed={ viewport === 'desktop' }
          onClick={ () => onViewportChange( 'desktop' ) }
        />
        <Button
          className={ viewport === 'tablet' ? 'is-active' : '' }
          icon={ () => <DeviceTabletIcon className="icon-sm" /> }
          label="Tablet preview"
          isPressed={ viewport === 'tablet' }
          onClick={ () => onViewportChange( 'tablet' ) }
        />
        <Button
          className={ viewport === 'mobile' ? 'is-active' : '' }
          icon={ () => <DevicePhoneMobileIcon className="icon-sm" /> }
          label="Mobile preview"
          isPressed={ viewport === 'mobile' }
          onClick={ () => onViewportChange( 'mobile' ) }
        />
      </div>
      <Button
        variant="primary"
        className="ai-workspace-header__publish"
        disabled={ ! canPublish || publishing }
        onClick={ onPublish }
      >
        { publishing ? 'Publishing...' : 'Publish' }
      </Button>
      <Button
        className="ai-workspace-header__sidebar-toggle"
        icon={ () => <ViewColumnsIcon className="icon-sm" /> }
        label={ sidebarOpen ? 'Close sidebar' : 'Open sidebar' }
        isPressed={ sidebarOpen }
        onClick={ onToggleSidebar }
      />
    </div>
  </div>
);

export default Header;
