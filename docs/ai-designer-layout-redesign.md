# AI Page Designer — Workspace Overhaul Implementation Plan

**Goal:** Replace the hub-and-spoke Dashboard ↔ Designer navigation with a single Gutenberg-aligned workspace: preview as center canvas, Chat/Design/History in a right sidebar, page rail as a left drawer, fullscreen shell.

**Reference:** `ai-page-designer-gutenberg-mockups.html` (4 states: Chat editing, Design tab, History tab, narrow responsive).

---

## 1. Architecture Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Shell framework | `@wordpress/components` + `@wordpress/interface` | Free Gutenberg spacing, focus states, keyboard behavior; native plugin feel; ports cleanly to hosting panel later |
| Routing | Hash-based state (`#/page/:id`), no page reloads | Deep-linkable, but switching pages is a state change, not navigation |
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

### Phase 1 — Shell & navigation (the core UX win)
- Fullscreen takeover + header + hash routing
- Page drawer with Recent (server-persisted) + ⌘K palette (server search)
- Page switching swaps chat thread + preview without remount
- Port existing Chat and History panels into the sidebar as-is
- Dashboard hero becomes the no-page-selected empty state
- **Exit criteria:** edit page A → switch to page B → back to A in ≤ 2 clicks, no reloads, threads intact

### Phase 2 — Canvas upgrades
- Section selection on preview + scope chips in chat
- Viewport toggle (desktop/tablet/mobile)
- Streaming/skeleton highlight during edits
- Contextual suggestion chips in composer (replace static "create a homepage" prompts)

### Phase 3 — Design tab
- Token endpoints + palette/typography UI + live preview swap
- AI palette suggestion
- Design changes logged into unified History

### Phase 4 — Scale & polish
- History hover-to-preview + non-destructive restore
- Library overlay (virtualized, thumbnails, filters, bulk actions)
- Pinned pages
- Responsive slide-over state + container-query breakpoints hardening
- A11y pass: focus trap in drawer/palette, keyboard nav, reduced motion

## 8. Migration & Compatibility

- Old Dashboard route: keep temporarily, redirect into workspace empty state; remove after one release
- Old Designer deep links (`?page_id=`): map to `#/page/:id`
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