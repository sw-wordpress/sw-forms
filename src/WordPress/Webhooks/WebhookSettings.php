<?php
/**
 * Normalization and validation of the webhooks in the form settings (docs/schema.md "Webhooks").
 *
 * Secrets are not part of the document: the signing secret and secret header values are
 * write-only fields handled by {@see SecretStore}.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

use SWForms\Core\Form\Tree;
use SWForms\Core\Webhook\NetworkGuard;
use SWForms\Core\Webhook\PayloadMapper;
use SWForms\WordPress\Forms\DocumentNormalizer;

final class WebhookSettings {

	public const MAX_WEBHOOKS = 20;
	public const MAX_HEADERS  = 20;
	public const MAX_MAPPING  = 200;
	public const METHODS      = array( 'POST', 'PUT', 'PATCH' );
	public const FORMATS      = array( 'json', 'form' );
	/** Set by the plugin or the HTTP transport. */
	public const RESERVED_HEADERS = array( 'host', 'content-length', 'content-type', 'connection', 'transfer-encoding', 'expect' );

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function normalize_list( mixed $input ): array {
		$out = array();
		foreach ( is_array( $input ) ? $input : array() as $webhook ) {
			if ( is_array( $webhook ) && count( $out ) < self::MAX_WEBHOOKS ) {
				$out[] = self::normalize( $webhook );
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function normalize( array $input ): array {
		$method  = strtoupper( is_string( $input['method'] ?? null ) ? $input['method'] : 'POST' );
		$headers = array();
		foreach ( is_array( $input['headers'] ?? null ) ? $input['headers'] : array() as $header ) {
			if ( ! is_array( $header ) || count( $headers ) >= self::MAX_HEADERS ) {
				continue;
			}
			$secret    = (bool) ( $header['secret'] ?? false );
			$headers[] = array(
				'name'   => self::single_line( $header['name'] ?? '', 100 ),
				// Secret values live in the SecretStore only.
				'value'  => $secret ? '' : self::single_line( $header['value'] ?? '', 2000 ),
				'secret' => $secret,
			);
		}
		$mapping = array();
		foreach ( is_array( $input['mapping'] ?? null ) ? $input['mapping'] : array() as $row ) {
			if ( is_array( $row ) && count( $mapping ) < self::MAX_MAPPING ) {
				$mapping[] = array(
					'field' => is_string( $row['field'] ?? null ) ? $row['field'] : '',
					'name'  => self::single_line( $row['name'] ?? '', 200 ),
				);
			}
		}
		return array(
			'id'       => self::id( $input['id'] ?? null ),
			'name'     => self::single_line( $input['name'] ?? '', 200 ),
			'enabled'  => (bool) ( $input['enabled'] ?? true ),
			'url'      => self::single_line( $input['url'] ?? '', 2000 ),
			'method'   => in_array( $method, self::METHODS, true ) ? $method : 'POST',
			'format'   => in_array( $input['format'] ?? '', self::FORMATS, true ) ? $input['format'] : 'json',
			'envelope' => (bool) ( $input['envelope'] ?? true ),
			'fields'   => 'custom' === ( $input['fields'] ?? '' ) ? 'custom' : 'all',
			'mapping'  => $mapping,
			'headers'  => $headers,
			'logic'    => DocumentNormalizer::logic( $input['logic'] ?? null ),
		);
	}

	public static function id( mixed $id ): string {
		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ? $id : wp_generate_uuid4();
	}

	/**
	 * Problems of the webhooks of a valid document, as `{path: "webhook:<id>", code}` pairs.
	 *
	 * @param list<array<string, mixed>> $webhooks
	 * @return list<array{path: string, code: string}>
	 */
	public static function validate( array $webhooks, Tree $tree ): array {
		$errors = array();
		$mapper = new PayloadMapper( $tree );
		foreach ( $webhooks as $webhook ) {
			$path  = 'webhook:' . (string) $webhook['id'];
			$codes = array();
			$url   = (string) $webhook['url'];
			if ( '' === $url ) {
				$codes[] = 'webhook_url_missing';
			} elseif ( NetworkGuard::OK !== NetworkGuard::parseUrl( $url )['error'] ) {
				$codes[] = 'webhook_url';
			}
			$names = array();
			foreach ( $webhook['headers'] as $header ) {
				$name = strtolower( (string) $header['name'] );
				if ( 1 !== preg_match( "/^[A-Za-z0-9!#$%&'*+.^_`|~-]{1,100}$/", $name ) || in_array( $name, self::RESERVED_HEADERS, true ) || str_starts_with( $name, 'x-swforms-' ) || isset( $names[ $name ] ) ) {
					$codes[] = 'webhook_header';
					break;
				}
				$names[ $name ] = true;
			}
			if ( 'custom' === $webhook['fields'] ) {
				$targets = array();
				foreach ( $webhook['mapping'] as $row ) {
					if ( ! $mapper->isMappable( (string) $row['field'] ) || ! PayloadMapper::validName( (string) $row['name'] ) || isset( $targets[ $row['name'] ] ) ) {
						$codes[] = 'webhook_mapping';
						break;
					}
					$targets[ $row['name'] ] = true;
				}
				if ( array() === $webhook['mapping'] ) {
					$codes[] = 'webhook_mapping_empty';
				}
			}
			// Conditions are evaluated at form level, like those of notifications.
			foreach ( $webhook['logic']['rules'] ?? array() as $rule ) {
				$field = (string) ( $rule['field'] ?? '' );
				if ( ! $tree->isLeafData( $field ) || null !== $tree->scopeOf( $field ) ) {
					$codes[] = 'rule_unknown_field';
					break;
				}
			}
			foreach ( $codes as $code ) {
				$errors[] = array(
					'path' => $path,
					'code' => $code,
				);
			}
		}
		return $errors;
	}

	private static function single_line( mixed $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		// Header and URL values must never contain line breaks (header injection).
		return mb_substr( trim( (string) preg_replace( '/[\x00-\x1f\x7f]+/', '', (string) $value ) ), 0, $max );
	}
}
