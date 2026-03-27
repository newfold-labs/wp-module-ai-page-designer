# Response Chaining Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement server-side conversation chaining for AI Page Designer by storing `response_id`, sending `previous_response_id`, and returning conversation identifiers to clients.

**Architecture:** Add conversation-key resolution + transient storage in the REST controller, extend the AI client to send `store: true` + `previous_response_id`, and return `response_id`/conversation identifiers in the REST response. Validation and error handling follow existing REST patterns.

**Tech Stack:** WordPress PHP (REST API, transients), existing AIPageDesigner module services.

---

## File/Component Map

- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/AIPageDesignerController.php`
  - Determine conversation key (`post-{id}` or `conv-{uuid}`)
  - Load/store `response_id` in transient
  - Validate `context.post_id` / `context.conversation_id`
  - Include `conversation_id`, `conversation_key`, `response_id` in response
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/includes/Services/AiClient.php`
  - Accept `previous_response_id` and `store: true`
  - Return `response_id` alongside content
  - Extract `response_id` from API response
- Modify (optional): `vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/AIPageDesignerController.php` response shape tests or manual verification notes

---

### Task 1: Extend AI client to send chaining inputs and return response_id

**Files:**
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/includes/Services/AiClient.php`

- [ ] **Step 1: Update generate_content signature**
  
  Accept an optional options array:
  - `previous_response_id` (string|null)

- [ ] **Step 2: Include new fields in inputPayload**
  
  Add to `inputPayload`:
  - `previous_response_id` when present
  - `store: true` always

- [ ] **Step 3: Return content + response_id**
  
  Update `generate_content()` return to include:
  - `content`
  - `response_id` (prefer `responseMetadata.response_id`, fallback to `outputPayload.id`)

- [ ] **Step 4: Manual test**
  
  Call the existing flow and verify AI request body includes `store: true` and `previous_response_id` when provided.

- [ ] **Step 5: Commit**
  
  ```bash
  git add vendor/newfold-labs/wp-module-ai-page-designer/includes/Services/AiClient.php
  git commit -m "feat: pass previous_response_id and return response_id"
  ```

---

### Task 2: Add conversation key resolution + transient storage in REST controller

**Files:**
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/AIPageDesignerController.php`

- [ ] **Step 1: Write/define validation helpers (no tests in repo)**
  
  Add private helpers (planned):
  - `get_conversation_key( array $context ): array` returning `conversation_key`, `conversation_id` (nullable)
  - `load_previous_response_id( string $conversation_key ): ?string`
  - `store_response_id( string $conversation_key, string $response_id ): void`
  - `is_valid_uuid_v4( string $value ): bool`

- [ ] **Step 2: Integrate conversation-key selection**
  
  In `generate_content()`:
  - Extract `context` and compute `conversation_key`.
  - If `post_id` is present, use `post-{post_id}` (ignore `conversation_id`).
  - If `conversation_id` present without `post_id`, validate UUID v4 or return 400.
  - If none, generate UUID v4 and use `conv-{uuid}`.

- [ ] **Step 3: Load previous response_id**
  
  If transient `nfd_ai_pd_conv_{conversation_key}` exists, pass it to AI client as `previous_response_id`.

- [ ] **Step 4: Persist response_id**
  
  After AI success, validate non-empty `response_id`. If missing, return server error and do not update transient. If present, store it back to the transient with TTL 24 hours.

- [ ] **Step 5: Update REST response payload**
  
  Include:
  - `response_id`
  - `conversation_key`
  - `conversation_id` only for `conv-{uuid}` flow (raw UUID).

- [ ] **Step 6: Manual test (until automated tests exist)**
  
  Run: `wp eval-file` or REST request via UI/HTTP client
  - Expect: first request returns `conversation_id` + `response_id`, second request reuses chain.
  - For post flow, expect no `conversation_id`.
  - Force an AI error (e.g., invalid model) and confirm transient is unchanged.

- [ ] **Step 7: Commit**
  
  ```bash
  git add vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/AIPageDesignerController.php
  git commit -m "feat: add conversation key and transient storage"
  ```

---

### Task 3: Validate request shapes and error handling

**Files:**
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/AIPageDesignerController.php`

- [ ] **Step 1: Add REST arg validation for context**
  
  - `context.post_id` must be integer when provided.
  - `context.conversation_id` must be UUID v4 **only when** `post_id` is absent.
  - If `post_id` is present, ignore `conversation_id` entirely (no validation, no error).
  - If invalid `conversation_id` with no `post_id`, return 400.
  - Reuse the same UUID helper from Task 2 to keep a single source of truth.
  - Prefer a single validation path (e.g., validate the `context` object as a whole) to avoid REST arg validation diverging from inline checks.

- [ ] **Step 2: Ensure post permissions**
  
  - If `post_id` provided and user cannot edit, return 403/404 per existing patterns.

- [ ] **Step 3: Manual test**
  
  - Invalid UUID should return 400.
  - Invalid post_id or insufficient permissions should return 403/404.

- [ ] **Step 4: Commit**
  
  ```bash
  git add vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/AIPageDesignerController.php
  git commit -m "feat: validate conversation inputs and permissions"
  ```

---

## Plan Review Loop

After plan approval, run a plan review subagent using:
`/Users/abhijit.bhatnagar/.cursor/plugins/cache/cursor-public/superpowers/8ea39819eed74fe2a0338e71789f06b30e953041/skills/writing-plans/plan-document-reviewer-prompt.md`

## Execution Handoff

Plan complete and saved to `vendor/newfold-labs/wp-module-ai-page-designer/docs/plans/2026-03-24-response-chaining-plan.md`. Two execution options:

1. **Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration
2. **Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?
