# WP Module AI Page Designer

**Version 1.0.5**

AI-powered page and post designer for WordPress with live preview and publishing capabilities.

## Features

### Dashboard
- Hero prompt input with an editable default prompt; press Enter or click Generate to start
- Random prompt chip suggestions for quick starts
- Separate Pages and Posts lists with title, publish/draft status badge, and relative modification date
- Inline real-time search for pages and posts with a result count badge and clear button
- Lists truncate to 5 items by default; expand/collapse toggle shows the rest
- Click any existing page or post to open it in the Designer view pre-loaded with its content

### Designer UI
- Header navigation between **Dashboard** and **Designer** views, plus a **Create New** action
- Side panel with **Chat** and **History** tabs
- Context-aware prompt chip suggestions in chat (new page, existing page, or selected block)
- Selected-block indicator when a section is targeted for editing, with a cancel action
- Publish bar in the chat panel and header when content or metadata has changed

### AI Designer
- Conversational AI chat interface; assistant replies include a human-readable summary
- Expandable `<details>` toggle on assistant messages lets you inspect the raw generated block markup
- **Cloudflare Worker backend** — `AiClientWorker` delegates generation to the Hiive AI Worker (`NFD_AI_BASE/ai-page-designer/generate`); prompt assembly, sanitization, and layout guards run server-side in the Worker
- **Streaming live preview** — the first generation for a new page streams block markup into the preview via `POST /generate/stream` (SSE); subsequent edits use the non-streaming `/generate` path for precise block replacement
- Live preview iframe loads WordPress block library CSS, compiled global styles from `theme.json`, and the active theme stylesheet
- **Self-contained requests** — each call sends the current markup (or selected block) plus theme context; the Worker backend is stateless
- **Session tracking for new pages** — the frontend stores `conversation_id` / `response_id` for new-page sessions; existing content edits are keyed by `post_id`
- **WonderBlocks scaffolding** — when `PATTERN_PROVIDER = 'wonderblocks'`, intent-based WonderBlocks patterns provide a base layout for new pages and redesigns
- **Pure AI by default** — `PATTERN_PROVIDER` is currently `''`, so new pages are generated from scratch with no base template
- Theme-aware generation using active theme colour palette, typography, and site name from `theme.json`
- Redesign/regeneration detection: when the user asks to redesign or start over, existing markup is skipped and a fresh layout is generated
- Legacy content without Gutenberg block markers is auto-converted to block markup on load via `wp.blocks.rawHandler`

### Targeted (Single-Block) Editing
- Click any section in the preview iframe to select it; the selected block is highlighted
- **Context optimization** — when a block is selected, only that block's Gutenberg markup is sent to the AI (not the full page), reducing token cost and improving precision
- **Additive inserts** — prompts like "add a pricing table below this section" insert new content before or after the selected block
- **Dual replacement strategy** — the modified block is spliced back using `wp.blocks.parse/serialize` when available, with a string-split fallback that works without the Gutenberg JS runtime
- **DOM patch fallback** — when the page is rendered HTML without block markers, edits are applied directly to the iframe DOM
- **Follow-up edit tracking** — after a targeted edit, a follow-up prompt without re-selecting continues editing the same block automatically
- **Lone page-wrapper normalization** — pages saved inside a single top-level `wp:group` are unwrapped so block indices stay accurate

### Fast-Path Operations (No AI Round-Trip)
- **Block removal (client)** — removal intent is detected from natural language; if no content qualifier is present, the block is removed from the DOM immediately
- **Text/background colour (client)** — simple colour change prompts on a selected block are applied directly in the preview iframe
- **Image replacement (server)** — `FastPathHandler` + `ImageService` resolve Unsplash images for placeholder URLs with AI-generated search keywords
- **Image insertion (server)** — when the user asks to add images to a layout with none, the Worker analyze endpoint identifies insertion positions
- **Metadata-only responses** — prompts for title, excerpt, or summary update the meta strip without altering the preview content; only the explicitly requested field(s) are applied

### Page Metadata (Meta Strip)
- Editable page title and excerpt fields; AI auto-populates them from `<!-- PAGE_TITLE: ... -->` and `<!-- PAGE_EXCERPT: ... -->` markers in generated output
- Featured image thumbnail with a Change button (WP media picker) and Remove option; shown only when the post type supports thumbnails

### History & Revert
- Edit history pane (History tab in the side panel) — every AI generation appends a timestamped entry labelled with the prompt
- Restore to any prior version (truncates subsequent history)
- Full revert — discards all AI changes and restores the original WordPress content (with a confirmation modal)

### Publishing
- **Publish as blog post** — creates a new post
- **Publish as new page** — creates a standalone page
- **Set as homepage** — creates a page and sets it as the site's static front page
- **Update existing** — replaces content on a selected page or post, including title, excerpt, and featured image in a single request
- **Overwrite from publish modal** — when publishing a new design, the modal lets you choose any existing page or post to overwrite instead
- Post-publish link appears in the chat: "View published page"

### Published Page Animations
- Frontend animation CSS (fade-in, slide-up, bounce-in, scale-in, hover lift) enqueued on pages, posts, and the front page
- Scroll-triggered `data-aos` animations via an IntersectionObserver
- Google Fonts (Playfair Display, Montserrat, Lora, Raleway) enqueued for AI-generated typography
- `wp_kses` and `safe_style_css` filters allow animation classes and CSS properties in published content

### Access Control
- **Two-tier Hiive capability gating:**
  - `canAccessAI` — AI Site Generation must be enabled for the site
  - `canAccessAIPageDesigner` — AI Page Designer must be enabled separately
- Admin assets always load so the React app can render a proper message when capabilities are missing; REST routes and AI features require both flags
- All REST routes require `edit_pages` user capability plus both Hiive site capabilities

---

## Installation

This module is automatically loaded by `wp-plugin-web` via the module loader. Both `canAccessAI` and `canAccessAIPageDesigner` Hiive site capabilities must be enabled for AI generation to work.

**PHP dependencies:** `newfold-labs/wp-module-loader`, `newfold-labs/wp-module-data`, `newfold-labs/wp-module-ai` (Worker endpoint via `NFD_AI_BASE`).

---

## Development

### Backend (PHP)

```bash
composer install   # Install PHP dependencies
composer lint      # PHP CodeSniffer
composer fix       # PHP CodeSniffer auto-fix
```

PHP source is in `includes/`:

| File | Purpose |
|---|---|
| `AIPageDesigner.php` | Main module class — hooks, asset registration, animation support, `PATTERN_PROVIDER` config |
| `RestApi/AIPageDesignerController.php` | AI generation endpoints (`/generate`, `/generate/stream`), response processing, image pipeline |
| `RestApi/WordPressProxyController.php` | WordPress content CRUD proxy |
| `Services/AiClientWorker.php` | Hiive Worker proxy for generate, stream, and analyze requests |
| `Services/FastPathHandler.php` | Image swap/insert fast paths with AI keyword generation |
| `Services/ImageService.php` | Unsplash search and image URL replacement |
| `Services/PatternLayoutProvider.php` | WonderBlocks intent-based pattern layouts as structural context |
| `Services/CapabilityGate.php` | Centralised `canAccessAI` / `canAccessAIPageDesigner` checks |

See also [`ARCHITECTURE.md`](ARCHITECTURE.md) and the [`docs/`](docs/) folder for deeper design notes.

### Frontend (React/TypeScript)

```bash
npm install        # Install dependencies
npm run build      # Production build
npm run dev        # Development build with watch
npm run build:dev  # Development build without watch
```

React source is in `src/`:

| File | Purpose |
|---|---|
| `src/index.tsx` | App entry point; exposes `window.AIPageDesignerApp` |
| `src/App.tsx` | Main app shell — dashboard/designer routing, header, meta state |
| `src/api.ts` | REST client — generate, stream (SSE), content CRUD |
| `src/types.ts` | Shared TypeScript types |
| `src/promptChips.ts` | Dashboard and chat prompt chip suggestions |
| `src/util/aiDesignerHelpers.ts` | HTML extraction, Gutenberg conversion, markup helpers |
| `src/components/DashboardView.tsx` | Dashboard with hero prompt, pages/posts lists, and search |
| `src/components/SidePanel.tsx` | Side panel with Chat and History tabs |
| `src/components/ChatPanel.tsx` | AI chat messages, prompt chips, loading indicator, publish bar |
| `src/components/HistoryPane.tsx` | Edit history list with restore-to-version |
| `src/components/PreviewFrame.tsx` | Live preview iframe with theme/block stylesheet injection |
| `src/components/MetaStrip.tsx` | Editable title, excerpt, and featured image fields |
| `src/components/PublishModal.tsx` | Publish options: new post, new page, homepage, overwrite existing |
| `src/components/RevertConfirm.tsx` | Confirmation modal for full revert |
| `src/hooks/useAiConversation.ts` | AI conversation state, streaming, targeted editing, fast paths, history |
| `src/hooks/usePublishFlow.ts` | Publish and update logic, publish modal state |
| `src/hooks/useBlockSelection.ts` | iframe postMessage listener for block click selection |
| `src/hooks/usePreviewIframe.ts` | Iframe initialisation with WordPress stylesheets |
| `src/hooks/useSiteContent.ts` | Fetches pages and posts from the WordPress proxy REST API |

---

## AI Output Contract

The AI Worker is instructed to:
- Return only raw Gutenberg block markup (no `<html>`/`<body>` wrappers)
- Embed the page title as `<!-- PAGE_TITLE: Title Here -->`, optional excerpt as `<!-- PAGE_EXCERPT: ... -->`, and a short chat summary as `<!-- RESPONSE_SUMMARY: ... -->`
- For metadata-only requests (e.g. "generate an excerpt"), return just the metadata comments with no block markup — the UI applies the values to the meta strip and keeps the preview unchanged
- Use `https://placehold.co/WIDTHxHEIGHT` for all image URLs (replaced automatically by Unsplash after generation)
- Use escaped `--` sequences inside Gutenberg block comment JSON whenever a CSS custom property appears there
- Keep rendered HTML `style` attributes in normal CSS syntax, e.g. `style="color:var(--wp--preset--color--contrast-midtone);font-family:system-font"`
- Apply color, background, and font changes via Gutenberg block attributes plus the corresponding rendered HTML style — never as standalone CSS

---

## REST API

All endpoints live under the `newfold-ai-page-designer/v1` namespace.

### AI Generation
- `POST /newfold-ai-page-designer/v1/generate`
  - Body: `{ messages: [{role, content}], context?: { ... } }`
  - Returns: `{ data: { content, title, excerpt, summary, response_id, conversation_id, is_metadata_only?, featured_image_url?, message? } }`

- `POST /newfold-ai-page-designer/v1/generate/stream`
  - Same body as `/generate`
  - Returns Server-Sent Events: `delta`, `snapshot`, `result`, `error`, `done`

**Context object fields:**

| Field | Type | Description |
|---|---|---|
| `current_markup` | string | Current page/block markup sent to the AI |
| `post_id` | number | Existing post/page ID (for edits) |
| `conversation_id` | string (UUID v4) | Session ID for new-page flows |
| `content_type` | `'page'` \| `'post'` | Content type hint |
| `selected_block_markup` | string | Markup of the clicked block |
| `single_block_edit` | boolean | When true, AI returns only the modified block |
| `page_title` | string | Current title from the meta strip |
| `page_excerpt` | string | Current excerpt from the meta strip |
| `theme_mode` | string | Optional theme mode override (`dark`, `black`, `blue`, etc.) |

### Content Management
- `GET /newfold-ai-page-designer/v1/content/pages` — List pages
- `GET /newfold-ai-page-designer/v1/content/posts` — List posts
- `GET /newfold-ai-page-designer/v1/content/{type}/{id}` — Get single item
- `POST /newfold-ai-page-designer/v1/content/{type}` — Create content
- `POST /newfold-ai-page-designer/v1/content/{type}/{id}` — Update content (title, excerpt, featured image, content)
- `POST /newfold-ai-page-designer/v1/homepage/{id}` — Set page as site front page

All endpoints require `edit_pages` user capability, `canAccessAI`, and `canAccessAIPageDesigner` Hiive site capabilities.

---

## Pattern Provider Configuration

The AI Page Designer supports optional layout scaffolding for new page generation, configured via the `PATTERN_PROVIDER` constant in `AIPageDesigner.php`.

### Available Providers

#### Pure AI (Default)
```php
const PATTERN_PROVIDER = '';
```
- AI generates layouts from scratch with no base template
- Current default setting

#### WonderBlocks
```php
const PATTERN_PROVIDER = 'wonderblocks';
```
- Intent-based pattern selection from curated WonderBlocks patterns
- Native blocks respect the active theme's `theme.json`
- Used for new pages and redesign requests (not blog posts)

To change the provider, modify the constant in [`includes/AIPageDesigner.php`](includes/AIPageDesigner.php). See [`docs/pattern-provider-configuration.md`](docs/pattern-provider-configuration.md) for additional notes.

---

## License

GPL-2.0-or-later
