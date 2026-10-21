import apiFetch from '@wordpress/api-fetch';

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

export type EditIntentAction =
  | 'recolor_text'
  | 'recolor_background'
  | 'remove'
  | 'redesign'
  | 'edit_metadata'
  | 'add_block'
  | 'replace_image'
  | 'freeform';

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
  palette?: Array<{ slug: string; name?: string; color: string }>;
}

const FREEFORM: EditIntent = {
  action: 'freeform',
  target: null,
  color: null,
  color_label: null,
  metadata_fields: [],
  block_type: null,
  insert_direction: null,
  confidence: 0,
};

// Enabled by default; only an explicit `false` (set via the
// nfd_ai_page_designer_enable_intent_classifier filter) turns it off.
export const isIntentClassifierEnabled = (): boolean =>
  ( window as any )?.nfdAIPageDesigner?.enableIntentClassifier !== false;

/**
 * Classify a free-text instruction into a typed edit action.
 * Always resolves (never throws) — returns a freeform fallback on any failure.
 */
export const classifyIntent = async (
  apiUrl: string,
  text: string,
  ctx: ClassifyContext
): Promise<EditIntent> => {
  try {
    const result = await apiFetch<EditIntent>( {
      path: `${ apiUrl }/classify`,
      method: 'POST',
      data: {
        text,
        context: {
          has_selection: ctx.has_selection,
          selected_block_type: ctx.selected_block_type || '',
          has_generated: ctx.has_generated,
          palette: ctx.palette || [],
        },
      },
    } );

    if ( ! result || typeof result.action !== 'string' ) {
      return FREEFORM;
    }
    return { ...FREEFORM, ...result };
  } catch {
    return FREEFORM;
  }
};

/**
 * Harness-owned metadata generation. Asks our own /metadata endpoint (which
 * prompts the model via the analyze pass-through) for a clean excerpt/title
 * derived from the page content. Returns the value, or null on any failure so
 * the caller can fall back gracefully.
 */
export const generateMetadata = async (
  apiUrl: string,
  field: 'excerpt' | 'title',
  markup: string
): Promise<string | null> => {
  try {
    const result = await apiFetch<{ field: string; value: string }>( {
      path: `${ apiUrl }/metadata`,
      method: 'POST',
      data: { field, markup },
    } );
    const value = result?.value ? result.value.trim() : '';
    return value || null;
  } catch {
    return null;
  }
};

/**
 * Design tab "Suggest with AI": asks the model to pick the best-fit palette
 * for the current page content, choosing only from the ids passed in (never
 * a free-form color — matches the plan's "no free-form color picker"
 * constraint). Returns the chosen palette id, or null on any failure so the
 * caller can leave the current selection untouched.
 */
export const suggestPalette = async (
  apiUrl: string,
  markup: string,
  palettes: Array<{ id: string; name: string }>
): Promise<string | null> => {
  try {
    const result = await apiFetch<{ paletteId: string }>( {
      path: `${ apiUrl }/suggest-palette`,
      method: 'POST',
      data: { markup, palettes },
    } );
    return result?.paletteId || null;
  } catch {
    return null;
  }
};
