import React from 'react';
import { ClockIcon } from '@heroicons/react/24/outline';
import type { HistoryEntry } from '../types';

type Props = {
  historyEntries: HistoryEntry[];
  onRevertTo: ( id: string ) => void;
};

const HistoryPane = ( { historyEntries, onRevertTo }: Props ) => {
  if ( historyEntries.length === 0 ) {
    return (
      <div className="ai-history-pane">
        <div className="ai-history-pane__header">
          <h3>Edit History</h3>
        </div>
        <div className="ai-history-pane__empty">
          <div className="ai-history-pane__empty-icon">
            <ClockIcon className="icon" />
          </div>
          <p>No edits yet.</p>
          <p>Your AI edits will appear here so you can restore earlier versions.</p>
        </div>
      </div>
    );
  }

  const lastId = historyEntries[ historyEntries.length - 1 ].id;
  const reversed = [ ...historyEntries ].reverse();

  return (
    <div className="ai-history-pane">
      <div className="ai-history-pane__header">
        <h3>Edit History</h3>
        <span className="ai-history-pane__count">{ historyEntries.length } edits</span>
      </div>
      <ul className="ai-history-pane__list">
        { reversed.map( ( entry ) => (
          <li key={ entry.id } className={ `ai-history-pane__item ${ entry.id === lastId ? 'ai-history-pane__item--current' : '' }` }>
            <div className="ai-history-pane__item-info">
              <span className="ai-history-pane__item-label">{ entry.label }</span>
              <span className="ai-history-pane__item-time">{ entry.timestamp }</span>
            </div>
            { entry.id !== lastId ? (
              <button
                type="button"
                className="ai-history-action"
                onClick={ () => onRevertTo( entry.id ) }
              >
                Restore
              </button>
            ) : (
              <span className="ai-history-pane__current-badge">Current</span>
            ) }
          </li>
        ) ) }
      </ul>
    </div>
  );
};

export default HistoryPane;
