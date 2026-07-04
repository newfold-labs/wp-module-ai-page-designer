# AI Page Designer — Generation Quality, Conformance & Composition: Implementation Plan

> Living design doc. Iterate on it in-repo.

## Status — as of 2026-07-04 (start here)

**Since 2026-07-02, four commits of live-testing hardening landed on `add/aipd-harness` (all user-verified in the browser):**

- **`51fe62c` — modern variants + theme-only colors + progressive preview reveal.** `HeroCover` `split` / `CtaBanner` `floating-card` are the defaults (see Stage 2). `Context::from_theme()` no longer merges WP core's default palette (missing `break` — black/white/vivid-purple leaked into theme-role resolution); gradients use only registered theme slugs and skip entirely without a tonally-close pair. The page-plan contract is now `{title, excerpt, sections}` (4-6-word AI title, ≥4 sections required, site-name/AI-call fallbacks). New-page previews reveal progressively: sections fade in one at a time (a rule-driven `nth-child` hide style injected into the iframe head — survives document swaps and re-renders; never hold element refs across an await, and never use cross-realm `instanceof` on iframe nodes), each section's content elements staggering in at 500ms. True token streaming stays REVERTED — from-scratch AI generation can't guarantee structure (it once wrapped the whole page in `core/columns`); deterministic PageAssembler + client-side reveal is the accepted resolution of that tension.
- **`55c637d` — truthful metadata messages + title/excerpt never blank.** Metadata edits report OUR applied-fields message, never the model's summary (it claimed fields the intent gate didn't apply); `/page-plan` retries can't clobber a prior attempt's title/excerpt; frontend derives title/excerpt from first heading/paragraph as last resort.
- **`754e599` — token-based `splitTopLevelBlocks`.** The old line-based splitter only classified each line's first token; PageAssembler markup nests delimiters mid-line, so depth desynced and inner hero blocks were treated as top-level sections — the root cause of every "insert landed inside the hero" report. Also: direction-less "add a `<section-scale block>`" with a selection now inserts a sibling section (classifier `add_block` direction, default after) instead of routing as an edit that nests inside the selection.
- **`13b9d4d` — motion-CSS scope-list fix.** `get_motion_css()` prepended the frontend scope list verbatim; the comma split the selector so bare `.entry-content` received the hover-only infinite pulse — published pages zoomed in/out continuously. Scopes now expand per-selector. (Corrects a flaw in the 2026-07-01 unification praised below.)

Original status (2026-07-02) follows — still accurate for everything it covers:

**Stage 1 is complete.** The PHP `MarkupHarness` exists with the keystone Validator gate, a `Context` from `theme.json`, an idempotent bounded-repair pipeline, and **12 rules that fully replace the Worker's `postProcessMarkup`**. PHPUnit is wired (`composer test`, **135 tests green** — includes Stage 2's `PageAssembly` suite, see below); PHPCS clean. Branch: **`add/aipd-harness`**, merged even with `develop` (`16f5cb4`, 2026-07-01) — 0 behind, safe to build on top of. **Stage 1's WYSIWYG asset-bundle unification (the last open Stage 1 item) is also done** — see "Unified preview + frontend motion CSS" below.

**Stage 2 is also complete for v1**: 10/10 archetypes built, the live page-plan pipeline is wired and on by default, and manually confirmed working in the browser. `HeroCover`/`CtaBanner` also now have modern default variants (split-screen hero, floating-card CTA) — see the Stage 2 section below for full detail.

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

**Worker slim: reverted 2026-07-02 — was premature, not needed.** The uncommitted `postProcessMarkup` removal in the separate repo (`cf-worker-ai-sitegen`, branch `update/ai-page-designer`) was discarded, and its related obsolete `styleRawFormButtons` stash dropped. Reasoning: the PHP harness doesn't depend on the Worker's own conformance being removed — it runs its own idempotent pass on whatever the Worker returns, `postProcessMarkup` intact or not. Meanwhile the WP module's harness (`add/aipd-harness`) still hasn't merged to this module's own `main`/`develop`, so removing the Worker's conformance now would have left current production with a real no-conformance window for however long that takes. The eventual Worker slim-down is still architecturally correct (once the harness branch ships, `postProcessMarkup` becomes genuinely redundant) — it's just cheap to redo later (a small, mechanical diff) and safer not to carry as an uncommitted liability in a separate repo in the meantime.

### Where to start next

**Stage 2's v1 catalogue is complete (10/10 archetypes), the live page-plan pipeline is proven and manually confirmed in the browser, page-plan is now on by default, and the preview/frontend motion-CSS drift is fixed** (see the Stage 2 section and "Unified preview + frontend motion CSS" above). `HeroCover`/`CtaBanner` now have modern default variants (split-screen, floating cards, gradients). Next: multi-variant support for the remaining 8 archetypes if warranted, and "editing on the plan" once the plan-based flow has more real usage to justify it.

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
- **Unified preview + frontend motion CSS — done (2026-07-01).** `AIPageDesigner::get_motion_css( $scope, $keyframe_prefix )` is now the single source of truth for the motion vocabulary (`fade-in`, `slide-up`, `bounce-in`, `scale-in`, `fade-in-delay-1/2/3`, `pulse-hover`, `glow-hover`, `card-hover-lift`, `data-aos`), parameterized by the content-wrapper scope (`.entry-content, .wp-block-post-content` for the front-end, `#nfd-preview-root` for the preview) and a keyframe-name prefix (`nfd-` on the front-end, to avoid colliding with the site's own CSS; none needed in the isolated preview iframe). `enqueue_frontend_animations()` calls it directly; the same output is localized to the preview as `previewMotionCss` and consumed by `usePreviewIframe.ts`'s `_enableAnimations()` instead of a hand-duplicated copy. This closes a real, live bug: `pulse-hover`/`glow-hover` previously existed only in the preview's copy, so those classes animated in the editor but did nothing on the published page — exactly the kind of drift a single source of truth prevents structurally instead of by remembering to update two places. **Caveat found live (fixed `13b9d4d`, 2026-07-04):** the original implementation prepended the scope string verbatim to each rule; because the frontend scope is a selector *list*, the comma split the selector and bare `.entry-content` received every declaration — including the hover-only infinite pulse (published pages zoomed continuously). `get_motion_css()` now expands the scope list so every scope prefixes every selector; never string-prepend a scope that may contain a comma. Content-wrapper *scope* itself is intentionally not literally unified (preview's synthetic shell vs. the theme's real `.entry-content`/`.wp-block-post-content` markup are different DOM structures by necessity) — what's unified is the CSS *rules*, which is what actually determines whether something animates. Fonts remain intentionally untouched (dropped from scope, see Status above).
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

**Status as of 2026-07-01: v1 catalogue complete (10/10), live pipeline working.** `includes/Services/PageAssembly/` exists — `PageAssembler` (the renderer; **not** named "Composer": that name collides with PHP's own `composer.json`/`composer test`/`composer lint`, a naming issue caught and fixed before any code was written) plus all 10 v1 archetypes: `HeroCover`, `FeatureGrid`, `AlternatingMediaText`, `CtaBanner`, `BookingForm`, `Testimonials`, `PricingTiers`, `FaqAccordion`, `StatsBar`, `RichText` — single-variant for v1, except `HeroCover`/`CtaBanner` which later gained a second, default "modern" variant each (see below). `Context` (Stage 1's) was extended, not replaced, with `spacing_attr()`/`spacing_css()` (theme `spacingSizes` presets, falling back to literal px) and `muted_light_slug()` (a spare light swatch for the surface/surface-alt background rhythm). A shared `RendersMarkup` trait holds the common building blocks every archetype past the first two needed — `render_section()` (the wide-group-with-heading/intro shape 6 of the 10 archetypes use), `render_button()`/`render_buttons_wrap()`, `contrasting_slug()`/`text_slug_for_background()` — so each archetype class stays small. 63 new PHPUnit tests (126 total) prove every archetype produces **zero** `Validator` violations with no repair pass applied, individually and composed together in one 10-section page — "correct by construction" is now an automated assertion, not just a design goal. `composer lint` clean.

**Wired live (`4f8723d`) and manually confirmed working** — first at the 2-archetype stage, then again after the full 10-archetype catalogue landed. `PagePlanController` (`POST /page-plan`) builds a prompt describing the registered archetypes (all 10) from `ARCHETYPE_SCHEMAS`, calls `analyze()`, parses the plan defensively, and renders it via `PageAssembler`. **On by default as of 2026-07-01** (`nfd_ai_page_designer_enable_page_plan` filter defaults `true` now that the catalogue is complete and manually verified twice; disable via `add_filter( 'nfd_ai_page_designer_enable_page_plan', '__return_false' )`). Frontend hook lives in `useAiConversation.handleSend`, engaging only for "Create New" with a prompt (no existing preview, no selected item) — never touches the edit flow.

**Worker impact corrected: none.** The line below ("Worker returns a page plan") was written assuming a Worker-side prompt/schema change. Re-derived from how this session already solved the equivalent problem for the intent classifier and harness-owned metadata generation: `AiClientWorker::analyze()` is already a dumb pass-through — it forwards the **caller's own** system prompt to the model with no Worker-side business logic. Getting a page-plan JSON back needed only a new PHP-owned prompt + an `analyze()` call (mirroring `IntentClassifierController::classify()`), **zero Worker code changes**. The Worker stays exactly the dumb data pipe the user wants it to be.

**Multi-variant support landed for `HeroCover`/`CtaBanner` (2026-07-02).** Both were single-variant since the v1 catalogue shipped and looked structurally basic — flat cover/band, no depth. New default variants: `HeroCover`'s `split` (two-column `core/columns`, text left, image right wrapped in a rounded/drop-shadowed "floating card") and `CtaBanner`'s `floating-card` (centered floating card on a gradient-over-solid-slug backdrop). The old `image-bg`/`accent-band` shapes are kept, unchanged, reachable only via an explicit `variant` override — the new variant is the default per an explicit product decision (consistency over model-judged per-page variety), so no `PagePlanController` prompt changes were needed at all. New shared `RendersMarkup` helpers: `gradient_style_declaration()` (a `background:linear-gradient(...)` inline CSS declaration built from two theme-role slugs — deliberately never set as a `backgroundColor`/`textColor` *attribute*, since `Validator::check_non_solid_colors()` only inspects those two JSON attrs, never arbitrary inline `style`, so it's safe by construction as long as the block still carries a real solid slug for editor/recolor compatibility) and `render_floating_card()` (a generalization of `PricingTiers::render_card()`'s proven shape, adding `border-radius`/`box-shadow`). 9 new PHPUnit tests (135 total) prove both new default paths are correct-by-construction with zero repair. The remaining 8 archetypes are still single-variant — out of scope for this pass, explicitly hero/CTA only.

**Where to go next:** multi-variant support for the other 8 archetypes, if warranted; "editing on the plan" once the catalogue has been exercised enough in practice to know it's worth replacing select-edit-splice with plan ops.

`PageAssembler` renders a page plan (theme-agnostic JSON: `[{archetype, content, variant}]`) from a catalogue of typed, theme-correct, attractive section archetypes — correct **by construction**. The plan itself comes from `PagePlanController` via the `analyze()` pass-through (see "Worker impact corrected: none" above) — no separate Worker-side plan format was ever needed.

### Design principle: theme-driven
Archetypes are structurally strong, visually neutral — the theme carries the skin. Prefer `theme.json` presets (color slugs, fontSize/fontFamily presets, spacing presets, `align`, `layout`) over inline CSS; use core blocks the theme already styles (`core/button` over raw `<button>`, `core/heading`, `core/columns`, `core/cover`). Resolve tokens once in `Context.php`. Newfold theme is the baseline; degrade gracefully when a token is absent.

### Motion & interactivity layer (theme-independent) — done
**One canonical motion vocabulary** (classes `fade-in`, `slide-up`, `scale-in`, `bounce-in`, `card-hover-lift`, `pulse-hover`, `glow-hover`, `fade-in-delay-1/2/3` + `data-aos`/`data-aos-delay`) is the single source of truth, generated by `AIPageDesigner::get_motion_css()` and consumed by both the preview and the front-end — see "Unified preview + frontend motion CSS" above, which closed the exact `pulse-hover`-style drift this section originally called out as a risk. No archetype currently emits any of these classes yet (v1 archetypes are deliberately unanimated); this is the vocabulary they'll draw from when **per-archetype curated** motion is added.

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

Typed form fields (`{type: text|email|tel|date|time|number|select|textarea, name, label, required?, options?[]}`) → the `bookingForm` archetype renders accessible, theme-styled markup with `core/button`-style submit. Raw-HTML form defects become structurally impossible **by construction, not by pattern-authoring** — implemented as a real `<form>` inside `core/html` with every field/button carrying explicit theme-derived inline styles, since no native Gutenberg form-field block exists to author a pattern against (see Decisions locked below).

### PageAssembler responsibilities
Background rhythm (alternate surface/alt/accent; hero=dark/accent, cta=accent — v1 implements the simplest case: an archetype's own default, else alternating `muted_light_slug()` for plain "surface" sections) · media L/R alternation · token resolution from `Context.php` · reuse the existing **Unsplash `ImageService`** at `imageQuery`/`avatarQuery` slots (done — resolved before an archetype ever sees its content, archetypes are pure functions) · page-type composition rules (homepage must include `heroCover` first + `ctaBanner`/form near end) enforced by the **plan validator** before assembling (not yet built — v1's Harness re-use covers markup-level correctness; a plan-level validator is a later addition once more archetypes exist).

### Editing on the plan
A section instance carries `{archetype, content}`; edits map to plan ops, dissolving most of the select-edit-splice fragility in `useAiConversation.ts`. (Frontend edit-flow rework tracked separately.)

## Open design questions

- Confirm exact `theme.json` token availability for spacing/border presets across target themes; fallbacks where missing (`Context::spacing_attr()`/`spacing_css()` already fall back to literal px when a theme has no `spacingSizes` scale, but this hasn't been tested against a real non-Newfold theme yet).
- ~~Stage 2 behind a feature flag alongside Stage 1 until the catalogue is broad enough, then cut over.~~ **Resolved 2026-07-02**: `nfd_ai_page_designer_enable_page_plan` now defaults `true` — catalogue complete, manually verified twice.
- PHPUnit setup: wp-env vs existing CI; how much needs WP bootstrap vs pure unit mocks. (In practice: everything so far runs under the pure-PHP bootstrap with a standalone `parse_blocks`/`serialize_blocks` polyfill — no wp-env has been needed.)
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
