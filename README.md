# WP Module AI Page Designer

AI-powered page and post designer for WordPress with live preview and publishing capabilities.

## Features

- AI chat-driven page/post generation with Gutenberg block markup output
- Blueprint/pattern-based base layouts for new pages to improve structure consistency
- Live preview with block-level selection and targeted edits
- Dashboard to browse and edit existing pages and posts, or create new ones with AI
- Always-visible page details strip (title, excerpt, featured image) in the designer view
- Context-aware prompt suggestion pill below the chat input (clickable, not pre-filled)
- Dashboard hero input pre-filled with a suggested prompt for quick generation
- Fast-path block removal: detects removal intent on a selected block and removes it without an AI round-trip
- Direct publishing options (new page, new post, or set as homepage)
- Theme-aware generation using active theme colour palette and typography
- Automatic Unsplash image replacement for all placeholder image URLs (including cover blocks and backgrounds)
- AI-generated image search keywords for accurate Unsplash results when swapping images
- Fast-path image swaps: detects image replacement intent and resolves without a full AI round-trip
- Redesign/regeneration detection: skips existing markup and uses a clean blueprint when the user asks to redesign or start over
- Markup size guard: large existing pages are skeletonised before being sent to the AI to avoid gateway timeouts
- Stale conversation recovery: automatically retries without a stale `previous_response_id` on failure
- Conversation history drawer with revert support
- Gated by Hiive `canAccessAI` capability

## Installation

This module is automatically loaded by the wp-plugin-web plugin when the `canAccessAI` capability is enabled.

## Development

### Backend (PHP)

The PHP code is located in the `includes/` directory:

| File | Purpose |
|---|---|
| `AIPageDesigner.php` | Main module class, hooks and asset registration |
| `RestApi/AIPageDesignerController.php` | AI generation endpoint, image replacement pipeline |
| `RestApi/WordPressProxyController.php` | WordPress content CRUD |
| `Services/AiClient.php` | JWT exchange and AI API request/response handling |
| `Services/PromptBuilder.php` | System prompt, user message assembly, markup skeletonisation |
| `Services/FastPathHandler.php` | Image swap fast path with AI keyword generation |
| `Services/ImageService.php` | Unsplash search and image URL replacement |
| `Services/BlueprintService.php` | Base layout blueprints for new pages |
| `Services/BlockMarkupSanitizer.php` | Sanitises and extracts title from AI output |
| `Data/SystemPrompts.php` | AI system prompts including Gutenberg serialization and image placeholder rules |

```bash
composer install   # Install PHP dependencies
composer lint      # PHP CodeSniffer
composer fix       # PHP CodeSniffer auto-fix
```

### Frontend (React/TypeScript)

The React app is in the `src/` directory.

```bash
npm install        # Install dependencies
npm run build      # Production build
npm run dev        # Development build with watch
```

## AI Output Contract

The AI is instructed to:
- Return only raw Gutenberg block markup (no `<html>`/`<body>` wrappers)
- Embed the page title as `<!-- PAGE_TITLE: Title Here -->`
- Use `https://placehold.co/WIDTHxHEIGHT` for all image URLs (replaced automatically by Unsplash)
- Use escaped `\u002d\u002d` sequences inside Gutenberg block comment JSON whenever a CSS custom property appears there
- Keep rendered HTML `style` attributes in normal CSS syntax, for example `style="color:var(--wp--preset--color--contrast_midtone);font-family:system-font"`
- Apply color, background, and font changes via Gutenberg block attributes plus the corresponding rendered HTML style when needed — never as standalone CSS

## REST API Endpoints

### AI Generation
- `POST /newfold-ai-page-designer/v1/generate`
  - Body: `{ messages: [{role, content}], current_markup?, content_type?, conversation_id? }`
  - Returns: AI-generated HTML content, page title, response/conversation IDs

### Content Management
- `GET /newfold-ai-page-designer/v1/content/pages` — List pages
- `GET /newfold-ai-page-designer/v1/content/posts` — List posts
- `GET /newfold-ai-page-designer/v1/content/{type}/{id}` — Get single item
- `POST /newfold-ai-page-designer/v1/content/{type}` — Create content
- `PUT /newfold-ai-page-designer/v1/content/{type}/{id}` — Update content
- `POST /newfold-ai-page-designer/v1/homepage/{id}` — Set as homepage

## Capability Requirements

All endpoints require:
1. User capability: `edit_pages`
2. Hiive capability: `canAccessAI`

## License

GPL-2.0-or-later
