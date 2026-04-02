import { type RefObject } from 'react';
type BlockSelectionResult = {
    selectedBlockIndex: string | null;
    selectedBlockHtml: string | null;
    setSelectedBlockIndex: (value: string | null) => void;
    setSelectedBlockHtml: (value: string | null) => void;
    clearSelection: (iframeRef?: RefObject<HTMLIFrameElement>) => void;
};
export declare const useBlockSelection: () => BlockSelectionResult;
export default useBlockSelection;
//# sourceMappingURL=useBlockSelection.d.ts.map