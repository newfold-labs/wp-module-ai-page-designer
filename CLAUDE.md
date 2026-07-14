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
- **Fast path:** `FastPathHandler` (PHP) handles image replacement requests without an AI round-trip. All style/color changes go through the full AI pipeline so the result is proper block markup that persists on publish.
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

## Coding Guidelines

### PHP Standards

**NEVER use short ternary operators** (`?:`):

```php
// ❌ Avoid short ternary:
$result = $value ?: 'default';

// ✅ Prefer explicit ternary:
$result = $value ? $value : 'default';

// ✅ Null coalescing is OK:
$name = $user->getName() ?? 'Unknown';
$config = $options['setting'] ?? $defaults['setting'];

// ✅ Or explicit conditionals for complex logic:
if ($value) {
    $result = $value;
} else {
    $result = 'default';
}
```

**cURL Usage:** Always add phpcs ignore comments when using cURL functions:
```php
// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
$curl = curl_init();
```

<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **wp-module-ai-page-designer** (2183 symbols, 5690 relationships, 186 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> If any GitNexus tool warns the index is stale, run `npx gitnexus analyze` in terminal first.

## Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `gitnexus_impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `gitnexus_detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `gitnexus_query({query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `gitnexus_context({name: "symbolName"})`.

## Never Do

- NEVER edit a function, class, or method without first running `gitnexus_impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `gitnexus_rename` which understands the call graph.
- NEVER commit changes without running `gitnexus_detect_changes()` to check affected scope.

## Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/wp-module-ai-page-designer/context` | Codebase overview, check index freshness |
| `gitnexus://repo/wp-module-ai-page-designer/clusters` | All functional areas |
| `gitnexus://repo/wp-module-ai-page-designer/processes` | All execution flows |
| `gitnexus://repo/wp-module-ai-page-designer/process/{name}` | Step-by-step execution trace |

## CLI

| Task | Read this skill file |
|------|---------------------|
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |

<!-- gitnexus:end -->
