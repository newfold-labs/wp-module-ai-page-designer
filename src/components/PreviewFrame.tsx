import React from 'react';
import { EyeIcon } from '@heroicons/react/24/outline';
import type { WPItem } from '../types';

type Props = {
  previewHtml: string | null;
  selectedItem: WPItem | null;
  iframeRef: React.RefObject<HTMLIFrameElement>;
};

const PreviewFrame = ( { previewHtml, selectedItem, iframeRef }: Props ) => {
  return (
    <div className="ai-preview-panel">
      <div className="preview-header">
        <h3>Preview</h3>
      </div>
      <div className="preview-body">
        { ( previewHtml || selectedItem ) ? (
          <iframe
            ref={ iframeRef }
            title="Page Preview"
            className="preview-iframe"
          />
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
