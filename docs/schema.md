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
continue to apply to every presentation. Search changes only option visibility; it never
removes selections or changes the submitted values. No matching results are announced by
a status region. Without JavaScript the search input stays hidden and all native options
remain available. Button and image presentations work without JavaScript as well.

Checks: `php tests/wp-choice-fields.php`, and with local WordPress and Playwright Chromium,
`node apps/admin/scripts/e2e/choices.mjs` (honors `WP_ROOT` for the WordPress installation).
