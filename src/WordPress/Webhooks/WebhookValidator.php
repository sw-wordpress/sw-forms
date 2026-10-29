<?php
/**
 * Reject invalid webhook configuration before publishing.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

use SWForms\Core\Form\Tree;
use SWForms\Core\Form\SchemaValidator;
use SWForms\Core\Logic\Evaluator;

final class WebhookValidator {

	/**
	 * @param list<array<string, mixed>> $webhooks Normalized webhooks.
	 * @return list<array{path: string, code: string, message: string}>
	 */
	public static function validate( array $webhooks, Tree $tree ): array {
		$errors = array();
		$ids    = array();
		foreach ( $webhooks as $item ) {
			$id         = $item['id'];
			$invalid    = isset( $ids[ $id ] ) || ! in_array( $item['method'], array( 'POST', 'PUT' ), true ) || ! in_array( $item['format'], array( 'json', 'form' ), true );
			$ids[ $id ] = true;
			$url        = wp_parse_url( $item['url'] );
			if ( $item['enabled'] && ( ! is_array( $url ) || ! in_array( strtolower( $url['scheme'] ?? '' ), array( 'http', 'https' ), true ) || empty( $url['host'] ) || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['fragment'] ) || preg_match( '/[\x00-\x20\x7f]/', $item['url'] ) ) ) {
				$invalid = true;
			}
			$names = array();
			foreach ( $item['headers'] as $header ) {
				$name = strtolower( $header['name'] );
				if ( ! preg_match( "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name ) || isset( $names[ $name ] ) || in_array( $name, array( 'host', 'content-type', 'content-length', 'connection', 'transfer-encoding', 'expect', 'x-swforms-signature' ), true ) || preg_match( '/[\r\n\x00]/', $header['value'] ) ) {
					$invalid = true;
				}
				$names[ $name ] = true;
			}
			$targets = array();
			foreach ( $item['mapping'] as $mapping ) {
				$field  = $mapping['field'];
				$target = $mapping['target'];
				if ( ! $tree->isData( $field ) || null !== $tree->scopeOf( $field ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $target ) || isset( $targets[ $target ] ) ) {
					$invalid = true;
				}
				$targets[ $target ] = true;
			}
			$rules = $item['logic']['rules'] ?? array();
			if ( count( $rules ) > SchemaValidator::MAX_RULES ) {
				$invalid = true;
			}
			foreach ( $rules as $rule ) {
				if ( ! $tree->isLeafData( $rule['field'] ) || null !== $tree->scopeOf( $rule['field'] ) || ! in_array( $rule['op'], Evaluator::OPERATORS, true ) ) {
					$invalid = true;
				}
			}
			if ( $invalid ) {
				$errors[] = array(
					'path'    => 'webhook:' . $id,
					'code'    => 'webhook_invalid',
					'message' => __( 'Invalid webhook settings. Check the URL, method, format, headers, mapping and conditions.', 'sw-forms' ),
				);
			}
		}
		return $errors;
	}
}
