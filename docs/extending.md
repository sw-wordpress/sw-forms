# Extending SW Forms

## A custom element type

Register custom field types on `swf_register_field_types` during plugin bootstrap,
before the registry is first used. Each type implements `FieldTypeInterface`; call
`$registry->register( new My_Type() )` to add it. Registering an existing slug replaces
that type; invalid slugs are ignored. Slugs must match `[a-z][a-z0-9-]{0,39}`.

The same registry drives schema validation, the builder's `/field-types` response,
rendering, submission validation and entry formatting.

```php
add_action( 'swf_register_field_types', function ( \SWForms\WordPress\Fields\FieldRegistry $registry ) {
	$registry->register( new My_Rating_Field() );
} );

final class My_Rating_Field extends \SWForms\WordPress\Fields\ChoiceField {
	public function type(): string { return 'rating'; }
	public function label(): string { return __( 'Rating', 'my-addon' ); }
	public function icon(): string { return 'r_star'; }          // Material icon (rounded)
	public function category(): string { return 'choice'; }

	public function settings_schema(): array {
		return array(
			array( 'name' => 'max', 'type' => 'number', 'integer' => true, 'min' => 2, 'max' => 10,
				'tab' => 'general', 'label' => __( 'Stars', 'my-addon' ), 'default' => 5 ),
		);
	}

	public function collect( array $raw, array $element ): mixed {
		return (string) absint( $raw[ $element['id'] ] ?? 0 );
	}

	public function validate( mixed $value, array $element ): array {
		return (int) $value > (int) $this->setting( $element, 'max', 5 ) ? array( array( 'code' => 'max', 'params' => array( 'max' => (string) $this->setting( $element, 'max', 5 ) ) ) ) : array();
	}

	public function render( array $element, \SWForms\WordPress\Frontend\RenderContext $context ): string {
		$html = '';
		for ( $i = 1; $i <= (int) $this->setting( $element, 'max', 5 ); $i++ ) {
			$html .= $this->choice( /* ... */ );
		}
		return $this->wrap( $element, $context, $html, true );
	}
}
```

* `AbstractFieldType` (and `ChoiceField` for option lists) provides settings normalisation, the accessible wrapper (`label`/`fieldset`,
  description, error slot, `aria-*`) and sensible defaults.
* The builder renders the inspector from `settings_schema()` – no JavaScript needed. Setting
  types: `text`, `textarea`, `html`, `code`, `number`, `toggle`, `select` (`choices`), `options`,
  `list`; optional `tab` (`general`, `validation`, `appearance`), `help`, `showIf`
  (`{setting, equals: [...]}`), `min`, `max`, `integer`.
* The canvas shows a placeholder for add-on types; the preview shows the real output.
* `value_kind()` tells the frontend script how to read the value for conditions
  (`text`, `bool`, `list`, `radio`, `none`).

## A custom action

```php
add_filter( 'swf_register_actions', function ( array $actions ) {
	$actions[] = new class() implements \SWForms\WordPress\Actions\ActionInterface {
		public function type(): string { return 'crm'; }
		public function run( \SWForms\WordPress\Submission\Submission $submission ): void {
			$response = wp_remote_post( 'https://crm.example/api', array( 'body' => wp_json_encode( $submission->data ) ) );
			$submission->log( $this->type(), '', is_wp_error( $response ) ? 'failed' : 'success', '' );
		}
	};
	return $actions;
} );
```

The entry log shows the logged result. Exceptions are caught and logged as `failed`.

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

Webhook configuration and request signing are described in `docs/schema.md`. Automatic
delivery and retries remain open in `TODO.md`.
