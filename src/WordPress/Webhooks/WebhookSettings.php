<?php
/**
 * Webhook document settings and the persistence/REST secret boundary.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

use SWForms\WordPress\Forms\DocumentNormalizer;

final class WebhookSettings {

	public const MAX_WEBHOOKS = 20;

	/**
	 * @param mixed $input Untrusted document setting.
	 * @return list<array<string, mixed>>
	 */
	public static function normalize( mixed $input ): array {
		$out = array();
		foreach ( is_array( $input ) ? array_slice( $input, 0, self::MAX_WEBHOOKS ) : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id    = self::text( $item['id'] ?? '' );
			$out[] = array(
				'id'      => preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $id ) ? $id : wp_generate_uuid4(),
				'name'    => sanitize_text_field( self::text( $item['name'] ?? '' ) ),
				'enabled' => (bool) ( $item['enabled'] ?? true ),
				'url'     => trim( self::text( $item['url'] ?? '', 2048 ) ),
				'method'  => self::text( $item['method'] ?? 'POST' ),
				'format'  => self::text( $item['format'] ?? 'json' ),
				'headers' => self::pairs( $item['headers'] ?? array(), 'name', 'value' ),
				'mapping' => self::pairs( $item['mapping'] ?? array(), 'field', 'target' ),
				'logic'   => DocumentNormalizer::logic( $item['logic'] ?? null ),
				// Never accept an encrypted value supplied by a client. Storage merges by id.
				'secret'  => self::text( $item['secret'] ?? '', 512 ),
			);
		}
		return $out;
	}

	/**
	 * Called at every repository write, including invalid drafts and programmatic writes.
	 * Empty/missing input secrets preserve only the same form's stored webhook id.
	 *
	 * @param array<string, mixed> $document Incoming document.
	 * @param array<string, mixed> $previous Trusted document already in the database.
	 * @return array<string, mixed>
	 */
	public static function protect( array $document, array $previous = array() ): array {
		$old = array();
		foreach ( $previous['settings']['webhooks'] ?? array() as $item ) {
			$old[ $item['id'] ] = $item['secretEncrypted'] ?? '';
		}
		$items = self::normalize( $document['settings']['webhooks'] ?? array() );
		foreach ( $items as &$item ) {
			$secret                  = $item['secret'];
			$item['secretEncrypted'] = '' !== $secret
				? SecretVault::encrypt( $secret )
				: ( $old[ $item['id'] ] ?? SecretVault::encrypt( bin2hex( random_bytes( 32 ) ) ) );
			unset( $item['secret'] );
		}
		unset( $item );
		$document['settings']['webhooks'] = $items;
		return $document;
	}

	/**
	 * Safe for REST and future document exports: never return ciphertext or plaintext.
	 *
	 * @param array<string, mixed> $document Stored document.
	 * @return array<string, mixed>
	 */
	public static function redact( array $document ): array {
		foreach ( $document['settings']['webhooks'] ?? array() as $index => $item ) {
			$item['hasSecret'] = ! empty( $item['secretEncrypted'] );
			unset( $item['secret'], $item['secretEncrypted'] );
			$document['settings']['webhooks'][ $index ] = $item;
		}
		return $document;
	}

	/**
	 * @param mixed  $input Pair list.
	 * @param string $first First property.
	 * @param string $second Second property.
	 * @return list<array<string, string>>
	 */
	private static function pairs( mixed $input, string $first, string $second ): array {
		$out = array();
		foreach ( is_array( $input ) ? array_slice( $input, 0, 50 ) : array() as $pair ) {
			if ( is_array( $pair ) ) {
				$out[] = array(
					$first  => self::text( $pair[ $first ] ?? '' ),
					$second => self::text( $pair[ $second ] ?? '', 2048 ),
				);
			}
		}
		return $out;
	}

	private static function text( mixed $value, int $length = 500 ): string {
		return is_scalar( $value ) ? mb_substr( (string) $value, 0, $length ) : '';
	}
}
