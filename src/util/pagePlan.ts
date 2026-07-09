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
  excerpt: string;
}

// On by default; only an explicit `false` (set via the
// nfd_ai_page_designer_enable_page_plan filter) turns it off.
export const isPagePlanEnabled = (): boolean =>
  ( window as any )?.nfdAIPageDesigner?.enablePagePlan !== false;

/**
 * Generate a page from a text prompt via the page-plan pipeline. When
 * redesigning an EXISTING page, pass its title/excerpt as `existingTitle`/
 * `existingExcerpt` — the server folds these into the SYSTEM prompt (never
 * the old body markup or images), not into the user's own prompt text.
 * Testing showed appending title/excerpt into the free-form prompt measurably
 * increased the odds of the model returning right-shaped-but-textually-empty
 * sections; keeping it in the system prompt (like the existing site-name/
 * tagline context) is reliable.
 * Never throws — returns null on any failure so callers can fall back to the
 * existing freeform AI generate path.
 */
export const generatePagePlanPage = async (
  apiUrl: string,
  prompt: string,
  existingTitle = '',
  existingExcerpt = ''
): Promise<PagePlanResult | null> => {
  try {
    const result = await apiFetch<{ content?: string; title?: string; excerpt?: string }>( {
      path: `${ apiUrl }/page-plan`,
      method: 'POST',
      data: {
        prompt,
        existing_title: existingTitle,
        existing_excerpt: existingExcerpt,
      },
    } );

    if ( ! result?.content ) {
      // eslint-disable-next-line no-console
      console.warn( '[AI Page Designer] /page-plan returned no content; falling back to freeform generate.', result );
      return null;
    }
    return { content: result.content, title: result.title || '', excerpt: result.excerpt || '' };
  } catch ( error ) {
    // eslint-disable-next-line no-console
    console.warn( '[AI Page Designer] /page-plan request failed; falling back to freeform generate.', error );
    return null;
  }
};
