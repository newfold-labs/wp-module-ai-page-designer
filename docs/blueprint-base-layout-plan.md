# Blueprint-Based Base Layout Plan

## Overview

Replace the current `PatternLayoutProvider` (which stitches individual patterns from `patterns.hiive.cloud`) with a `BlueprintService` that fetches full, curated site blueprints from the blueprints API and uses a single page's Gutenberg markup as the AI's base layout.

The blueprints are richer and more coherent than stitched patterns. The AI still rewrites all text and styling — the blueprint is purely structural scaffolding.

---

## API

**Endpoint:** `GET https://patterns.hiive.cloud/api/v1/blueprints`

**Response structure (per blueprint):**
```json
{
  "name": "Glowup",
  "slug": "glowup",
  "type": "business",
  "description": "...",
  "preview_url": "https://blueprints.hiive.cloud/glowup",
  "screenshot_url": "https://cdn.digitaloceanspaces.com/...",
  "resources_url": "https://bh-wp-onboarding.sfo3.cdn.digitaloceanspaces.com/blueprints/resources/glowup.zip",
  "priority": 10
}
```

**Blueprint types:** `business` (10), `ecommerce` (9), `personal` (3), `linkinbio` (4)

---

## ZIP / SQL Structure

Each `resources_url` points to a ZIP containing:
- `blueprint.sql` — full WordPress DB export with `{{PREFIX}}` table prefix placeholder
- `uploads/` — media files referenced in the block markup

The `{{PREFIX}}posts` table INSERT rows contain `post_content` with real, full Gutenberg block markup. Relevant rows are `post_type = 'page'` and `post_status = 'publish'`.

Example pages in a blueprint: Home, Mission, News, Testimonials, Contact Us.

Image URLs in the markup are absolute (pointing to `blueprints.hiive.cloud`). These are replaced by the existing `ImageService` Unsplash pipeline.

---

## Caching Strategy

### Blueprints API list
- **Option key:** `nfd_aipd_state_blueprints`
- **Written by:** our `BlueprintService` if `nfd_module_onboarding_state_blueprints` does not exist
- **Structure:**
```php
array(
    'blueprints'        => [ ...full API response array... ],
    'selectedBlueprint' => 'glowup',   // slug, or null
    'last_updated'      => 1234567890,
)
```
- We **read** from `nfd_module_onboarding_state_blueprints` (written by onboarding) but **never write to it**
- We write only to `nfd_aipd_state_blueprints`

### Blueprint ZIP / SQL content
- Cache parsed page markup per blueprint slug using a WordPress transient:
  `nfd_aipd_blueprint_markup_{slug}` with a long TTL (e.g. `WEEK_IN_SECONDS`)

---

## Blueprint Selection Logic

```
Priority 1: nfd_module_onboarding_state_blueprints['selectedBlueprint']
            → user explicitly picked a blueprint during onboarding, use it directly

Priority 2: nfd_module_onboarding_site_info['site_type']
            → filter blueprints by matching type, pick randomly

Priority 3: class_exists('WooCommerce')
            → filter by 'ecommerce', pick randomly

Priority 4: No signal
            → filter by 'business', pick randomly (safe default —
               AI rewrites content regardless of blueprint structure)
```

Once a blueprint is selected (priorities 2–4), store the slug in `nfd_aipd_state_blueprints['selectedBlueprint']` so subsequent requests reuse it without re-picking.

Rotation: use a transient (`nfd_aipd_last_blueprint_{type}`) to avoid picking the same blueprint twice in a row, same pattern as the existing `PatternLayoutProvider`.

---

## Page Selection Within a Blueprint

The blueprint SQL contains multiple published pages (Home, Mission, Contact, etc.). Page to use:

- Default to the **Home** page (`post_name = 'home'`) as it has the most complete layout
- Future improvement: map the user's prompt keywords to a specific page slug (e.g. "contact" → `contact-us`, "about" → `mission`)

---

## Posts vs Pages: No Base Layout for Posts

Blog posts are editorial content (paragraphs, headings, quotes, images). Sending a full page blueprint layout as a base for a post would be wrong — the AI would get a hero/CTA/columns structure when it should be generating a clean article.

**Rule: skip base layout injection entirely when `content_type === 'post'`.**

Without a base layout the AI generates appropriate post content naturally — it already knows to produce Gutenberg markup and the system prompt keeps it on track.

### Changes required

**Frontend (`src/api.ts` or wherever `generateContent` is called):**
- Pass `content_type: 'page' | 'post'` in the `context` object alongside `post_id` / `conversation_id`

**Backend (`AIPageDesignerController`)**
- Read `content_type` from `$context` and pass it down to `PromptBuilder::build_user_messages()`

**`PromptBuilder::build_user_messages()`**
- Accept `$content_type` parameter (default `'page'`)
- Skip base layout injection entirely when `$content_type === 'post'`

---

## Preview iframe CSS

**No theme installation is required. The preview will work as-is.**

The blueprint block markup uses two categories of CSS:

1. **`nfd-wb-*` utility classes** — served from `https://patterns.hiive.cloud/cdn` and injected as inline `<style>` tags on the frontend by `wp-module-patterns/CSSUtilities.php`. Since `usePreviewIframe` fetches the full frontend HTML from `siteUrl` and extracts all `<link>` and `<style>` tags, these utility styles are already present in the preview.

2. **Blueprint theme CSS custom properties** (`--wp--preset--color--accent-1`, `playfair-display` font, etc.) — defined by the `bluehost-blueprint` theme on the blueprint's server, not on the user's site. However, `PromptBuilder::get_theme_context_prompt()` explicitly instructs the AI to replace all color slugs and font references with the user's active theme values. The AI output — which is what the preview renders — should not contain blueprint-specific vars.

**Edge case:** if the AI partially preserves a blueprint color var in its output it won't resolve. This is a pre-existing risk with the current pattern approach too and is not specific to blueprints.

---

## Implementation Plan

### New file: `includes/Services/BlueprintService.php`

Responsibilities:
1. `get_blueprints()` — fetch from API or return cached list from `nfd_aipd_state_blueprints`
2. `get_selected_blueprint()` — run the priority selection logic above
3. `get_base_layout( $user_prompt )` — download ZIP, parse SQL, extract `post_content` for the target page, minify, return markup string
4. `get_site_type()` — encapsulate the fallback chain (onboarding option → WooCommerce → `'business'`)

### Modify: `includes/Services/PatternLayoutProvider.php`

- Keep the class and its `get_random_pattern_layout()` method as a fallback
- `PromptBuilder` will try `BlueprintService` first; fall back to `PatternLayoutProvider` if blueprint markup is empty

### Modify: `includes/Services/PromptBuilder.php`

- Inject `BlueprintService` alongside `PatternLayoutProvider`
- Accept `$content_type` in `build_user_messages()` (default `'page'`)
- For `'page'`: prefer blueprint markup over pattern layout
- For `'post'`: skip base layout injection entirely

### Modify: `includes/RestApi/AIPageDesignerController.php`

- Read `content_type` from `$context` request param
- Pass it to `PromptBuilder::build_ai_messages()`

### Modify: `src/api.ts` (frontend)

- Include `content_type: 'page' | 'post'` in the context payload sent to `/generate`

---

## What Does Not Change

- `ImageService` — already replaces image URLs; blueprint image URLs are just another set of URLs to swap out
- `PromptBuilder` base layout injection format (`--- BASE LAYOUT ---` block) — unchanged
- AI system prompt — unchanged
- `usePreviewIframe` — no changes needed for CSS
