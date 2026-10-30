<?php
/**
 * Per form settings: submit button, confirmation, storage, spam protection, notifications.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Forms;

final class FormSettings {

	public const MAX_NOTIFICATIONS = 20;

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function normalize( array $input ): array {
		$confirmation = is_array( $input['confirmation'] ?? null ) ? $input['confirmation'] : array();
		$spam         = is_array( $input['spam'] ?? null ) ? $input['spam'] : array();
		$type         = $confirmation['type'] ?? 'message';

		$notifications = array();
		if ( ! array_key_exists( 'notifications', $input ) ) {
			$notifications[] = self::default_notification();
		}
		foreach ( is_array( $input['notifications'] ?? null ) ? $input['notifications'] : array() as $notification ) {
			if ( is_array( $notification ) && count( $notifications ) < self::MAX_NOTIFICATIONS ) {
				$notifications[] = self::notification( $notification );
			}
		}

		return array(
			'submitLabel'   => self::text( $input['submitLabel'] ?? null, __( 'Submit', 'sw-forms' ) ),
			'cssClass'      => DocumentNormalizer::css_classes( $input['cssClass'] ?? '' ),
			'confirmation'  => array(
				'type'    => in_array( $type, array( 'message', 'redirect', 'page' ), true ) ? $type : 'message',
				'message' => wp_kses_post( (string) ( is_scalar( $confirmation['message'] ?? null ) ? $confirmation['message'] : __( 'Thank you! Your message has been sent.', 'sw-forms' ) ) ),
				'url'     => esc_url_raw( (string) ( is_scalar( $confirmation['url'] ?? null ) ? $confirmation['url'] : '' ) ),
				'pageId'  => absint( $confirmation['pageId'] ?? 0 ),
			),
			'storeEntries'  => (bool) ( $input['storeEntries'] ?? true ),
			'retentionDays' => min( 3650, absint( $input['retentionDays'] ?? 0 ) ),
			'spam'          => array(
				'provider'   => is_string( $spam['provider'] ?? null ) ? sanitize_key( $spam['provider'] ) : '',
				'honeypot'   => (bool) ( $spam['honeypot'] ?? true ),
				'minSeconds' => min( 60, absint( $spam['minSeconds'] ?? 2 ) ),
				'rateLimit'  => min( 1000, absint( $spam['rateLimit'] ?? 10 ) ),
			),
			'notifications' => $notifications,
			'webhooks'      => \SWForms\WordPress\Webhooks\WebhookSettings::normalize( $input['webhooks'] ?? array() ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function default_notification(): array {
		return self::notification(
			array(
				'id'      => 'admin',
				'name'    => __( 'Admin notification', 'sw-forms' ),
				'enabled' => true,
				'to'      => '{admin_email}',
				'subject' => __( 'New entry: {form:title}', 'sw-forms' ),
				'message' => '{all_fields}',
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private static function notification( array $input ): array {
		$id = is_string( $input['id'] ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $input['id'] ) ? $input['id'] : wp_generate_uuid4();
		return array(
			'id'          => $id,
			'name'        => self::text( $input['name'] ?? null, __( 'Notification', 'sw-forms' ) ),
			'enabled'     => (bool) ( $input['enabled'] ?? true ),
			// Recipients may contain merge tags ({field:email}); addresses are validated when sending.
			'to'          => self::text( $input['to'] ?? null, '' ),
			'subject'     => self::text( $input['subject'] ?? null, '' ),
			'message'     => wp_kses_post( (string) ( is_scalar( $input['message'] ?? null ) ? $input['message'] : '' ) ),
			'replyTo'     => self::text( $input['replyTo'] ?? null, '' ),
			'attachFiles' => ! empty( $input['attachFiles'] ),
			'fromName'    => self::text( $input['fromName'] ?? null, '' ),
			'logic'       => DocumentNormalizer::logic( $input['logic'] ?? null ),
		);
	}

	private static function text( mixed $value, string $fallback ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, 500 ) : $fallback;
	}
}
