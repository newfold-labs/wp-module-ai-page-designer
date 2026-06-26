# Polling-Based Incremental Content Delivery

## Problem
Apache `mod_proxy_fcgi` on the shared hosting ignores FastCGI FLUSH packets from PHP-FPM, making SSE streaming through PHP impossible. Content arrives in 2 chunks.

## Solution
Replace the SSE streaming approach with a polling-based approach. PHP processes the Worker stream internally and writes chunks to storage. The frontend polls a REST endpoint every 1.5s for new chunks.

```
[Frontend] --POST /generate/poll/start--> [PHP] -> stores ID, returns { generation_id }
                                               └── fastcgi_finish_request() closes HTTP conn
                                               └── PHP continues in background:
                                                     └── cURL stream from Worker
                                                     └── each delta chunk -> stored in options
[Frontend] --GET /generate/poll/{id}--> [PHP] -> returns new chunks + status
[Frontend] --every 1.5s polls again--> [PHP] -> until status = 'completed'
[Frontend] --DELETE /generate/poll/{id}--> [PHP] -> cleans up stored options
```

## Files to Modify

### 1. Backend: `AIPageDesignerController.php`

Add three new REST routes:

#### Route 1: `POST /generate/poll/start`
- Authenticates user (same permission check)
- Generates unique `generation_id` via `wp_generate_uuid4()`
- Stores request data: `nfd_ai_gen_{id}_meta` = { status: 'pending', created_at: time() }
- Initializes chunks: `nfd_ai_gen_{id}_chunks` = []
- Returns `{ generation_id }`
- Calls `fastcgi_finish_request()` (if available) to close HTTP connection
- Calls `ignore_user_abort(true)` and `set_time_limit(0)`
- Starts Worker streaming via existing `AiClientWorker::stream_content()`
- In the callback, for each delta: fetches current chunks array, appends new text, updates option
- On completion: calls `build_response_payload()`, stores final result, updates status to 'completed'
- On error: updates status to 'error' with error message

#### Route 2: `GET /generate/poll/{generation_id}`
- Reads `nfd_ai_gen_{id}_meta` for status
- Reads `nfd_ai_gen_{id}_chunks` for chunks array
- Accepts `?offset=N` query param to return only chunks after index N
- Returns JSON: `{ status, chunks: [...new chunks since offset], offset: updated_offset, result: {...} }`
- Returns `result` (full `build_response_payload()` output) only when status = 'completed'

#### Route 3: `DELETE /generate/poll/{generation_id}`
- Deletes `nfd_ai_gen_{id}_meta` and `nfd_ai_gen_{id}_chunks` from options table
- Called by the frontend after receiving 'completed' or 'error' status
- Returns `{ success: true }`
- If the generation is still 'in_progress', returns 409 Conflict

### 2. Frontend: `api.ts` - `generateContentStream()` function

Replace the SSE `ReadableStream` approach with a polling loop that calls the start endpoint, polls for chunks, and deletes on completion.

### 3. Backend: `AiClientWorker.php`

No changes needed. The existing `stream_content()` and `stream_with_curl()` methods are reused as-is.

## Key Design Decisions

1. **Background processing**: Uses `fastcgi_finish_request()` (PHP-FPM) to close the HTTP connection while PHP continues streaming from the Worker. Falls back to synchronous blocking if not available.

2. **No cron cleanup**: Frontend deletes transients via `DELETE` endpoint on completion. Orphaned records use WordPress transients with auto-expiration.

3. **Chunk storage**: Uses WordPress options API with `get_option()` + `update_option()` for each chunk. Accepts rare dropped chunks under race conditions.

4. **Polling interval**: 1.5 seconds between polls, with max 120 polls (3 minutes timeout).

## Routes Summary

| Method | Route | Purpose |
|--------|-------|---------|
| POST | `/newfold-ai-page-designer/v1/generate/poll/start` | Start generation, return ID |
| GET | `/newfold-ai-page-designer/v1/generate/poll/{id}` | Poll for new chunks |
| DELETE | `/newfold-ai-page-designer/v1/generate/poll/{id}` | Clean up after completion |
