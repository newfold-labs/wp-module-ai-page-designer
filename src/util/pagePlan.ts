import apiFetch from '@wordpress/api-fetch';

/**
 * Live page-plan generation (PROTOTYPE — Stage 2, catalogue at 2/10 archetypes).
 *
 * Asks the harness-owned /page-plan endpoint for a plan restricted to the
 * currently registered archetypes, rendered server-side via PageAssembler.
 * Off by default (`enablePagePlan` filter) — enable only for testing until
 * the catalogue is broad enough for real use.
 */

export interface PagePlanResult {
  content: string;
  title: string;
}

// Opt-in: only an explicit `true` (set via the
// nfd_ai_page_designer_enable_page_plan filter) turns it on.
export const isPagePlanEnabled = (): boolean =>
  ( window as any )?.nfdAIPageDesigner?.enablePagePlan === true;

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
