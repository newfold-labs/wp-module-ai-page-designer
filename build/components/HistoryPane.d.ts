import React from 'react';
import type { HistoryEntry } from '../types';
type Props = {
    historyEntries: HistoryEntry[];
    onRevertTo: (id: string) => void;
};
declare const HistoryPane: ({ historyEntries, onRevertTo }: Props) => React.JSX.Element;
export default HistoryPane;
//# sourceMappingURL=HistoryPane.d.ts.map