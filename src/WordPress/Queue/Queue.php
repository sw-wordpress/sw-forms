<?php
/**
 * Background jobs on Action Scheduler (bundled, group `swf`), which survives failed runs and
 * shows its jobs under Tools › Scheduled Actions. Falls back to WP-Cron if no Action Scheduler is
 * loaded, which should only happen in broken installations.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Queue;

final class Queue {

	public const GROUP = 'swf';

	private static bool $kick_registered = false;

	/** Runs `do_action( $hook, ...$args )` in the background after `$delay` seconds. */
	public static function schedule( string $hook, array $args = array(), int $delay = 0 ): bool {
		$timestamp = time() + max( 0, $delay );
		if ( self::scheduler() ) {
			$id = 0 === $delay
				? as_enqueue_async_action( $hook, $args, self::GROUP )
				: as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
			$ok = $id > 0;
		} else {
			$ok = true === wp_schedule_single_event( $timestamp, $hook, $args, true );
		}
		if ( $ok && 0 === $delay ) {
			self::kick();
		}
		return $ok;
	}

	/** Removes pending jobs of a hook (all hooks of the group if empty). */
	public static function cancel( string $hook = '' ): void {
		if ( self::scheduler() ) {
			// Action Scheduler only cancels in bulk by hook alone or by group alone.
			if ( '' === $hook ) {
				as_unschedule_all_actions( '', array(), self::GROUP );
			} else {
				as_unschedule_all_actions( $hook );
			}
		} elseif ( '' !== $hook ) {
			// Uninstall: the plugin (and its Action Scheduler copy) is not loaded, clean the shared table directly.
			global $wpdb;
			$table = $wpdb->prefix . 'actionscheduler_actions';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$wpdb->delete(
					$table,
					array(
						'hook'   => $hook,
						'status' => 'pending',
					),
					array( '%s', '%s' )
				);
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		if ( '' !== $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/** Whether Action Scheduler is loaded and its tables are ready. */
	public static function scheduler(): bool {
		return class_exists( 'ActionScheduler', false ) && \ActionScheduler::is_initialized() && function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Asks WordPress to process due jobs right after this request instead of waiting for the next
	 * cron run triggered by a visitor. Respects sites that run cron by a system job.
	 */
	public static function kick(): void {
		if ( self::$kick_registered || ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			return;
		}
		self::$kick_registered = true;
		add_action(
			'shutdown',
			static function (): void {
				if ( ! wp_doing_cron() ) {
					spawn_cron();
				}
			}
		);
	}
}
