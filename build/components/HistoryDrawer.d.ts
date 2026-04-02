import React from 'react';
import type { HistoryEntry } from '../types';
type Props = {
    historyEntries: HistoryEntry[];
    isOpen: boolean;
    onToggleOpen: () => void;
    onRevertTo: (id: string) => void;
};
declare const HistoryDrawer: ({ historyEntries, isOpen, onToggleOpen, onRevertTo, }: Props) => React.JSX.Element | null;
export default HistoryDrawer;
//# sourceMappingURL=HistoryDrawer.d.ts.map