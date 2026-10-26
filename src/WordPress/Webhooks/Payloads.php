<?php
/**
 * Webhook payloads (docs/webhooks.md): the envelope `{event, eventId, form, entry, data}` or, with
 * `envelope` switched off, only the (mapped) data.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

use SWForms\Core\Form\Tree;
use SWForms\Core\Webhook\PayloadMapper;
use SWForms\WordPress\Fields\FieldRegistry;

final class Payloads {

	public const EVENT_SUBMITTED = 'form.submitted';
	public const EVENT_TEST      = 'webhook.test';

	/**
	 * @param array<string, mixed> $webhook Webhook settings.
	 * @param array<string, mixed> $data    Submission data keyed by field key.
	 * @param array<string, mixed> $context `event`, `event_id`, `form` {id, title, version}, `entry` {id|null, createdAt, sourceUrl}.
	 * @return array<string, mixed>
	 */
	public static function build( array $webhook, Tree $tree, array $data, array $context ): array {
		if ( 'custom' === $webhook['fields'] ) {
			$data = ( new PayloadMapper( $tree ) )->apply( $data, $webhook['mapping'] );
		}
		if ( empty( $webhook['envelope'] ) ) {
			return $data;
		}
		return array(
			'event'   => $context['event'],
			'eventId' => $context['event_id'],
			'form'    => $context['form'],
			'entry'   => $context['entry'],
			// An empty object, not a list, when no field has a value.
			'data'    => array() === $data ? new \stdClass() : $data,
		);
	}

	/**
	 * Example data in the shape of a form's data for the test button: every field filled in,
	 * repeaters with one row.
	 *
	 * @param list<array<string, mixed>> $fields
	 * @return array<string, mixed>
	 */
	public static function sample( array $fields ): array {
		$tree = Tree::build( $fields, FieldRegistry::instance() );
		return self::sample_object( $tree, $tree->rootIds() );
	}

	/**
	 * @param list<string> $ids
	 * @return array<string, mixed>
	 */
	private static function sample_object( Tree $tree, array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			if ( ! $tree->isData( $id ) ) {
				$out += self::sample_object( $tree, $tree->childIds( $id ) );
				continue;
			}
			$key = (string) ( $tree->node( $id )['key'] ?? $id );
			if ( $tree->isRepeater( $id ) ) {
				$out[ $key ] = array( self::sample_object( $tree, $tree->childIds( $id ) ) );
			} elseif ( $tree->isLeafData( $id ) ) {
				$out[ $key ] = self::sample_value( $tree->node( $id ) );
			} else {
				$out[ $key ] = self::sample_object( $tree, $tree->childIds( $id ) );
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $element
	 */
	private static function sample_value( array $element ): mixed {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$options  = is_array( $settings['options'] ?? null ) ? $settings['options'] : array();
		$first    = (string) ( $options[0]['value'] ?? 'option-1' );
		$today    = current_datetime()->format( 'Y-m-d' );
		switch ( (string) ( $element['type'] ?? '' ) ) {
			case 'checkbox':
				return 'single' === ( $settings['mode'] ?? '' ) ? true : array( $first );
			case 'radio':
				return $first;
			case 'select':
				return ! empty( $settings['multiple'] ) ? array( $first ) : $first;
			case 'textarea':
				return __( 'Example text', 'sw-forms' );
			case 'range':
			case 'rating':
			case 'scale':
				return '3';
			case 'color':
				return '#336699';
			case 'tags':
				return array( 'alpha', 'beta' );
			case 'birthdate':
				return '1990-05-17';
			case 'date-interval':
				return array( $today, current_datetime()->modify( '+7 days' )->format( 'Y-m-d' ) );
			case 'time-interval':
				return array( '09:00', '17:00' );
		}
		return match ( (string) ( $settings['inputType'] ?? 'text' ) ) {
			'email'          => 'jane.doe@example.org',
			'tel'            => '+49 30 1234567',
			'url'            => 'https://example.org',
			'number'         => '42',
			'date'           => $today,
			'time'           => '09:30',
			'datetime-local' => $today . 'T09:30',
			'password'       => '********',
			default          => __( 'Example', 'sw-forms' ),
		};
	}
}
