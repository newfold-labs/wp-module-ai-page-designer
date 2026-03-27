# WonderBlocks Hybrid Approach

## Overview
This hybrid approach transitions the AI Page Designer from generating raw HTML
from scratch to using native WordPress Gutenberg Patterns (via
`wp-module-patterns` / WonderBlocks). The AI is repositioned as a content editor
rather than a layout builder.

This strategy solves two major issues:
1. **Speed:** Reduces the 35-40s wait time down to ~5-15s.
2. **Block Compatibility:** Guarantees 100% native block editor compatibility by starting with valid `<!-- wp:block -->` structures instead of raw HTML.

---

## Architectural Flows

### 1. Flow for New Pages (Creation)
When a user asks to build a completely new page (e.g., "Build a homepage for a modern coffee shop").

1. **Intent Extraction:** AI or basic logic parses the user's prompt to determine the page type and required sections (e.g., `hero`, `about`, `menu`, `footer`).
2. **Pattern Retrieval:** Backend uses `NewfoldLabs\WP\Module\Patterns\Library\Items::get()` to fetch the corresponding Gutenberg patterns from WonderBlocks.
3. **Pre-Assembly:** The backend strings these patterns together into a single cohesive block markup string.
4. **AI Personalization:** The assembled block markup + the user's prompt is sent to the AI API. The AI is instructed to *only* swap out the placeholder text, images, and theme colors inside the block attributes.
5. **Render:** The personalized block markup is returned and rendered in the canvas.

### 2. Flow for Existing Pages (Updates)
When a user requests modifications to what is currently on the canvas.

#### A. Whole Page Updates (e.g., "Make it sound more professional")
1. React app extracts the *current* full Gutenberg block markup from the canvas.
2. App sends: `[User Prompt] + [Current Block Markup]`.
3. AI modifies the text/attributes within the existing JSON comments and tags.
4. AI returns the updated full markup.

#### B. Targeted Block Updates (Recommended for Maximum Speed)
1. User selects a specific section in the UI and types a prompt (e.g., "Make this hero title punchier").
2. React app extracts *only* that specific block's markup.
3. App sends: `[User Prompt] + [Selected Block Markup]`.
4. AI returns the updated block instantly.
5. React app replaces the old block with the new block in the canvas.

---

## Implementation Steps

### Phase 1: Backend Pattern Integration (`AIPageDesignerController.php`)
- [x] Add an endpoint or modify `generate_content` to identify when a user wants a new page.
- [x] Import `NewfoldLabs\WP\Module\Patterns\Library\Items`.
- [x] Create a helper method (`get_random_pattern_layout`) to map business types/intents to WonderBlocks categories and randomize the pattern selection.
- [x] Fetch patterns and concatenate them as a "BASE LAYOUT".

### Phase 2: System Prompt Updates (`SystemPrompts.php`)
- [x] Rewrite `get_page_designer_prompt()` to shift the AI's role.
- **Key new prompt instructions:**
  - *"You are an expert Gutenberg block editor."*
  - *"You will be provided with existing valid Gutenberg block markup and a user request."*
  - *"Modify ONLY the text and styling attributes inside the provided blocks. Do NOT modify image URLs."*
  - *"NEVER break or remove the `<!-- wp:blockname -->` HTML comments."*
  - *"Do not add new wrapper HTML tags."*

### Phase 3: Frontend Payload Adjustments (`src/App.tsx` & API helpers)
- [x] Update the API call payload to include the current canvas state.
- [x] Ensure the frontend is passing `current_markup` as part of the `context` to the API.
- [x] Updated to use the non-streaming `/v1/response` endpoint with the faster
  `gpt-4o` model for the backend request.
- [x] (Optional) Add UI for selecting specific blocks to send targeted, micro-edits to the AI for 3-second response times.

### Phase 4: Parsing and Sanitization
- [x] Implemented robust sanitization on the frontend to handle incomplete AI generation (e.g., cut off comments) and close any unclosed Gutenberg block tags using a stack-based algorithm.

---

## Color Change Strategy
For color updates, pair this WonderBlocks flow with the hybrid color pipeline
so global palette changes render accurately while scoped edits stay fast. See:
`docs/ai-designer-color-hybrid.md`.

## Benefits Summary
* **Professional Design:** Inherits modern UX/UI from WonderBlocks instead of
  relying on AI-hallucinated layouts.
* **Theme Compliance:** Native blocks respect the active theme's `theme.json`
  typography and spacing.
* **Lower Token Usage:** Updating existing blocks requires fewer generative
  tokens than writing standard HTML from scratch.