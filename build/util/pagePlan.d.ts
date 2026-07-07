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
export declare const isPagePlanEnabled: () => boolean;
/**
 * Generate a page from a text prompt via the page-plan pipeline.
 * Never throws — returns null on any failure so callers can fall back to the
 * existing freeform AI generate path.
 */
export declare const generatePagePlanPage: (apiUrl: string, prompt: string) => Promise<PagePlanResult | null>;
//# sourceMappingURL=pagePlan.d.ts.map