# AI Page Designer — Generation Quality, Conformance & Composition: Implementation Plan

> Living design doc. Iterate on it in-repo.

## Status — as of 2026-07-01 (start here)

**Stage 1 is complete.** The PHP `MarkupHarness` exists with the keystone Validator gate, a `Context` from `theme.json`, an idempotent bounded-repair pipeline, and **11 rules that fully replace the Worker's `postProcessMarkup`**. PHPUnit is wired (`composer test`, **63 tests green**); PHPCS clean. Branch: **`add/aipd-harness`**, merged even with `develop` (`16f5cb4`, 2026-07-01) — 0 behind, safe to build Stage 2 on top of.

**Fonts: dropped from scope (decision 2026-07-01).** Not deferred — out. The forced-font enqueue in `AIPageDesigner.php` stays as-is; there is no font conformance rule and no hybrid theme/designer-font toggle. Revisit only if a concrete problem surfaces later. This also drops the "Fonts (hybrid)" design in Stage 2 below — Stage 2 archetypes use whatever font enqueue is currently active, unconditionally.

**Rules in the pipeline (execution order)** — each = `Rule` class + (usually) a mirrored `Validator` assertion + tests, all idempotent:

| Rule | What it does | Validator assertion | Status |
|---|---|---|---|
| `RepairDelimiters` | repair malformed/mismatched `<!-- wp: -->` comments | — (pre-parse repair) | done `ad24d1d` |
| `SanitizeCss` | bare-unit CSS, `1fr1fr` run-together grid | `invalid_css:bare_unit`, `invalid_grid:run_together_fr` | done (earlier) |
| `BackgroundImagePlaceholder` | drop a `placehold.co` background-image that shadows a real one | — (WYSIWYG repair) | done `58c1fb6` |
| `UnwrapLoneGroup` | hoist children of a lone page-wrapper group | — | done (earlier) |
| `SectionGroupPattern` | top-level groups → `align:wide` + h-padding | — (styling default, not gated) | done `305722b` |
| `GroupPaddingSymmetry` | fill missing horizontal padding when vertical exists | `asymmetric_padding:group` | done (earlier) |
| `ColumnWidthNormalize` | redistribute inconsistent column %widths (4×50%→25%) | `invalid_column_widths` | done `0ac4a77` |
| `CoverImage` | inject `<img class=wp-block-cover__image-background>` when url set | `cover_missing_image` | done `064f1fb` |
| `CoverDefaults` | minHeight 520 / dimRatio 60 / dark bg fallback | `cover_missing_defaults` | done `909be28` |
| `StyleRawFormButtons` | style unselectable raw `<button>`/`<input submit>` | `unstyled_form_button` | done (earlier) |
| `ButtonBackgroundCollision` | button bg == section bg → contrasting slug + legible text | `button_bg_collision` | done `087c15c` |
| `ColorLegibility` | non-solid / low-contrast text & background repair | `non_solid_color` | done (earlier) |

**Integration seams wired:** `AIPageDesignerController` (generate, before returning to React), `WordPressProxyController` (save ×2, via shared `conform_for_save()`), `FastPathHandler::build_response`. Single `Harness::conform()` at each, plus `ImageService::replace_placeholder_images()` as a final image guarantee at the same two seams (never publish a `placehold.co` image, regardless of how it survived to that point).

**Also shipped since the "essentially complete" note (all on top of Stage 1, not Stage 2):** AI intent classifier (`IntentClassifierController`, on by default, filterable — routes recolour/remove/metadata edits deterministically instead of via regex); harness-owned excerpt/title generation (`/metadata` endpoint, Worker stays AI-only via the `analyze()` pass-through); friendlier non-technical post-edit messages; unsaved-changes guard (native `beforeunload` + in-app confirm on Dashboard/Designer/Create New nav).

**Worker slim (task done, LEFT UNCOMMITTED on purpose):** in the separate repo `/Users/abhijit.bhatnagar/Sites/cf-worker-ai-sitegen` branch `update/ai-page-designer`, removed `postProcessMarkup` + all 9 conformance helpers from `src/services/AIPageDesignerPrompts.js` (−649 lines) and both call sites + the dead import from `src/controllers/aiPageDesigner.js`. **Kept** `getThemeContextPrompt` / `deduplicatePalette` / `hexBrightness` (prompt-only). Both pass `node --check`. Caveats: ⚠️ a prior uncommitted `styleRawFormButtons` addition is preserved at the Worker's `git stash@{0}`; ⚠️ **deploy-coordination** — do not deploy the slimmed Worker before the PHP harness is released (else a no-conformance window). Still not deployed as of this writing.

### Where to start next

**Stage 2's v1 catalogue is complete (10/10 archetypes) and the live page-plan pipeline is proven** — see the Stage 2 section below for full status. Next: a broader manual test in the browser across the full catalogue, then decide on multi-variant support and "editing on the plan". The "unify preview + frontend bundle" work (task tracked separately, not blocked on fonts anymore since fonts are out of scope) remains open but is not a Stage 2 prerequisite; it can proceed independently whenever picked up.

### Unrelated, parked (NOT harness)

`develop` branch has a parked **new-page streaming bug** (Worker returns 200 but no content; `response_id null`, ~1s). Debug instrumentation (`total_bytes`/`raw_sample`/`curl_error` in `AiClientWorker::stream_with_curl`) is saved in `git stash` on `develop` ("PARKED: new-page streaming debug"). Next step there: reproduce, read the `cURL streaming completed` log to tell Worker-empty vs SSE-parse vs frontend. Unaffected by the `develop` → `add/aipd-harness` merge (stash, not a commit).

## Context

The AI Page Designer asks the model for a whole page of Gutenberg markup, then patches the result reactively with a growing pile of regex passes **in the Cloudflare Worker** (`cf-worker-ai-sitegen` → `AIPageDesignerPrompts.postProcessMarkup`). Every manual test surfaces a new defect class (missing padding, `1fr1fr`, unstyled raw-HTML form buttons, lone wrapper groups, incomplete covers…) because the model has unbounded latitude and there is no validation gate and no tests. Two structural truths drive this plan:

1. **Design quality and structural correctness should be *controllable* (harness-owned), not *emergent* (model whim).**
2. **The biggest defect vein is free-form output** — especially raw-HTML forms (unselectable, unthemed, invented each time).

Goals: (a) a **self-validating** step so markup is asserted against a definition-of-done and never shipped while failing — fix once; (b) **more attractive, more varied** designs (modern, animated) that still **match the active theme**; (c) move toward **AI-returns-sections / harness-stitches-structure** so correctness and design are by construction.

**Decision: the harness lives in the WordPress PHP module (`wp-module-ai-page-designer`), not the Worker.** The module already has what the harness needs natively — `parse_blocks()`/`serialize_blocks()` (`AIPageDesignerController.php:607,643`, `ImageService.php:317`), full `theme.json` via `wp_get_global_settings()`/`wp_get_global_stylesheet()` (`AIPageDesigner.php:129-136`), an existing `PatternLayoutProvider` service, and it is the last line before content is persisted/rendered. The Worker stays **AI-only**: returns markup today, a theme-agnostic **page plan** in Stage 2.

## Non-negotiable: WYSIWYG (saved == previewed == published)

The previewed page, the saved `post_content`, and the published front-end must be pixel-identical. Design that guarantees it:

- **Conform before preview, not at save.** `AIPageDesignerController` runs the harness on the Worker's output *before* returning to React, so the preview renders already-conformed markup. (Same applies to the fast-path output.)
- **Idempotent re-conform on save.** `WordPressProxyController` runs the harness again on save as defense; because rules are idempotent it is a no-op, so save never diverges from what was previewed. One source of truth = the conformed markup.
- **Unified preview + frontend assets.** Preview (`src/hooks/usePreviewIframe.ts`) and frontend (`AIPageDesigner.php::enqueue_frontend_animations`) must load **one canonical CSS/motion/font bundle** and use the **same content-wrapper scope**. Today they diverge (preview `#nfd-preview-root` + `fadeIn`; frontend `.entry-content/.wp-block-post-content` + `nfd-fadeIn`; `pulse-hover` preview-only; forced fonts) — unifying this is now *required*, not optional.
- **Render strategy (decided): same assets, client-render.** Preview keeps rendering the conformed block markup client-side but with the exact frontend bundle + wrapper scope. Markup is static + conformed, so same markup + same stylesheet = same pixels. (Server-render via `do_blocks` considered and deferred.)

## The Self-Validation Gate (keystone)

- A single PHP `Validator::validate($markup, $ctx) -> { ok, violations[] }` used by **both** the runtime and the test suite (one oracle). Can leverage the **real block registry** (`WP_Block_Type`) and optionally `render_block` for authoritative checks — far stronger than regex.
- Pipeline: `conform/repair → validate → (bounded repair, max N) → gate`. Deterministic, idempotent repairs converge fast; residual violations are logged, never silently shipped.
- The assertion set is the growing definition-of-done; **every past bug becomes a permanent assertion**. Examples: parses cleanly; no `<html>/<script>/<style>` wrappers; every section has symmetric side padding + `align:wide`; no `1fr1fr`/bare-unit CSS; no unstyled raw buttons; covers have bg+dim+image; text legible on dark/accent bg; motion hooks restricted to the canonical vocabulary; (Stage 2) plan has required archetypes + filled slots.

## Stage 1 — PHP Conformance + Validation Harness ✅ DONE (see Status at top)

New service namespace under `includes/Services/` → `MarkupHarness/` (namespace `NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness`):
```
MarkupHarness/
  Harness.php       conform($markup, $ctx) -> $markup   (entry; runs rule pipeline + validation gate)
  Context.php       theme roles (dark/light/accent slugs), fonts, spacing/border tokens from wp_get_global_settings()
  Validator.php     the shared assertion oracle (registry-aware)
  Rules/            one class per rule, implementing a Rule interface: Sanitize, Structure, Layout, Theme, Forms, Motion
  Rules/Rule.php    interface: apply(string $markup, Context $ctx): string
```
- **Engine:** WordPress-native `parse_blocks()` / `serialize_blocks()` for structural rules (no new dependency, no custom serializer, no round-trip risk). String/regex only for inline-CSS/HTML-scoped fixes inside a block's innerHTML.
- **Rules (port the Worker logic, rewritten on `parse_blocks`):** lone-wrapper unwrap, section `align:wide`+symmetric padding, horizontal-padding-symmetry, column-width normalize, cover defaults+image element, button-bg collision, dark-bg text legibility, font conformance, grid/bare-unit CSS fixes, raw form button/field styling (interim until Stage 2 form archetype). Reuse palette-role logic from the Worker's `getThemeContextPrompt`/`postProcessMarkup` — now sourced from native `theme.json`.
- **Integration seams:** `AIPageDesignerController` (generate response, after `AiClientWorker` returns) · `WordPressProxyController` (save) · `FastPathHandler` output. Single `Harness::conform()` call at each.
- **Worker cleanup:** remove `postProcessMarkup`'s conformance passes (keep `getThemeContextPrompt` for prompting). `themeContext` still sent for the prompt, not for fixes.
- **Tests:** introduce PHPUnit (module currently has none — `composer lint/fix` only). Add `composer test`. Fixtures = the real captured cases (CTA padding, `1fr1fr` form, bare button, lone wrapper, cover, 4×50% columns, full page). Same `Validator` is the oracle. Unit-level rules can use WP_Mock/Brain Monkey; integration uses a WP test bootstrap for `parse_blocks`/theme APIs.

## Stage 2 — Section Archetype Catalogue + Page Assembler

**Status as of 2026-07-01: v1 catalogue complete (10/10), live pipeline working.** `includes/Services/PageAssembly/` exists — `PageAssembler` (the renderer; **not** named "Composer": that name collides with PHP's own `composer.json`/`composer test`/`composer lint`, a naming issue caught and fixed before any code was written) plus all 10 v1 archetypes: `HeroCover`, `FeatureGrid`, `AlternatingMediaText`, `CtaBanner`, `BookingForm`, `Testimonials`, `PricingTiers`, `FaqAccordion`, `StatsBar`, `RichText` — each single-variant for v1. `Context` (Stage 1's) was extended, not replaced, with `spacing_attr()`/`spacing_css()` (theme `spacingSizes` presets, falling back to literal px) and `muted_light_slug()` (a spare light swatch for the surface/surface-alt background rhythm). A shared `RendersMarkup` trait holds the common building blocks every archetype past the first two needed — `render_section()` (the wide-group-with-heading/intro shape 6 of the 10 archetypes use), `render_button()`/`render_buttons_wrap()`, `contrasting_slug()`/`text_slug_for_background()` — so each archetype class stays small. 63 new PHPUnit tests (126 total) prove every archetype produces **zero** `Validator` violations with no repair pass applied, individually and composed together in one 10-section page — "correct by construction" is now an automated assertion, not just a design goal. `composer lint` clean.

**Wired live (`4f8723d`) and manually confirmed working** (at the 2-archetype stage; re-verified programmatically after the full catalogue landed — see verification below). `PagePlanController` (`POST /page-plan`) builds a prompt describing the registered archetypes (now all 10) from `ARCHETYPE_SCHEMAS`, calls `analyze()`, parses the plan defensively, and renders it via `PageAssembler`. Gated behind opt-in filter `nfd_ai_page_designer_enable_page_plan` (still default false — the pipeline is proven, but hasn't yet had a broad manual pass across all 10 archetypes live in the browser). Frontend hook lives in `useAiConversation.handleSend`, engaging only for "Create New" with a prompt (no existing preview, no selected item) — never touches the edit flow.

**Worker impact corrected: none.** The line below ("Worker returns a page plan") was written assuming a Worker-side prompt/schema change. Re-derived from how this session already solved the equivalent problem for the intent classifier and harness-owned metadata generation: `AiClientWorker::analyze()` is already a dumb pass-through — it forwards the **caller's own** system prompt to the model with no Worker-side business logic. Getting a page-plan JSON back needed only a new PHP-owned prompt + an `analyze()` call (mirroring `IntentClassifierController::classify()`), **zero Worker code changes**. The Worker stays exactly the dumb data pipe the user wants it to be.

**Where to go next:** a broader manual test now that all 10 archetypes are live (a homepage prompt should reasonably use several of them, not just hero + feature grid); then "editing on the plan" once the catalogue has been exercised enough in practice to know it's worth replacing select-edit-splice with plan ops; then the multi-variant work each archetype's table entry originally called for (only one variant per archetype exists so far).

Worker returns a **page plan** (theme-agnostic JSON: `[{archetype, content, variant}]` + copy); `PageAssembler` renders it from a catalogue of typed, theme-correct, attractive section archetypes — correct **by construction**.

### Design principle: theme-driven
Archetypes are structurally strong, visually neutral — the theme carries the skin. Prefer `theme.json` presets (color slugs, fontSize/fontFamily presets, spacing presets, `align`, `layout`) over inline CSS; use core blocks the theme already styles (`core/button` over raw `<button>`, `core/heading`, `core/columns`, `core/cover`). Resolve tokens once in `Context.php`. Newfold theme is the baseline; degrade gracefully when a token is absent.

### Motion & interactivity layer (theme-independent)
Motion is a separate, theme-agnostic layer. **One canonical motion vocabulary** (classes `fade-in`, `slide-up`, `scale-in`, `bounce-in`, `card-hover-lift`, `fade-in-delay-1/2/3` + `data-aos`/`data-aos-delay`) is the single source of truth; preview and frontend CSS are generated from / checked against it; the Validator rejects any hook outside it (kills `pulse-hover`-style drift). **Per-archetype curated** motion.

### Fonts
Out of scope (dropped 2026-07-01). The current forced-font enqueue at `AIPageDesigner.php:256` is left as-is, unconditionally, for both Stage 1 and Stage 2 output. No hybrid toggle, no font conformance rule.

### v1 catalogue (10) — typed slots / variants / default bg
| Archetype | Purpose | Key slots | Variants | Default bg |
|---|---|---|---|---|
| `heroCover` | Hero | eyebrow?, heading, subheading?, primaryCta, secondaryCta?, imageQuery | image-bg · split-media · centered-minimal | dark/accent |
| `featureGrid` | Value props | heading?, intro?, items[{icon?,title,body}] | cards-3 · cards-4 · icon-list | surface |
| `alternatingMediaText` | Story rows | rows[{heading,body,imageQuery,cta?}] | auto L/R alternation | surface/alt |
| `ctaBanner` | Conversion | heading, subheading?, cta | accent-band · split | accent |
| `bookingForm`/`contactForm` | Lead capture | heading?, intro?, fields[typed], submitLabel | stacked · two-col-fields · with-intro-aside | surface-alt |
| `testimonials` | Social proof | heading?, quotes[{quote,author,role?,avatarQuery?}] | single-large · grid-3 | surface/accent |
| `pricingTiers` | Pricing | heading?, tiers[{name,price,period?,features[],cta,highlighted?}] | 3-tier · 2-tier | surface |
| `faqAccordion` | FAQ | heading?, items[{q,a}] | accordion · two-col | surface |
| `statsBar` | Metrics | items[{value,label}] | accent-band · light | accent/surface |
| `richText` | Prose / escape hatch | heading?, body, cta? | default | surface |

Typed form fields (`{type: text|email|tel|date|time|number|select|textarea, name, label, required?, options?[]}`) → the `bookingForm` archetype renders accessible, theme-styled markup with `core/button` submit. Raw-HTML form defects become structurally impossible. Best authored as **block patterns via the existing `PatternLayoutProvider`**.

### PageAssembler responsibilities
Background rhythm (alternate surface/alt/accent; hero=dark/accent, cta=accent — v1 implements the simplest case: an archetype's own default, else alternating `muted_light_slug()` for plain "surface" sections) · media L/R alternation · token resolution from `Context.php` · reuse the existing **Unsplash `ImageService`** at `imageQuery`/`avatarQuery` slots (done — resolved before an archetype ever sees its content, archetypes are pure functions) · page-type composition rules (homepage must include `heroCover` first + `ctaBanner`/form near end) enforced by the **plan validator** before assembling (not yet built — v1's Harness re-use covers markup-level correctness; a plan-level validator is a later addition once more archetypes exist).

### Editing on the plan
A section instance carries `{archetype, content}`; edits map to plan ops, dissolving most of the select-edit-splice fragility in `useAiConversation.ts`. (Frontend edit-flow rework tracked separately.)

## Open design questions

- Confirm exact `theme.json` token availability for spacing/border presets across target themes; fallbacks where missing.
- Stage 2 behind a feature flag alongside Stage 1 until the catalogue is broad enough, then cut over.
- PHPUnit setup: wp-env vs existing CI; how much needs WP bootstrap vs pure unit mocks.
- How far archetypes flex to non-Newfold themes.

## Decisions locked

- **Home: PHP module**; Worker stays AI-only, and **needs no code changes at all** for the page-plan contract — `analyze()` already lets PHP supply its own prompt (corrected 2026-07-01; see Stage 2 status above).
- **WYSIWYG** non-negotiable: conform-before-preview + idempotent re-conform on save + unified preview/frontend bundle; preview = same-assets client-render.
- Sequence: **Stage 1 → Stage 2**.
- Engine: WordPress-native `parse_blocks`/`serialize_blocks` (+ optional `render_block` in validation).
- Self-validation gate is the keystone; Validator == test oracle (PHPUnit, new) — and, as of the Stage 2 skeleton, doubles as the archetype-correctness oracle too (zero violations with no repair pass).
- v1 catalogue: all **10** archetypes — **done** (`heroCover`, `featureGrid`, `alternatingMediaText`, `ctaBanner`, `bookingForm`, `testimonials`, `pricingTiers`, `faqAccordion`, `statsBar`, `richText`); each single-variant so far, forms rendered as a styled raw `<form>` inside `core/html` rather than block patterns (no Gutenberg form-field block exists to pattern-author against).
- Aesthetic: **theme-driven**; Motion: **per-archetype curated**, one vocabulary; Fonts: **out of scope** (dropped 2026-07-01 — current forced enqueue stays, unconditionally, no rule/toggle).
- Naming: the Stage 2 renderer is called **`PageAssembler`**, never "Composer" — that name collides with PHP's own Composer tooling in this repo.

## Verification

- `composer test` (new PHPUnit): per-rule, validator-oracle, idempotency (`conform(conform(x)) == conform(x)`), full-page integration fixtures.
- `composer lint` clean (PHPCS; no short ternary; cURL `phpcs:ignore` per CLAUDE.md).
- Manual WYSIWYG check: generate booking/event page → preview correct → **Publish → front-end pixel-identical**; follow-up targeted edit does not collapse the page.

## Out of scope (now)

- Retroactively conforming already-published pages (on-save hook conforms them on next edit/publish).
- Frontend edit-scope rewrite (revisited when Stage 2 editing-on-plan lands).
