<?php
/**
 * Translated texts for error codes produced by the core and the field types.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Support;

final class Messages {

	/**
	 * @param array<string, string|int> $params
	 */
	public static function validation( string $code, array $params = array() ): string {
		$message = match ( $code ) {
			'required'     => __( 'This field is required.', 'sw-forms' ),
			'email'        => __( 'Please enter a valid email address.', 'sw-forms' ),
			'url'          => __( 'Please enter a valid web address starting with http:// or https://.', 'sw-forms' ),
			'tel'          => __( 'Please enter a valid phone number.', 'sw-forms' ),
			'date'         => __( 'Please enter a valid date.', 'sw-forms' ),
			'time'         => __( 'Please enter a valid time.', 'sw-forms' ),
			'integer'      => __( 'Please enter a whole number.', 'sw-forms' ),
			'step'         => __( 'Please use the configured interval.', 'sw-forms' ),
			'number'       => __( 'Please enter a number.', 'sw-forms' ),
			/* translators: %s: minimum value */
			'min'          => sprintf( __( 'Please enter a value of at least %s.', 'sw-forms' ), (string) ( $params['min'] ?? '' ) ),
			/* translators: %s: maximum value */
			'max'          => sprintf( __( 'Please enter a value of at most %s.', 'sw-forms' ), (string) ( $params['max'] ?? '' ) ),
			/* translators: %d: minimum number of characters */
			'minlength'    => sprintf( _n( 'Please enter at least %d character.', 'Please enter at least %d characters.', (int) ( $params['min'] ?? 0 ), 'sw-forms' ), (int) ( $params['min'] ?? 0 ) ),
			/* translators: %d: maximum number of characters */
			'maxlength'    => sprintf( _n( 'Please enter at most %d character.', 'Please enter at most %d characters.', (int) ( $params['max'] ?? 0 ), 'sw-forms' ), (int) ( $params['max'] ?? 0 ) ),
			'pattern'      => '' !== (string) ( $params['message'] ?? '' ) ? (string) $params['message'] : __( 'Please match the requested format.', 'sw-forms' ),
			'option'       => __( 'Please choose one of the offered options.', 'sw-forms' ),
			/* translators: %d: minimum number of selections */
			'min_selected' => sprintf( _n( 'Please select at least %d option.', 'Please select at least %d options.', (int) ( $params['min'] ?? 0 ), 'sw-forms' ), (int) ( $params['min'] ?? 0 ) ),
			/* translators: %d: maximum number of selections */
			'max_selected' => sprintf( _n( 'Please select at most %d option.', 'Please select at most %d options.', (int) ( $params['max'] ?? 0 ), 'sw-forms' ), (int) ( $params['max'] ?? 0 ) ),
			/* translators: %d: minimum number of rows */
			'min_rows'     => sprintf( _n( 'Please add at least %d entry.', 'Please add at least %d entries.', (int) ( $params['min'] ?? 0 ), 'sw-forms' ), (int) ( $params['min'] ?? 0 ) ),
			default        => __( 'This value is invalid.', 'sw-forms' ),
		};
		return $message;
	}

	/**
	 * @param array<string, string|int> $params
	 */
	public static function schema( string $code, array $params = array() ): string {
		return match ( $code ) {
			'invalid_document'    => __( 'The form document is invalid.', 'sw-forms' ),
			'invalid_element'     => __( 'An element is invalid.', 'sw-forms' ),
			'invalid_id'          => __( 'An element has an invalid id.', 'sw-forms' ),
			'duplicate_id'        => __( 'Two elements share the same id.', 'sw-forms' ),
			/* translators: %s: element type */
			'unknown_type'        => sprintf( __( 'The element type "%s" is not available. Is an add-on missing?', 'sw-forms' ), (string) ( $params['type'] ?? '' ) ),
			/* translators: %s: field key */
			'invalid_key'         => sprintf( __( 'The field name "%s" is invalid. Use letters, digits and underscores; start with a letter.', 'sw-forms' ), (string) ( $params['key'] ?? '' ) ),
			/* translators: %s: field key */
			'duplicate_key'       => sprintf( __( 'The field name "%s" is used twice at the same level.', 'sw-forms' ), (string) ( $params['key'] ?? '' ) ),
			'invalid_label'       => __( 'The label is invalid.', 'sw-forms' ),
			'invalid_children'    => __( 'The contents of this container are invalid.', 'sw-forms' ),
			/* translators: %d: maximum number of columns */
			'invalid_columns'     => sprintf( __( 'A column layout needs between 1 and %d columns.', 'sw-forms' ), (int) ( $params['max'] ?? 6 ) ),
			'invalid_width'       => __( 'Column widths must be between 1 and 12.', 'sw-forms' ),
			/* translators: %d: maximum nesting depth */
			'too_deep'            => sprintf( __( 'Elements may be nested at most %d levels deep.', 'sw-forms' ), (int) ( $params['max'] ?? 8 ) ),
			'invalid_logic'       => __( 'The conditional logic is invalid.', 'sw-forms' ),
			'invalid_rule'        => __( 'A condition is invalid.', 'sw-forms' ),
			'invalid_operator'    => __( 'A condition uses an unknown comparison.', 'sw-forms' ),
			'rule_unknown_field'  => __( 'A condition refers to a field that does not exist or has no value.', 'sw-forms' ),
			'rule_self_reference' => __( 'A condition cannot refer to the element itself.', 'sw-forms' ),
			'rule_out_of_scope'   => __( 'A condition refers to a field inside a repeatable group. Only fields of the same row or outside the group can be used.', 'sw-forms' ),
			'logic_cycle'         => __( 'Conditions depend on each other in a circle.', 'sw-forms' ),
			default               => __( 'The form contains an error.', 'sw-forms' ),
		};
	}
}
