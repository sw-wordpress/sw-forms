<?php
/**
 * Sends the notification e-mails configured in the form settings.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Actions;

use SWForms\Core\Logic\Evaluator;
use SWForms\Core\Template\MergeTags;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Submission\Submission;
use SWForms\WordPress\Support\Settings;
use SWForms\WordPress\Template\MergeTagResolver;

final class EmailNotifications implements ActionInterface {

	public function __construct( private readonly FieldRegistry $registry ) {}

	public function type(): string {
		return 'email';
	}

	public function run( Submission $submission ): void {
		$notifications = $submission->document['settings']['notifications'] ?? array();
		$resolver      = new MergeTagResolver( $submission, $this->registry );
		$evaluator     = new Evaluator( $submission->tree );
		foreach ( is_array( $notifications ) ? $notifications : array() as $notification ) {
			if ( empty( $notification['enabled'] ) ) {
				continue;
			}
			$id = (string) $notification['id'];
			if ( ! $evaluator->conditionMet( $notification['logic'] ?? null, $submission->values ) ) {
				$submission->log( $this->type(), $id, 'skipped', __( 'Condition not met.', 'sw-forms' ) );
				continue;
			}
			$recipients = self::addresses( $resolver->replace( (string) $notification['to'], MergeTagResolver::CONTEXT_TEXT ) );
			if ( array() === $recipients ) {
				$submission->log( $this->type(), $id, 'failed', __( 'No valid recipient address.', 'sw-forms' ) );
				continue;
			}
			// Header injection guard: no line breaks in single line values.
			$subject = trim( (string) preg_replace( '/[\r\n]+/', ' ', $resolver->replace( (string) $notification['subject'], MergeTagResolver::CONTEXT_TEXT ) ) );
			$body    = $resolver->replace( (string) $notification['message'], MergeTagResolver::CONTEXT_HTML );
			$body    = '<!doctype html><html><body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;font-size:14px;line-height:1.5">' . wpautop( $body ) . '</body></html>';
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );

			$reply_to = self::addresses( $resolver->replace( (string) $notification['replyTo'], MergeTagResolver::CONTEXT_TEXT ) );
			if ( array() !== $reply_to ) {
				$headers[] = 'Reply-To: ' . $reply_to[0];
			}
			$settings  = Settings::all();
			$from_name = trim( (string) preg_replace( '/[\r\n<>"]+/', ' ', $resolver->replace( (string) $notification['fromName'], MergeTagResolver::CONTEXT_TEXT ) ) );
			$from_name = '' !== $from_name ? $from_name : $settings['from_name'];
			if ( '' !== $settings['from_email'] ) {
				$headers[] = 'From: ' . ( '' !== $from_name ? '"' . $from_name . '" ' : '' ) . '<' . $settings['from_email'] . '>';
			}

			/**
			 * Filters the arguments of a notification e-mail.
			 *
			 * @since 0.1.0
			 *
			 * @param array{to: list<string>, subject: string, message: string, headers: list<string>} $args
			 * @param array<string, mixed> $notification Notification settings.
			 * @param Submission           $submission   Current submission.
			 */
			$args = apply_filters(
				'swf_email_args',
				array(
					'to'      => $recipients,
					'subject' => $subject,
					'message' => $body,
					'headers' => $headers,
				),
				$notification,
				$submission
			);
			$sent = wp_mail( $args['to'], $args['subject'], $args['message'], $args['headers'] );
			$submission->log(
				$this->type(),
				$id,
				$sent ? 'success' : 'failed',
				$sent
					/* translators: %s: comma separated e-mail addresses */
					? sprintf( __( 'Sent to %s.', 'sw-forms' ), implode( ', ', $args['to'] ) )
					: __( 'wp_mail() reported an error. Check the mail configuration of the site.', 'sw-forms' )
			);
		}
	}

	/**
	 * Valid addresses of a comma or semicolon separated list (merge tags already resolved).
	 *
	 * @return list<string>
	 */
	public static function addresses( string $list ): array {
		$out = array();
		foreach ( preg_split( '/[,;]+/', $list ) ?: array() as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate || array() !== MergeTags::tags( $candidate ) ) {
				continue;
			}
			$email = sanitize_email( $candidate );
			if ( is_email( $email ) ) {
				$out[] = $email;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
