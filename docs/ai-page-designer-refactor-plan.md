---
name: ai-page-designer-refactor
overview: Analyze the AI Page Designer module (PHP + React) and outline a refactor plan that preserves current REST routes/response shapes and build tooling while improving maintainability and testability.
todos:
  - id: backend-services
    content: Draft service boundaries and move controller logic
    status: pending
  - id: capability-gate
    content: Unify hasAISiteGen checks via helper
    status: pending
  - id: frontend-split
    content: Extract App.tsx into components/hooks
    status: pending
  - id: css-organize
    content: Reorganize styles.css sections, no tooling change
    status: pending
  - id: tests-checklist
    content: Add minimal tests + manual verification list
    status: pending
isProject: false
---

# AI Page Designer Refactor Plan

## Context and targets (reference)

- [ ] Reference primary module entry and capability gating in `[/Users/abhijit.bhatnagar/Local Sites/wordpress-netsol-local/app/public/wp-content/plugins/wp-plugin-web/vendor/newfold-labs/wp-module-ai-page-designer/bootstrap.php](/Users/abhijit.bhatnagar/Local%20Sites/wordpress-netsol-local/app/public/wp-content/plugins/wp-plugin-web/vendor/newfold-labs/wp-module-ai-page-designer/bootstrap.php)` and `[/Users/abhijit.bhatnagar/Local Sites/wordpress-netsol-local/app/public/wp-content/plugins/wp-plugin-web/vendor/newfold-labs/wp-module-ai-page-designer/includes/AIPageDesigner.php](/Users/abhijit.bhatnagar/Local%20Sites/wordpress-netsol-local/app/public/wp-content/plugins/wp-plugin-web/vendor/newfold-labs/wp-module-ai-page-designer/includes/AIPageDesigner.php)` (current `hasAISiteGen` checks and asset setup).
- [ ] Reference the complexity hotspot: `AIPageDesignerController::generate_content()` in `[/Users/abhijit.bhatnagar/Local Sites/wordpress-netsol-local/app/public/wp-content/plugins/wp-plugin-web/vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/AIPageDesignerController.php](/Users/abhijit.bhatnagar/Local%20Sites/wordpress-netsol-local/app/public/wp-content/plugins/wp-plugin-web/vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/AIPageDesignerController.php)`.
- [ ] Reference the frontend monolith: `[/Users/abhijit.bhatnagar/Local Sites/wordpress-netsol-local/app/public/wp-content/plugins/wp-plugin-web/vendor/newfold-labs/wp-module-ai-page-designer/src/App.tsx](/Users/abhijit.bhatnagar/Local%20Sites/wordpress-netsol-local/app/public/wp-content/plugins/wp-plugin-web/vendor/newfold-labs/wp-module-ai-page-designer/src/App.tsx)`.
- [ ] Reference centralized styles at `[/Users/abhijit.bhatnagar/Local Sites/wordpress-netsol-local/app/public/wp-content/plugins/wp-plugin-web/vendor/newfold-labs/wp-module-ai-page-designer/src/styles.css](/Users/abhijit.bhatnagar/Local%20Sites/wordpress-netsol-local/app/public/wp-content/plugins/wp-plugin-web/vendor/newfold-labs/wp-module-ai-page-designer/src/styles.css)`.

## Refactor goals (constraints)

- [ ] Keep current REST routes and response shapes unchanged.
- [ ] Keep build tooling unchanged (no bundler or script changes).
- [ ] Improve separation of concerns, reduce method length/duplication, and make the code testable.

## Backend refactor structure (no API changes)

- [ ] Add internal service classes (under `includes/Services/` or `includes/Domain/`) with narrow responsibilities:
- [ ] Add `CapabilityGate` (single source of truth for `hasAISiteGen` checks).
- [ ] Add `PromptBuilder` (system prompt + theme context + base/current markup injection).
- [ ] Add `AiClient` (Hiive JWT exchange + API request/response decoding, including timeout behavior).
- [ ] Add `ImageService` (Unsplash query, caching, replacement, URL mapping).
- [ ] Add `BlockMarkupSanitizer` (title extraction and block-comment repair).
- [ ] Add `PatternLayoutProvider` (pattern selection and minification).
- [ ] Keep `AIPageDesignerController` as HTTP boundary: validate input, call services, map errors to `WP_Error`, shape responses.
- [ ] Consolidate capability checks across `bootstrap.php`, `AIPageDesigner`, and controller into `CapabilityGate`.
- [ ] Preserve response payloads and error codes currently returned to the client.

## Backend behavior-preserving cleanup

- [ ] Extract fast-path logic for image replacements and theme changes into private helpers or a `FastPathHandler`.
- [ ] Centralize logging and error formatting for AI calls to reduce repeated branches.
- [ ] Add small unit-testable helpers for:
- [ ] Prompt assembly.
- [ ] `sanitize_block_content()` behavior.
- [ ] Unsplash query sanitization.
- [ ] Block image replacement mapping.
- [ ] Ensure `get_jwt_token()` uses the incoming token and isolate it in `AiClient`.

## Frontend componentization (no tooling change)

- [ ] Split `App.tsx` into feature-focused components:
- [ ] `components/ChatPanel`
- [ ] `components/PreviewFrame`
- [ ] `components/Sidebar`
- [ ] `components/PublishModal`
- [ ] `components/HistoryDrawer`
- [ ] Extract hooks:
- [ ] `hooks/useSiteContent`
- [ ] `hooks/usePreviewIframe`
- [ ] `hooks/useAiConversation`
- [ ] `hooks/usePublishFlow`
- [ ] `hooks/useBlockSelection`
- [ ] Keep API calls and URL shapes identical; move them into a thin `api.ts`.
- [ ] Keep global `window.AIPageDesignerApp` integration and `src/index.tsx` mount behavior unchanged.

## CSS organization without tooling changes

- [ ] Keep `styles.css` as a single output file.
- [ ] Group styles by feature (layout, sidebar, chat, preview, modal, history) with section headers.
- [ ] Prefer existing `:root` variables to reduce duplication.

## Validation and safety checks

- [ ] Add or update lightweight tests (PHP unit tests for helpers if available; frontend tests optional).
- [ ] Run a manual test checklist:
- [ ] AI generation.
- [ ] Fast-path image swap.
- [ ] Theme change.
- [ ] Content CRUD routes.
- [ ] Preview iframe selection.
- [ ] Publish flow.
- [ ] History.

## Expected outcomes

- [ ] `AIPageDesignerController` becomes a thin orchestrator with smaller helpers.
- [ ] Reusable services reduce duplication and make changes safer.
- [ ] `App.tsx` becomes a composable set of components and hooks, improving readability and maintainability.
