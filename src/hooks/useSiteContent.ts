import { useCallback, useEffect, useState } from 'react';
import type { WPItem } from '../types';
import { fetchSitePages, fetchSitePosts } from '../api';

type UseSiteContentResult = {
  sitePages: WPItem[];
  sitePosts: WPItem[];
  loadingSite: boolean;
  fetchSiteContent: () => Promise<void>;
};

export const useSiteContent = ( apiUrl: string, active: boolean ): UseSiteContentResult => {
  const [ sitePages, setSitePages ] = useState<WPItem[]>( [] );
  const [ sitePosts, setSitePosts ] = useState<WPItem[]>( [] );
  const [ loadingSite, setLoadingSite ] = useState( false );

  const fetchSiteContent = useCallback( async () => {
    setLoadingSite( true );
    try {
      const [ pagesRes, postsRes ] = await Promise.all( [
        fetchSitePages( apiUrl ),
        fetchSitePosts( apiUrl ),
      ] );
      setSitePages( pagesRes || [] );
      setSitePosts( postsRes || [] );
    } catch ( error ) {
      console.error( 'Failed to fetch site content:', error );
    } finally {
      setLoadingSite( false );
    }
  }, [ apiUrl ] );

  useEffect( () => {
    if ( active ) {
      fetchSiteContent();
    }
  }, [ active, fetchSiteContent ] );

  return {
    sitePages,
    sitePosts,
    loadingSite,
    fetchSiteContent,
  };
};

export default useSiteContent;
