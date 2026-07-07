/**
 * Intent classifier (PROTOTYPE).
 *
 * Replaces the scattered keyword-regex routing in useAiConversation with a
 * single cheap AI call that turns a free-text instruction into a typed action.
 * The model resolves the fuzzy parts regex can't ("light blue" -> a hex, "a
 * bit darker", "match the theme") and disambiguates intent (recolour vs. add a
 * block vs. metadata edit). The frontend then routes deterministically on the
 * returned type — the AI decides WHAT, our code decides HOW.
 *
 * Behind the `enableIntentClassifier` flag so we can A/B it against the regex
 * path. On any error / low confidence / 'freeform', callers fall back to the
 * existing deterministic-regex + AI-generate path, so this never blocks an edit.
 */
export type EditIntentAction = 'recolor_text' | 'recolor_background' | 'remove' | 'redesign' | 'edit_metadata' | 'add_block' | 'replace_image' | 'freeform';
export interface EditIntent {
    action: EditIntentAction;
    target: 'selected' | 'page' | null;
    /** A concrete CSS colour (hex/name) the model resolved, or null. */
    color: string | null;
    /** A friendly, non-technical colour name for display ("light blue"), or null. */
    color_label: string | null;
    metadata_fields: Array<'title' | 'excerpt' | 'summary'>;
    /** Core block slug without the `core/` prefix, for add_block. */
    block_type: string | null;
    insert_direction: 'before' | 'after' | null;
    confidence: number;
    reason?: string;
}
export interface ClassifyContext {
    has_selection: boolean;
    selected_block_type?: string | null;
    has_generated: boolean;
    palette?: Array<{
        slug: string;
        name?: string;
        color: string;
    }>;
}
export declare const isIntentClassifierEnabled: () => boolean;
/**
 * Classify a free-text instruction into a typed edit action.
 * Always resolves (never throws) — returns a freeform fallback on any failure.
 */
export declare const classifyIntent: (apiUrl: string, text: string, ctx: ClassifyContext) => Promise<EditIntent>;
/**
 * Harness-owned metadata generation. Asks our own /metadata endpoint (which
 * prompts the model via the analyze pass-through) for a clean excerpt/title
 * derived from the page content. Returns the value, or null on any failure so
 * the caller can fall back gracefully.
 */
export declare const generateMetadata: (apiUrl: string, field: "excerpt" | "title", markup: string) => Promise<string | null>;
//# sourceMappingURL=intentClassifier.d.ts.map