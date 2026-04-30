# WP Module AI Page Designer

AI-powered page and post designer for WordPress with live preview and publishing capabilities.

## Features

### Dashboard
- Hero prompt input pre-filled with a suggested prompt; press Enter or click Generate to start
- Separate Pages and Posts lists with title, publish/draft status badge, and relative modification date
- Inline real-time search for pages and posts with a result count badge and clear button
- Lists truncate to 5 items by default; expand/collapse toggle shows the rest
- Click any existing page or post to open it in the Designer view pre-loaded with its content

### AI Designer
- Conversational AI chat interface; assistant replies include a human-readable summary
- Expandable `<details>` toggle on assistant messages lets you inspect the raw generated block markup
- **Streaming live preview** — the first generation for a new page streams block markup into the preview in real time; subsequent targeted edits use a non-streaming path for precise block replacement
- Live preview iframe loads WordPress block library CSS and the active theme stylesheet for an accurate in-browser preview without a full page load
- **Conversation chaining** — `response_id` / `conversation_id` are passed to the AI API so each message builds on prior context without resending the full history
- Blueprint/pattern-based base layouts for new pages improve structural consistency; blueprints rotate to avoid repetition and adapt by site type (`ecommerce` for WooCommerce, `business` otherwise)
- Theme-aware generation using active theme colour palette and typography from `theme.json`
- Redesign/regeneration detection: when the user asks to redesign or start over, existing markup is skipped and a clean blueprint is used
- Markup size guard: large existing pages are skeletonised before being sent to the AI to avoid gateway timeouts
- Stale conversation recovery: automatically retries without a stale `previous_response_id` on failure

### Targeted (Single-Block) Editing
- Click any section in the preview iframe to select it; the selected block is highlighted
- **Context optimization** — when a block is selected, only that block's Gutenberg markup is sent to the AI (not the full page), reducing token cost and improving precision
- **Dual replacement strategy** — the modified block is spliced back using `wp.blocks.parse/serialize` when available, with a string-split fallback that works without the Gutenberg JS runtime
- **DOM patch fallback** — when the page is rendered HTML without block markers, edits are applied directly to the iframe DOM
- **Follow-up edit tracking** — after a targeted edit, a follow-up prompt without re-selecting continues editing the same block automatically

### Fast-Path Operations (No AI Round-Trip)
- **Block removal** — removal intent is detected from natural language (`remove`, `delete`, `get rid of`, etc.); if no content qualifier is present, the block is removed from the DOM immediately
- **Image replacement** — `ImageService` resolves Unsplash images for placeholder URLs with AI-generated search keywords
- **Metadata-only responses** — prompts for title, excerpt, or summary update the meta strip without altering the preview content

### Page Metadata (Meta Strip)
- Editable page title and excerpt fields; AI auto-populates them from `<!-- PAGE_TITLE: ... -->` and `<!-- PAGE_EXCERPT: ... -->` markers in generated output
- Featured image thumbnail with a Change button (WP media picker) and Remove option; shown only when the post type supports thumbnails

### History & Revert
- Edit history drawer — every AI generation appends a timestamped entry labelled with the prompt
- Restore to any prior version (truncates subsequent history)
- Full revert — discards all AI changes and restores the original WordPress content (with a confirmation modal)

### Publishing
- **Publish as blog post** — creates a new post
- **Publish as new page** — creates a standalone page
- **Set as homepage** — creates a page and sets it as the site's static front page
- **Update existing** — replaces content on a selected page or post, including title, excerpt, and featured image in a single request
- **Overwrite from publish modal** — when publishing a new design, the modal lets you choose any existing page or post to overwrite instead
- Post-publish link appears in the chat: "View published page"

### Access Control
- Gated by Hiive `canAccessAI` capability — the module does not load at all on sites without this flag
- All REST routes require `edit_pages` user capability AND `canAccessAI` Hiive capability

---

## Installation

This module is automatically loaded by `wp-plugin-web` when the `canAccessAI` capability is enabled for the site.

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
| `AIPageDesigner.php` | Main module class, hooks and asset registration |
| `RestApi/AIPageDesignerController.php` | AI generation endpoint, image replacement pipeline |
| `RestApi/WordPressProxyController.php` | WordPress content CRUD proxy |
| `Services/AiClient.php` | Hiive JWT exchange and AI API request/response handling |
| `Services/PromptBuilder.php` | System prompt and user message assembly, markup skeletonisation |
| `Services/FastPathHandler.php` | Image swap fast path with AI keyword generation |
| `Services/ImageService.php` | Unsplash search and image URL replacement |
| `Services/BlueprintService.php` | Base layout blueprints — fetches, caches, and parses from Hiive API |
| `Services/PatternLayoutProvider.php` | Gutenberg block pattern layouts as structural context |
| `Services/BlockMarkupSanitizer.php` | Validates and auto-closes unclosed block tags in AI output |
| `Services/CapabilityGate.php` | Centralised capability checks for REST routes and module load |
| `Data/SystemPrompts.php` | AI system prompts including Gutenberg serialisation and image placeholder rules |

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
| `src/index.tsx` | App entry point, mounts `AIPageDesignerApp` |
| `src/components/DashboardView.tsx` | Dashboard with hero prompt, pages/posts lists, and search |
| `src/components/ChatPanel.tsx` | AI chat messages, loading indicator, history drawer, publish bar |
| `src/components/PreviewFrame.tsx` | Live preview iframe with theme/block stylesheet injection |
| `src/components/MetaStrip.tsx` | Editable title, excerpt, and featured image fields |
| `src/components/HistoryDrawer.tsx` | Collapsible edit history with restore-to-version |
| `src/components/PublishModal.tsx` | Publish options: new post, new page, homepage, overwrite existing |
| `src/components/RevertConfirm.tsx` | Confirmation modal for full revert |
| `src/hooks/useAiConversation.ts` | AI conversation state, streaming, targeted editing, history |
| `src/hooks/usePublishFlow.ts` | Publish and update logic, publish modal state |
| `src/hooks/useBlockSelection.ts` | iframe postMessage listener for block click selection |
| `src/hooks/usePreviewIframe.ts` | Iframe initialisation with WordPress stylesheets |
| `src/hooks/useSiteContent.ts` | Fetches pages and posts from the WordPress proxy REST API |

---

## AI Output Contract

The AI is instructed to:
- Return only raw Gutenberg block markup (no `<html>`/`<body>` wrappers)
- Embed the page title as `<!-- PAGE_TITLE: Title Here -->`, optional excerpt as `<!-- PAGE_EXCERPT: ... -->`, and a short chat summary as `<!-- RESPONSE_SUMMARY: ... -->`
- For metadata-only requests (e.g. "generate an excerpt"), return just the metadata comments with no block markup — the UI applies the values to the meta strip and keeps the preview unchanged
- Use `https://placehold.co/WIDTHxHEIGHT` for all image URLs (replaced automatically by Unsplash)
- Use escaped `--` sequences inside Gutenberg block comment JSON whenever a CSS custom property appears there
- Keep rendered HTML `style` attributes in normal CSS syntax, e.g. `style="color:var(--wp--preset--color--contrast_midtone);font-family:system-font"`
- Apply color, background, and font changes via Gutenberg block attributes plus the corresponding rendered HTML style — never as standalone CSS

---

## REST API

### AI Generation
- `POST /newfold-ai-page-designer/v1/generate`
  - Body: `{ messages: [{role, content}], current_markup?, content_type?, conversation_id?, selected_block_markup?, single_block_edit? }`
  - Returns: AI-generated content, page title, excerpt, summary, response/conversation IDs

### Content Management
- `GET /newfold-ai-page-designer/v1/content/pages` — List pages
- `GET /newfold-ai-page-designer/v1/content/posts` — List posts
- `GET /newfold-ai-page-designer/v1/content/{type}/{id}` — Get single item
- `POST /newfold-ai-page-designer/v1/content/{type}` — Create content
- `PUT /newfold-ai-page-designer/v1/content/{type}/{id}` — Update content (title, excerpt, featured image, content)
- `POST /newfold-ai-page-designer/v1/homepage/{id}` — Set page as site front page

All endpoints require `edit_pages` user capability and `canAccessAI` Hiive site capability.

---

## Pattern Provider Configuration

The AI Page Designer supports multiple layout providers for new page generation, configured via the `PATTERN_PROVIDER` constant in `AIPageDesigner.php`.

### Available Providers

#### WonderBlocks (Recommended)
```php
const PATTERN_PROVIDER = 'wonderblocks';
```
- Intent-based pattern selection from curated, modern UI/UX patterns
- Native blocks respect the active theme's `theme.json`
- ~5–15s generation time

#### Blueprints
```php
const PATTERN_PROVIDER = 'blueprints';
```
- Fetches and rotates blueprints from the Hiive API
- Downloads blueprint ZIP, parses the SQL export, and extracts Gutenberg page markup as a base layout

#### Pure AI (No Scaffolding)
```php
const PATTERN_PROVIDER = '';
```
- AI generates layouts from scratch with no base template
- Useful for testing AI layout generation independently

To change the provider, modify the constant in [`includes/AIPageDesigner.php`](includes/AIPageDesigner.php).

---

## License

GPL-2.0-or-later
