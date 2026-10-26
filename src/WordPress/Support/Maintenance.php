<?php
/**
 * Daily maintenance: deletes entries older than each form's retention period, requeues webhook
 * deliveries whose queue job got lost and removes old finished deliveries without entry.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Support;

use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Webhooks\DeliveryRepository;
use SWForms\WordPress\Webhooks\DeliveryService;

final class Maintenance {

	public const HOOK = 'swf_daily_maintenance';

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public function run(): void {
		$forms   = new FormRepository();
		$entries = new EntryRepository();
		foreach ( $forms->all() as $form ) {
			$document = $forms->published( (int) $form['id'] );
			$days     = (int) ( $document['settings']['retentionDays'] ?? 0 );
			if ( $days > 0 ) {
				$entries->purge_older_than( (int) $form['id'], $days );
			}
		}
		( new DeliveryService() )->recover();
		( new DeliveryRepository() )->purge_finished( 30 );
	}
}
