import React, { useState } from 'react';
import { SwatchIcon } from '@heroicons/react/24/outline';
import { applyColorToBlocks } from '../util/blockMarkupEditor';
import type { ColorTarget } from '../util/blockMarkupEditor';

type ColorSwatch = {
  slug: string;
  name: string;
  color: string;
};

type Props = {
  colorPalette: ColorSwatch[];
  previewHtml: string | null;
  onApplyDirectChange: ( html: string, label: string ) => void;
};

const ColorPane = ( { colorPalette, previewHtml, onApplyDirectChange }: Props ) => {
  const [ target, setTarget ] = useState<ColorTarget>( 'text' );

  const handleSwatchClick = ( swatch: ColorSwatch ) => {
    if ( ! previewHtml ) {
      return;
    }
    const updated = applyColorToBlocks( previewHtml, swatch.slug, target );
    const label = `Color: ${ swatch.name } (${ target === 'text' ? 'Text' : 'Background' })`;
    onApplyDirectChange( updated, label );
  };

  if ( colorPalette.length === 0 ) {
    return (
      <div className="ai-color-pane">
        <div className="ai-color-pane__empty">
          <div className="ai-color-pane__empty-icon">
            <SwatchIcon className="icon" />
          </div>
          <p>No theme colors available.</p>
          <p>Theme color palettes from your active theme will appear here.</p>
        </div>
      </div>
    );
  }

  return (
    <div className="ai-color-pane">
      <div className="ai-color-pane__section">
        <p className="ai-color-pane__section-title">Apply to</p>
        <div className="ai-color-pane__target-toggle">
          <button
            type="button"
            className={ `ai-color-pane__target-btn ${ target === 'text' ? 'active' : '' }` }
            onClick={ () => setTarget( 'text' ) }
          >
            Text
          </button>
          <button
            type="button"
            className={ `ai-color-pane__target-btn ${ target === 'background' ? 'active' : '' }` }
            onClick={ () => setTarget( 'background' ) }
          >
            Background
          </button>
        </div>
      </div>

      <div className="ai-color-pane__section">
        <p className="ai-color-pane__section-title">Theme colors</p>
        { ! previewHtml && (
          <p className="ai-color-pane__no-content">Generate a page first to apply colors.</p>
        ) }
        <div className="ai-color-pane__swatches">
          { colorPalette.map( ( swatch ) => (
            <button
              key={ swatch.slug }
              type="button"
              className="ai-color-swatch"
              style={ { backgroundColor: swatch.color } }
              title={ swatch.name }
              aria-label={ `Apply ${ swatch.name }` }
              onClick={ () => handleSwatchClick( swatch ) }
              disabled={ ! previewHtml }
            />
          ) ) }
        </div>
      </div>
    </div>
  );
};

export default ColorPane;
