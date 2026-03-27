# AI Page Designer Meta Strip Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an editable meta strip (title, excerpt, featured image) for existing items in the AI Page Designer, saved only when “Update in WordPress” is clicked.

**Architecture:** Extend the module REST payload to include excerpt and featured image metadata, add a meta strip component above the chat+preview split, and persist meta edits through the existing update endpoint. Media picker uses `wp.media` with image-only selection and safe fallbacks.

**Tech Stack:** PHP (WordPress REST), React/TypeScript, wp.media, CSS.

---

## File structure changes

**Modify (PHP):**
- `vendor/newfold-labs/wp-module-ai-page-designer/includes/AIPageDesigner.php` (enqueue media picker scripts)
- `vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/WordPressProxyController.php` (response fields + update handling)

**Modify (TS/React):**
- `vendor/newfold-labs/wp-module-ai-page-designer/src/types.ts` (WPItem shape)
- `vendor/newfold-labs/wp-module-ai-page-designer/src/api.ts` (update payload)
- `vendor/newfold-labs/wp-module-ai-page-designer/src/App.tsx` (meta strip placement + state)
- `vendor/newfold-labs/wp-module-ai-page-designer/src/components/PreviewFrame.tsx` (or new component for meta strip)
- `vendor/newfold-labs/wp-module-ai-page-designer/src/hooks/usePublishFlow.ts` (include meta in update)
- `vendor/newfold-labs/wp-module-ai-page-designer/src/styles.css` (meta strip styles)

**Create (TS/React):**
- `vendor/newfold-labs/wp-module-ai-page-designer/src/components/MetaStrip.tsx`

**Tests:**
- `tests/phpunit/WordPressProxyControllerTest.php` (new REST response/update tests)

---

## Task 1: REST response fields for excerpt & featured image

**Files:**
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/WordPressProxyController.php`
- Test: `tests/phpunit/WordPressProxyControllerTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/phpunit/WordPressProxyControllerTest.php` with WordPress test case to validate list/single response shape:

```php
<?php

class WordPressProxyControllerTest extends WP_UnitTestCase {
	public function test_list_content_includes_excerpt_and_featured_image() {
		$post_id = self::factory()->post->create( array(
			'post_title'   => 'Title',
			'post_excerpt' => 'Excerpt text',
			'post_content' => '<p>Body</p>',
		) );

		$attachment_id = self::factory()->attachment->create_upload_object( __DIR__ . '/fixtures/test-image.jpg', $post_id );
		set_post_thumbnail( $post_id, $attachment_id );

		$controller = new NewfoldLabs\WP\Module\AIPageDesigner\RestApi\WordPressProxyController();
		$request = new WP_REST_Request( 'GET', '/newfold-ai-page-designer/v1/content/posts' );
		$request->set_param( 'type', 'posts' );

		$response = $controller->list_content( $request );
		$data = $response->get_data();

		$this->assertNotEmpty( $data );
		$item = $data[0];
		$this->assertArrayHasKey( 'excerpt', $item );
		$this->assertArrayHasKey( 'featured_media', $item );
		$this->assertArrayHasKey( 'featured_image_url', $item );
		$this->assertSame( 'Excerpt text', $item['excerpt']['raw'] );
		$this->assertNotEmpty( $item['featured_image_url'] );
	}
}
```

- [ ] **Step 2: Run tests to verify failure**

Run: `./vendor/bin/phpunit --filter WordPressProxyControllerTest`
Expected: FAIL (missing keys).

- [ ] **Step 3: Implement minimal response fields**

In `list_content()` and `get_content()`:
- Add `excerpt` with `raw` and `rendered`.
- Add `featured_media` from `get_post_thumbnail_id( $post->ID )`.
- Add `featured_image_url` using `wp_get_attachment_image_src()` with size fallback (`medium`, `large`, `full`).

- [ ] **Step 4: Run tests to verify pass**

Run: `./vendor/bin/phpunit --filter WordPressProxyControllerTest`
Expected: PASS.

- [ ] **Step 5: Refactor**

Extract a small private helper inside the controller:
```php
private function get_featured_image_url( $post_id ) { ... }
```

- [ ] **Step 6: Re-run tests**

Run: `./vendor/bin/phpunit --filter WordPressProxyControllerTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add tests/phpunit/WordPressProxyControllerTest.php vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/WordPressProxyController.php
git commit -m "$(cat <<'EOF'
test: cover meta fields in AI content REST

EOF
)"
```

---

## Task 2: Update endpoint supports title/excerpt/featured image

**Files:**
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/WordPressProxyController.php`
- Test: `tests/phpunit/WordPressProxyControllerTest.php`

- [ ] **Step 1: Write failing test**

Add test for update request:

```php
public function test_update_content_updates_meta_fields() {
	$post_id = self::factory()->post->create( array(
		'post_title'   => 'Old',
		'post_excerpt' => 'Old excerpt',
		'post_content' => '<p>Body</p>',
	) );

	$attachment_id = self::factory()->attachment->create_upload_object( __DIR__ . '/fixtures/test-image.jpg', $post_id );

	$controller = new NewfoldLabs\WP\Module\AIPageDesigner\RestApi\WordPressProxyController();
	$request = new WP_REST_Request( 'POST', "/newfold-ai-page-designer/v1/content/posts/$post_id" );
	$request->set_param( 'id', $post_id );
	$request->set_param( 'type', 'posts' );
	$request->set_param( 'title', 'New Title' );
	$request->set_param( 'excerpt', 'New excerpt' );
	$request->set_param( 'featured_media', $attachment_id );

	$response = $controller->update_content( $request );
	$this->assertSame( 200, $response->get_status() );

	$post = get_post( $post_id );
	$this->assertSame( 'New Title', $post->post_title );
	$this->assertSame( 'New excerpt', $post->post_excerpt );
	$this->assertSame( $attachment_id, get_post_thumbnail_id( $post_id ) );
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `./vendor/bin/phpunit --filter WordPressProxyControllerTest::test_update_content_updates_meta_fields`
Expected: FAIL (meta not updated).

- [ ] **Step 3: Implement minimal update support**

In `update_content()`:
- Accept `excerpt` param and set `post_excerpt` using `sanitize_text_field`.
- Accept `featured_media` param:
  - `0` → `delete_post_thumbnail( $id )`
  - else `set_post_thumbnail( $id, absint( $featured_media ) )`
- Ensure response includes updated meta fields in the payload.

- [ ] **Step 4: Run test to verify pass**

Run: `./vendor/bin/phpunit --filter WordPressProxyControllerTest::test_update_content_updates_meta_fields`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/phpunit/WordPressProxyControllerTest.php vendor/newfold-labs/wp-module-ai-page-designer/includes/RestApi/WordPressProxyController.php
git commit -m "$(cat <<'EOF'
feat: update AI content meta fields on save

EOF
)"
```

---

## Task 3: Enqueue media picker scripts for the module UI

**Files:**
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/includes/AIPageDesigner.php`

- [ ] **Step 1: Add failing smoke test (manual)**

Manual check requirement: open AI Page Designer UI and verify media picker opens.

- [ ] **Step 2: Implement enqueue**

Add `wp_enqueue_media()` inside `enqueue_assets()` when the module loads.

- [ ] **Step 3: Manual verification**

Open module UI and confirm the media modal opens on a test button (will be added in Task 5).

- [ ] **Step 4: Commit**

```bash
git add vendor/newfold-labs/wp-module-ai-page-designer/includes/AIPageDesigner.php
git commit -m "$(cat <<'EOF'
feat: enqueue media scripts for AI designer

EOF
)"
```

---

## Task 4: Extend TS types & update payloads

**Files:**
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/src/types.ts`
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/src/api.ts`
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/src/hooks/usePublishFlow.ts`

- [ ] **Step 1: Write failing type test (compile)**

Add temporary usage in a local branch (or expect TS error) to ensure `WPItem` lacks new fields.

- [ ] **Step 2: Update types**

Extend `WPItem`:
```ts
excerpt?: { rendered?: string; raw?: string };
featured_media?: number;
featured_image_url?: string;
```

- [ ] **Step 3: Update updateExistingItem() payload**

Update `updateExistingItem()` to accept meta fields and pass:
```ts
data: { content, title, excerpt, featured_media }
```

- [ ] **Step 4: Update usePublishFlow**

When updating existing items, pass current meta state along with content.

- [ ] **Step 5: Build/Typecheck**

Run: `npm run build` in `vendor/newfold-labs/wp-module-ai-page-designer`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add vendor/newfold-labs/wp-module-ai-page-designer/src/types.ts vendor/newfold-labs/wp-module-ai-page-designer/src/api.ts vendor/newfold-labs/wp-module-ai-page-designer/src/hooks/usePublishFlow.ts
git commit -m "$(cat <<'EOF'
feat: add meta fields to AI designer client types

EOF
)"
```

---

## Task 5: Meta strip component (UI + state)

**Files:**
- Create: `vendor/newfold-labs/wp-module-ai-page-designer/src/components/MetaStrip.tsx`
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/src/App.tsx`
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/src/components/PreviewFrame.tsx` (if needed)
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/src/styles.css`

- [ ] **Step 1: Write failing UI test or manual checklist**

Manual checklist:
- Meta strip appears above chat+preview only for existing items.
- Title/excerpt inputs editable.
- Featured image shows placeholder when absent.

- [ ] **Step 2: Implement MetaStrip component**

Component props:
```ts
type MetaStripProps = {
  visible: boolean;
  title: string;
  excerpt: string;
  featuredImageUrl: string | null;
  featuredMediaId: number | null;
  canUseMedia: boolean;
  onChangeTitle: (value: string) => void;
  onChangeExcerpt: (value: string) => void;
  onPickImage: () => void;
  onRemoveImage: () => void;
};
```

Uses `wp.media` for `onPickImage`.

- [ ] **Step 3: Wire state in App**

Add local state in `App.tsx`:
- `metaTitle`, `metaExcerpt`, `metaFeaturedMediaId`, `metaFeaturedImageUrl`.
- Initialize from `selectedItem` on selection.
- Reset on dashboard/new.

- [ ] **Step 4: Insert MetaStrip**

Add above `.ai-designer-content` so it spans full width.

- [ ] **Step 5: Style**

Add CSS for:
- Full-width strip, light background, borders.
- Responsive wrap on narrow width.
- Thumbnail sizing and buttons.

- [ ] **Step 6: Build**

Run: `npm run build` in module folder.

- [ ] **Step 7: Commit**

```bash
git add vendor/newfold-labs/wp-module-ai-page-designer/src/components/MetaStrip.tsx vendor/newfold-labs/wp-module-ai-page-designer/src/App.tsx vendor/newfold-labs/wp-module-ai-page-designer/src/styles.css
git commit -m "$(cat <<'EOF'
feat: add editable meta strip to AI designer

EOF
)"
```

---

## Task 6: Update flow integration

**Files:**
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/src/hooks/usePublishFlow.ts`
- Modify: `vendor/newfold-labs/wp-module-ai-page-designer/src/App.tsx`

- [ ] **Step 1: Ensure Update includes meta state**

When `handleReplaceItem()` runs, include `metaTitle`, `metaExcerpt`, and `featuredMediaId` (fallback to selectedItem values if unchanged).

- [ ] **Step 2: After update, refresh state**

Use update response payload to refresh meta state.

- [ ] **Step 3: Manual verification**

Edit title/excerpt/featured image, click Update, refresh UI and confirm values persist.

- [ ] **Step 4: Commit**

```bash
git add vendor/newfold-labs/wp-module-ai-page-designer/src/hooks/usePublishFlow.ts vendor/newfold-labs/wp-module-ai-page-designer/src/App.tsx
git commit -m "$(cat <<'EOF'
feat: persist AI meta strip fields on update

EOF
)"
```

---

## Final verification

- [ ] Run: `./vendor/bin/phpunit --filter WordPressProxyControllerTest`
- [ ] Run: `npm run build` in `vendor/newfold-labs/wp-module-ai-page-designer`
- [ ] Manual: open AI Page Designer → select existing post → edit title/excerpt/featured image → Update → verify persisted

---

## Notes
- If `wp.media` is not present, the picker button should be disabled and show helper text.
- For post types without `thumbnail` support, hide image controls and show a short note.
