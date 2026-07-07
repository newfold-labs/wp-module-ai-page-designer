import React from 'react';
import { CURATED_FONT_PAIRINGS, CURATED_PALETTES } from '../../designTokens';

type Props = {
  selectedPaletteId: string | null;
  selectedFontPairingId: string;
  onSelectPalette: ( paletteId: string | null ) => void;
  onSelectFontPairing: ( fontPairingId: string ) => void;
};

const DesignTab = ( {
  selectedPaletteId,
  selectedFontPairingId,
  onSelectPalette,
  onSelectFontPairing,
}: Props ) => {
  return (
    <div className="ai-design-tab">
      <div className="ai-design-tab__section">
        <h4 className="ai-design-tab__section-title">Color palette</h4>
        <p className="ai-design-tab__section-hint">Swaps instantly in the preview — no AI call.</p>
        <div className="ai-design-tab__palette-grid">
          <button
            type="button"
            className={ `ai-design-tab__palette ${ selectedPaletteId === null ? 'is-selected' : '' }` }
            onClick={ () => onSelectPalette( null ) }
          >
            <span className="ai-design-tab__palette-swatches ai-design-tab__palette-swatches--default" />
            <span className="ai-design-tab__palette-name">Theme default</span>
          </button>
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
