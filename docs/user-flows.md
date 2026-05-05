# AI Page Designer User Flows Documentation

This document outlines all possible user flows in the AI Page Designer and how they are handled by the system.

## Flow 1: New Page Generation

### User Flow
1. User starts with a blank page
2. User asks: "Create a landing page for a yoga studio"

### System Handling
- **Frontend**: `previewHtml` is empty/null
- **Context Sent**: Empty `current_markup` string to backend
- **Backend Logic**: 
  - Detects empty markup → checks for Base Layout blueprints
  - If blueprint matches: Sends blueprint under `--- BASE LAYOUT ---` 
  - If no blueprint: Sends raw prompt with no HTML context
- **AI Generation**: Creates WordPress blocks from scratch or modifies blueprint
- **Result**: Full page HTML generated and displayed
- **State**: `lastGeneratedHtml` stores the complete generated page

## Flow 2: Full Page Edits (No Block Selected)

### User Flow
1. User has existing page content
2. User makes page-wide request without selecting a block: "change the font size to 13px"

### System Handling
- **Frontend**: `selectedBlockIndex` is null
- **Context Logic**: `contextMarkup = previewHtml` (entire page)
- **Context Sent**: ENTIRE page HTML to backend
- **Backend Logic**: Wraps full HTML under `--- CURRENT TARGET LAYOUT ---`
- **Token Optimization**: If page > 8000 chars, backend "skeletonizes" it (keeps structure, removes content)
- **AI Generation**: Returns full modified page
- **Result**: Entire page updated with changes
- **State**: `lastGeneratedHtml` stores the new full page

### Follow-up Requests
- **Example**: User follows with "now make the text bold"
- **Context Logic**: `isFollowUpEdit` triggers (lastGeneratedHtml exists)
- **Context Sent**: Full page HTML again (since last generation was full page)
- **Result**: AI modifies entire page again

## Flow 3: Targeted Block Edits (Block Selected)

### User Flow  
1. User clicks to select a specific block (e.g., paragraph)
2. User asks: "Add 2 more paragraphs below this one"

### System Handling
- **Frontend**: `selectedBlockIndex` and `selectedBlockHtml` are set
- **Context Sent**: ONLY the selected block HTML (e.g., 200-500 characters)
- **Backend Logic**: Wraps selected HTML under `--- SELECTED BLOCK ---` or `--- CURRENT TARGET LAYOUT ---`
- **AI Generation**: Returns modified block(s) only
- **Frontend Integration**: 
  - Finds the wrapper element by `data-block-index`
  - Replaces wrapper innerHTML with AI response
  - Rebuilds full page HTML from DOM
  - Clears selection after successful edit
- **State**: `lastGeneratedHtml` stores the generated block content

## Flow 4: Stateful Follow-Up Edits (Smart Context)

### User Flow
1. User completes Flow 3 (adds paragraphs to selected block)
2. WITHOUT selecting anything, user asks: "Make the font color red for the new paragraphs"

### System Handling
- **Context Logic**: 
  - `selectedBlockIndex` is null (cleared after first edit)
  - `isFollowUpEdit` triggers: `lastGeneratedHtml` exists and is found in `previewHtml`
- **Context Sent**: ONLY the previously generated content (the 3 paragraphs)
- **AI Generation**: Modifies just those paragraphs
- **Frontend Integration**: 
  - Uses `previewHtml.replace(lastGeneratedHtml, newContent)`
  - Preserves all other page content
- **Benefits**: 
  - Huge token savings (sends ~500 chars instead of 5000+)
  - Perfect content preservation
  - Natural conversation flow

## Flow 5: Metadata-Only Requests

### User Flow
1. User has existing page content
2. User asks: "add an excerpt" or "create a title"

### System Handling
- **Frontend Detection**: Regex matches metadata keywords
- **Override Logic**: `isMetadataRequest` prevents follow-up edit detection
- **Context Sent**: EMPTY string (regardless of page content)
- **Backend Logic**: 
  - `is_metadata_only_request()` returns true
  - Adds instruction: "return only metadata using comment format"
- **AI Generation**: Returns only metadata comments (no block markup)
- **Frontend Handling**: 
  - `applyMetadataOnlyResponse()` extracts metadata
  - Updates meta fields (title, excerpt, summary)
  - Preserves all page content unchanged
- **Stream Handling**: If streaming corrupts preview, restores original HTML

## Flow 6: Content Removal

### User Flow
1. User selects a block
2. User asks: "remove this" or "delete this section"

### System Handling
- **Frontend Detection**: `isRemovalIntent()` matches removal keywords without content qualifiers
- **Fast Path**: No AI call needed
- **DOM Manipulation**: 
  - Finds wrapper by `selectedBlockIndex`
  - Removes wrapper from DOM
  - Rebuilds page HTML
- **Fallback**: If AI returns empty content for removal keywords, triggers same removal logic

## Flow 7: Image-Related Requests

### User Flow
1. User asks: "add images" or "replace images"

### System Handling
- **Backend Detection**: Checks for image-related keywords in prompt
- **Image Processing**: 
  - After AI generates content, backend detects image blocks
  - Calls Unsplash API with context from prompts + titles
  - Replaces placeholder URLs with real images
- **Image Preservation**: For non-image requests, `restore_image_urls()` preserves existing images

## Flow 8: Style-Only Changes

### User Flow
1. User asks: "change font color to blue" or "make text larger"

### System Handling
- **System Prompt**: Enhanced with comprehensive color application rules
- **AI Instructions**: 
  - Apply to ALL text-bearing elements (paragraphs, headings, spans, lists, buttons)
  - Handle nested elements (columns, groups, covers)
  - Verify no elements were missed
- **Gutenberg Compliance**: 
  - Updates both block JSON attributes and HTML inline styles
  - Escapes CSS custom properties in JSON (`\u002d\u002d` for `--`)
  - Never uses `!important` (breaks Gutenberg validation)

## Flow 9: Error Handling & Edge Cases

### User Flow
Various error conditions and edge cases

### System Handling
- **Empty AI Response**: Shows "No response generated" message
- **Streaming Errors**: Captures and displays error messages
- **Missing Block Wrapper**: Falls back to `setPreviewHtml(previewHtml)`
- **Stale Response IDs**: Clears stored conversation and retries
- **Large Pages**: Backend automatically skeletonizes content over token limits
- **Malformed Blocks**: `extractHtml()` cleans markdown artifacts
- **Image Service Failures**: Preserves existing images, shows service unavailable message

## State Management

### Key State Variables
- `selectedBlockIndex` / `selectedBlockHtml`: Currently selected block
- `lastGeneratedHtml`: Last AI-generated content (enables follow-up edits)
- `previewHtml`: Full page HTML
- `isLoading`: Prevents concurrent requests
- `messages`: Chat history (display only - not sent to AI)

### State Transitions
- **Selection**: `selectedBlockIndex` set → cleared after targeted edit
- **Follow-up**: `lastGeneratedHtml` set after generation → cleared when user selects new block
- **Metadata**: All content state preserved, only meta fields updated

## Token Optimization Strategies

1. **Targeted Edits**: Send only selected block (~200-500 chars vs 5000+ full page)
2. **Follow-up Detection**: Send only previously generated content
3. **Metadata Requests**: Send empty context
4. **Skeletonization**: Remove content from large pages, keep structure
5. **Blueprint System**: Reuse proven layouts instead of generating from scratch
6. **Image Handling**: Process images after AI generation to avoid sending image URLs