import React from 'react';
import { XMarkIcon } from '@heroicons/react/24/outline';
import type { WPItem } from '../types';

type Props = {
  open: boolean;
  selectedItem: WPItem | null;
  onClose: () => void;
  onConfirm: () => void;
};

const RevertConfirm = ( { open, selectedItem, onClose, onConfirm }: Props ) => {
  if ( ! open ) {
    return null;
  }

  const message = selectedItem
    ? 'Revert AI changes and restore the original content from WordPress?'
    : 'Revert AI changes and clear the current draft?';

  return (
    <div className="confirm-modal-overlay" onClick={ onClose }>
      <div className="confirm-modal" onClick={ ( event ) => event.stopPropagation() }>
        <div className="confirm-modal-header">
          <h3>Revert AI changes?</h3>
          <button type="button" className="confirm-modal-close" onClick={ onClose } aria-label="Close revert dialog">
            <XMarkIcon className="icon" />
          </button>
        </div>
        <p className="confirm-modal-body">{ message }</p>
        <div className="confirm-modal-actions">
          <button
            type="button"
            className="ai-history-action ai-history-action--secondary"
            onClick={ onClose }
          >
            Cancel
          </button>
          <button
            type="button"
            className="ai-history-action"
            onClick={ onConfirm }
          >
            Revert changes
          </button>
        </div>
      </div>
    </div>
  );
};

export default RevertConfirm;
