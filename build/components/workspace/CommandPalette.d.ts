import React from 'react';
import type { WPItem } from '../../types';
type Props = {
    open: boolean;
    apiUrl: string;
    onClose: () => void;
    onSelectItem: (item: WPItem) => void;
};
declare const CommandPalette: ({ open, apiUrl, onClose, onSelectItem }: Props) => React.JSX.Element | null;
export default CommandPalette;
//# sourceMappingURL=CommandPalette.d.ts.map