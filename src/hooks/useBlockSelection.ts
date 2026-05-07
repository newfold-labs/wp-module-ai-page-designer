import { useEffect, useState, type RefObject } from 'react';

type BlockSelectionResult = {
  selectedBlockIndex: string | null;
  selectedBlockHtml: string | null;
  selectedBlockLabel: string | null;
  setSelectedBlockIndex: (value: string | null) => void;
  setSelectedBlockHtml: (value: string | null) => void;
  setSelectedBlockLabel: (value: string | null) => void;
  clearSelection: (iframeRef?: RefObject<HTMLIFrameElement>) => void;
};

export const useBlockSelection = (): BlockSelectionResult => {
  const [ selectedBlockIndex, setSelectedBlockIndex ] = useState<string | null>( null );
  const [ selectedBlockHtml, setSelectedBlockHtml ] = useState<string | null>( null );
  const [ selectedBlockLabel, setSelectedBlockLabel ] = useState<string | null>( null );

  useEffect( () => {
    const handleMessage = ( event: MessageEvent ) => {
      if ( event.data?.type === 'NFD_BLOCK_SELECTED' ) {
        setSelectedBlockIndex( event.data.index !== null ? event.data.index : null );
        setSelectedBlockHtml( event.data.html || null );
        setSelectedBlockLabel( event.data.blockLabel || null );
      }
    };

    window.addEventListener( 'message', handleMessage );
    return () => window.removeEventListener( 'message', handleMessage );
  }, [] );

  const clearSelection = ( iframeRef?: RefObject<HTMLIFrameElement> ) => {
    setSelectedBlockIndex( null );
    setSelectedBlockHtml( null );
    setSelectedBlockLabel( null );
    if ( iframeRef?.current?.contentWindow ) {
      iframeRef.current.contentWindow.postMessage( { type: 'NFD_CLEAR_SELECTION' }, '*' );
    }
  };

  return {
    selectedBlockIndex,
    selectedBlockHtml,
    selectedBlockLabel,
    setSelectedBlockIndex,
    setSelectedBlockHtml,
    setSelectedBlockLabel,
    clearSelection,
  };
};

export default useBlockSelection;
