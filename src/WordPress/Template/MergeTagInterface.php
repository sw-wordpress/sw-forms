<?php
/**
 * Public merge tag contract. Return plain text; the resolver escapes for its context.
 *
 * @package SWForms
 * @since 0.4.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Template;

use SWForms\WordPress\Submission\Submission;

interface MergeTagInterface {
	public function name(): string;
	/** Null leaves an unknown tag unchanged. */
	public function resolve( string $argument, Submission $submission ): ?string;
}
