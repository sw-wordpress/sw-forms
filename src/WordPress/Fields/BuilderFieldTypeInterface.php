<?php
/**
 * Optional companion to FieldTypeInterface for custom Vue inspector/canvas components.
 *
 * @package SWForms
 * @since 0.4.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Fields;

interface BuilderFieldTypeInterface {
	/** A registered WordPress script handle depending on sw-forms-builder-api. */
	public function builder_script(): string;
}
