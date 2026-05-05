# AI Page Designer — Redesign Plan

Source designs: `~/Downloads/stitch/`
- `obsidian_slate_atelier/DESIGN.md` — design system spec ("The Editorial Monolith")
- `professional_simplified_dashboard/` — dashboard screen + HTML reference
- `professional_simplified_designer_view/` — designer screen + HTML reference

---

## 1. What changes vs. what stays the same

### Changes (visual / structural only)
- CSS variables / design tokens (full palette swap)
- Typography (add Manrope + Inter, replace system-ui stack)
- `App.tsx` — nav bar restructure; publish buttons move from chat panel into the header
- `DashboardView.tsx` — hero section replaces the simple "Create New" card; list items get thumbnails and status badges
- `ChatPanel.tsx` — bubble-style messages replace flat avatar rows; input area redesign; publish bar removed (moves to header)
- `MetaStrip.tsx` — restyled as a "Page Details Bar" below the header (same fields, new visual treatment)
- `HistoryDrawer.tsx` — updated colours and spacing to match new system
- `styles.css` — full rewrite of all component classes

### Does NOT change
- All hooks (`useAiConversation`, `usePublishFlow`, `useBlockSelection`, `usePreviewIframe`, `useSiteContent`)
- All TypeScript types (`types.ts`)
- All prop interfaces (no API changes to components)
- PHP backend
- Build system

---

## 2. Design tokens — CSS variable rewrite

Replace the existing variables in `:root` with the new palette. The current WP-admin-blue scheme is replaced by the "Editorial Monolith" slate/navy system.

**Gradient accent rule:** `--gradient-primary` is used everywhere a flat primary color would have been used — all `.ai-btn--primary` buttons (Publish Page, Generate, Send, modal primary CTA), nav active indicator, and the user chat bubble background. This creates visual continuity between the hero banner and all interactive elements.

```css
:root {
  /* Primary — deep navy */
  --color-primary:            #2D3A5D;
  --color-primary-container:  #3E4C7A;
  --color-on-primary:         #ffffff;

  /* Surfaces — tonal hierarchy, no hard lines */
  --color-surface:                  #F8FAFC;
  --color-surface-bright:           #ffffff;
  --color-surface-container-low:    #F8FAFC;
  --color-surface-container:        #F1F5F9;
  --color-surface-container-high:   #E2E8F0;
  --color-surface-container-highest:#CBD5E1;
  --color-surface-dim:              #F1F5F9;

  /* Text */
  --color-on-surface:         #0F172A;
  --color-on-surface-variant: #475569;

  /* Borders — used sparingly and at low opacity */
  --color-outline:            #64748B;
  --color-outline-variant:    #CBD5E1;

  /* Semantic */
  --color-tertiary:           #94A3B8;
  --color-error:              #BA1A1A;

  /* Chat bubbles */
  --color-user-bubble:        var(--gradient-primary);   /* gradient, was WP blue #2271b1 */
  --color-ai-bubble-bg:       #ffffff;
  --color-ai-bubble-border:   rgba(203, 213, 225, 0.5);

  /* Shadows — tinted ambient, never pure black */
  --shadow-ambient: 0 12px 32px -4px rgba(15, 23, 42, 0.06);
  --shadow-editorial: 0 20px 50px -12px rgba(15, 23, 42, 0.10);
  --shadow-sm: 0 1px 2px rgba(15, 23, 42, 0.04);

  /* Radii — crisp, tailored */
  --radius-xs:  2px;
  --radius-sm:  4px;
  --radius-md:  6px;
  --radius-lg:  8px;
  --radius-xl:  12px;
  --radius-full: 9999px;

  /* Gradients — used for all primary actions and accents */
  --gradient-primary: linear-gradient(135deg, #2D3A5D, #1E293B);
  --gradient-primary-hover: linear-gradient(135deg, #3E4C7A, #2D3A5D);

  /* Transitions */
  --transition: all 0.2s ease;
}
```

---

## 3. Typography

Download and bundle Manrope and Inter as local WOFF2 files — no CDN dependency.

**Weights needed:**
- Inter: 400, 500, 600
- Manrope: 600, 700, 800

**File location:** `src/fonts/` (e.g. `inter-400.woff2`, `manrope-700.woff2`, etc.)

**Webpack change** — add a font asset rule to `webpack.config.js`:
```js
{
  test: /\.(woff2|woff)$/i,
  type: 'asset/resource',
  generator: {
    filename: 'fonts/[name][ext]',
  },
},
```
This copies font files to `build/fonts/` and allows importing them in CSS via relative paths.

**CSS `@font-face` declarations** (in `styles.css`, no `wp_enqueue_style` changes needed):
```css
@font-face {
  font-family: 'Inter';
  src: url('./fonts/inter-400.woff2') format('woff2');
  font-weight: 400;
  font-display: swap;
}
/* ... repeat for 500, 600 */

@font-face {
  font-family: 'Manrope';
  src: url('./fonts/manrope-600.woff2') format('woff2');
  font-weight: 600;
  font-display: swap;
}
/* ... repeat for 700, 800 */
```

**Font stack:**
```css
.ai-designer-container {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

.ai-designer-container h1,
.ai-designer-container h2,
.ai-designer-container h3,
.ai-headline {
  font-family: 'Manrope', sans-serif;
}
```

---

## 4. Component changes

### 4.1 `App.tsx` — Header + layout restructure

**Current:** A tab strip (`DesignerTabs`) sits at the very top. The publish bar lives inside `ChatPanel`. The meta strip floats above the designer content area.

**New:**
- Replace `DesignerTabs` with a fixed `<header>` bar containing: logo text, nav links (Dashboard / Designer), and — when in the designer view — "Save Draft" + "Publish Page" action buttons on the right.
- The header uses frosted glass: `background: rgba(248,250,252,0.7); backdrop-filter: blur(12px);`
- The `ChatPanel` publish bar is removed from the chat entirely (actions live in the header).
- The content area below the header has `padding-top` equal to header height.
- Keep `DesignerTabs` component but repurpose it as the nav items or remove it in favour of inline JSX in App.tsx.

**Props impact:** `ChatPanel` loses `onPublish`, `onRevertChanges`, `hasAIGenerated`, `publishing`, `selectedItem` — or these stay but the publish bar within ChatPanel is hidden and the header receives those props instead. Cleaner to pass them to the header sub-component.

The "Publish Page" button uses `background: var(--gradient-primary)` (gradient fill matching the hero banner).

Header structure (JSX sketch):
Nav contains only **Dashboard** and **Designer** — no Library or Settings links.

```tsx
<header className="ai-designer-header">
  <div className="ai-designer-header__left">
    <span className="ai-designer-header__logo">AI Page Designer</span>
    <nav className="ai-designer-header__nav">
      <button className={view === 'dashboard' ? 'active' : ''} onClick={...}>Dashboard</button>
      <button className={view === 'designer' ? 'active' : ''} onClick={...}>Designer</button>
    </nav>
  </div>
  <div className="ai-designer-header__right">
    {/* only in designer view when AI has generated */}
    {hasAIGenerated && (
      <button className="ai-btn ai-btn--primary" onClick={onPublish}>Publish Page</button>
    )}
  </div>
</header>
```

**"Save Draft"** is removed — the design showed it but the current publish flow has no draft-save concept separate from publish. A single **"Publish Page"** button covers both new and existing items (the `selectedItem` conditional label is dropped; the backend distinction between create and update is unchanged).

---

### 4.2 `DashboardView.tsx` — Hero + grid

**Current:** A simple "Create New Page with AI" action card, a divider with "OR", and two columns for pages/posts.

**New:**
1. **Hero section** — full-width gradient banner (`#2D3A5D → #1E293B`, 135°), containing:
   - "INTELLIGENCE CANVAS" chip label (pill, white/10 bg)
   - Large heading: "Create New Page with AI" (Manrope, bold)
   - Subheading text
   - Inline input + "Generate" button (gradient fill `var(--gradient-primary)`, white text). Pressing Generate:
     1. Opens the designer view (same as current `onCreateNew()`)
     2. Pre-fills the chat input with the typed prompt
     3. Immediately submits it so the AI starts generating without an extra click
   - **Prop change:** `onCreateNew()` is replaced by `onCreateWithPrompt(prompt: string)` in `DashboardView`. In `App.tsx`, this handler opens the designer view and calls `conversation.setInput(prompt)` + `conversation.handleSend()` in sequence. A prompt is required to press Generate (button disabled when input is empty).

2. **Pages / Posts grid** — two white cards side by side (no border, tonal contrast against `surface-container` page bg). Each card has:
   - Title + subtitle (e.g. "12 active documents")
   - Search input (borderless, bottom-line focus only)
   - List items: title, "Edited X ago" sub-label, Published/Draft badge chip. No thumbnails.
   - "Show more" link as before

**New CSS classes needed:** `.ai-hero`, `.ai-hero__chip`, `.ai-hero__input-row`, `.ai-badge`, `.ai-badge--published`, `.ai-badge--draft`

---

### 4.3 `ChatPanel.tsx` — Bubble messages + redesigned input

**Current:** Flat message rows with an avatar icon circle on the left, publish bar at bottom.

**New:**
1. **Message bubbles:**
   - Assistant: white card, rounded-2xl with flat top-left corner (`border-radius: 0 16px 16px 16px`), subtle border (`var(--color-ai-bubble-border)`), drop shadow
   - User: filled with `var(--color-user-bubble)`, white text, rounded-2xl with flat top-right corner (`border-radius: 16px 0 16px 16px`)
   - Remove explicit avatar circles; messages are self-identified by shape/colour
   - Loading state: white bubble with pulsing dot indicator

2. **Input area:** White background, `border-top: 1px solid var(--color-surface-container-high)`, `padding: 24px`. Textarea with `background: var(--color-surface-container)`, rounded-xl, no outer border (bottom-line focus only). Send button: gradient-filled (`var(--gradient-primary)`) square icon button, bottom-right of textarea.

3. **Remove publish bar** — replaced by header buttons (see 4.1).

4. **History drawer** stays at the bottom of the scroll area, above the input.

---

### 4.4 `MetaStrip.tsx` — Page Details Bar

**Current:** A collapsible strip showing title, excerpt, featured image fields.

**New:** Restyled as a persistent horizontal bar immediately below the header when in designer view. Visual treatment:
- White background, `border-bottom: 1px solid var(--color-surface-container-high)`
- Three inline sections separated by thin vertical dividers:
  1. **Title** — label "PAGE TITLE" in uppercase tracking-wide indigo, editable input below (no border, transparent bg, Manrope bold)
  2. **Status** — label "STATUS", coloured dot + "Draft"/"Published" text + "Auto-saving..." hint
  3. **Featured Image** — small 40×40 thumbnail (or placeholder), "Change Hero" link

The existing `MetaStrip` props are unchanged — only JSX and CSS are updated.

---

### 4.5 `HistoryDrawer.tsx` — Styling update only

The functionality is unchanged (from our earlier refactor). Update only:
- Background: `var(--color-surface-container)` instead of current bg
- Entry items: tonal hover (`var(--color-surface-container-high)`)
- "Restore to this version" button: tertiary style (no background, `var(--color-primary)` text, weight 600)
- Header: Manrope font, tracking-tight

---

### 4.6 `PublishModal.tsx` + `RevertConfirm.tsx` — Glass modal style

Update modals to use the "Glass & Gradient" floating rule from the design system:
- `background: rgba(248,250,252,0.92); backdrop-filter: blur(20px);`
- Box shadow: `var(--shadow-ambient)` (tinted, never pure black)
- Ghost border: `border: 1px solid rgba(170, 179, 185, 0.15)`
- Primary button: `background: var(--gradient-primary)`
- Radius: `var(--radius-lg)`

---

## 5. Preview panel — no changes to functionality

The `PreviewFrame` iframe and `usePreviewIframe` hook are unchanged. The surrounding container background changes from the current solid grey to `var(--color-surface-container)` (slate-100/50 equivalent) and the iframe canvas gets `box-shadow: var(--shadow-editorial)` and `border-radius: var(--radius-lg)`.

---

## 6. "No-line" rule implementation

Per the design spec, explicit 1px borders for section separation are prohibited. Instead:

| Separation needed | Technique |
|---|---|
| Header / content | `box-shadow` on header only (ambient shadow downward) |
| Pages card / Posts card | Both on `surface-container` bg; cards use `surface-bright` (`#fff`) — tonal contrast alone |
| Chat panel / preview | `border-right: 1px solid var(--color-surface-container-high)` at **very low contrast** — this is the one exception, as a split-pane requires a visible edge |
| List items | Vertical space (`gap: 4px`) + hover tonal shift only |
| Meta strip sections | Vertical dividers at `var(--color-outline-variant)` **15% opacity** |

---

## 7. Implementation order

1. **CSS tokens + fonts** — update `:root` variables and font loading. Low risk, immediately visible improvement across all components.
2. **Header restructure** (`App.tsx` + new header CSS) — relocate publish buttons, swap tab strip for nav.
3. **Dashboard hero + list items** (`DashboardView.tsx`) — new hero section, badge chips, list item thumbnails.
4. **Chat bubbles** (`ChatPanel.tsx`) — message shape + colour, input area, remove publish bar.
5. **Meta strip → Page Details Bar** (`MetaStrip.tsx`) — horizontal layout, inline fields.
6. **HistoryDrawer** — colour/spacing pass.
7. **Modals** (`PublishModal`, `RevertConfirm`) — glass style.
8. **Preview container** — shadow + radius on canvas.

Each step is independently reviewable without breaking other steps.

---

## 8. Resolved decisions

| # | Decision | Resolution |
|---|---|---|
| 1 | Hero prompt input | `onCreateNew()` → `onCreateWithPrompt(prompt: string)`. Hero Generate button pre-fills the chat input AND auto-submits — designer opens with the AI already generating. Button is disabled when input is empty. |
| 2 | Publish label | Single **"Publish Page"** button always. "Save Draft" and the `selectedItem`-conditional "Update in WordPress" label are dropped. Backend create-vs-update logic is unchanged. |
| 3 | Post thumbnails | No thumbnails in list items. Title + sub-label + badge only. |
| 4 | Nav links | **Dashboard** and **Designer** only. Library and Settings links are not included. |
| 5 | Fonts | Local WOFF2 files in `src/fonts/`, served via `@font-face` in CSS. Webpack asset rule added for font files. No Google Fonts CDN dependency. |
