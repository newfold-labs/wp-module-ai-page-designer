# AI Page Designer — Workspace Overhaul Implementation Plan

**Goal:** Replace the hub-and-spoke Dashboard ↔ Designer navigation with a single Gutenberg-aligned workspace: preview as center canvas, Chat/Design/History in a right sidebar, page rail as a left drawer, fullscreen shell.

**Reference:** `ai-page-designer-gutenberg-mockups.html` (4 states: Chat editing, Design tab, History tab, narrow responsive).

---

## Implementation Status (as of 2026-07-07)

**Phases 0 through 3 are complete and merged into `update/aipd-redesign`.** See `ai-designer-layout-redesign-todo.md` for the itemized checklist. Notable deviations from this plan as originally written:

- **Routing (§1):** shipped as the query param `nfd_page_id` (`?nfd_page_id=123`), not `#/page/:id`. The parent plugin (`wp-plugin-web`) owns `location.hash` exclusively via its own `HashRouter` — a hash-based scheme here would have collided with it. Deep-linkability and no-reload page switching are preserved; only the URL shape differs.
- **Rail recents (§3):** backed by a dedicated module endpoint (`_apd_recent_ids` user meta, `GET/POST /recent`) rather than `GET /wp/v2/pages?orderby=modified`. Needed so opening a page can *touch* its recency per-user, which a sitewide `orderby=modified` query can't express.
- **Pinned pages:** not stubbed in Phase 1 as planned — deferred whole to Phase 4. The drawer currently shows Recent only.
- **⌘K command palette:** ships without the type/status filters described in §2/Phase 1 todo; search-as-you-type only.
- **Migration (§8):** the old Dashboard route wasn't kept as a redirect — it was replaced outright by the workspace's empty state, and legacy `?page_id=` deep links are not remapped. Low-risk for an internal wp-admin tool, but flagging since the plan called for an explicit redirect shim.
- **Phase 2's canvas upgrades turned out mostly pre-existing:** section selection (hover/click outline, scope chip in chat) and contextual composer suggestion chips were already built pre-redesign and needed no new work. Only the viewport toggle and the streaming skeleton highlight were net-new for Phase 2. The "feature-detect + graceful degrade if the postMessage bridge fails" item (§9 risks) was not built — still open.
- **§4's "primary/secondary/accent/background" CSS custom properties don't exist as such.** The actual theme.json schema (Newfold's Blueprint theme, consistent across sites) exposes 10 roles — `base`/`contrast` + `accent_1`–`accent_6` + `base_midtone`/`contrast_midtone` — each backed by `--wp--preset--color--{slug}`. Curated palettes (7 shipped, not a hardcoded 6–8) map all 10 roles; confirmed by inspecting real generated block markup rather than assuming the plan's framing.
- **Design tokens persist per-page** (`_apd_design_tokens` post meta) via the existing content update/create endpoints (a `design_tokens` param) rather than the dedicated `GET/PUT .../design-tokens` route sketched in §3 — one fewer endpoint, same effect, and it piggybacks on the existing publish flow instead of a separate save step.
- **Published pages apply the same override the preview uses** (`AIPageDesigner::enqueue_frontend_animations()`, reading the page's saved design tokens), which needed `!important` on the color custom properties — the preview avoids that fight by scoping to a high-specificity `#nfd-preview-root` id, but the public page's `:root`-level override has no such advantage against WP core's own global-styles stylesheet at equal specificity/later load order.
- **"Suggest with AI"** ships as a new `/suggest-palette` route on the existing `IntentClassifierController` (same cheap-AI-call pattern as `/classify`/`/metadata`) rather than a new controller. The model chooses only from palette ids the client sends — it can't invent a color, per §4's "no free-form color picker."
- **Bidirectional chat↔Design sync (§4) is one-directional only:** manual Design tab changes log to History (via a small additive `addHistoryEntry()` on `useAiConversation`, not Phase 4's not-yet-built unified version log). Chat-driven style language ("make it feel premium") does **not** select a palette in the Design tab — that direction wasn't built.

---

## 1. Architecture Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Shell framework | `@wordpress/components` + `@wordpress/interface` | Free Gutenberg spacing, focus states, keyboard behavior; native plugin feel; ports cleanly to hosting panel later |
| Routing | ~~Hash-based state (`#/page/:id`)~~ → **shipped as `?nfd_page_id=` query param** (see Implementation Status above), no page reloads | Deep-linkable, but switching pages is a state change, not navigation |
| Fullscreen | Take over viewport on entry (Gutenberg fullscreen pattern), `← Back to WP Admin` escape hatch top-left | Buys back 160px of admin menu; users already expect this from the block editor |
| Responsive strategy | CSS container queries (`@container`) on app root, not media queries | wp-admin menu can be expanded/collapsed independently of viewport; respond to actual available space |
| Sidebar width | ~380px default, user-resizable via drag handle | Gutenberg's 280px is too cramped for chat |
| Data at scale | Rail shows Pinned + Recent only; everything else via ⌘K server-side search; "Browse all" opens virtualized library overlay | O(1) initial load regardless of content count |
| History model | Non-destructive: restore creates a new version entry | Removes restore anxiety; single event log shared by Chat, Design, and manual edits |

## 2. Component Breakdown

```
<WorkspaceShell>                     // fullscreen takeover, container-query root
├── <Header>                         // fixed grammar across all states
│   ├── <BackToAdmin />              // top-left square
│   ├── <DrawerToggle />             // list icon
│   ├── <NewPageButton />
│   ├── <PageTitle status={...} />   // center: title + Draft/Unpublished badge
│   ├── <ViewportToggle />           // desktop/tablet/mobile
│   ├── <PublishButton />            // top-right, never moves
│   └── <SidebarToggle />
├── <PageDrawer>                     // left, transient overlay
│   ├── <QuickSearch />              // opens ⌘K palette
│   ├── <PinnedList max={5} />
│   ├── <RecentList max={15} />      // orderby=modified, server-persisted per user
│   └── <BrowseAllLink />            // opens <LibraryOverlay>
├── <Canvas>                         // center: iframe preview
│   ├── <PreviewFrame />             // postMessage bridge for section select/highlight
│   └── <SectionSelection />         // outline + label chip, Gutenberg block-select grammar
├── <Sidebar tabs>                   // right, ~380px, resizable
│   ├── <ChatTab />                  // messages w/ scope chips, contextual suggestions, composer
│   ├── <DesignTab />                // palette grid, typography selects, AI-suggest
│   └── <HistoryTab />               // entries w/ relative time + scope, hover-preview, restore
├── <CommandPalette />               // ⌘K: server-side search, type/status filters
└── <LibraryOverlay />               // virtualized full list, thumbnails, bulk actions
```

## 3. Data Layer

### REST endpoints (existing WP core, trimmed payloads)
- Rail recents: `GET /wp/v2/pages?orderby=modified&per_page=15&_fields=id,title,status,modified` (+ same for posts)
- ⌘K search: `GET /wp/v2/search?search=<q>&per_page=20` — debounced ~250ms
  - *Optional upgrade:* route through the shared `SearchProvider` interface (BM25 tier) for ranked results instead of SQL LIKE
- Library overlay: cursor pagination via `orderby=modified` + `before=<timestamp>`, `react-window`/TanStack Virtual for rendering

### Module endpoints (new/extended)
- `GET/POST /ai-page-designer/v1/pages/:id/chat` — thread per page (already exists; ensure keyed by page)
- `GET /ai-page-designer/v1/pages/:id/versions` — unified event log (chat edits, design-token changes, restores)
- `POST /ai-page-designer/v1/pages/:id/versions/:vid/restore` — creates new version, returns it
- `GET/PUT /ai-page-designer/v1/pages/:id/design-tokens` — palette + font pair
- User meta: `_apd_pinned_ids`, `_apd_recent_ids` (last N, server-persisted so workset follows the user across devices)

### Client state
- Selected page id, drawer/sidebar open state, active tab, viewport — URL/hash + light store (zustand or `@wordpress/data`)
- Cache last ~5 pages' chat threads in memory for instant switch-back
- Unpublished-changes flag per page → amber dot on rail item + header badge

## 4. Design Tokens (Design tab)

- 6–8 curated named palettes, each defining the existing CSS custom properties (primary/secondary/accent/background) from the two-layer styling contract — **no free-form color picker**
- Palette switch = CSS variable swap in the preview iframe: instant, zero LLM tokens
- Fonts: `--font-heading` / `--font-body` pair + Google Fonts enqueue; curated pairings list
- "Suggest with AI" → single LLM call proposing a palette from page content
- Bidirectional with chat: "make it feel premium" in chat may apply a palette (reflected as selected in Design tab); manual Design changes log to History ("Palette → Midnight")
- **Out of scope by design:** per-section styling controls (font-size sliders etc.) — granular changes go through chat only

## 5. Canvas & Section Targeting

- Preview in sandboxed iframe; parent ↔ frame postMessage bridge
- Hover: subtle outline on sections; click: persistent outline + label chip (Gutenberg block-selection affordance)
- Selected section id flows into composer as a scope chip; sent with the chat request; echoed on the message bubble
- During an edit: skeleton-highlight the target section while streaming/applying, so users see where the change lands
- Viewport toggle resizes the iframe; at narrow container widths auto-select the viewport that renders honestly (user can override)

## 6. Responsive Behavior (container-query breakpoints on app root)

| Available width | Drawer | Sidebar | Canvas |
|---|---|---|---|
| ≥ 1600px | Overlay when opened (default closed) | Docked, 380px | Center, remaining width |
| 1200–1600px | Overlay | Docked, 340px (min) | Center |
| < 1200px | Overlay | Detached slide-over floating on canvas, toggled from header | Full width; viewport auto → mobile |

Header grammar (back square, title, Publish, toggles) is invariant across all states.

## 7. Phased Delivery

### Phase 1 — Shell & navigation (the core UX win) — ✅ Done
- [x] Fullscreen takeover + header + routing (query param, not hash — see Implementation Status)
- [x] Page drawer with Recent (server-persisted) + ⌘K palette (server search; no type/status filters yet)
- [x] Page switching swaps chat thread + preview without remount
- [x] Port existing Chat and History panels into the sidebar as-is
- [x] Dashboard hero becomes the no-page-selected empty state
- **Exit criteria:** edit page A → switch to page B → back to A in ≤ 2 clicks, no reloads, threads intact — ✅ verified

### Phase 2 — Canvas upgrades — ✅ Done
- [x] Section selection on preview + scope chips in chat *(already existed pre-redesign)*
- [x] Viewport toggle (desktop/tablet/mobile)
- [x] Streaming/skeleton highlight during edits
- [x] Contextual suggestion chips in composer (replace static "create a homepage" prompts) *(already existed pre-redesign)*
- [ ] Feature-detect + graceful degrade if the postMessage bridge fails *(not built — still open, see §9)*

### Phase 3 — Design tab — ✅ Done
- [x] Token endpoints + palette/typography UI + live preview swap *(persisted via the existing content endpoints' `design_tokens` param, not a dedicated route — see Implementation Status)*
- [x] AI palette suggestion *(`/suggest-palette` on `IntentClassifierController`; chooses only from client-provided ids)*
- [x] Design changes logged into unified History *(one-directional: manual Design changes → History. Chat-driven style language does not select a Design tab palette — that direction not built)*

### Phase 4 — Scale & polish — Not started
- [ ] History hover-to-preview + non-destructive restore
- [ ] Library overlay (virtualized, thumbnails, filters, bulk actions)
- [ ] Pinned pages
- [ ] Responsive slide-over state + container-query breakpoints hardening
- [ ] A11y pass: focus trap in drawer/palette, keyboard nav, reduced motion

## 8. Migration & Compatibility

- Old Dashboard route: keep temporarily, redirect into workspace empty state; remove after one release — **not done as planned; the old route was replaced outright rather than redirected (see Implementation Status)**
- Old Designer deep links (`?page_id=`): map to `#/page/:id` — **not done; legacy `?page_id=` links are not remapped to `?nfd_page_id=`**
- Existing chat threads/history: already page-keyed — no data migration expected; version log needs a backfill entry ("Initial generation") per existing page
- Decide: rail shows **all** site content (badging AI-designed items) vs AI-managed only → recommend **all**, since "open any old post and restyle with AI" is an upsell moment

## 9. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| iframe postMessage bridge fragile across themes | Feature-detect; degrade to no section-select (chat still works untargeted) |
| Chat cramped even at 380px | Resizable handle + slide-over expand; test with long AI responses early |
| wp-admin plugins injecting CSS into fullscreen shell | Scope styles aggressively; test against top-10 plugin stacks (Elementor, Yoast, WooCommerce…) |
| Container query support on old bundled browsers | Support is universal in evergreen browsers; fallback media-query layer if hosting analytics show stragglers |
| Search latency on large shared-MySQL sites | Debounce + `_fields` trimming; SearchProvider/BM25 tier if LIKE proves slow |
| Leadership placement decision (plugin vs panel) still open | Shell on `@wordpress/interface` keeps both paths open; demo Phase 1 to force the decision |

## 10. Success Metrics

- Page-switch time: from ~4 interactions (back → search → click → wait) to 1–2, no full reloads
- % of edit sessions touching ≥ 2 pages (expect increase — currently suppressed by friction)
- Duplicate-page creation rate (expect decrease — users iterate instead of regenerating)
- Time-to-first-edit for returning users (Recent list should make this near-instant)