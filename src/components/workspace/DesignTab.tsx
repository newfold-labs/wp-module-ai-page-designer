import React from 'react';
import { SparklesIcon } from '@heroicons/react/24/outline';
import { CURATED_FONT_PAIRINGS, CURATED_PALETTES, type DesignPalette } from '../../designTokens';

type Props = {
  selectedPaletteId: string | null;
  selectedFontPairingId: string;
  themePalettes: DesignPalette[];
  canSuggest: boolean;
  suggesting: boolean;
  onSelectPalette: ( paletteId: string | null ) => void;
  onSelectFontPairing: ( fontPairingId: string ) => void;
  onSuggestPalette: () => void;
};

const DesignTab = ( {
  selectedPaletteId,
  selectedFontPairingId,
  themePalettes,
  canSuggest,
  suggesting,
  onSelectPalette,
  onSelectFontPairing,
  onSuggestPalette,
}: Props ) => {
  return (
    <div className="ai-design-tab">
      <div className="ai-design-tab__section">
        <div className="ai-design-tab__section-header">
          <div>
            <h4 className="ai-design-tab__section-title">Color palette</h4>
            <p className="ai-design-tab__section-hint">Swaps instantly in the preview — no AI call.</p>
          </div>
          <button
            type="button"
            className="ai-design-tab__suggest-btn"
            onClick={ onSuggestPalette }
            disabled={ ! canSuggest || suggesting }
          >
            <SparklesIcon className="icon-sm" />
            { suggesting ? 'Thinking...' : 'Suggest with AI' }
          </button>
        </div>
        <div className="ai-design-tab__palette-grid">
          <button
            type="button"
            className={ `ai-design-tab__palette ${ selectedPaletteId === null ? 'is-selected' : '' }` }
            onClick={ () => onSelectPalette( null ) }
          >
            <span className="ai-design-tab__palette-swatches ai-design-tab__palette-swatches--default" />
            <span className="ai-design-tab__palette-name">Theme default</span>
          </button>
          {
            /* 'theme-classic' ("Site colors") is the theme's own unmodified
               colors — the same thing "Theme default" above already selects
               (paletteId=null). Skip it here to avoid showing two entries
               that produce an identical look; 'Site bold'/'Site soft' are
               genuine variations and stay. Still returned by
               buildThemePalettes() (App.tsx) so any page saved with this id
               before this change still resolves correctly. */
            themePalettes
              .filter( ( palette ) => palette.id !== 'theme-classic' )
              .map( ( palette ) => (
            <button
              key={ palette.id }
              type="button"
              className={ `ai-design-tab__palette ${ selectedPaletteId === palette.id ? 'is-selected' : '' }` }
              onClick={ () => onSelectPalette( palette.id ) }
            >
              <span className="ai-design-tab__palette-swatches">
                { palette.preview.map( ( color, index ) => (
                  <span
                    key={ index }
                    className="ai-design-tab__palette-swatch"
                    style={ { backgroundColor: color } }
                  />
                ) ) }
              </span>
              <span className="ai-design-tab__palette-name">{ palette.name }</span>
            </button>
          ) ) }
          { CURATED_PALETTES.map( ( palette ) => (
            <button
              key={ palette.id }
              type="button"
              className={ `ai-design-tab__palette ${ selectedPaletteId === palette.id ? 'is-selected' : '' }` }
              onClick={ () => onSelectPalette( palette.id ) }
            >
              <span className="ai-design-tab__palette-swatches">
                { palette.preview.map( ( color, index ) => (
                  <span
                    key={ index }
                    className="ai-design-tab__palette-swatch"
                    style={ { backgroundColor: color } }
                  />
                ) ) }
              </span>
              <span className="ai-design-tab__palette-name">{ palette.name }</span>
            </button>
          ) ) }
        </div>
      </div>

      <div className="ai-design-tab__section">
        <h4 className="ai-design-tab__section-title">Typography</h4>
        <p className="ai-design-tab__section-hint">Heading and body font pairing for this page.</p>
        <div className="ai-design-tab__font-list">
          { CURATED_FONT_PAIRINGS.map( ( pairing ) => (
            <button
              key={ pairing.id }
              type="button"
              className={ `ai-design-tab__font ${ selectedFontPairingId === pairing.id ? 'is-selected' : '' }` }
              onClick={ () => onSelectFontPairing( pairing.id ) }
            >
              <span
                className="ai-design-tab__font-preview"
                style={ pairing.headingFont ? { fontFamily: pairing.headingFont } : undefined }
              >
                Aa
              </span>
              <span className="ai-design-tab__font-name">{ pairing.name }</span>
            </button>
          ) ) }
        </div>
      </div>
    </div>
  );
};

export default DesignTab;
