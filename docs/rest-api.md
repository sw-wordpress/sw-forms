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
| POST | `/forms/{id}/webhooks/{webhook}/test` | `swf_manage_forms` | Save draft first; sends synthetic `webhook.test` data synchronously, including for disabled webhooks. Returns `{success, code, duration, message}`; destination failures use `success: false`. |
| POST | `/entries/{id}/webhooks/{delivery}/resend` | both `swf_manage_forms` and `swf_manage_entries` | Requeue an existing terminal delivery (202); pending/running returns 409. Uses the original signed request/event id. |
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
Entry detail includes `webhooks[] = {id, webhookId, eventId, status, attempts, httpCode,
durationMs, message, nextAttemptAt}`; snapshots/headers/signatures are never returned there.
Temporary entries used for delivery when storage is disabled return 404 for direct entry
routes and are omitted from lists, counters and bulk operations. The form list includes
`webhookFailures` for terminal failures. Resend also verifies that the delivery belongs to
the given entry. Test requests do not use real entry data, store an entry or retry failures.

### Multipart file fields

`POST /forms/{id}/submit` accepts native multipart files named `swf[field_id][]`, including
`swf[repeater_id][row][field_id][]`. The same signed submission token and spam protections apply;
there is no separate anonymous upload endpoint. Only a fully valid submission stores files.
The `file` type can enable multiple selection and configure extensions, per-file size and count.
See `docs/schema.md` for formats, host limits, public URL visibility and deletion behavior.

Stored entry data contains lists of `{name, url, size, type}` descriptors. Presented entry
nodes additionally contain `files: [{name, url}]` for rendering escaped links. Validation
responses contain field paths and messages, without local temporary file paths or download URLs.


### Entry export, editing and notes (M3)

* `GET /forms/{id}/entries/export` requires `swf_view_entries`, with the list's `filter`,
  `search` and `order` parameters. Cookie-authenticated browser downloads use `_wpnonce`.
  The response is a private, uncached UTF-8 CSV attachment. It contains entry id, date,
  status and the union of `data.*` keys across matching versions. Groups use dotted columns;
  repeaters/lists remain JSON. Spreadsheet formula prefixes are escaped with an apostrophe.
  Temporary webhook entries are excluded. Export reads live data in batches, not a snapshot.
* `GET /entries/{id}/values` requires `swf_manage_entries` and returns `{fields, values,
  revision}` from the entry's original published version. Values use element ids like submissions.
* `PUT /entries/{id}/values` accepts `{values, revision}` with the same capability. The full
  set of editable values is validated, data/search indexes update together, and stale revisions
  return 409. Validation errors return 422 with field paths. Uploaded files and repeater row
  counts are preserved. Editing records a log entry without replaying notifications/webhooks;
  already queued webhook requests retain their original frozen payload.
* `POST /entries/{id}/notes` requires `swf_manage_entries`, accepts `{note}` (1–2000 characters
  after sanitization), and returns 201. Notes are internal log records tied to the current user.

`GET /settings` includes `captcha.{turnstile,hcaptcha}.{siteKey,hasSecret}`. `PUT /settings`
accepts the corresponding `siteKey` and write-only `secret`; an empty secret preserves the
existing encrypted value. Client-supplied ciphertext is ignored. Selecting an unconfigured
provider prevents form publication.

WordPress's personal-data export/erase tools register an SW Forms handler. It matches
email-typed fields case-insensitively using each entry's original schema, including nested
and repeated fields and temporary entries. Export includes stored values and internal notes.
Erasure permanently removes matching entries, search indexes, logs, uploads and queued webhook
snapshots. It cannot retract messages or webhooks already delivered to external recipients.

### Form tools and editor embedding (0.5.0)

All form-tool routes require `swf_manage_forms`, except embedding choices as noted.

| Method | Route | Contract |
| --- | --- | --- |
| GET | `/form-templates` | `{items: [{id, title, document}]}` for contact, registration and survey starters. No notifications are enabled by the starters. |
| GET | `/forms/{id}/export` | Private uncached JSON envelope `{format: "swforms", formatVersion: 1, title, document}`. Exports the editable draft or current publication; no entries or site secrets. |
| POST | `/forms/import` | Accepts that envelope (up to 1 MB, schema version 1). Validates before creation; returns `{id}` (201) for a new **inactive** form. Never overwrites an existing form. Missing add-on types return validation errors (422). |
| GET | `/forms/{id}/versions?page=1` | `{items: [{version, createdAt, createdBy}]}`, newest first, 50 per page. Unknown form: 404. |
| GET | `/forms/{id}/versions/{version}` | `{document}` with signing secrets redacted. Unknown version/form: 404. |
| POST | `/forms/{id}/versions/{version}/restore` | Validate and load a draft; returns `{restored: true}`. Does not publish or alter existing entry version references. Preserves current signing secrets for matching webhook IDs, rather than reviving historical credentials. |
| GET | `/forms/embed-options` | Requires `edit_posts` or `swf_manage_forms`; only active, published `{id, title}` pairs. No document/entry data. |

Portable files remove recognized credential keys, password-field defaults and webhook
signing secrets/headers/destination URLs (the URL itself may be a credential). Webhooks
are disabled and receive fresh signing secrets when imported. Review delivery settings,
recipients and privacy configuration before activation. Free text and arbitrary add-on
settings can contain sensitive data under unknown names: review files before sharing them.

`POST /forms/{id}/submit` additionally accepts `swf_step` (zero-based integer). This is
validation-only and returns `success` with code `step`, or the current step's path errors.
Normal token, honeypot and rate checks still apply. Invalid step indices return code `step`
as an error. Omit `swf_step` for the final submission; all fields are revalidated regardless
of previously successful step requests. No client-side "step passed" flag is trusted.
