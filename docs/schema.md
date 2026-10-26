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
  ],
  "webhooks": [
    { "id": "w1", "name": "CRM", "enabled": true, "url": "https://crm.example/hooks/forms",
      "method": "POST|PUT|PATCH", "format": "json|form", "envelope": true,
      "fields": "all|custom", "mapping": [ { "field": "<element id>", "name": "contact.email" } ],
      "headers": [ { "name": "Authorization", "value": "", "secret": true } ], "logic": null }
  ]
}
```

Webhook secrets (signing secret, values of headers with `secret: true`) are not stored in the
document; see `webhooks.md`.

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
