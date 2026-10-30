<?php
/**
 * Something that happens after a valid submission (notification e-mail, later: webhook).
 * Add-ons register actions on `swf_register_actions`.
 *
 * Failure policy: actions must not throw; exceptions are caught, logged on the entry and do not
 * affect the visitor's confirmation.
 *
 * Public extension API; stable from 0.4.0.
 *
 * @since 0.1.0
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Actions;

use SWForms\WordPress\Submission\Submission;

interface ActionInterface {

	/** Unique slug used in the entry log, e.g. `email`. */
	public function type(): string;

	public function run( Submission $submission ): void;
}
