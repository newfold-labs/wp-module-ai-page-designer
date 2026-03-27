# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### Frontend
```bash
npm install          # Install dependencies
npm run build        # Production build
npm run dev          # Development build with watch
npm run build:dev    # Development build without watch
```

### PHP
```bash
composer install     # Install PHP dependencies
composer lint        # PHP CodeSniffer
composer fix         # PHP CodeSniffer auto-fix
composer i18n-pot    # Generate translation template
```

No automated test suite is configured.

## Architecture

This is a WordPress module (library) that adds an AI-powered page designer UI to WordPress admin. It is consumed by `wp-plugin-web` via Composer and the `newfold-labs/wp-module-loader` system.

**Entry point:** `bootstrap.php` — hooks into `plugins_loaded`, checks capabilities, registers REST routes, enqueues the React app.

### Frontend (React + TypeScript)

- **Build:** Webpack bundles `src/index.tsx` → `build/index.js` + `build/index.css`. React and ReactDOM are externalized (loaded from WordPress globals).
- **Mount:** App mounts as `window.AIPageDesignerApp` (or `#nfd-ai-page-designer-root`). WordPress config is passed via `window.nfdAIPageDesigner` (localized script).
- **Two views:** Dashboard (list existing pages/posts) and Designer (AI chat + live preview).
- **Live preview:** An iframe loads WordPress block library CSS + active theme styles to render Gutenberg block markup in real time.
- **Fast path:** `getLocalStyleChange()` in `src/util/aiDesignerHelpers.ts` detects simple prompts (dark mode, color changes) and applies CSS directly without an AI round-trip.
- **Conversation state:** `useAiConversation` hook manages message history, tracks `response_id` for chaining, and maintains `HistoryEntry[]` for the history drawer.

### Backend (PHP)

**REST namespace:** `newfold-ai-page-designer/v1`

Two controllers under `includes/RestApi/`:

| Controller | Routes | Purpose |
|---|---|---|
| `AIPageDesignerController` | `POST /generate` | Orchestrates AI content generation |
| `WordPressProxyController` | `GET/POST/PUT /content/{type}`, `POST /homepage/{id}` | CRUD wrapper for WordPress pages/posts |

**AI generation pipeline** (in `AIPageDesignerController::generate_content()`):
1. `FastPathHandler` — checks for quick edits; returns early if matched
2. `PromptBuilder` — builds system prompt (with theme context) + user messages
3. `AiClient` — exchanges Hiive token for JWT, calls `api-gw.builderservices.io/ai-api/v1/response`; uses `previous_response_id` for conversation chaining
4. `BlockMarkupSanitizer` — extracts `<!-- PAGE_TITLE: ... -->` from response
5. `ImageService` — replaces placeholder images with Unsplash results

**Permissions:** All routes require `edit_pages` capability AND Hiive `hasAISiteGen` site capability (checked via `CapabilityGate`).

### AI Output Contract

The AI is instructed (via `includes/Data/SystemPrompts.php`) to:
- Return only raw Gutenberg block markup (no `<html>`/`<body>` wrappers)
- Preserve all `<!-- wp:blockname -->` / `<!-- /wp:blockname -->` comment delimiters exactly
- Embed the page title as `<!-- PAGE_TITLE: Title Here -->`
- Never modify block structure, only text content and styles

### Key Types (`src/types.ts`)

```typescript
Message      // { role: 'user' | 'assistant', content: string, link?: string }
WPItem       // { id, title, content, status, link, type }
HistoryEntry // { id, html, label, timestamp, publishTitle? }
```

### Module Integration

- The parent plugin (`wp-plugin-web`) loads this module; the admin menu is registered by the parent.
- Assets are enqueued only on the `web` admin page via `admin_enqueue_scripts`.
- WordPress block library and theme stylesheets are injected into the preview iframe by `usePreviewIframe`.
