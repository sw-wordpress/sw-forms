<?php
/**
 * Outcome of a submission attempt.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Submission;

final class SubmissionResult {

	public const SUCCESS = 'success';
	public const INVALID = 'invalid';
	public const ERROR   = 'error';

	/**
	 * @param string                $status   One of the constants.
	 * @param string                $code     Machine readable reason for errors (e.g. `expired`, `too_fast`).
	 * @param string                $message  Confirmation (HTML, already escaped/kses'd) or error text.
	 * @param array<string, string> $errors   Field errors per path.
	 * @param array<string, mixed>  $values   Submitted clean values, for re-rendering without JavaScript.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $code = '',
		public readonly string $message = '',
		public readonly array $errors = array(),
		public readonly string $redirect = '',
		public readonly int $entry_id = 0,
		public readonly array $values = array(),
	) {}

	public static function error( string $code, string $message ): self {
		return new self( self::ERROR, $code, $message );
	}

	/**
	 * REST representation. The entry id is not exposed to visitors.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'status'   => $this->status,
			'code'     => $this->code,
			'message'  => $this->message,
			'errors'   => (object) $this->errors,
			'redirect' => $this->redirect,
		);
	}

	public function http_status(): int {
		return match ( $this->status ) {
			self::SUCCESS => 200,
			self::INVALID => 422,
			default       => in_array( $this->code, array( 'not_found', 'unavailable' ), true ) ? 404 : ( 'rate_limited' === $this->code ? 429 : 400 ),
		};
	}
}
