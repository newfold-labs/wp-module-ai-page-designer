# WooCommerce Dynamic Template Support — Design Doc

> Living design doc. Iterate on it in-repo.

## Status — 2026-07-21: implemented as exclusion, not redesign support

Written in response to a real bug report: opening a WooCommerce Checkout page in the AI Page Designer shows a blank preview, and nothing stopped a redesign from corrupting the page structure WooCommerce depends on to populate it.

**Decision reversed mid-doc, on real evidence.** The initial framing below (Phase 1 = "detect + protect + honest preview so the page can still be opened and sections added around the WooCommerce block") assumed there was *some* legitimate design surface worth preserving access to. Ground-truthing WooCommerce's actual `block.json` files (WooCommerce 10.9.4, installed locally) disproved that: every Checkout/Cart inner block declares **no color/spacing/typography/border supports at all**, and the top-level blocks are `templateLock:"insert"` with children `inserter:false` + `lock:{remove:true,move:true}` — WooCommerce itself gives merchants nothing to redesign here, not even in the native Site Editor. Building an "allow editing around a protected block" feature for a page type with no real editable surface wasn't worth the added product surface. **Implemented instead: exclude these pages from the Designer entirely** — the user's own fallback suggestion, confirmed correct once the schema evidence was in.

**Shipped (branch `add/aipd-harness`):**
- `includes/Services/WooCommerceGuard.php` — `is_dynamic_template( ?\WP_Post $post ): bool`. Detects via `wc_get_page_id('cart'|'checkout'|'myaccount')` (WooCommerce's own registered special pages) OR a top-level `woocommerce/checkout`, `woocommerce/cart`, or `woocommerce/mini-cart` block in `post_content` (defense in depth for a page that embeds one of these manually). Deliberately narrow — does NOT match the broader `woocommerce/*` namespace (product grids, filters, single-product blocks), which are usually hand-placed inside otherwise-ordinary pages, not the page's sole content; see "Confirmed facts" below.
- `WordPressProxyController::list_content()` — skips these posts, so they never appear in the Dashboard.
- `WordPressProxyController::get_content()` / `update_content()` — return a 403 `woocommerce_dynamic_template` `WP_Error` if reached directly by ID (defense in depth; nothing in the normal Dashboard flow can produce this ID, but the Command Palette's search can — see below).
- `WordPressProxyController::list_recent()` — same skip, so a stale "recent" entry can't resurface one either.
- **Real gap found and fixed:** `src/components/workspace/CommandPalette.tsx` searches via WordPress core's own unfiltered `/wp/v2/search`, so it CAN still surface a Cart/Checkout/My Account page by ID — selecting it hits the new 403. Previously this failed silently (`.catch` only logged to console; the "Opening…" spinner just vanished). Fixed: `CommandPalette` now surfaces `error.message` (the WP_Error's own message, confirmed to be what `@wordpress/api-fetch` rejects with) in an inline error banner, reusing the existing `.publish-status--error` pattern from `PublishModal.tsx` rather than inventing new UI.
- Tests: `tests/Services/WooCommerceGuardTest.php` (7 tests) + `tests/wp-block-polyfill.php` extended to load a real `\WP_Post` (its constructor just copies a stdClass's properties, no DB needed) so the guard can be tested against a real `instanceof \WP_Post`. `phpunit.xml.dist` gained a `services` testsuite. 231 tests green (was 224), PHPCS clean, `tsc --noEmit` clean, `npm run build` clean.
- Verified upstream: no `post_row_actions` filter anywhere in this module or the parent `wp-plugin-web` links a native WordPress Pages-list row into the Designer — the Dashboard list and the Command Palette are the only two entry points, and both are now covered.

## Problem

WooCommerce's Cart, Checkout, Mini-Cart, and My Account pages are built from **dynamic block containers** — e.g.:

```html
<!-- wp:woocommerce/checkout -->
<div class="wp-block-woocommerce-checkout ..."><!-- wp:woocommerce/checkout-fields-block -->
<div class="wp-block-woocommerce-checkout-fields-block"><!-- wp:woocommerce/checkout-express-payment-block -->
<div class="wp-block-woocommerce-checkout-express-payment-block"></div>
<!-- /wp:woocommerce/checkout-express-payment-block -->
...
```

The stored `post_content` for these blocks is (by design) near-empty divs. WooCommerce populates them at **render time** from cart/session/customer state — there is no static text to show and nothing meaningful to "design" inside them via markup edits.

This module's entire architecture — `PromptBuilder`, `MarkupHarness\Validator`, the `PageAssembly` archetype catalogue, and `usePreviewIframe.ts` — is built around a **closed, known set of `core/*` blocks** (confirmed: zero references to "woocommerce", "wc-block", or any non-`core/` namespace check anywhere in `includes/` or `src/`). When one of these pages is opened today:

1. **Blank preview.** The iframe just injects raw markup + `wp-block-library` CSS (`usePreviewIframe.ts`); with no cart/session context, WooCommerce's own blocks render nothing meaningful client-side, so the user sees an empty page.
2. **Structure at risk on any AI edit.** The `Validator` doesn't recognize `wp:woocommerce/*` block names, so any rule that *does* match a generic pattern (or a future AI redesign that rewrites the whole page) has no guard rail telling it "do not touch this subtree." Nothing currently makes this concrete failure impossible — the module has just never been asked to edit one of these pages before, in testing.

## Confirmed facts (research, this session)

**The module itself has no WooCommerce/dynamic-block concept at any layer** — not prompt, not harness, not preview. Every block-name check in the codebase (`Validator.php`, the `Rules/*` classes, `AIPageDesignerController.php:995,1066`, `IntentClassifierController.php:402,479`, `ImageService.php`, every `PageAssembly/Archetypes/*`) is a hardcoded literal/`in_array` match against specific `core/*` names. Unknown block names don't get explicitly skipped as "opaque" — they simply fail to match any rule, i.e. they pass through **unvalidated and unprotected**, not deliberately ignored.

**This is not a hypothetical edge case for this product.** Across the parent `wp-plugin-web` plugin and sibling `newfold-labs/wp-module-*` packages:
- `inc/Data.php:73` directly checks `is_plugin_active('woocommerce/woocommerce.php')`.
- `wp-module-ecommerce` is a dedicated sibling module (~153 WooCommerce references) providing a Store dashboard, product/order stats, and payment/shipping/tax setup.
- `wp-module-onboarding-data/includes/Plugins.php:108,111,234-266` defines an **`'ecommerce'` onboarding plan** that installs WooCommerce (+ YITH/Shippo/PayPal add-ons) when a site owner picks "start a store" during setup — this is a first-class, deliberately funneled flow, not incidental plugin detection.
- `wp-module-next-steps` and `wp-module-patterns` both reference WooCommerce extensively, consistent with post-onboarding guidance steering users toward store pages.

**The Dashboard already lists these pages today, unfiltered.** `WordPressProxyController::list_content()` (`includes/RestApi/WordPressProxyController.php:227-247`) queries by `post_type` (page/post) and `post_status=publish` only — no template, meta, or block-content filtering. `src/api.ts:6,10` and `DashboardView.tsx` apply only a client-side text search. A WooCommerce Cart/Checkout/My Account page (a normal `page` post, referenced via `wc_get_page_id()`) shows up in the list exactly like any hand-authored page, with zero guard. **Any customer who took the ecommerce onboarding path can hit this blank-page bug today, unassisted.**

**The block surface to detect is small if scoped correctly.** The "lone top-level block *is* the whole page" pattern applies to a small, closed set of WooCommerce **system pages**: Cart (`woocommerce/cart`), Checkout (`woocommerce/checkout`), Mini-Cart (`woocommerce/mini-cart`), My Account. The broader `woocommerce/*` namespace (product collection/query, filters, single-product blocks) is much larger — dozens of blocks — but those are typically hand-placed inside custom pages, not the sole content of an auto-generated system page. **Scoping to "system pages whose content is essentially one WooCommerce container block" is deliberately the target, not "any WooCommerce block anywhere."**

## Effectiveness / ROI assessment

**What's actually being asked for when a user opens a Checkout page in the Designer?** Almost never "redesign my checkout flow" — WooCommerce intentionally keeps checkout/cart structurally rigid for compliance and conversion reasons, and the theme's `theme.json` already governs their look (colors, fonts, spacing) the same way it governs every other block on the site. The realistic asks are: "make my checkout page match my brand colors," or "add a trust banner above/below checkout." Both of those are either **already solved by nothing needing to happen** (global styles apply automatically) or **solved by editing the page's non-WooCommerce sections only** — not by the AI rewriting the WooCommerce subtree itself.

This reframes effectiveness sharply by scope:

| Scope | Effort | Value |
|---|---|---|
| **Phase 1 — Detect + protect + honest preview** (stop the blank page, stop any future corruption, let the user add ordinary sections around the WC block) | Small: one detection helper + one Validator/Harness guard + one preview fallback | **High.** Fixes a real, live, currently-shippable bug for every ecommerce-onboarding customer, using the harness's existing "correct by construction" pattern almost unchanged. |
| **Phase 2 — Let the AI generate/redesign content around a protected WC block** (e.g. add a hero above checkout, a testimonials strip on Cart) | Medium: PromptBuilder/PagePlanController awareness that a page has a fixed anchor block | **Medium.** Nice-to-have, not blocking — most stores don't customize cart/checkout page framing beyond the theme. |
| **Phase 3 — General "any WooCommerce block, anywhere" support** (product grids, filters, single-product blocks placed inside ordinary pages by AI) | Large: open-ended block surface, no closed set, would need per-block-type knowledge the harness doesn't have for anything today | **Low relative to effort.** Speculative; no evidence of demand in this repo's onboarding/patterns flows beyond the fixed system pages. |

**Original recommendation was "Phase 1 = protect + honest preview" (below, kept for record); superseded once the block-schema evidence came in** — see Status at top. Redesign support (Phase 2/3 as originally scoped) is **not** happening: there's no legitimate per-block design surface WooCommerce itself allows, so there's nothing to build toward.

## Superseded: original "protect + preview" architecture (not built)

Kept for context on the reasoning trail; do not implement without re-opening this doc. If a future need arises to let users at least *add sections around* a WooCommerce system page (rather than excluding it outright), this is the starting sketch:

1. **Detection.** A small helper (`MarkupHarness\Context` or a new `DynamicBlockRegistry`) that recognizes a **closed list** of WooCommerce system-page container block names (`woocommerce/cart`, `woocommerce/checkout`, `woocommerce/mini-cart`) — written generically enough (`namespace !== 'core'` combined with an explicit allow-list, not a blanket "any non-core block is dynamic" rule, since third-party page builders/blocks shouldn't all be assumed dynamic) that it's a one-line addition to extend later. **(This part was reused as-is — see `WooCommerceGuard` in Status above.)**
2. **Harness guard, not just a validator check.** Add an `OpaqueBlockGuard` concept: when `Harness::conform()` encounters one of these block names, it treats that subtree as **immutable** — no rule walks into it, no repair rewrites it, and (new) a diff-guard asserts the AI's returned markup didn't alter that subtree's attributes/innerBlocks byte-for-byte versus what was sent in.
3. **Preview.** Two options were weighed — **(a) honest placeholder** box in place of the dynamic block vs. **(b) real server-render** via `/wp/v2/block-renderer/{block}` or the live front-end URL. Moot now (pages are excluded, never previewed), but (a) was the leaning: cheaper, always correct, no second rendering pathway to keep in WYSIWYG sync.
4. **Generation pipeline awareness.** `PromptBuilder`/`IntentClassifierController` would need to hold the protected block's markup out of what's sent to the AI and splice it back in unchanged — mirroring `FastPathHandler`'s image-swap bypass.
5. **Dashboard labeling.** A "WooCommerce" badge instead of full exclusion.

## Out of scope (now)

- "Add sections around a WooCommerce system page" (the superseded Phase 1 idea above) — not planned; revisit only if a real user request surfaces wanting to keep these pages openable in some constrained form.
- Any change to WooCommerce's own block rendering/behavior — this module only ever reads/excludes, never re-implements WC's rendering.
- The broader `woocommerce/*` block namespace (product grids, filters, single-product blocks) appearing inside otherwise-ordinary pages — untouched by `WooCommerceGuard` on purpose; those pages still open normally in the Designer today.
