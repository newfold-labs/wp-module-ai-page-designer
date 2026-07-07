import type { ConversationSnapshot } from '../hooks/useAiConversation';

// Plan: "cache last ~5 pages' chat threads in memory for instant
// switch-back". Everything needed to make a page look exactly as the user
// left it — including publishedHtml, since without a per-page baseline the
// single shared publishedHtmlRef would misreport an already-published page
// as dirty after visiting and publishing a different one in between.
export type PageSession = {
  previewHtml: string | null;
  originalPreviewHtml: string | null;
  metaTitle: string;
  metaExcerpt: string;
  metaFeaturedMediaId: number | null;
  metaFeaturedImageUrl: string | null;
  publishTitle: string;
  originalMeta: { title: string; excerpt: string; featuredMediaId: number | null } | null;
  publishedHtml: string | null;
  conversation: ConversationSnapshot;
  selectedPaletteId: string | null;
  selectedFontPairingId: string;
};

const MAX_ENTRIES = 5;

export function createPageSessionCache() {
  const cache = new Map<number, PageSession>();

  return {
    get( id: number ): PageSession | undefined {
      const entry = cache.get( id );
      if ( entry ) {
        // Bump to most-recently-used position.
        cache.delete( id );
        cache.set( id, entry );
      }
      return entry;
    },
    set( id: number, session: PageSession ): void {
      cache.delete( id );
      cache.set( id, session );
      if ( cache.size > MAX_ENTRIES ) {
        const oldestKey = cache.keys().next().value;
        if ( oldestKey !== undefined ) {
          cache.delete( oldestKey );
        }
      }
    },
  };
}
