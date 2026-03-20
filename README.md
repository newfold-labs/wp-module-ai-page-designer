# WP Module AI Page Designer

AI-powered page and post designer for WordPress with live preview and publishing capabilities.

## Features

- AI chat-driven page/post generation with Gutenberg block markup output
- Pattern-based base layouts for new pages to improve structure and speed
- Live preview with block-level selection and targeted edits
- Existing page/post selection with in-place updates
- Direct publishing options (new page, new post, or set as homepage)
- Theme-aware generation using active theme palette and typography
- Automatic Unsplash image replacement for all image URLs (including backgrounds)
- Fast-path updates for image swaps and theme color adjustments
- Gated by Hiive `hasAISiteGen` capability

## Installation

This module is automatically loaded by the wp-plugin-web plugin when the `hasAISiteGen` capability is enabled.

## Development

### Backend (PHP)

The PHP code is located in the `includes/` directory:
- `AIPageDesigner.php` - Main module class
- `RestApi/AIPageDesignerController.php` - AI generation endpoint
- `RestApi/WordPressProxyController.php` - WordPress content CRUD
- `Data/SystemPrompts.php` - AI system prompts

### Frontend (React/TypeScript)

The React app is in the `src/` directory.

To build the frontend:

```bash
cd vendor/newfold-labs/wp-module-ai-page-designer
npm install
npm run build
```

For development with auto-rebuild:

```bash
npm run dev
```

## REST API Endpoints

### AI Generation
- `POST /newfold-ai-page-designer/v1/generate`
  - Body: `{ messages: [{role: 'user', content: '...'}] }`
  - Returns: AI-generated HTML content

### Content Management
- `GET /newfold-ai-page-designer/v1/content/pages` - List pages
- `GET /newfold-ai-page-designer/v1/content/posts` - List posts
- `GET /newfold-ai-page-designer/v1/content/{type}/{id}` - Get single item
- `POST /newfold-ai-page-designer/v1/content/{type}` - Create content
- `PUT /newfold-ai-page-designer/v1/content/{type}/{id}` - Update content
- `POST /newfold-ai-page-designer/v1/homepage/{id}` - Set as homepage

## Capability Requirements

All endpoints require:
1. User capability: `edit_pages`
2. Hiive capability: `hasAISiteGen`

## License

GPL-2.0-or-later
