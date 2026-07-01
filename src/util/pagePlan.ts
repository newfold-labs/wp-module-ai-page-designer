import apiFetch from '@wordpress/api-fetch';

/**
 * Live page-plan generation (Stage 2 — 10/10 v1 archetype catalogue complete).
 *
 * Asks the harness-owned /page-plan endpoint for a plan of typed archetype
 * sections, rendered server-side via PageAssembler. On by default; disable
 * via the nfd_ai_page_designer_enable_page_plan filter.
 */

export interface PagePlanResult {
  content: string;
  title: string;
}

// On by default; only an explicit `false` (set via the
// nfd_ai_page_designer_enable_page_plan filter) turns it off.
export const isPagePlanEnabled = (): boolean =>
  ( window as any )?.nfdAIPageDesigner?.enablePagePlan !== false;

/**
 * Generate a page from a text prompt via the page-plan pipeline.
 * Never throws — returns null on any failure so callers can fall back to the
 * existing freeform AI generate path.
 */
export const generatePagePlanPage = async (
  apiUrl: string,
  prompt: string
): Promise<PagePlanResult | null> => {
  try {
    const result = await apiFetch<{ content?: string; title?: string }>( {
      path: `${ apiUrl }/page-plan`,
      method: 'POST',
      data: { prompt },
    } );

    if ( ! result?.content ) {
      return null;
    }
    return { content: result.content, title: result.title || '' };
  } catch {
    return null;
  }
};
