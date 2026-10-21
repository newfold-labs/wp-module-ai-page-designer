import React from 'react';
import { EyeIcon } from '@heroicons/react/24/outline';
import type { WPItem } from '../types';

type Viewport = 'desktop' | 'tablet' | 'mobile';

type Props = {
  previewHtml: string | null;
  selectedItem: WPItem | null;
  iframeRef: React.RefObject<HTMLIFrameElement>;
  viewport?: Viewport;
};

const PreviewFrame = ( { previewHtml, selectedItem, iframeRef, viewport = 'desktop' }: Props ) => {
  return (
    <div className="ai-preview-panel" data-viewport={ viewport }>
      <div className="preview-body">
        { ( previewHtml || selectedItem ) ? (
          <div className="preview-viewport-frame">
            <iframe
              ref={ iframeRef }
              title="Page Preview"
              className="preview-iframe"
            />
          </div>
        ) : (
          <div className="preview-empty-state">
            <div className="preview-empty-state-icon">
              <EyeIcon className="icon" />
            </div>
            <p>Live preview will appear here</p>
          </div>
        ) }
      </div>
    </div>
  );
};

export default PreviewFrame;
