# Response Chaining for AI Page Designer

## Overview
Add server-managed conversation chaining for the AI Page Designer AI response API calls by storing and reusing `previous_response_id`. The server owns the conversation key and persists the latest response ID in a transient. Clients may pass a `conversation_id`, but the server will generate one if missing.

## Goals
- Automatically chain AI requests via `previous_response_id`.
- Support both existing pages/posts and new (unsaved) pages.
- Keep storage lightweight and expiring by default.

## Non-Goals
- Client-side key generation.
- Custom database tables or permanent storage.
- Streaming endpoint support (non-streaming only).

## Data Flow
1. REST request arrives at `newfold-ai-page-designer/v1/generate` with `messages` and optional `context`.
2. Server determines `conversation_key` (namespaced to avoid collisions):
   - If `context.post_id` is present, use `post-{post_id}`.
   - Else if `context.conversation_id` is provided, it must be a raw UUID v4; use `conv-{conversation_id}`.
   - Else generate a new `conversation_id` server-side (UUID v4) and use `conv-{conversation_id}`.
   - If both `post_id` and `conversation_id` are present, `post_id` wins and `conversation_id` is ignored (no validation).
3. Server looks up transient `nfd_ai_pd_conv_{conversation_key}` for stored `response_id`.
4. If present, include `previous_response_id` in `inputPayload`.
5. Always set `store: true` for responses.
6. On success, extract `response_id` from AI response (prefer `responseMetadata.response_id`, fallback to `outputPayload.id`) and persist back to transient.
7. Return `response_id` and `conversation_key` in API response. Include `conversation_id` only for the new-page (`conv-{uuid}`) flow.
8. When `previous_response_id` is present, client may send only the new user message (plus required system/context). When it is not present, client sends the full `messages` array.

## Storage
- Transient name: `nfd_ai_pd_conv_{conversation_key}`.
- Value: latest AI `response_id`.
- TTL: 24 hours (configurable later if needed).
- Conversation scope: per post (shared across editors) or per new-page conversation ID. No user-specific namespacing in v1.

## API Contract Changes
### Request
Add optional fields under `context`:
- `post_id` (int) for existing pages/posts.
- `conversation_id` (string, UUID v4) for new page flow.
  - If server generates a `conversation_id`, the client must echo the same raw UUID back on subsequent requests to continue the same chain.

### Response
Include:
- `conversation_id` (string): raw UUID for new page flow (not prefixed); omitted for post_id flow.
- `conversation_key` (string): internal key used for this request (`post-{id}` or `conv-{uuid}`). This is for debugging/telemetry and not required for client chaining.
- `response_id` (string): latest AI response ID.

## Error Handling
- If AI call fails, do not update transient.
- If no `response_id` is present in the AI response, return a server error and do not update transient.
- If `conversation_id` is provided but fails validation, return a 400 error and do not call the AI service.
- If `post_id` is provided but invalid or the user cannot edit it, return a 403/404 (use existing REST permission/validation patterns) and do not call the AI service.

## Security and Validation
- Continue existing capability checks in REST controller.
- Validate `conversation_id` format (UUID v4, no prefix) and apply `conv-` prefix internally.
- Avoid exposing raw AI service errors beyond existing handling.

## Concurrency
- If multiple requests are in-flight for the same `conversation_key`, transient updates are last-write-wins. This is acceptable for v1.

## Testing
- New page flow:
  - First request returns `conversation_id` + `response_id`.
  - Second request with same `conversation_id` reuses `previous_response_id`.
  - When `previous_response_id` is present, request may include only the new message.
- Existing page flow:
  - Use `post_id` to persist and reuse `response_id`.
  - Response omits `conversation_id` and includes `conversation_key` as `post-{id}`.
- Error path:
  - AI error does not mutate transient.
  - Invalid `conversation_id` returns 400 and does not call AI.
