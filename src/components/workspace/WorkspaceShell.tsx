import React, { useEffect } from 'react';
import { InterfaceSkeleton } from '@wordpress/interface';

type Props = {
  header: React.ReactNode;
  drawer: React.ReactNode | null;
  sidebar: React.ReactNode | null;
  content: React.ReactNode;
};

// No core CSS backs Gutenberg's own fullscreen body class outside the
// post/site editor bundle (neither of which this page loads), so the
// takeover — buying back the wp-admin menu/toolbar — is done with our own
// fixed-position layer instead of @wordpress/interface's FullscreenMode.
const BODY_CLASS = 'nfd-ai-workspace-active';

const WorkspaceShell = ( { header, drawer, sidebar, content }: Props ) => {
  useEffect( () => {
    document.body.classList.add( BODY_CLASS );
    return () => document.body.classList.remove( BODY_CLASS );
  }, [] );

  return (
    <div id="nfd-ai-page-designer-root" className="ai-workspace-shell">
      <InterfaceSkeleton
        header={ header }
        secondarySidebar={ drawer }
        sidebar={ sidebar }
        content={ content }
      />
    </div>
  );
};

export default WorkspaceShell;
