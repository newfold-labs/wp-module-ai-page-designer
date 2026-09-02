/**
 * Deep-links the open page via the `nfd_page_id` query param, not the URL
 * hash — the parent wp-plugin-web app's HashRouter owns `location.hash`
 * (exact-matches `#/ai-designer` with no wildcard route), so writing our own
 * `#/page/:id` there stomps its route and un-mounts this app entirely.
 */

const PARAM_NAME = 'nfd_page_id';

export function parsePageIdFromLocation(): number | null {
  const raw = new URLSearchParams( window.location.search ).get( PARAM_NAME );
  if ( ! raw ) {
    return null;
  }
  const parsed = parseInt( raw, 10 );
  return isNaN( parsed ) ? null : parsed;
}

// Not a real navigation (plan section 1: "switching pages is a state change,
// not navigation") — replaceState so it never adds a back-button stop.
export function writePageIdToLocation( pageId: number | null ): void {
  const url = new URL( window.location.href );
  if ( pageId === null ) {
    url.searchParams.delete( PARAM_NAME );
  } else {
    url.searchParams.set( PARAM_NAME, String( pageId ) );
  }
  try {
    window.history.replaceState( window.history.state, '', url.toString() );
  } catch ( error ) {
    // Same-document history mutations share a single browser-wide rate
    // limit with the parent app's own HashRouter navigation — swallow so a
    // burst of quick page switches degrades the URL sync, not the app.
    // eslint-disable-next-line no-console
    console.warn( 'nfd-ai-page-designer: failed to sync nfd_page_id to the URL', error );
  }
}
