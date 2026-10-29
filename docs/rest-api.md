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

### Webhook settings

Form managers edit `document.settings.webhooks` using the existing publish/draft routes;
see `docs/schema.md` for the definition and mapping constraints. Signing `secret` is
write-only. Empty/omitted input preserves the same webhook id's stored secret; responses
return `hasSecret` only, never plaintext or ciphertext. Client-supplied `secretEncrypted`
is ignored. Invalid publication returns a `webhook:<id>` error path and keeps an encrypted
draft. Duplication creates new independent secrets; discarding a draft restores published
secrets without creating a new version. Entry-only viewers do not receive webhook settings.
There is currently no test-send or resend endpoint; delivery is still pending.

### Multipart file fields

`POST /forms/{id}/submit` accepts native multipart files named `swf[field_id][]`, including
`swf[repeater_id][row][field_id][]`. The same signed submission token and spam protections apply;
there is no separate anonymous upload endpoint. Only a fully valid submission stores files.
The `file` type can enable multiple selection and configure extensions, per-file size and count.
See `docs/schema.md` for formats, host limits, public URL visibility and deletion behavior.

Stored entry data contains lists of `{name, url, size, type}` descriptors. Presented entry
nodes additionally contain `files: [{name, url}]` for rendering escaped links. Validation
responses contain field paths and messages, without local temporary file paths or download URLs.
