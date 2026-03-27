# AI Page Designer: Hybrid Color Changes Plan

## Goal
Enable stable, theme-accurate color changes in the preview while keeping
fast, targeted edits for specific blocks or sections.

## Overview
Use a hybrid approach:
- Global changes use WordPress Global Styles (`useGlobalStylesOutput`) and
  render through `BlockEditorProvider` + iframe `EditorStyles`.
- Targeted changes keep the existing inline style injection behavior.

## Phase 0: Baseline + Discovery
- Identify the current preview rendering path in AI Page Designer.
- Locate the text-to-style parsing logic (ex: "make text blue") and how it
  injects styles today.
- Decide where a "global styles config" object will live in state.

## Phase 1: Global Styles Preview Pipeline
- Create a `previewSettings` object from the current theme's global styles
  (theme.json-style object).
- Compute preview styles with:
  - `useGlobalStylesOutput(previewSettings, storedPreviewSettings)`
- Render the preview using:
  - `BlockEditorProvider` with `settings.settings`
  - iframe `EditorStyles` with `settings.styles`
- Confirm the preview reflects the active theme styles before any changes.

## Phase 2: Map User Intents to Global Styles
Translate user prompts into updates on the global styles config.

Examples:
- "make text blue"
  - `styles.color.text = '#0000ff'`
- "make links blue"
  - `styles.elements.link.color.text = '#0000ff'`
- "make headings blue"
  - `styles.elements.heading.color.text = '#0000ff'`
- "set background to light gray"
  - `styles.color.background = '#f3f4f6'`

After updating the config:
- Re-run `useGlobalStylesOutput(...)`
- Re-render preview with new `settings.styles`

## Phase 3: Keep Targeted Block Edits Inline
If a block is explicitly selected or the user says "this section":
- Continue using inline style changes on the block and its descendants
- Avoid altering global styles for scoped changes

## Phase 4: Conflict Resolution Rules
- Inline styles should win for targeted edits.
- Global styles should be the default for everything else.
- If both exist, do not overwrite inline styles with global styles.

## Phase 5: UX and Messaging
- Confirm to the user whether the change is global or scoped.
- Record a history entry per change type:
  - `global style change: <label>`
  - `section style change: <label>`

## Phase 6: Testing + Verification
- Verify global updates render accurately across themes.
- Validate scoped changes do not get overridden by global updates.
- Check that undo/history entries restore the correct state.

## Notes
- This approach improves preview accuracy for theme-driven color updates.
- It keeps the fast, targeted behavior for block-level adjustments.
