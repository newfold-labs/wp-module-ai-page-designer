import React from 'react';
declare global {
    interface Window {
        nfdAIPageDesigner: {
            apiUrl: string;
            apiRoot: string;
            nonce: string;
            siteUrl: string;
            canAccessAI: boolean;
            currentUserId: number;
            ajaxUrl: string;
            enableStreaming?: boolean;
            previewStylesheets?: {
                blockLibrary: string;
                themeUrl: string;
                globalStyles: string;
            };
        };
    }
}
declare const App: () => React.JSX.Element;
export default App;
//# sourceMappingURL=App.d.ts.map