---
name: ai-page-designer-refactor
overview: Analyze the AI Page Designer module (PHP + React) and outline a refactor plan that preserves current REST routes/response shapes and build tooling while improving maintainability and testability.
todos:
  - id: backend-services
    content: Draft service boundaries and move controller logic
    status: completed
  - id: capability-gate
    content: Unify hasAISiteGen checks via helper
    status: completed
  - id: frontend-split
    content: Extract App.tsx into components/hooks
    status: completed
  - id: css-organize
    content: Reorganize styles.css sections, no tooling change
    status: completed
  - id: tests-checklist
    content: Add minimal tests + manual verification list
    status: completed
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

- [x] Add internal service classes (under `includes/Services/` or `includes/Domain/`) with narrow responsibilities:
- [x] Add `CapabilityGate` (single source of truth for `hasAISiteGen` checks).
- [x] Add `PromptBuilder` (system prompt + theme context + base/current markup injection).
- [x] Add `AiClient` (Hiive JWT exchange + API request/response decoding, including timeout behavior).
- [x] Add `ImageService` (Unsplash query, caching, replacement, URL mapping).
- [x] Add `BlockMarkupSanitizer` (title extraction and block-comment repair).
- [x] Add `PatternLayoutProvider` (pattern selection and minification).
- [x] Keep `AIPageDesignerController` as HTTP boundary: validate input, call services, map errors to `WP_Error`, shape responses.
- [x] Consolidate capability checks across `bootstrap.php`, `AIPageDesigner`, and controller into `CapabilityGate`.
- [x] Preserve response payloads and error codes currently returned to the client.

## Backend behavior-preserving cleanup

- [x] Extract fast-path logic for image replacements and theme changes into private helpers or a `FastPathHandler`.
- [ ] Centralize logging and error formatting for AI calls to reduce repeated branches.
- [x] Add small unit-testable helpers for:
- [x] Prompt assembly.
- [x] `sanitize_block_content()` behavior.
- [x] Unsplash query sanitization.
- [x] Block image replacement mapping.
- [x] Ensure `get_jwt_token()` uses the incoming token and isolate it in `AiClient`.

## Frontend componentization (no tooling change)

- [x] Split `App.tsx` into feature-focused components:
- [x] `components/ChatPanel` (left designer panel)
- [x] `components/DesignerTabs`
- [x] `components/DashboardView`
- [x] `components/PreviewFrame`
- [x] `components/PublishModal`
- [x] `components/HistoryDrawer`
- [x] Extract hooks:
- [x] `hooks/useSiteContent`
- [x] `hooks/usePreviewIframe`
- [x] `hooks/useAiConversation`
- [x] `hooks/usePublishFlow`
- [x] `hooks/useBlockSelection`
- [x] Keep API calls and URL shapes identical; move them into a thin `api.ts`.
- [x] Keep global `window.AIPageDesignerApp` integration and `src/index.tsx` mount behavior unchanged.

## CSS organization without tooling changes

- [x] Keep `styles.css` as a single output file.
- [x] Group styles by feature (layout, sidebar, chat, preview, modal, history) with section headers.
- [x] Prefer existing `:root` variables to reduce duplication.

## Validation and safety checks

- [x] Add or update lightweight tests (PHP unit tests for helpers if available; frontend tests optional).
- [x] Run module build and PHP lint verification.
- [ ] Run a manual test checklist:
- [ ] AI generation.
- [ ] Fast-path image swap.
- [ ] Theme change.
- [ ] Content CRUD routes.
- [ ] Preview iframe selection.
- [ ] Publish flow.
- [ ] History.

## Expected outcomes

- [x] `AIPageDesignerController` becomes a thin orchestrator with smaller helpers.
- [x] Reusable services reduce duplication and make changes safer.
- [x] `App.tsx` becomes a composable set of components and hooks, improving readability and maintainability.
