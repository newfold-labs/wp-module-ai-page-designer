import { useCallback, useEffect, useState } from 'react';
import type { WPItem } from '../types';
import { fetchRecentItems, touchRecentItem } from '../api';

type UseRecentItemsResult = {
  recentItems: WPItem[];
  loadingRecent: boolean;
  touchRecent: ( id: number ) => Promise<void>;
};

export const useRecentItems = ( apiUrl: string ): UseRecentItemsResult => {
  const [ recentItems, setRecentItems ] = useState<WPItem[]>( [] );
  const [ loadingRecent, setLoadingRecent ] = useState( false );

  useEffect( () => {
    let cancelled = false;
    setLoadingRecent( true );
    fetchRecentItems( apiUrl )
      .then( ( items ) => {
        if ( ! cancelled ) {
          setRecentItems( items || [] );
        }
      } )
      .catch( ( error ) => console.error( 'Failed to fetch recent items:', error ) )
      .finally( () => {
        if ( ! cancelled ) {
          setLoadingRecent( false );
        }
      } );
    return () => {
      cancelled = true;
    };
  }, [ apiUrl ] );

  // Optimistic: reflect the touched item at the front immediately, then
  // reconcile with the server's response (dedup/order authoritative there).
  const touchRecent = useCallback( async ( id: number ) => {
    setRecentItems( ( prev ) => {
      const match = prev.find( ( item ) => item.id === id );
      if ( ! match ) {
        return prev;
      }
      return [ match, ...prev.filter( ( item ) => item.id !== id ) ];
    } );
    try {
      const items = await touchRecentItem( apiUrl, id );
      setRecentItems( items || [] );
    } catch ( error ) {
      console.error( 'Failed to record recent item:', error );
    }
  }, [ apiUrl ] );

  return { recentItems, loadingRecent, touchRecent };
};

export default useRecentItems;
