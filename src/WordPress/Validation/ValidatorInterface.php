<?php
/**
 * Public custom value validator contract.
 *
 * @package SWForms
 * @since 0.4.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Validation;

interface ValidatorInterface {
	public function slug(): string;

	/** @param array<string, mixed> $element Collected, active, non-empty field. */
	public function supports( array $element ): bool;

	/**
	 * @param array<string, mixed> $element
	 * @return list<array{code: string, params?: array<string, string|int>, message?: string}>
	 */
	public function validate( mixed $value, array $element ): array;
}
