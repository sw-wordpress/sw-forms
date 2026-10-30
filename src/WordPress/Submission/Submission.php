<?php
/**
 * A validated submission as handed to actions (`ActionInterface::run()`) and hooks.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Submission;

use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Visibility;
use SWForms\WordPress\Entries\EntryRepository;

final class Submission {

	/**
	 * @param array<string, mixed> $form     Form row (id, title, ...).
	 * @param array<string, mixed> $document Published document the submission was validated against.
	 * @param array<string, mixed> $values   Clean values keyed by element id.
	 * @param array<string, mixed> $data     Public data keyed by field key.
	 * @param int                  $entry_id 0 when entries are not stored.
	 */
	public function __construct(
		public readonly array $form,
		public readonly int $version,
		public readonly array $document,
		public readonly Tree $tree,
		public readonly array $values,
		public readonly Visibility $visibility,
		public readonly array $data,
		public readonly int $entry_id,
		private readonly EntryRepository $entries,
		public readonly string $source_url = '',
	) {}

	public function form_id(): int {
		return (int) $this->form['id'];
	}

	/**
	 * Records the outcome of an action in the entry log (only when the entry is stored).
	 *
	 * @param string $status `success`, `failed` or `skipped`.
	 */
	public function log( string $type, string $action_id, string $status, string $message = '' ): void {
		if ( $this->entry_id > 0 ) {
			$this->entries->log( $this->entry_id, $this->form_id(), $type, $action_id, $status, $message );
		}
	}
}
