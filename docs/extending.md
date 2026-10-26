# Extending SW Forms

## A custom element type

Use the filter to add types before the registry is built. Register this callback during plugin
bootstrap (before the registry is first used). The same registry drives schema validation,
the builder's `/field-types` response, rendering, submission validation and entry formatting.

```php
add_filter( 'swf_field_types', function ( array $types ): array {
    $types[] = new My_Rating_Field();
    return $types;
} );
```

Every item must implement `FieldTypeInterface`; invalid items are ignored. The array order
controls replacement: the last instance of a slug wins. Return an array (including an empty
array to remove all built-ins). A non-array result falls back to the built-in list. Type slugs
must match `[a-z][a-z0-9-]{0,39}`.

The existing action remains supported and runs **after** the filter. Register a custom type
with either approach, rather than both:

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
* Date boundaries in this increment are explicit ISO values. Dynamic relative
  boundaries, email confirmation, password strength and additional choice/media/
  structured types from issue #2 remain follow-up work. Existing email, phone, URL,
  number, date, password, hidden, multi-select and single-checkbox functionality is retained.
