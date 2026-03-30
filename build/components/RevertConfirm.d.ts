import React from 'react';
import type { WPItem } from '../types';
type Props = {
    open: boolean;
    selectedItem: WPItem | null;
    onClose: () => void;
    onConfirm: () => void;
};
declare const RevertConfirm: ({ open, selectedItem, onClose, onConfirm }: Props) => React.JSX.Element | null;
export default RevertConfirm;
//# sourceMappingURL=RevertConfirm.d.ts.map