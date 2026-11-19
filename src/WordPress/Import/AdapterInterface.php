<?php
/**
 * Lazy, read-only source adapter contract.
 *
 * @package SWForms
 * @since 0.8.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Import;

interface AdapterInterface {

	/** @return list<array{id: int, title: string}> One page of source forms. */
	public function forms( int $page ): array;

	/**
	 * Read a source without changing it. Return null for missing/invalid sources.
	 * Report every omitted feature; never execute source markup or import delivery settings.
	 *
	 * @return array{title: string, document: array<string, mixed>, imported: list<string>, skipped: list<string>, warnings: list<string>}|null
	 */
	public function read( int $id ): ?array;
}
