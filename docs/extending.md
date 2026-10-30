# Extending SW Forms

## Stable API and deprecation policy (0.4.0)

The contracts below are public and stable from 0.4.0. Their original introduction
versions are recorded in PHP `@since` annotations and [hooks.md](hooks.md).

- Fields: `FieldTypeInterface`, `AbstractFieldType`, `FieldRegistry`, `RenderContext`.
- Actions: `ActionInterface`, `Submission` (public properties, `form_id()`, `log()`).
- Validation: `ValidatorInterface`, `ValidatorRegistry` (0.4.0).
- Merge tags: `MergeTagInterface`, `MergeTagRegistry` (0.4.0).
- Spam: `ProviderInterface`, `ProviderRegistry` (0.3.0).
- Optional builder UI: `BuilderFieldTypeInterface` and JavaScript API version 1 (0.4.0).
- The PHP functions and hooks listed in [hooks.md](hooks.md).

Other implementation classes are internal. Minor releases may add optional capabilities
through companion interfaces, optional parameters or new hooks; they will not add required
methods to an existing interface or change argument order/types. Breaking changes require
a major version. Deprecated APIs retain behavior for at least two minor releases and six
months, whichever is longer. Announce the replacement, `@deprecated` version and migration
example in this document before removal; use WordPress deprecation helpers where applicable.
There are no deprecated public APIs in 0.4.0. Existing filters remain supported.

## Registration and execution

Install registration callbacks during plugin bootstrap (or `plugins_loaded`), before first
registry use. Each singleton registration action runs once per request. A duplicate slug
replaces its implementation; invalid names are ignored (field types also report
`_doing_it_wrong`). Field slugs: `[a-z][a-z0-9-]{0,39}`; validator/provider slugs additionally
allow underscores; merge tag names use `[a-z][a-z0-9_]{0,39}`.

`swf_register_field_types` receives `FieldRegistry`. The same definitions drive schema
validation, `/field-types`, rendering, collection and entry formatting. Extend
`AbstractFieldType` for accessible wrappers, settings normalization and defaults.
`value_kind()` describes how public JavaScript reads the value for conditions. The PHP
renderer remains authoritative; add-on types need no builder JavaScript to work.

`swf_register_validators` receives `ValidatorRegistry`; register a `ValidatorInterface`.
`supports($element)` selects fields; `validate($value, $element)` returns a list of
`{code, params?, message?}` errors. Messages are plain translated text. Validation runs in
registration order after built-in checks, only for active, non-empty leaf fields (including
repeater rows); the first error wins. Required checks remain central. Unknown codes use the
generic error message unless `message` is supplied. Exceptions propagate; validators must
return errors for invalid user input. `swf_validation_errors` still runs afterwards.

`swf_register_merge_tags` receives `MergeTagRegistry`; register a `MergeTagInterface`.
`name()` identifies `{name:argument}`; `resolve($argument, $submission)` returns plain text
or `null`. A non-null result overrides a built-in text tag (`all_fields` remains
renderer-owned HTML); the existing `swf_merge_tag` filter
runs last. Unknown tags remain unchanged. HTML/URL contexts escape returned values.

`swf_register_actions` remains a filter receiving the action list and `Submission`.
Append an `ActionInterface`; `run()` executes only after successful validation and storage.
Use `$submission->log($type, $action_id, $status, $message)` for entry logs. Exceptions are
caught and logged as failures without changing the visitor's confirmation. Actions must
implement their own retry/idempotency policy if they contact external systems.

## Settings schema

`settings_schema()` returns a list with `name`, `type`, `label`, `default` and optional
`tab` (`general`, `validation`, `appearance`), `help`, `showIf` (`{setting, equals: [...]}`).
Supported types: `text`, `textarea`, `html`, `code`, `number`, `toggle`, `select` (`choices`),
`options`, `list`, plus these 0.4.0 types:

| Type | Stored shape | Additional schema |
| --- | --- | --- |
| `field-picker` | Element ID string, empty string when cleared | Optional `fieldTypes: ['input']` limits the picker. Labels/keys may change; IDs must not. |
| `key-value` | `[{key: string, value: string}]` | Optional `maxItems` (0–100, default 100). Order and duplicate keys are retained. |
| `repeater` | List of objects normalized against child definitions | `fields: [setting, ...]`, optional `maxItems`; at most four structured levels. |

Unknown settings and unknown repeater properties are dropped. Text is sanitized, configured
numeric bounds apply recursively, malformed rows are ignored. Defaults are trusted schema
values; declare them in the correct shape. `field-picker` validates ID syntax, not existence
or scope; the consuming add-on must validate references. `fieldTypes` is a builder filter.
`showIf` only controls visibility; it does not erase stored values.

## Custom Vue inspector and canvas

Implement the optional `BuilderFieldTypeInterface::builder_script(): string` alongside the
field contract. Register that WordPress script on `init` with dependency
`sw-forms-builder-api`. SW Forms enqueues it only on its admin page. No extra Vue bundle is
needed: register through `window.swFormsBuilder.register(api => { ... })`, before or after
the SPA loads. `api.apiVersion` is `1`; `api.vue` is the host Vue runtime.

```js
window.swFormsBuilder.register(function (api) {
  api.registerFieldType('my-field', { inspector: MyInspector, canvas: MyCanvas });
});
```

Components are Vue component objects, defined with the supplied runtime. Inspector props:
`element`, `modelValue` (settings); emit `update:modelValue` with a settings patch to use
normal undo/autosave. Canvas props: `element`, `settings`; it is inert and approximate.
Do not mutate props. Standard schema controls remain available alongside the custom
inspector. Missing components fall back to the default inspector/canvas. Registrations
replace by field slug; invalid slugs are ignored. Failed registration callbacks are logged
and do not prevent other add-ons from registering. Component runtime errors remain the
add-on's responsibility.

## Runnable example

[examples/sw-forms-example](../examples/sw-forms-example/) contains a complete field,
validator, merge tag, custom inspector/canvas and logged action. Install that directory as
its own plugin beside SW Forms and activate it. Add **Reference**, use key `reference`, and
set confirmation to `{example_reference:reference}`. `REF-123` succeeds; other non-empty
values fail. The action records a local log and makes no network request. Remove the example
only after deleting its test forms; forms referencing missing types cannot be edited safely.
The PHPUnit [ExtensionApiTest](../tests/WordPress/ExtensionApiTest.php) loads the same example
without installing another plugin.

## Input validation

The `input` type supports `text`, `email`, `tel`, `url`, `number`, `date`, `time`,
`datetime-local`, `password` and `hidden`. Existing documents need no migration.

* `validate` defaults to `true` when omitted. Turning it off skips format, length,
  pattern, range and interval checks for this input; sanitization and the central
  `required` check remain active. Native email/URL/temporal inputs become text inputs
  in this mode so browsers do not erase values that the form deliberately accepts.
* Numbers support `integer`, `min`, `max` and `step`. Step is enforced on the server,
  relative to `min` (or zero). Decimal commas remain supported.
* Temporal inputs accept `minDate` and `maxDate` in their own format: `YYYY-MM-DD`,
  `HH:MM[:SS]`, or `YYYY-MM-DDTHH:MM[:SS]`. Invalid configured bounds are ignored.
  Times and local datetimes support a positive `timeStep` in seconds, relative to
  the minimum (or midnight / the Unix epoch). There is no timezone conversion or
  wraparound range across midnight. Omitted intervals impose no server restriction.
* `minDate` and `maxDate` also accept `today` or `now`, optionally followed by a signed
  offset: e.g. `today + 7 days`, `today - 18 years`, `now + 2 hours`. Supported units:
  minute(s), hour(s), day(s), week(s), month(s), year(s); offset: 0–9999. A single offset
  is allowed. `today` starts at midnight, `now` uses the current minute. Resolution uses
  the WordPress site timezone for `date` and `datetime-local`, with calendar arithmetic
  (including normal month-end rollover). `time` still accepts only literal bounds.
  Bounds are recalculated on submission, so cached pages never relax server checks.
* Email inputs may set `emailConfirmation: true`. The second input is named `<id>:confirm`
  in the same request scope. It must match the sanitized primary email exactly. Required
  checks and conditional visibility still apply. The confirmation is read only for
  validation, never stored or exposed as a second field value, and remains blank after
  a non-JavaScript validation error. `validate: false` also disables confirmation.
* Telephone inputs may set `countryCode: true`, `countryCodes: [{label, value}]` and
  `defaultCountryCode`. Codes such as `+49` must be selected from the configured list;
  an empty selection means no prefix. Numbers already starting with `+` or `00` are
  preserved; otherwise the selected code is prepended, separated by a space. National
  trunk prefixes and formatting are preserved; this is not country-specific numbering
  plan validation. Browser conditions and server collection use the same combined value.
  After a postback the combined number is retained and the calling-code selection is empty,
  preventing duplicate prefixes.
* Password inputs may choose `passwordStrength: none|length|mixed` (default `none`) and
  `passwordLength` (8–500, default 12). `mixed` requires Unicode uppercase, lowercase,
  a number and a non-whitespace symbol in addition to length. `validate: false` disables
  these checks. Password whitespace is preserved and the control is never repopulated.

The settings schema may set `images: false` on an `options` setting to hide image URL
controls (for example in calling-code lists).

Built-in `range`, `rating`, `scale`, `color` and `tags` fields are documented in `docs/schema.md`.
Built-in `birthdate`, `date-interval` and `time-interval` fields are also documented there.
Built-in `name` and `address` are editable group presets (see `docs/schema.md`).
Built-in `image` extends the file-upload pipeline with local previews and mobile camera hints (see `docs/schema.md`).
Built-in `matrix` supports single-answer question grids and Likert scales (see `docs/schema.md`).

Built-in `signature`, `audio` and `video` fields reuse `FileField`'s multipart contract.
`FileField::allowed_mimes()` and `detected_mimes()` are protected extension points for
subclasses with narrow format/container requirements; normal file fields retain their
previous allowlist. WordPress MIME policy still applies. Such subclasses should override
`validate_uploads()` for content-specific checks after the parent provenance/size/MIME checks.
See `RecordingField` for stream inspection and `SignatureField` for PNG-only drawing output.

## Preset groups

`GroupField` is extendable. A `PresetGroupField` subclass can implement `initial_children()`
to return initial keyed component templates without ids, and register through the existing
`swf_register_field_types` action. `input_part()` constructs the flat input components used
by the built-in name/address presets. The registry publishes normalized templates as
`initialChildren`; the normalizer seeds omitted children for programmatic creation. This
initialization never replaces an explicit child list. Persist generated ids when editing.

## Background queue (Milestone 2 foundation)

`SWForms\WordPress\Support\Queue` wraps Action Scheduler. Use it after
`action_scheduler_init` (normally `init` priority greater than 1). Register callbacks with
`add_action()` and the appropriate accepted argument count. Hooks must start with `swf_`
and use lowercase letters, digits and underscores; jobs belong to `swf_background`.

```php
$queue = new \SWForms\WordPress\Support\Queue();
$action_id = $queue->schedule( 'swf_my_job', array( $entry_id, 0 ) );
$retry_id = $queue->schedule( 'swf_my_job', array( $entry_id, 1 ), time() + 60 );
$queue->cancel( 'swf_my_job', array( $entry_id, 1 ) );
```

Arguments are a positional list. Pass identifiers, not submission data or secrets: Action
Scheduler keeps job history. `schedule()` returns the persisted action id and throws on
storage failure or use before initialization. It does not deduplicate jobs or retry them;
the delivery service must implement its own idempotency and retry policy. Cancellation
matches the hook and arguments within the SW Forms group only. Dispatch depends on
WordPress cron/loopback processing; calling `schedule()` never performs the job inline.

Webhook configuration and request signing are described in `docs/schema.md`. Delivery, retries, leases, temporary-entry erasure and destination exceptions are
implemented by the WordPress webhook services; see `docs/schema.md` and `docs/hooks.md`.


## Spam providers (M3)

Implement `SWForms\WordPress\Spam\ProviderInterface`: `slug(): string`,
`configured(): bool`, `public_config(): array`, and `verify(string $token): bool`.
Register an instance through `swf_register_spam_providers` using `$registry->register($provider)`.
Slugs use lowercase letters, digits, underscores or hyphens, beginning with a letter (40
characters maximum); registering the same slug replaces its implementation. Return only
public widget configuration, keep secrets server-side, and reject verification failures.

Built-in form settings expose Turnstile and hCaptcha. An add-on provider must supply its own
settings/selection and browser widget integration, posting its response as `swf_captcha`.
The core widget loader recognizes the two built-in providers only. The public submission
pipeline invokes the selected registered provider before file storage or submission actions.

### WP Armour (free and Extended)

Active WP Armour automatically protects public forms using `wpa_form` and
`wpa_check_is_spam()`. The free version is sufficient; Extended adds its configured word
filter. JavaScript is required. Preview and trusted `swf_submit()` calls are exempt;
SW Forms' existing protections remain active.

Spam receives a quiet confirmation without storage or actions. `wpa_handle_spammers`
receives `sw_forms`, form values and protection fields, excluding SW Forms tokens and
unrelated request metadata. Extended may log values/IPs and block IPs according to its
settings. Clear page caches after activation/deactivation.

## Embedding and document tools (0.5.0)

Gutenberg registers the dynamic block `swforms/form` with attributes `formId` (integer)
and `title` (boolean). It stores only these attributes and renders the current published
PHP form, including for ServerSideRender; drafts are not exposed. Its editor preview is
inert. WPBakery/Visual Composer support is optional: `vc_before_init` maps the existing
`sw_form` shortcode with an `id` dropdown. No editor plugin files are modified. The separate
Visual Composer Website Builder product is not targeted by this WPBakery integration.

Document import/export, templates and history contracts are in [rest-api.md](rest-api.md#form-tools-and-editor-embedding-050).
A restored draft still uses the existing published-key warnings; explicitly review keys
before publishing it. Historical entry data and its original field definitions are retained.
