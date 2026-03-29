import React from 'react';
import type { HistoryEntry } from '../types';

type Props = {
  historyEntries: HistoryEntry[];
  isOpen: boolean;
  onToggleOpen: () => void;
  onRevertTo: (id: string) => void;
};

const HistoryDrawer = ( {
  historyEntries,
  isOpen,
  onToggleOpen,
  onRevertTo,
}: Props ) => {
  if ( historyEntries.length === 0 ) {
    return null;
  }

  const lastId = historyEntries[ historyEntries.length - 1 ].id;

  return (
    <div className="ai-history">
      <div className="ai-history-header">
        <h4>AI edit history</h4>
        <div className="ai-history-header-actions">
          <button
            type="button"
            className="ai-history-toggle"
            onClick={ onToggleOpen }
          >
            { isOpen ? 'Hide' : 'Show' }
          </button>
        </div>
      </div>
      { isOpen ? (
        <ul className="ai-history-list">
          { historyEntries.map( ( entry ) => (
            <li key={ entry.id } className="ai-history-item">
              <span className="ai-history-text">
                { entry.label }
                <span className="ai-history-time">{ entry.timestamp }</span>
              </span>
              { entry.id !== lastId && (
                <button
                  type="button"
                  className="ai-history-action"
                  onClick={ () => onRevertTo( entry.id ) }
                >
                  Restore to this version
                </button>
              ) }
            </li>
          ) ) }
        </ul>
      ) : (
        <p className="ai-history-collapsed">
          { historyEntries.length } edits saved. Click &ldquo;Show&rdquo; to view.
        </p>
      ) }
    </div>
  );
};

export default HistoryDrawer;
