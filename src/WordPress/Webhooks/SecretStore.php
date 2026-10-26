<?php
/**
 * Encrypted webhook secrets of a form (option `swf_secrets_<form id>`, not autoloaded).
 *
 * The builder sends secrets as write-only fields: `webhook.secret` (new signing secret),
 * `webhook.clearSecret` and the `value` of a header marked `secret`. They are stored here and
 * removed from the document, so secrets never appear in form versions, exports or REST responses.
 * Responses only carry `hasSecret` / `hasValue` flags.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

use SWForms\WordPress\Support\Crypto;

final class SecretStore {

	public static function option( int $form_id ): string {
		return 'swf_secrets_' . $form_id;
	}

	/**
	 * Stores the write-only secrets of a raw builder document and returns the document without them.
	 *
	 * @param array<string, mixed> $document
	 * @return array<string, mixed>
	 */
	public function absorb( int $form_id, array $document ): array {
		$webhooks = $document['settings']['webhooks'] ?? null;
		if ( ! is_array( $webhooks ) ) {
			return $document;
		}
		$store   = $this->load( $form_id );
		$changed = false;
		foreach ( $webhooks as $index => $webhook ) {
			if ( ! is_array( $webhook ) || ! is_string( $webhook['id'] ?? null ) || WebhookSettings::id( $webhook['id'] ) !== $webhook['id'] ) {
				continue;
			}
			$id = $webhook['id'];
			if ( ! empty( $webhook['clearSecret'] ) ) {
				unset( $store[ $id ]['signing'] );
				$changed = true;
			}
			$secret = is_string( $webhook['secret'] ?? null ) ? trim( $webhook['secret'] ) : '';
			if ( '' !== $secret ) {
				$store[ $id ]['signing'] = Crypto::encrypt( mb_substr( $secret, 0, 500 ) );
				$changed                 = true;
			}
			unset( $webhooks[ $index ]['secret'], $webhooks[ $index ]['clearSecret'] );
			foreach ( is_array( $webhook['headers'] ?? null ) ? $webhook['headers'] : array() as $h => $header ) {
				if ( ! is_array( $header ) || empty( $header['secret'] ) ) {
					continue;
				}
				$name  = strtolower( trim( is_string( $header['name'] ?? null ) ? $header['name'] : '' ) );
				$value = is_scalar( $header['value'] ?? null ) ? trim( (string) preg_replace( '/[\r\n]+/', '', (string) $header['value'] ) ) : '';
				if ( '' !== $name && '' !== $value ) {
					$store[ $id ]['headers'][ $name ] = Crypto::encrypt( mb_substr( $value, 0, 2000 ) );
					$changed                          = true;
				}
				$webhooks[ $index ]['headers'][ $h ]['value'] = '';
			}
		}
		if ( $changed ) {
			$this->save( $form_id, $store );
		}
		$document['settings']['webhooks'] = $webhooks;
		return $document;
	}

	/**
	 * Adds `hasSecret` and, for secret headers, `hasValue` to the webhooks of a document (for the builder).
	 *
	 * @param array<string, mixed> $document
	 * @return array<string, mixed>
	 */
	public function decorate( int $form_id, array $document ): array {
		if ( ! is_array( $document['settings']['webhooks'] ?? null ) ) {
			return $document;
		}
		$store = $this->load( $form_id );
		foreach ( $document['settings']['webhooks'] as &$webhook ) {
			$id                   = (string) ( $webhook['id'] ?? '' );
			$webhook['hasSecret'] = isset( $store[ $id ]['signing'] );
			if ( ! is_array( $webhook['headers'] ?? null ) ) {
				continue;
			}
			foreach ( $webhook['headers'] as &$header ) {
				if ( ! empty( $header['secret'] ) ) {
					$header['hasValue'] = isset( $store[ $id ]['headers'][ strtolower( (string) $header['name'] ) ] );
				}
			}
			unset( $header );
		}
		unset( $webhook );
		return $document;
	}

	public function signing_secret( int $form_id, string $webhook_id ): ?string {
		$stored = $this->load( $form_id )[ $webhook_id ]['signing'] ?? null;
		return is_string( $stored ) ? Crypto::decrypt( $stored ) : null;
	}

	public function has_signing_secret( int $form_id, string $webhook_id ): bool {
		return isset( $this->load( $form_id )[ $webhook_id ]['signing'] );
	}

	/**
	 * Decrypted value of a secret header; null if missing or unreadable.
	 */
	public function header( int $form_id, string $webhook_id, string $name ): ?string {
		$stored = $this->load( $form_id )[ $webhook_id ]['headers'][ strtolower( $name ) ] ?? null;
		return is_string( $stored ) ? Crypto::decrypt( $stored ) : null;
	}

	/**
	 * Removes secrets of webhooks and headers that are no longer part of the (published) document.
	 *
	 * @param array<string, mixed> $document Normalized document.
	 */
	public function prune( int $form_id, array $document ): void {
		$keep = array();
		foreach ( $document['settings']['webhooks'] ?? array() as $webhook ) {
			$keep[ (string) $webhook['id'] ] = array();
			foreach ( $webhook['headers'] ?? array() as $header ) {
				if ( ! empty( $header['secret'] ) ) {
					$keep[ (string) $webhook['id'] ][ strtolower( (string) $header['name'] ) ] = true;
				}
			}
		}
		$store = $this->load( $form_id );
		$clean = array();
		foreach ( $store as $id => $secrets ) {
			if ( ! isset( $keep[ $id ] ) ) {
				continue;
			}
			if ( isset( $secrets['signing'] ) ) {
				$clean[ $id ]['signing'] = $secrets['signing'];
			}
			foreach ( $secrets['headers'] ?? array() as $name => $value ) {
				if ( isset( $keep[ $id ][ $name ] ) ) {
					$clean[ $id ]['headers'][ $name ] = $value;
				}
			}
		}
		if ( $clean !== $store ) {
			$this->save( $form_id, $clean );
		}
	}

	public function copy( int $from_form_id, int $to_form_id ): void {
		$store = $this->load( $from_form_id );
		if ( array() !== $store ) {
			$this->save( $to_form_id, $store );
		}
	}

	public function delete( int $form_id ): void {
		delete_option( self::option( $form_id ) );
	}

	/**
	 * @return array<string, array{signing?: string, headers?: array<string, string>}>
	 */
	private function load( int $form_id ): array {
		$stored = get_option( self::option( $form_id ), array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param array<string, array{signing?: string, headers?: array<string, string>}> $store
	 */
	private function save( int $form_id, array $store ): void {
		if ( array() === $store ) {
			delete_option( self::option( $form_id ) );
			return;
		}
		update_option( self::option( $form_id ), $store, false );
	}
}
