<?php
/**
 * Shorthand builders for the built-in template documents, so the catalog stays readable.
 * Every helper emits ordinary schema version 1 elements (docs/schema.md); nothing here is
 * template specific and add-ons may reuse the helpers for their own starter documents.
 *
 * @package SWForms
 * @since 0.7.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Forms;

final class TemplateElements {

	/**
	 * Element with `id` and `key` set to the same stable name.
	 *
	 * @param array<string, mixed> $settings type specific settings
	 * @param array<string, mixed> $extra    common properties such as `required`, `description`, `logic`
	 * @return array<string, mixed>
	 */
	public static function element( string $type, string $id, string $label, array $settings = array(), array $extra = array() ): array {
		$element = array_merge(
			array(
				'id'    => $id,
				'type'  => $type,
				'key'   => $id,
				'label' => $label,
			),
			$extra
		);
		if ( array() !== $settings ) {
			$element['settings'] = $settings;
		}
		return $element;
	}

	/**
	 * Layout element without a data key.
	 *
	 * @param array<string, mixed> $settings type specific settings
	 * @param array<string, mixed> $extra    common properties such as `description`, `logic`
	 * @return array<string, mixed>
	 */
	public static function layout( string $type, string $id, string $label = '', array $settings = array(), array $extra = array() ): array {
		$element = array_merge(
			array(
				'id'   => $id,
				'type' => $type,
			),
			$extra
		);
		if ( '' !== $label ) {
			$element['label'] = $label;
		}
		if ( array() !== $settings ) {
			$element['settings'] = $settings;
		}
		return $element;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function text( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::element( 'input', $id, $label, $settings, $extra );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function email( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::text( $id, $label, array_merge( array( 'inputType' => 'email' ), $settings ), $extra );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function telephone( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::text( $id, $label, array_merge( array( 'inputType' => 'tel' ), $settings ), $extra );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function link( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::text( $id, $label, array_merge( array( 'inputType' => 'url' ), $settings ), $extra );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function number( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::text( $id, $label, array_merge( array( 'inputType' => 'number' ), $settings ), $extra );
	}

	/**
	 * Dates are validated against the site calendar; `minDate`/`maxDate` accept the relative
	 * `today` grammar (see docs/schema.md), so cached pages re-check them on submission.
	 *
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function date( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::text( $id, $label, array_merge( array( 'inputType' => 'date' ), $settings ), $extra );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function time( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::text( $id, $label, array_merge( array( 'inputType' => 'time' ), $settings ), $extra );
	}

	/**
	 * Birthdate type: valid, non-future date and optional `minAge`/`maxAge` in completed years.
	 *
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function birthdate( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::element( 'birthdate', $id, $label, $settings, $extra );
	}

	/**
	 * Money amount: a number field with two decimals. There is no separate currency type, so the
	 * currency belongs in the label or description and stays part of one plain number.
	 *
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function amount( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::number( $id, $label, array_merge( array( 'step' => 0.01 ), $settings ), $extra );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function textarea( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::element( 'textarea', $id, $label, $settings, $extra );
	}

	/**
	 * @param list<array<string, string>> $options
	 * @param array<string, mixed>        $settings
	 * @param array<string, mixed>        $extra
	 * @return array<string, mixed>
	 */
	public static function select( string $id, string $label, array $options, array $settings = array(), array $extra = array() ): array {
		return self::element( 'select', $id, $label, array_merge( array( 'options' => $options ), $settings ), $extra );
	}

	/**
	 * @param list<array<string, string>> $options
	 * @param array<string, mixed>        $settings
	 * @param array<string, mixed>        $extra
	 * @return array<string, mixed>
	 */
	public static function radio( string $id, string $label, array $options, array $settings = array(), array $extra = array() ): array {
		return self::element( 'radio', $id, $label, array_merge( array( 'options' => $options ), $settings ), $extra );
	}

	/**
	 * Checkbox group (a list of selected option values).
	 *
	 * @param list<array<string, string>> $options
	 * @param array<string, mixed>        $settings
	 * @param array<string, mixed>        $extra
	 * @return array<string, mixed>
	 */
	public static function checkbox_group( string $id, string $label, array $options, array $settings = array(), array $extra = array() ): array {
		return self::element( 'checkbox', $id, $label, array_merge( array( 'options' => $options ), $settings ), $extra );
	}

	/**
	 * Single confirmation checkbox; `$text` is the safe inline HTML next to the box.
	 *
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function consent( string $id, string $label, string $text = '', array $extra = array() ): array {
		$settings = array(
			'mode' => 'single',
			'text' => $text,
		);
		return self::element( 'checkbox', $id, $label, $settings, array_merge( array( 'required' => true ), $extra ) );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function rating( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::element( 'rating', $id, $label, $settings, $extra );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function scale( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::element( 'scale', $id, $label, $settings, $extra );
	}

	/**
	 * Uploads keep the engine's server-side checks: extension allowlist, size and count.
	 *
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function upload( string $id, string $label, array $settings = array(), array $extra = array() ): array {
		return self::element( 'file', $id, $label, $settings, $extra );
	}

	/**
	 * Informational block: safe inline HTML only (`a`, `strong`, `em`, `br`), blank lines become
	 * paragraphs. It produces no entry value and has no label of its own.
	 *
	 * @param array<string, mixed> $extra common properties such as `logic`
	 * @return array<string, mixed>
	 * @since 0.7.0
	 */
	public static function note( string $id, string $content, array $extra = array() ): array {
		return self::layout( 'html', $id, '', array( 'content' => $content ), $extra );
	}

	/**
	 * Section separator for multi-step forms; its label titles the following step.
	 *
	 * @return array<string, mixed>
	 */
	public static function step( string $id, string $label ): array {
		return self::layout( 'page-break', $id, $label );
	}

	/**
	 * Field group. Its children form their own data object.
	 *
	 * @param list<array<string, mixed>> $children
	 * @param array<string, mixed>       $settings
	 * @param array<string, mixed>       $extra
	 * @return array<string, mixed>
	 */
	public static function group( string $id, string $label, array $children, array $settings = array(), array $extra = array() ): array {
		$element             = self::element( 'group', $id, $label, $settings, $extra );
		$element['children'] = $children;
		return $element;
	}

	/**
	 * Repeatable group (a list of objects). Rows are added by the visitor.
	 *
	 * @param list<array<string, mixed>> $children
	 * @param array<string, mixed>       $settings
	 * @param array<string, mixed>       $extra
	 * @return array<string, mixed>
	 */
	public static function repeater( string $id, string $label, array $children, array $settings = array(), array $extra = array() ): array {
		return self::group( $id, $label, $children, array_merge( array( 'repeatable' => true ), $settings ), $extra );
	}

	/**
	 * One cell of a row: `width` in twelfths of the grid (12 = full width).
	 *
	 * @param array<string, mixed> ...$fields
	 * @return array<string, mixed>
	 */
	public static function cell( int $width, array ...$fields ): array {
		return array(
			'width'    => $width,
			'children' => array_values( $fields ),
		);
	}

	/**
	 * Column layout. Cells stack on narrow screens, so every row is responsive.
	 *
	 * @param array<string, mixed> ...$cells
	 * @return array<string, mixed>
	 */
	public static function row( string $id, array ...$cells ): array {
		$columns = array();
		$index   = 0;
		foreach ( $cells as $cell ) {
			++$index;
			$columns[] = array(
				'id'       => $id . '_' . $index,
				'width'    => (int) $cell['width'],
				'children' => $cell['children'],
			);
		}
		return array(
			'id'      => $id,
			'type'    => 'columns',
			'columns' => $columns,
		);
	}

	/**
	 * Two half-width fields side by side.
	 *
	 * @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 * @return array<string, mixed>
	 */
	public static function half_row( string $id, array $left, array $right ): array {
		return self::row( $id, self::cell( 6, $left ), self::cell( 6, $right ) );
	}

	/**
	 * Stable choice entry.
	 *
	 * @return array<string, string>
	 */
	public static function choice( string $value, string $label ): array {
		return array(
			'value' => $value,
			'label' => $label,
		);
	}

	/**
	 * One condition rule. Conditions reference element ids, never labels.
	 *
	 * @return array<string, mixed>
	 */
	public static function rule( string $field, string $op, string $value = '' ): array {
		$rule = array(
			'field' => $field,
			'op'    => $op,
		);
		if ( ! in_array( $op, array( 'empty', 'notempty' ), true ) ) {
			$rule['value'] = $value;
		}
		return $rule;
	}

	/**
	 * Reusable choice lists (values are data contracts, labels are translatable).
	 *
	 * @return list<array<string, string>>
	 */
	public static function salutations(): array {
		return array(
			self::choice( 'mr', __( 'Mr', 'sw-forms' ) ),
			self::choice( 'ms', __( 'Ms', 'sw-forms' ) ),
			self::choice( 'diverse', __( 'Diverse', 'sw-forms' ) ),
			self::choice( 'none', __( 'Prefer not to say', 'sw-forms' ) ),
		);
	}

	/**
	 * @return list<array<string, string>>
	 */
	public static function days(): array {
		return array(
			self::choice( 'monday', __( 'Monday', 'sw-forms' ) ),
			self::choice( 'tuesday', __( 'Tuesday', 'sw-forms' ) ),
			self::choice( 'wednesday', __( 'Wednesday', 'sw-forms' ) ),
			self::choice( 'thursday', __( 'Thursday', 'sw-forms' ) ),
			self::choice( 'friday', __( 'Friday', 'sw-forms' ) ),
			self::choice( 'saturday', __( 'Saturday', 'sw-forms' ) ),
			self::choice( 'sunday', __( 'Sunday', 'sw-forms' ) ),
		);
	}

	/**
	 * @return list<array<string, string>>
	 */
	public static function day_parts(): array {
		return array(
			self::choice( 'morning', __( 'Morning', 'sw-forms' ) ),
			self::choice( 'midday', __( 'Midday', 'sw-forms' ) ),
			self::choice( 'afternoon', __( 'Afternoon', 'sw-forms' ) ),
			self::choice( 'evening', __( 'Evening', 'sw-forms' ) ),
		);
	}

	/**
	 * Starter settings: no automatic e-mails (managers choose recipients explicitly) and a
	 * confirmation that lists the submitted values, so every template shows a summary.
	 *
	 * @param array<string, mixed> $extra overrides, e.g. `steps`
	 * @return array<string, mixed>
	 */
	public static function settings( array $extra = array() ): array {
		return array_merge(
			array(
				// Starters never send mail on their own: an unconfigured recipient would leak entries.
				'notifications' => array(),
				'confirmation'  => array(
					'type'    => 'message',
					'message' => __( 'Thank you! Your entries:', 'sw-forms' ) . '<p>{all_fields}</p>',
				),
			),
			$extra
		);
	}

	/**
	 * Multi-step settings with a progress indicator; the first step needs its own title.
	 *
	 * @return array<string, mixed>
	 */
	public static function step_settings( string $first_label, string $indicator = 'numbers' ): array {
		return self::settings(
			array(
				'steps' => array(
					'enabled'    => true,
					'firstLabel' => $first_label,
					'indicator'  => $indicator,
				),
			)
		);
	}

	/**
	 * Visibility condition. Hidden fields are not validated and never stored.
	 *
	 * @param list<array<string, mixed>> $rules
	 * @return array<string, mixed>
	 */
	public static function show( array $rules, string $match = 'all' ): array {
		return array(
			'action' => 'show',
			'match'  => $match,
			'rules'  => $rules,
		);
	}

	/**
	 * Conditional requirement. A field that is also hidden stays unvalidated, so pair
	 * `require` with the same rule on a visible field only.
	 *
	 * @param list<array<string, mixed>> $rules
	 * @return array<string, mixed>
	 */
	public static function require_if( array $rules, string $match = 'all' ): array {
		return array(
			'action' => 'require',
			'match'  => $match,
			'rules'  => $rules,
		);
	}
}
