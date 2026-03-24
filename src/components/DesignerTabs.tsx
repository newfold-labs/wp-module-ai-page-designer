import React from 'react';
import { SparklesIcon, Squares2X2Icon } from '@heroicons/react/24/outline';

type Props = {
  view: 'dashboard' | 'designer';
  onDashboard: () => void;
  onDesigner: () => void;
};

const DesignerTabs = ( { view, onDashboard, onDesigner }: Props ) => {
  return (
    <div className="ai-designer-tabs">
      <button
        className={ `ai-designer-tab ${ view === 'dashboard' ? 'active' : '' }` }
        onClick={ onDashboard }
      >
        <Squares2X2Icon className="icon" />
        Dashboard
      </button>
      <button
        className={ `ai-designer-tab ${ view === 'designer' ? 'active' : '' }` }
        onClick={ onDesigner }
      >
        <SparklesIcon className="icon" />
        Designer
      </button>
    </div>
  );
};

export default DesignerTabs;
