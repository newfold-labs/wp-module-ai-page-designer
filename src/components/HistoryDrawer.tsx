import React from 'react';
import type { HistoryEntry } from '../types';

type Props = {
  historyEntries: HistoryEntry[];
  selectedHistoryIds: string[];
  isOpen: boolean;
  onToggleOpen: () => void;
  onToggleSelection: (id: string) => void;
  onRevertSelected: () => void;
  onClearSelection: () => void;
};

const HistoryDrawer = ( {
  historyEntries,
  selectedHistoryIds,
  isOpen,
  onToggleOpen,
  onToggleSelection,
  onRevertSelected,
  onClearSelection,
}: Props ) => {
  if ( historyEntries.length === 0 ) {
    return null;
  }

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
        <>
          <ul className="ai-history-list">
            { historyEntries.map( ( entry ) => (
              <li key={ entry.id } className="ai-history-item">
                <label className="ai-history-label">
                  <input
                    type="checkbox"
                    checked={ selectedHistoryIds.includes( entry.id ) }
                    onChange={ () => onToggleSelection( entry.id ) }
                  />
                  <span className="ai-history-text">
                    { entry.label }
                    <span className="ai-history-time">{ entry.timestamp }</span>
                  </span>
                </label>
              </li>
            ) ) }
          </ul>
          <div className="ai-history-actions">
            <button
              type="button"
              className="ai-history-action"
              disabled={ selectedHistoryIds.length === 0 }
              onClick={ onRevertSelected }
            >
              Revert selected (and newer)
            </button>
            <button
              type="button"
              className="ai-history-action ai-history-action--secondary"
              disabled={ selectedHistoryIds.length === 0 }
              onClick={ onClearSelection }
            >
              Clear selection
            </button>
          </div>
          <p className="ai-history-hint">
            Reverting removes the selected edits and anything after them.
          </p>
        </>
      ) : (
        <p className="ai-history-collapsed">
          { historyEntries.length } edits saved. Click “Show” to view.
        </p>
      ) }
    </div>
  );
};

export default HistoryDrawer;
