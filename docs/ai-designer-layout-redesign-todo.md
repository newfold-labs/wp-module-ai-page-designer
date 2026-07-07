# AI Page Designer — Workspace Overhaul: Todo List & Time Estimate

Companion to `ai-designer-layout-redesign.md`. Breaks the plan into actionable tasks per phase with effort estimates.

**Basis for estimate:** one engineer, full-time, already familiar with this codebase (~5,000 lines TS/TSX frontend, ~10,800 lines PHP backend). No automated test suite exists (per CLAUDE.md) — all "done" estimates include manual QA time, not just coding time. Days are ideal working days, not calendar days.

**Assumption / open risk:** the plan's own Risks table flags "leadership placement decision (plugin vs panel) still open" — this estimate assumes it resolves after the Phase 1 demo without a rework of the shell. If it flips to "panel," expect Phase 1 shell work to be partially redone.

---

## Phase 0 — Setup & Foundations (not in original plan, but required first)

- [ ] Add `@wordpress/components` + `@wordpress/interface` deps; configure webpack externals (currently only `react`/`react-dom` are externalized — these two are not yet in `package.json`) — **0.5–1 day**
- [ ] Hash-based router utility (`#/page/:id`), no full page reload — **0.5 day**
- [ ] Client state decision + skeleton store (zustand vs `@wordpress/data`) for selected page id, drawer/sidebar/tab/viewport state — **1 day**
- [ ] Container-query (`@container`) base scaffold on app root + fallback plan for old bundled browsers — **0.5 day**

**Phase 0 subtotal: 2.5–3 days**

---

## Phase 1 — Shell & Navigation (core UX win)

- [ ] `<WorkspaceShell>` fullscreen takeover + `← Back to WP Admin` escape hatch — **1 day**
- [ ] `<Header>`: BackToAdmin, DrawerToggle, NewPageButton, PageTitle w/ Draft/Unpublished badge, ViewportToggle (stub), PublishButton (relocated), SidebarToggle — **2 days**
- [ ] Backend: user-meta endpoints for `_apd_recent_ids` (read on load, update on page open) — **1 day**
- [ ] `<PageDrawer>`: Pinned (stub list) + Recent list wired to `/wp/v2/pages` and `/wp/v2/posts`, `_fields` trimmed — **1.5 days**
- [ ] `<CommandPalette>` (⌘K): debounced `/wp/v2/search`, type/status filters — **1.5 days**
- [ ] Page-switch logic: swap chat thread + preview without remount; cache last ~5 threads in memory — **2 days**
- [ ] Port existing `ChatPanel` + `HistoryPane` into new `<Sidebar>` tabs as-is (layout adaptation only, no new features) — **1.5 days**
- [ ] Dashboard hero → empty state when no page selected — **0.5 day**
- [ ] Migration: old Dashboard route redirects into empty state; old `?page_id=` deep links map to `#/page/:id` — **1 day**
- [ ] Manual QA against Phase 1 exit criteria (A → B → A in ≤2 clicks, no reloads, threads intact) — **1 day**

**Phase 1 subtotal: 13–14 days (~2.5–3 weeks)**

---

## Phase 2 — Canvas Upgrades

- [ ] iframe postMessage bridge: hover outline + click → persistent outline + label chip — **2.5 days**
- [ ] Scope chip: selected section flows into composer, sent with request, echoed on message bubble — **1.5 days**
- [ ] Skeleton/highlight on target section during streaming/applying edits — **1.5 days**
- [ ] Viewport toggle (desktop/tablet/mobile), resizes iframe, auto-selects at narrow container widths — **1 day**
- [ ] Contextual suggestion chips in composer (replace static prompts) — **1.5 days**
- [ ] Feature-detect + graceful degrade when bridge fails (theme incompatibility) — **1 day**

**Phase 2 subtotal: 9 days (~2 weeks)**

---

## Phase 3 — Design Tab

- [ ] Backend: `GET/PUT /ai-page-designer/v1/pages/:id/design-tokens` — **1 day**
- [ ] Define 6–8 curated palettes mapped to existing CSS custom properties (two-layer styling contract) — **1 day**
- [ ] `<DesignTab>` UI: palette grid, typography selects, curated font pairings, Google Fonts enqueue — **2 days**
- [ ] Live preview: CSS-variable swap in iframe (instant, no LLM call) — **1 day**
- [ ] "Suggest with AI" — single LLM call proposing a palette from page content — **1.5 days**
- [ ] Bidirectional sync: chat-driven style change reflects as selected in Design tab; manual Design change logs to History — **2 days**

**Phase 3 subtotal: 8.5–9.5 days (~2 weeks)**

*(Depends on the version-log endpoint built in Phase 4 for the "logs to History" requirement — sequence Phase 4's backend versioning work first, or stub it here and wire later.)*

---

## Phase 4 — Scale & Polish

- [ ] Backend: unified version log (`GET /pages/:id/versions`, `POST /pages/:id/versions/:vid/restore`), non-destructive restore — **2.5 days**
- [ ] Backfill: one "Initial generation" version entry per existing page — **0.5 day**
- [ ] `<HistoryTab>`: hover-to-preview, restore action wired to new endpoint — **1.5 days**
- [ ] Pinned pages: `_apd_pinned_ids` meta + pin/unpin UI + `<PinnedList>` — **1.5 days**
- [ ] `<LibraryOverlay>`: virtualized list (react-window/TanStack Virtual), cursor pagination (`orderby=modified`+`before=`), thumbnails, bulk actions — **3 days**
- [ ] Responsive slide-over sidebar (< 1200px tier) + hardening all 3 container-query breakpoints — **2 days**
- [ ] A11y pass: focus trap in drawer/palette, keyboard nav, `prefers-reduced-motion` — **1.5 days**

**Phase 4 subtotal: 12.5 days (~2.5 weeks)**

---

## Cross-Cutting

- [ ] wp-admin CSS scoping/testing against top plugin stacks (Elementor, Yoast, WooCommerce) — **1.5 days**
- [ ] SearchProvider/BM25 upgrade for ⌘K if LIKE search proves slow on large sites — **not estimated (conditional, measure first)**

**Cross-cutting subtotal: 1.5 days**

---

## Total Estimate

| Phase | Days |
|---|---|
| Phase 0 — Setup | 2.5–3 |
| Phase 1 — Shell & Navigation | 13–14 |
| Phase 2 — Canvas Upgrades | 9 |
| Phase 3 — Design Tab | 8.5–9.5 |
| Phase 4 — Scale & Polish | 12.5 |
| Cross-cutting | 1.5 |
| **Subtotal** | **47–49.5 days** |
| Buffer (~15% — iteration, review cycles, unknowns) | ~7 days |
| **Total** | **~54–57 days ≈ 11 weeks (~2.5 months)** solo, full-time |

**Shippable checkpoint:** Phase 1 alone (~3–3.5 weeks incl. Phase 0) delivers the "core UX win" per the plan and can go out as its own release, with Phases 2–4 following incrementally — matches the plan's phased-delivery intent and de-risks the placement decision in section 9.
