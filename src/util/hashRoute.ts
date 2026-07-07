/**
 * Hash-route format: `#/page/:id`. No page reloads — reading/writing
 * `location.hash` is a state change, not navigation (plan section 1: Routing).
 */

const HASH_ROUTE_PATTERN = /^#\/page\/(\d+)$/;

export function parseHashRoute( hash: string ): { pageId: number | null } {
  const match = HASH_ROUTE_PATTERN.exec( hash );
  if ( ! match ) {
    return { pageId: null };
  }
  return { pageId: parseInt( match[ 1 ], 10 ) };
}

export function serializeHashRoute( pageId: number | null ): string {
  return pageId === null ? '#/' : `#/page/${ pageId }`;
}
