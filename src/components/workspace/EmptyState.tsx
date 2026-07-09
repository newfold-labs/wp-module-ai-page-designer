import React, { useMemo, useState } from 'react';
import { SparklesIcon } from '@heroicons/react/24/outline';
import { DASHBOARD_PROMPT_CHIPS, pickRandomPromptChips } from '../../promptChips';

type Props = {
  onCreateWithPrompt: ( prompt: string ) => void;
};

// Canvas's no-page-selected state — the old Dashboard route's hero, minus
// the pages/posts grid (the PageDrawer now owns browsing).
const EmptyState = ( { onCreateWithPrompt }: Props ) => {
  const promptSuggestions = useMemo( () => pickRandomPromptChips( DASHBOARD_PROMPT_CHIPS, 3 ), [] );
  const [ prompt, setPrompt ] = useState(
    'Create a modern homepage with a hero section, key features, and a call to action'
  );

  const handleGenerate = () => {
    const trimmed = prompt.trim();
    if ( ! trimmed ) return;
    onCreateWithPrompt( trimmed );
  };

  const handleKeyDown = ( e: React.KeyboardEvent<HTMLTextAreaElement> ) => {
    if ( e.key === 'Enter' && prompt.trim() ) {
      handleGenerate();
    }
  };

  return (
    <div className="ai-workspace-empty-state">
      <div className="ai-hero">
        <div className="ai-hero__content">
          <div className="ai-hero__chip">Intelligence Canvas</div>
          <h2 className="ai-hero__heading">Create New Page with AI</h2>
          <p className="ai-hero__sub">Leverage AI to generate high-converting layouts in seconds.</p>
          <div className="ai-hero__input-wrap">
            <textarea
              className="ai-hero__input"
              placeholder="Describe your dream page..."
              value={ prompt }
              onChange={ ( e ) => setPrompt( e.target.value ) }
              onKeyDown={ handleKeyDown }
              rows={ 2 }
            />
            <button
              type="button"
              className="ai-hero__generate-btn"
              onClick={ handleGenerate }
              disabled={ ! prompt.trim() }
            >
              <SparklesIcon className="icon-sm" />
              Generate
            </button>
          </div>
          <div className="ai-hero__prompt-chips">
            <span className="ai-hero__prompt-label">Try:</span>
            { promptSuggestions.map( ( suggestion ) => (
              <button
                key={ suggestion }
                type="button"
                className="ai-hero__prompt-chip"
                onClick={ () => setPrompt( suggestion ) }
              >
                { suggestion }
              </button>
            ) ) }
          </div>
        </div>
        <div className="ai-hero__deco-icon" aria-hidden="true">
          <SparklesIcon style={ { color: '#ffffff', stroke: '#ffffff' } } />
        </div>
        <div className="ai-hero__deco-glow" aria-hidden="true"></div>
      </div>
    </div>
  );
};

export default EmptyState;
