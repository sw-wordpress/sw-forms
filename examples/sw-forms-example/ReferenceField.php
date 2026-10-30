<?php
/**
 * The public field implementation. PHP remains authoritative; builder.js is only editor UI.
 * AbstractFieldType supplies text collection/sanitization, empty checks, default values,
 * settings normalization and text formatting. The registered validator supplies our rule.
 * BuilderFieldTypeInterface is optional: omit it if the default builder UI is sufficient.
 */
declare(strict_types=1);
namespace SWFormsExample;

final class ReferenceField extends \SWForms\WordPress\Fields\AbstractFieldType implements \SWForms\WordPress\Fields\BuilderFieldTypeInterface {
	// Immutable type slug: stored in form documents and matched by the validator and JS UI.
	public function type(): string { return 'example-reference'; }
	// Human-readable palette name. Use the add-on's own translation domain.
	public function label(): string { return __( 'Reference', 'sw-forms-example' ); }
	// A rounded Material icon name, not HTML or an image URL.
	public function icon(): string { return 'r_confirmation_number'; }
	// Place this field in the existing Basic palette section.
	public function category(): string { return 'basic'; }
	// Match the registered WordPress handle; return neither a file path nor a URL.
	public function builder_script(): string { return 'sw-forms-example'; }
	// These definitions generate standard inspector controls and constrain saved settings.
	// The settings below demonstrate API shapes; they do not change submission processing.
	public function settings_schema(): array {
		return array(
			// Also edited by our custom inspector and displayed in the canvas approximation.
			array( 'name' => 'caption', 'type' => 'text', 'label' => 'Caption', 'default' => '' ),
			// Store an element ID so changing its label/key cannot silently change this reference.
			// An add-on using it must additionally check existence and scope on the server.
			array( 'name' => 'related', 'type' => 'field-picker', 'label' => 'Related field', 'default' => '' ),
			// Ordered {key, value} rows; the cap applies in the UI and server normalization.
			array( 'name' => 'metadata', 'type' => 'key-value', 'label' => 'Metadata', 'default' => array(), 'maxItems' => 5 ),
			// Each row is normalized against fields; undeclared properties are discarded.
			array( 'name' => 'steps', 'type' => 'repeater', 'label' => 'Steps', 'default' => array(), 'maxItems' => 3, 'fields' => array(
				array( 'name' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => '' ),
				array( 'name' => 'enabled', 'type' => 'toggle', 'label' => 'Enabled', 'default' => true ),
			) ),
		);
	}
	// Used for the actual public form, preview and server-rendered validation errors.
	public function render( array $element, \SWForms\WordPress\Frontend\RenderContext $context ): string {
		// wrap() adds the label, description and error slot. attrs() escapes attribute values.
		// aria() links the input to that description/error slot and reports validation state.
		// Always use RenderContext: names and IDs differ inside repeater rows. value() restores
		// collected values after a failed postback. A placeholder is a hint, not validation.
		return $this->wrap( $element, $context, '<input' . $this->attrs( array_merge( $this->aria( $element, $context ), array(
			'type' => 'text', 'id' => $context->dom_id( $element['id'] ), 'name' => $context->name( $element['id'] ),
			'value' => (string) $context->value( $element['id'] ), 'placeholder' => 'REF-123',
		) ) ) . '>' );
	}
}
