import { useCallback, useEffect, useState } from 'react';
import { parseHashRoute, serializeHashRoute } from '../util/hashRoute';

/**
 * Not wired into App.tsx yet — Phase 1 replaces the current `view` state
 * (dashboard/designer) with page-id-driven routing built on this hook.
 */
export function useHashRoute() {
  const [ pageId, setPageIdState ] = useState<number | null>(
    () => parseHashRoute( window.location.hash ).pageId
  );

  useEffect( () => {
    const onHashChange = () => {
      setPageIdState( parseHashRoute( window.location.hash ).pageId );
    };
    window.addEventListener( 'hashchange', onHashChange );
    return () => window.removeEventListener( 'hashchange', onHashChange );
  }, [] );

  const setPageId = useCallback( ( nextPageId: number | null ) => {
    const nextHash = serializeHashRoute( nextPageId );
    if ( window.location.hash !== nextHash ) {
      window.location.hash = nextHash;
    }
    setPageIdState( nextPageId );
  }, [] );

  return { pageId, setPageId };
}
