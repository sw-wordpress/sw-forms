<?php
/**
 * Builds a filtered payload and signs the exact transport body. Does not send HTTP.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

use SWForms\Core\Webhooks\Payload;
use SWForms\Core\Webhooks\Signature;
use SWForms\WordPress\Submission\Submission;

final class RequestBuilder {

	/**
	 * @param array<string, mixed> $webhook Stored webhook configuration.
	 * @return array<string, mixed>
	 */
	public function payload( Submission $submission, array $webhook, string $event_id ): array {
		$entry   = $submission->entry_id > 0 ? ( new \SWForms\WordPress\Entries\EntryRepository() )->find( $submission->entry_id ) : null;
		$data    = ( new Payload( $submission->tree ) )->map( $submission->data, $webhook['mapping'] );
		$payload = Payload::envelope( $event_id, $submission->form_id(), $submission->form['title'], $submission->version, $submission->entry_id, $entry['createdAt'] ?? gmdate( 'c' ), $data );
		/**
		 * Filters the final webhook envelope before serialization and signing.
		 *
		 * @since 0.2.0
		 * @param array<string, mixed> $payload Envelope.
		 * @param array<string, mixed> $webhook Webhook settings without signing credentials.
		 * @param Submission $submission Submission.
		 */
		$safe = $webhook;
		unset( $safe['secret'], $safe['secretEncrypted'] );
		return (array) apply_filters( 'swf_webhook_payload', $payload, $safe, $submission );
	}

	/**
	 * @param array<string, mixed> $payload Final filtered envelope.
	 * @param array<string, mixed> $webhook Stored webhook configuration.
	 * @return array{method: string, headers: array<string, string>, body: string, timeout: int, redirection: int, limit_response_size: int}
	 * @throws \InvalidArgumentException For invalid transport settings.
	 * @throws \RuntimeException For encoding or decryption failures.
	 */
	public function build( array $payload, array $webhook ): array {
		if ( ! in_array( $webhook['method'], array( 'POST', 'PUT' ), true ) || ! in_array( $webhook['format'], array( 'json', 'form' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook transport settings.' );
		}
		if ( 'json' === $webhook['format'] ) {
			$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $body ) {
				throw new \RuntimeException( 'Webhook payload cannot be encoded.' );
			}
		} else {
			$body = http_build_query( $payload, '', '&', PHP_QUERY_RFC3986 );
		}
		$headers = array();
		foreach ( $webhook['headers'] as $header ) {
			$headers[ $header['name'] ] = $header['value'];
		}
		$headers['Content-Type']        = 'json' === $webhook['format'] ? 'application/json; charset=UTF-8' : 'application/x-www-form-urlencoded; charset=UTF-8';
		$headers['X-SWForms-Signature'] = Signature::sign( $body, SecretVault::decrypt( $webhook['secretEncrypted'] ) );
		return array(
			'method'              => $webhook['method'],
			'headers'             => $headers,
			'body'                => $body,
			'timeout'             => 15,
			'redirection'         => 0,
			'limit_response_size' => 2048,
		);
	}
}
