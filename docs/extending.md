# Extending SW Forms

## A custom element type

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
