import { useCallback, useEffect, useState } from 'react';
import { parsePageIdFromLocation, writePageIdToLocation } from '../util/pageRoute';

export function usePageRoute() {
  const [ pageId, setPageIdState ] = useState<number | null>( () => parsePageIdFromLocation() );

  useEffect( () => {
    // Covers back/forward through history entries with a different
    // nfd_page_id — our own writes use replaceState (see writePageIdToLocation)
    // and never trigger this, so no update loop.
    const onPopState = () => {
      setPageIdState( parsePageIdFromLocation() );
    };
    window.addEventListener( 'popstate', onPopState );
    return () => window.removeEventListener( 'popstate', onPopState );
  }, [] );

  const setPageId = useCallback( ( nextPageId: number | null ) => {
    writePageIdToLocation( nextPageId );
    setPageIdState( nextPageId );
  }, [] );

  return { pageId, setPageId };
}
