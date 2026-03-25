---
title: AI Page Designer editable meta strip
date: 2026-03-24
status: ready-for-review
scope: wp-module-ai-page-designer
---

# AI Page Designer editable meta strip

## Context
The AI Page Designer currently previews only the post/page body (Gutenberg `post_content`) inside an iframe. For existing items, users want a small, editable meta strip (title, excerpt, featured image) shown above the preview, spanning the combined width of the AI chat + preview columns. Edits should apply only when the user clicks “Update in WordPress.”

## Goals
- Show a meta strip for **existing** pages/posts only.
- Meta strip spans full width of the designer content area (chat + preview).
- Title, excerpt, featured image are **editable inline** in the meta strip.
- Featured image uses the **WordPress media picker**.
- Persist meta changes only when “Update in WordPress” is clicked.
- Keep iframe preview focused on editable body content (no theme shell).

## Non-goals
- No auto-save of meta fields.
- No theme/header/footer or admin chrome in the iframe preview.
- No new standalone “rendered page preview” mode.

## Current behavior (summary)
- Existing item selection loads `content.raw` into preview.
- REST payload includes `title.rendered`, `content.rendered/raw`, `status`, `link`, `type`.
- Update endpoint only posts `content`.

## Proposed changes

### 1) REST payload extensions (module endpoints)
Extend `newfold-ai-page-designer/v1/content/*` responses (both list and single item) to include:
- `excerpt.rendered` (HTML, display only for future parity; editor uses `raw`)
- `excerpt.raw` (string, used for editing round-trip)
- `featured_media` (integer ID)
- `featured_image_url` (string URL; empty if none, derived from a sized image)

This keeps data flow within the module (no core REST fetches from the frontend).

**Update request/response contract (existing item)**:
- Route: `POST newfold-ai-page-designer/v1/content/{type}/{id}`
- Payload keys:
  - `content` (string)
  - `title` (string)
  - `excerpt` (string; plain text)
  - `featured_media` (integer; `0` clears)
- Semantics: send all meta fields each update (no partial semantics).
- Response: return the full updated item shape, including new meta fields, to avoid a follow-up GET.
- Sanitization: server uses `sanitize_text_field` for `title` and `excerpt`, `absint` for `featured_media`.

### 2) Types and client data flow
- Extend `WPItem` type to include `excerpt`, `featured_media`, `featured_image_url`.
- On selecting an existing item:
  - Initialize meta state from the selected item fields.
  - Preview still uses `content.raw` as the iframe markup.

### 3) Meta strip UI
Add a new meta strip component above the existing chat/preview split:
- Layout: full-width row within the designer content area.
- Fields:
  - Title: single-line input (editable).
  - Excerpt: textarea (editable).
- Featured image: thumbnail + “Change image” (media picker) + “Remove”.
- Visible only for existing items (when `selectedItem` is set).
- Responsive: when layout stacks (narrow screens), the meta strip wraps fields vertically.
- If the post type does not support thumbnails, hide featured image controls and show a short note.

### 4) Update flow
When user clicks “Update in WordPress”:
- Send `content`, `title`, `excerpt`, and `featured_media` in the update request.
- Backend updates `post_title`, `post_excerpt`, and featured image.
- After success, refresh meta state from server response to ensure ID/URL stay aligned.
- Permissions: update requires `edit_post` capability for the item.

### 5) Media picker integration
- Ensure media scripts are enqueued for the module surface (requires `wp_enqueue_media()`).
- Use `wp.media` to open the media library, restricted to images only.
- On selection:
  - Store selected media ID for save.
  - Display a sized image URL in the meta strip (prefer `medium`, fallback to `large`, then `full`).
- On remove:
  - Set featured image ID to `0` and clear URL.
- If `wp.media` is unavailable, disable the picker button and show helper text.
- Opening the picker requires `upload_files` capability (or disable with helper text).

## Error handling / edge cases
- If a post has no excerpt or featured image, show empty field + placeholder image.
- If featured image is removed, ensure both UI state and update payload clear it.
- If user lacks permissions, show error state on update (no silent failure).
- No additional confirmation if only meta fields changed; the Update button is the single save gate.

## Testing & verification
- Add unit/integration coverage for new REST response fields (PHP):
  - excerpt raw/rendered
  - featured_media `0` clears
  - featured_image_url size fallback
- Add UI test (or minimal smoke test) covering:
  - Meta strip shown for existing item.
  - Edit title/excerpt.
  - Choose featured image via media picker.
  - “Update in WordPress” persists meta fields.
- Manual check: preview iframe remains body-only.
- Media picker behavior can be manual-only if CI is too flaky; keep a unit test around the payload fields.
