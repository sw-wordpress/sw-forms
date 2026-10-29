# Form document (schema version 1)

A form is one JSON document. The builder edits it, the server validates and renders it, the
frontend script evaluates its conditions, and entries/notifications use the data shape derived
from it. Published versions are immutable snapshots (`wp_swf_form_versions`); every entry
references the version it was submitted against.

```json
{
  "schemaVersion": 1,
  "fields": [ Element, ... ],
  "settings": { ... }        // see "Form settings"
}
```

## Elements

| Property      | Type            | Notes |
|---------------|-----------------|-------|
| `id`          | string          | Immutable, `^[A-Za-z0-9_-]{1,64}$`, unique in the document. Conditions refer to ids. |
| `type`        | string          | `input`, `textarea`, `checkbox`, `radio`, `select`, `group`, `columns` or an add-on type. |
| `key`         | string          | Data key, `^[A-Za-z_][A-Za-z0-9_]{0,63}$`, unique per data object (see below). Only data types. |
| `label`       | string          | Plain text. |
| `description` | string          | Inline HTML (`a`, `strong`, `em`, `br`). |
| `required`    | bool            | |
| `adminLabel`  | string          | Used in entries and e-mails instead of the label. |
| `cssClass`    | string          | Space separated class names. |
| `logic`       | Logic \| null   | Conditional visibility. |
| `settings`    | object          | Type specific, normalised against the type's settings schema. |
| `children`    | Element[]       | `group` only. |
| `columns`     | Column[]        | `columns` only. |

Which of the common properties a type uses is declared by its `supports()` list.

### Columns

`{"id": "c1", "width": 6, "logic": null, "children": [...]}` – 1 to 6 columns, `width` in units
of a 12 column grid (rendered as `fr` tracks). Columns are layout only.

### Logic

```json
{ "action": "show|hide", "match": "all|any", "rules": [ { "field": "<element id>", "op": "is", "value": "x" } ] }
```

Operators: `is`, `isnot`, `contains`, `gt`, `lt`, `empty`, `notempty`. Comparisons trim and ignore
case; lists (checkbox list, multi select) match per item; numbers accept `1.5` and `1,5`.

* A node is hidden when its parent is hidden or its own logic says so.
* A rule that references a hidden field sees an empty value (cascade).
* Inside a repeater row rules see the same row and all enclosing scopes; a rule can never look
  into repeater rows from outside.
* Cycles are rejected on save. Hidden fields are neither validated nor stored nor sent.

The PHP (`src/Core/Logic/Evaluator.php`) and TypeScript (`apps/admin/shared/logic.ts`)
evaluators are tested against the same fixtures in `tests/fixtures/logic/`.

## Data shape

Entries, merge tags and the REST/webhook payload use the keys:

* `columns` are transparent – their fields belong to the surrounding object;
* a `group` is an object, a repeatable `group` a list of objects;
* hidden elements are omitted.

Values: text fields → string, single checkbox → bool, checkbox list / multi select → list of
option values, radio with "other" → the typed text.

```json
{ "name": "Max", "kind": "company", "people": [ { "name": "Anna", "kid": true, "age": "7" } ] }
```

The search index (`wp_swf_entry_values`) stores the display text per field (option labels,
Yes/No) and the row path (`people[0].`) for fields inside repeaters.

## Form settings

```json
{
  "submitLabel": "Absenden",
  "cssClass": "",
  "confirmation": { "type": "message|page|redirect", "message": "<p>Danke {field:name}</p>", "url": "", "pageId": 0 },
  "storeEntries": true,
  "retentionDays": 0,
  "spam": { "honeypot": true, "minSeconds": 2, "rateLimit": 10 },
  "notifications": [
    { "id": "admin", "name": "", "enabled": true, "to": "{admin_email}", "subject": "", "message": "{all_fields}",
      "replyTo": "{field:email}", "fromName": "", "logic": null }
  ]
}
```

## Webhook configuration (Milestone 2, preparation)

`settings.webhooks` defaults to `[]`, with at most 20 definitions:

```json
{
  "id": "receiver", "name": "CRM", "enabled": false,
  "url": "https://example.org/receive", "method": "POST", "format": "json",
  "headers": [{"name": "Authorization", "value": "Bearer example"}],
  "mapping": [{"field": "immutable_element_id", "target": "receiver_name"}],
  "logic": null
}
```

Methods are `POST`/`PUT`, formats `json`/`form`. Header names must be unique ignoring case;
line breaks and reserved transport/signature headers are rejected on publication. Enabled
webhooks require an HTTP(S) URL without credentials or a fragment. Network/SSRF checks will
be applied by the delivery service; URL syntax validation alone is not authorization to send.

No mapping means all final visible data. A nonempty mapping selects only those values, with
flat receiver keys matching the normal field-key pattern. Sources use immutable element ids,
so renaming a source key does not break selection. Nonrepeatable nested fields and whole
groups/repeaters can be selected; individual fields within repeater rows cannot. Hidden or
missing sources are omitted, never replaced with all data. Deleted sources invalidate the
webhook until the mapping or condition is explicitly repaired. Conditions use the shared
logic model and form-level leaf fields outside repeaters.

REST accepts a write-only `secret` for replacement; absent/empty input preserves the stored
secret of the same form and webhook id. New webhooks without a secret get a random 256-bit
secret. To verify signatures at the receiver, enter a secret known to that receiver.
Documents in the database use authenticated AES-256-GCM `secretEncrypted` envelopes keyed
from WordPress salts. REST returns only `hasSecret`; both plaintext and ciphertext are
removed by `WebhookSettings::redact()` (also use this for future exports). Other header
values are ordinary configuration, visible only to form managers. Entry-only viewers do
not receive webhook configuration. Duplicated forms get independent signing secrets.
Draft rotation does not affect the published version; discarding restores the original.
Changing WordPress salts makes old encrypted secrets unreadable; replace them afterwards.

The builder currently configures webhooks only. Automatic delivery, retries, the test-send
button and resend/logging are still pending. No HTTP requests are made by these classes.

### Prepared request contract

`RequestBuilder` prepares `{event, eventId, form, entry, data}`. The event is `entry.created`;
`form` contains `id`, `title`, `version`, and `entry` contains `id`, `date` (UTC). Its caller
must generate one event id and persist it along with the filtered request for all retries.
The `data` value is an object even when empty. The `swf_webhook_payload` filter runs after
mapping and before serialization/signing. It receives no signing secret.

`X-SWForms-Signature: sha256=<hex HMAC-SHA256>` signs the exact raw request body bytes using
the configured secret. JSON uses UTF-8 with unescaped Unicode/slashes; form-encoded data
uses RFC 3986 bracket notation, including nested groups/lists. Standard PHP form encoding
omits empty arrays/objects. Receivers verify the raw bytes before parsing, using a constant
time comparison. Do not reserialize the parsed body to verify a signature. The signature
alone provides integrity/authentication, not replay prevention: retain processed event ids.

Checks: `tests/Unit/WebhookTest.php`, `tests/WordPress/WebhookSettingsTest.php`,
`node apps/admin/scripts/e2e/webhooks.mjs`.

## Merge tags

`{field:key}` (`{field:group.key}` for plain groups), `{all_fields}`, `{form:id}`, `{form:title}`,
`{entry:id}`, `{entry:date}`, `{entry:url}`, `{site:name}`, `{site:url}`, `{admin_email}`,
`{date}` / `{date:Y-m-d}`, `{user:email|display_name|first_name|last_name|login}`. Default values
of inputs additionally support `{get:parameter}`. Values are escaped for the target context
(HTML, plain text, URL).

## Choice presentations

Choice variants use the existing type slugs and value contracts; no migration is needed.

| Type | Setting | Behavior |
|------|---------|----------|
| `checkbox` (`mode: single`) | `switch: true` | Native boolean checkbox styled as a switch, including keyboard support. |
| `radio`, `checkbox` (list) | `presentation: standard\|buttons\|images` | Alternative option labels around native inputs; list checkbox selections remain arrays. |
| `select` | `searchable: true` | Adds a search input when JavaScript is available, for single and multiple selects. Selected values remain visible while filtering. |

Options retain `{label, value}` and may include `image`, an HTTP(S) image URL. The builder's
option editor exposes optional image URL inputs. Image presentation keeps the text label
as the accessible name and as a fallback when no image is configured. Only HTTP(S)-safe
URLs are rendered; images do not alter the stored option values. Standard presentations
ignore images. Bulk editing preserves images for options whose values are retained.

Required checks, allowed-option validation and existing checkbox min/max selection settings
continue to apply to every presentation. Searchable selects use a combined Tom Select control,
bundled locally into the plugin JavaScript/CSS (no CDN), with keyboard search and removable
multiple selections. The original select remains the submitted value source. Reset,
conditional disabling, validation descriptions and dynamically added/removed repeater rows
are synchronized. Without JavaScript all native options remain available. Button and image
presentations work without JavaScript as well. Third-party licenses are in `licenses/`.

Checks: `php tests/wp-choice-fields.php`, and with local WordPress and Playwright Chromium,
`node apps/admin/scripts/e2e/choices.mjs` (honors `WP_ROOT` for the WordPress installation).

## Additional input settings

The `input` type additionally supports `emailConfirmation`, `countryCode`, `countryCodes`,
`defaultCountryCode`, `passwordStrength` and `passwordLength`. Temporal `minDate`/`maxDate`
accept restricted relative expressions such as `today + 7 days` for dates/local datetimes.
All new checks are opt-in; no migration or new field-type registration is necessary.
See `docs/extending.md` for formats, defaults and precise validation rules.

Email confirmation and telephone calling-code controls use request keys `<id>:confirm` and
`<id>:country` in the field's own scope, including repeater rows. They are auxiliary
controls, not form elements: no extra entry keys, merge tags or condition targets are added.
A confirmed email still produces one string; the selected calling code becomes part of the
telephone string. Programmatic `swf_submit()` calls must supply the confirmation when enabled.

Checks: `php tests/wp-input-options.php`, `node apps/admin/scripts/e2e/inputs.mjs`.

## Range, score, color and tag fields

These built-in types use the same field keys, conditional visibility, repeater scopes and
entry pipeline as existing fields. Custom types still register on `swf_register_field_types`.

| Type | Settings / defaults | Stored value |
|------|---------------------|--------------|
| `range` | `min=0`, `max=100`, `step=1`, `default=0` | Numeric string; native slider. |
| `rating` | Fixed minimum 1, `max=5` (up to 10), optional `default` | Integer string or unanswered `""`; star labels on native radios. |
| `scale` | `min=1` (0–19), `max=10` (1–20), optional `default` | Integer string or unanswered `""`; numeric native radios. |
| `color` | `default="#000000"` | Lowercase `#rrggbb` string; native RGB picker without alpha. |
| `tags` | `default=""`, `minTags=0`, `maxTags=20`, `maxTagLength=50` | List of unique plain-text strings, preserving order and case. |

Range bounds are limited to ±1,000,000,000; step is between 0.000001 and 1,000,000,000.
Invalid/reversed settings are normalized (maximum raised to minimum); defaults are clamped
and snapped down to the step grid relative to the minimum. Range/color controls have an
initial value, so `required` does not imply that the visitor explicitly interacted with them.
The slider value readout is enhanced with JavaScript; the native slider remains usable without it.

Ratings/scales initially have no selected value unless a default is configured. `required`
therefore enforces a selection. Scores must be integers within configured bounds; range
values must match bounds and step. RGB format and score membership remain structural checks.

Tags use a textarea with comma/newline separators. Programmatic submissions may also supply
arrays of strings; separators are processed the same way. Surrounding ASCII whitespace and
exact duplicates are removed. Tag content is plain text, including literal markup characters,
and is escaped in rendering, entry HTML and notification output. It is never interpreted as HTML.
Tag counts (up to 100) and Unicode code-point length (up to 200 per tag) are checked server-side;
the parser retains one extra item/character so overflow is rejected rather than silently accepted.
Empty optional fields remain valid; set `required` to require tags, and `minTags` for a larger
minimum on nonempty lists. Frontend conditions read the same parsed list as the server.

New checks: `php tests/wp-scalar-fields.php` and `node apps/admin/scripts/e2e/scalars.mjs`.
The PHP and TypeScript tag parsers share `tests/fixtures/tag-values.json`.

## Birthdates and temporal intervals

| Type | Settings | Value |
|------|----------|-------|
| `birthdate` | Optional `minAge` / `maxAge` (0–150 completed years), `validate=true` | ISO date string, initially empty. |
| `date-interval` | `defaultFrom`, `defaultTo`, `min`, `max`, `allowEqual=true`, `validate=true` | Empty `[]` or ordered `[from, to]` date strings. |
| `time-interval` | Same, plus `step=60` in seconds (1–86400) | Empty `[]` or ordered `[from, to]` time strings. |

Birthdates must be valid, non-future dates. Age is evaluated on the current calendar day
in the WordPress site timezone. A February 29 birthday advances on March 1 in non-leap years.
Picker bounds and server age validation use the same policy. If both age limits are supplied,
a smaller maximum is raised to the minimum. `validate=false` disables date/future/age checks
and renders a text input; the central required check remains active.

Interval requests use `swf[id][from]` and `swf[id][to]` (or the corresponding repeater scope).
Programmatic submissions may pass `{from, to}` or an ordered two-string list. Whitespace is
trimmed; endpoint strings are capped at 200 Unicode code points. Empty optional pairs are
allowed. Once one endpoint is supplied, both are required, even with `validate=false`.
Nonempty malformed input is rejected as incomplete.

Date intervals use ISO `YYYY-MM-DD`. Time intervals use `HH:MM[:SS]`; they represent a single
local day, with no timezone conversion, duration calculation or overnight wrapping. Start
must not exceed end; `allowEqual=false` requires a strictly later end. Time steps are relative
to a valid minimum or midnight. Both endpoints must satisfy configured `min`/`max` bounds.
Date bounds support the existing `today`/`now` offset grammar; invalid bounds are ignored,
as for simple date inputs. Bounds and ages are reevaluated when submitting cached pages.
`validate=false` switches endpoint controls to text and disables format, bounds, step and
ordering checks while retaining completeness and required checks.

Intervals are leaf fields with the existing `list` conditional-value kind. `contains` can match
an endpoint, and empty/not-empty conditions use the whole pair. Endpoints are not independently
addressable form elements. Entries/notifications format the pair as `from – to`. Existing
field documents and the `swf_register_field_types` extension action remain unchanged.

Checks: `php tests/wp-temporal-fields.php`, `node apps/admin/scripts/e2e/temporal.mjs`.
PHP/TypeScript interval collection shares `tests/fixtures/interval-values.json`.

### Time interval picker

Validated `time-interval` endpoints progressively use linked hour/minute/second selectors.
Every offered combination lies on the configured seconds grid, starting at `min` (or
midnight), and stays within `min`/`max`. For example, `step=900` offers quarter-hours;
`min=09:07` shifts that grid to 09:07, 09:22, etc. Seconds appear only when needed.
Even `step=1` uses at most 24 hour and 60 minute/second options per selector rather than
an 86,400-item dropdown. Native names and `[from,to]` values stay unchanged. Reset,
repeater rows, conditions, keyboard labels and validation errors are synchronized.
Without JavaScript native time inputs remain available with the existing server validation.
With `validate=false` the original unrestricted text inputs remain. Shared
`tests/fixtures/time-options.json` cases verify that browser choices pass server checks.

## Name and address presets

`name` and `address` are data-bearing `children` containers, using the same group rendering,
validation, conditions and optional repeatability as `group`. Their initial components are
ordinary editable fields, not a separate opaque value parser.

| Preset | Initial component keys | Required by default |
|--------|------------------------|---------------------|
| `name` | `salutation`, `given_name`, `family_name`, `title` | Given/family name |
| `address` | `street`, `house_number`, `postal_code`, `city`, `country` | Street, city, country |

Salutation/title and house number/postal code are optional. Country is free text; postal codes
and house numbers remain text, retaining leading zeros, letters and national formatting.
There is no global postal-code pattern or address lookup. Native autocomplete hints are
provided where applicable. Components reuse normal input length, pattern, format and required
settings. Default maximum lengths are 120 characters for names, 60 for salutation/title,
200 for street, 50 for house number, 30 for postal code and 120 for city/country.
These settings can be edited like other input fields.

The builder receives optional `initialChildren` templates in `/field-types`. Creating a preset
copies them once with fresh ids. Label changes retain the canonical component keys, including
before publishing and after draft reload or duplication. Editing a key explicitly remains
possible; published-key warnings still apply. Existing saved children are never reset when
loading/saving; removed parts stay removed. Components can be reordered, configured, moved or
extended through the normal builder. There is no aggregate required flag: requirements belong
to the component fields, so an optional name/address is configured by relaxing those parts.

Programmatic documents may omit `children` to request initial components. Normalization assigns
deterministic ids from the parent id and each initial key, so repeated shorthand normalization
is stable. Supplying explicit `children` preserves them, including an empty list. After the
first save, keep the saved ids/children as usual when editing a form.

Nonrepeatable groups produce named objects, e.g. `person.given_name` and
`location.postal_code`; repeatable groups produce lists of those objects. Requests use the
actual child **ids** in the current scope, as for ordinary groups: `swf[child_id]` outside a
repeater, `swf[people_id][row][child_id]` inside one. The group itself is not a condition source;
its component fields are. Component merge tags such as `{field:person.given_name}` work in
nonrepeatable groups, while `{all_fields}` renders the full hierarchy, including repeaters.

Checks: `php tests/wp-contact-fields.php`, `node apps/admin/scripts/e2e/contacts.mjs`.
The PHP/TypeScript evaluators share `tests/fixtures/logic/contact-groups.json`.

## File uploads

The `file` type uses a native accessible file selector; `multiple=true` enables multiple files
and the same control accepts drag and drop. Files work through AJAX and native multipart
postback, including repeaters, columns, groups and conditional visibility. Registry additions
still use the existing `swf_register_field_types` action.

| Setting | Default | Limits |
|---------|---------|--------|
| `multiple` | `false` | Single file unless enabled. |
| `extensions` | `pdf,jpg,jpeg,png,txt` | Comma-separated subset of `pdf,txt,csv,jpg,jpeg,png,gif,webp,zip`; also restricted by WordPress MIME policy. Empty/unsupported-only lists reject all files. |
| `maxSize` | `10` | Integer 1–100 MiB **per file**, further limited by the host's upload limit. |
| `maxFiles` | `5` | Integer 1–20, used only with multiple selection; single mode always permits one. |

Files require `storeEntries=true`. The builder explains this and their storage visibility.
They are stored under the normal WordPress uploads directory in `/swforms/` with cryptographically
random 128-bit basenames, outside the Media Library. **The URLs are public bearer links**:
anyone who knows a URL can fetch it. They are not authenticated downloads and should not be
used for documents requiring private access. The setting's help text exposes this tradeoff.

The request uses file parts named `swf[id][]`, or `swf[repeater_id][row][id][]` inside repeaters.
REST and postback read PHP's file metadata separately from ordinary values. Strings, URLs,
attachment IDs or client-supplied server paths in form data cannot become uploads. A trusted
`swf_submit()` call may pass PHP's `$_FILES` shape via `$context['files']`; files must still be
actual HTTP uploads (`is_uploaded_file`). The field's `required` rule requires at least one file.
Count, nonempty content, effective byte size, PHP upload status, extension and detected MIME
are checked server-side; images additionally need matching image metadata. These are structural
checks and cannot be switched off. ZIPs are stored as archives, never unpacked.

Preview validates without moving files. Spam, invalid forms and hidden fields never persist
files. Only after all validation passes are visible uploads moved; failures while moving or
storing the entry roll back this submission's files. Native postback cannot retain a file
selection: users must choose files again after a validation error or reload. AJAX keeps the
selection for correction. Host `post_max_size`, `max_file_uploads` and web-server body limits
still apply to the **whole request**, independently of per-field settings. Configure them for
the sum of all fields/repeater rows. PHP's discarded oversized POST body produces an error;
a web server rejection may instead surface as a network error.

Condition values remain lists of plain filenames in both PHP and JavaScript, so `empty`,
`notempty`, `is` and `contains` work like other list fields, including row scopes. Stored data
is always a list, even in single mode (empty `[]` for unanswered optional fields):

```json
{"documents": [{"name": "consent.pdf", "url": "https://example.test/wp-content/uploads/swforms/swf-<random>.pdf", "size": 12345, "type": "application/pdf"}]}
```

The entry index searches filenames. Entry details and `{all_fields}` show escaped file labels
as links; plain text and `{field:documents}` give names and URLs. Actions/webhooks receive the
same descriptors. No local temporary or absolute filesystem path is stored in entry data or
returned to visitors. An entry's `meta.uploads` holds generated basenames for cleanup, independent
of filtered public data. Trash retains files; permanent entry/form deletion, retention cleanup
and opted-in uninstall remove them. Backups, offloaded storage and external copies require
their own lifecycle handling; this implementation owns local files in `/swforms/` only.

Checks: `php tests/wp-file-uploads.php`, `node apps/admin/scripts/e2e/uploads.mjs`.
Shared PHP/TypeScript visibility fixture: `tests/fixtures/logic/files.json`.

## Image uploads and mobile camera hints

`image` is an image-only variation of `file`. It shares the same request layout, metadata lists,
required/count/byte limits, repeaters, conditions, entry links, rollback and deletion lifecycle.
It still requires entry storage and uses the same **public random URL** storage contract.
Only a configured subset of JPEG (`jpg`, `jpeg`), PNG, GIF and WebP is accepted; defaults allow
all five extensions. WordPress MIME policy additionally applies. SVG, HEIC and non-image files
are rejected even when a raw document attempts to allow their extensions. An empty extension
list permits no uploads. Validated HTTP files additionally need positive image dimensions.

| Additional setting | Default | Behavior |
|--------------------|---------|----------|
| `preview` | `true` | Local image thumbnails when JavaScript/object URLs are available. |
| `crop` | `false` | Explicit client-side cropping when previews and PNG uploads are enabled. |
| `capture` | `""` | `""` (file picker), `environment` (rear camera) or `user` (front camera). |

Camera preference emits the native `capture` attribute on the image file control, with
image MIME types in `accept` for native camera support. Extension limits still apply server-side.
Actual camera availability and which picker opens depend on the device/browser. This is a selection hint,
not a request for microphone/camera access through `getUserMedia`. Native selection, capture
hints and server validation also work without JavaScript.

Previews use local `blob:` URLs, plain-text filenames and image elements, without sending bytes
to a server or changing the selected files. Failed or unsupported previews leave native upload
and server validation available. The selected original bytes are stored unless the visitor
explicitly applies a crop. Preview count is limited by the configured file
count (at most 20 per control). Changing/clearing selections, hiding controls, removing repeater
rows, resetting or completing a form releases their local object URLs. Visible selections
restore their previews when a hidden field becomes visible again. A native postback error
requires selecting images again, like other file uploads.

`FileField` now exposes protected `upload_attributes()` and `upload_extra()` methods for this
shared markup. `ImageField` reuses its actual validation/storage pipeline; both still register
through `swf_register_field_types`. Existing `file` elements keep their behavior and do not gain
previews or camera hints. Conditions continue to compare lists of plain filenames. No saved
document migration is needed.

Coverage includes real multipart image acceptance/rejection/dimensions in the WordPress suite,
preview lifecycle/escaping/fallbacks in Vitest and
`node apps/admin/scripts/e2e/images.mjs` for actual decoded pixels, camera attributes,
repeater controls, unchanged downloaded bytes and palette/settings save/reload. These browser
checks do not claim to exercise physical phone cameras. Likert remains tracked with matrix issue #1.

## Image cropping, signatures and recordings

With `image.settings.crop=true`, preview thumbnails offer percentage inputs for left, top,
width and height, plus Apply/Cancel. These native number controls work with keyboard and touch.
Cropping is optional and enabled only when `preview=true` and PNG is permitted. Cancel leaves
the selection unchanged. Apply replaces just that file with `<stem>-cropped.png`, then updates
conditions and the thumbnail. The resulting PNG is scaled down to at most 4096 pixels per side;
animation and metadata are not preserved. PNG transparency is preserved. There is no upload
before submission. Original files are not modified on disk. Invalid coordinates, decode/export
failures and stale async results preserve the selection. The server validates the resulting
multipart file exactly like any other PNG; it does not trust crop coordinates or client claims.

Three further built-in types use the same list value, request layout, entry links, conditions,
repeater handling, ownership manifest, rollback and deletion as `file`/`image`:

| Type | Settings | Accepted content |
|------|----------|------------------|
| `signature` | `maxSize=10` MiB | Exactly one PNG with positive image dimensions. |
| `audio` | `maxSize=10`, `extensions=webm,ogg,m4a,mp3,wav,mp4`, `maxSeconds=60` | Exactly one audio recording, with an audio stream and no video stream. |
| `video` | `maxSize=10`, `extensions=webm,mp4`, `maxSeconds=60` | Exactly one video recording with positive video dimensions; audio is optional. |

The builder can narrow recording extensions. WordPress MIME restrictions apply too. General
`file` fields retain their previous allowlist and do not implicitly accept media extensions.
Server checks include actual HTTP provenance, extension, detected MIME/container, stream kind
using WordPress's bundled media reader, actual bytes, required and file count. The transport
MIME sent by the browser is not trusted. `maxSize` is further limited by the host upload limit.
`maxSeconds` is an integer 1–600 and limits **browser recording only**, not uploaded duration:
streaming WebM output may lack duration metadata. The server always enforces bytes and stream
kind. No transcoding or antivirus scanning is performed.

The signature canvas accepts mouse, pen or touch. A blank canvas does not select a file.
Visitors explicitly choose **Use signature** to produce a white-background 800×300 PNG;
**Clear signature** removes the drawing and selection. A PNG file can be chosen instead,
including with keyboard alone or without JavaScript. This is an image of a signature, without
identity verification or a cryptographic signing protocol. Client drawing history is not stored.

Audio/video uses `getUserMedia` and `MediaRecorder` only after **Start recording**, on HTTPS
(or a browser-trusted localhost origin), with browser permission. The supported recorder MIME
must match an allowed extension; unsupported APIs/formats leave the native selector available.
Stop finalizes a local file; time limit/device disconnect also stop. Size overflow discards the
new recording and retains any prior selection. Visitors can play or discard the selected clip
before submitting. No autoplay or background upload occurs. Submission waits until recording
and async image processing are finished. Denied permissions leave file upload usable.

Microphone/camera tracks, timers and local playback URLs are released on stop/error,
discard, replacement, condition hiding, row removal, reset, successful submission, tab hiding
and page exit. Pending permission/export results cannot attach a file after cancellation.
Native no-JS multipart submission remains available for all types; a postback error requires
reselection. Entry storage is required and the existing **public random URL** contract applies
to signatures and recordings as well. Own types still register via `swf_register_field_types`.

Checks: WordPress PHPUnit real media fixtures, shared PHP/TypeScript `logic/media.json`,
Vitest lifecycle tests and `node apps/admin/scripts/e2e/media.mjs`. The browser check uses
real canvas pixels and MediaRecorder encoders with synthetic Chromium devices, plus no-JS
fallback and builder save/reload; physical devices and other browser implementations need
deployment testing.

## Matrix and Likert scales

`matrix` is a leaf field in the choice palette. It starts with two questions and a five-point
Likert scale from strongly disagree (`"1"`) to strongly agree (`"5"`), with no answer selected.
Both `settings.rows` and `settings.options` use editable `{label, value}` lists. A visitor
chooses exactly one option per answered row; every row has its own native radio group.
Setting `required: true` requires all rows. Optional matrices allow unanswered or partial rows.

Row values are stable answer keys, limited to 64 ASCII letters/digits/underscores/hyphens,
starting with a letter. Invalid/duplicate rows are removed on save. Matrices have at most
50 rows and 20 answer columns; empty lists restore the defaults so the field remains usable.
The editor supports bulk text entry and explicitly edited values stay fixed on label changes.
Use “Show values” to set stable row keys before publishing; changing row values changes the
data contract. Option values are stored as strings, including numeric Likert scores.

For example:

```json
{
  "id": "survey", "type": "matrix", "key": "feedback", "label": "Experience",
  "required": true,
  "settings": {
    "rows": [{"label": "Service", "value": "service"}, {"label": "Quality", "value": "quality"}],
    "options": [{"label": "Disagree", "value": "1"}, {"label": "Agree", "value": "5"}]
  }
}
```

Posts use `swf[survey][service]=5`. Stored entry/webhook data is
`{"feedback":{"service":"5","quality":"1"}}`; unanswered rows are omitted and a completely
unanswered matrix is `[]`. Unknown row keys are ignored and nonempty invalid option values or
malformed answers are rejected. Repeater/group nesting follows the ordinary data shape.
Entry views, search text and `{all_fields}` pair each question label with its answer label.

The runtime uses the `matrix` value kind to collect checked answers into a map. Conditions
compare its answer values, as PHP already does for associative arrays: `is`/`contains` match
any answer and `empty`/`notempty` check the entire matrix. Row keys are not compared, and
numeric `gt`/`lt` comparisons on a matrix are false. Individual row conditions are not exposed.
PHP and TypeScript share `tests/fixtures/logic/matrix.json`.

A fieldset/legend and a semantic table connect each radio to its question and column header.
Descriptions and errors are linked to all radios. The grid scrolls horizontally on narrow
screens. Native keyboard navigation, server validation and postback work without JavaScript.
Browser checks: `node apps/admin/scripts/e2e/matrix.mjs`.
