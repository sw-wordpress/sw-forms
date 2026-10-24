<?php
/**
 * Contract of an element type (built-in or registered by an add-on via `swf_register_field_types`).
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Frontend\RenderContext;

interface FieldTypeInterface {

	/** Unique type slug, e.g. `input`. Lowercase letters, digits and dashes. */
	public function type(): string;

	/** Translated name shown in the builder palette. */
	public function label(): string;

	/** Material icon name (rounded set, e.g. `r_short_text`) for the palette. */
	public function icon(): string;

	/** Palette section: `basic`, `choice`, `layout` or a custom slug. */
	public function category(): string;

	/** Whether the element produces a value in the entry. */
	public function is_data(): bool;

	/** One of {@see \SWForms\Core\Form\TypeCatalog} CONTAINER_* constants. */
	public function container(): string;

	/**
	 * Common element properties the builder offers for this type.
	 *
	 * @return list<string> Subset of `key`, `label`, `description`, `required`, `cssClass`, `adminLabel`, `logic`.
	 */
	public function supports(): array;

	/**
	 * Inspector definition (docs/extending.md "Settings schema").
	 *
	 * @return list<array<string, mixed>>
	 */
	public function settings_schema(): array;

	/**
	 * Settings of a new element.
	 *
	 * @return array<string, mixed>
	 */
	public function default_settings(): array;

	/**
	 * Cleans settings coming from the builder before they are stored.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public function normalize_settings( array $settings ): array;

	/**
	 * Initial value (no submission yet).
	 *
	 * @param array<string, mixed> $element
	 */
	public function default_value( array $element ): mixed;

	/**
	 * Reads and sanitizes the submitted value from the raw request data of the element's scope.
	 *
	 * @param array<string, mixed> $raw     Unslashed request data of the scope, keyed by element id.
	 * @param array<string, mixed> $element
	 */
	public function collect( array $raw, array $element ): mixed;

	/**
	 * @param array<string, mixed> $element
	 */
	public function is_empty( mixed $value, array $element ): bool;

	/**
	 * Validates a non-empty value. Required checks happen centrally.
	 *
	 * @param array<string, mixed> $element
	 * @return list<array{code: string, params?: array<string, string|int>}>
	 */
	public function validate( mixed $value, array $element ): array;

	/**
	 * Escaped HTML of the element.
	 *
	 * @param array<string, mixed> $element
	 */
	public function render( array $element, RenderContext $context ): string;

	/**
	 * Plain text representation of a value (notifications, entry lists).
	 *
	 * @param array<string, mixed> $element
	 */
	public function format_text( mixed $value, array $element ): string;

	/**
	 * How the frontend script reads the value: `text`, `bool`, `list`, `radio` or `none`.
	 */
	public function value_kind( array $element ): string;
}
