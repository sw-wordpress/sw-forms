# REST API (`/wp-json/swforms/v1/`)

Cookie authentication with the `wp_rest` nonce (`X-WP-Nonce`). Errors are `WP_Error` objects
(`code`, `message`, `data.status`).

| Method | Route | Capability | Notes |
|--------|-------|------------|-------|
| GET | `/forms` | `swf_manage_forms` or `swf_view_entries` | List with entry counters. |
| POST | `/forms` | `swf_manage_forms` | `{title}` → form detail (201). |
| GET | `/forms/{id}` | `swf_manage_forms` or `swf_view_entries` | Detail: editable `document` (draft or published), `publishedKeys`, `previewUrl`, `shortcode`. |
| PUT | `/forms/{id}` | `swf_manage_forms` | `{title, document}`: validate and publish. 422 `swf_invalid_form` with `data.errors[] = {path, code, message}`; the invalid document is kept as draft. |
| PATCH | `/forms/{id}` | `swf_manage_forms` | `{status: active|inactive}` |
| DELETE | `/forms/{id}` | `swf_manage_forms` | Deletes versions, entries and logs. |
| PUT | `/forms/{id}/draft` | `swf_manage_forms` | Autosave without validation. |
| DELETE | `/forms/{id}/draft` | `swf_manage_forms` | Discard the draft. |
| POST | `/forms/{id}/duplicate` | `swf_manage_forms` | |
| GET | `/field-types` | `swf_manage_forms` | Type definitions incl. settings schema. |
| GET | `/forms/{id}/entries` | `swf_view_entries` | `filter` (inbox, unread, starred, spam, trash), `search`, `page`, `per_page` (≤ 100), `order`. Returns `items`, `total`, `pages`, `counts`, `columns`. |
| GET | `/entries/{id}` | `swf_view_entries` | Entry, labelled `fields` tree (from its form version), `log`. |
| PATCH | `/entries/{id}` | `swf_manage_entries` | `{status?, starred?}` |
| DELETE | `/entries/{id}` | `swf_manage_entries` | |
| POST | `/entries/bulk` | `swf_manage_entries` | `{ids[], action}`: read, unread, star, unstar, spam, trash, restore, delete. |
| GET/PUT | `/settings` | `swf_settings` | Global settings. |
| POST | `/forms/{id}/submit` | public | Form fields as multipart/urlencoded (`swf[...]`, `swf_token`, honeypot). 200 `success`, 422 `invalid` with `errors` per path, 400/404/429 `error` with `code` (`expired`, `too_fast`, `duplicate`, `rate_limited`, ...). `swf_preview=1` (needs `swf_manage_forms`) validates the draft only. |

Submission token: `admin-ajax.php?action=swf_token&form={id}` returns `{token, nonce}` (nonce only
for logged-in users). Anonymous submissions use the signed token instead of a nonce so that
cached pages keep working.
