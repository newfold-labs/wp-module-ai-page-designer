import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import './styles.css';

// Extend Window interface for TypeScript
declare global {
  interface Window {
    AIPageDesignerApp: typeof App;
  }
}

// Expose the App component globally so the main plugin can mount it
window.AIPageDesignerApp = App;

// Legacy mount point for standalone page (fallback) - React 18 compatible
document.addEventListener('DOMContentLoaded', () => {
  const rootElement = document.getElementById('nfd-ai-page-designer-root');
  
  if (rootElement) {
    const root = createRoot(rootElement);
    root.render(<App />);
  }
});
