<?php
/**
 * Action Scheduler boundary for SW Forms background jobs.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Support;

use InvalidArgumentException;
use RuntimeException;

final class Queue {

	public const GROUP = 'swf_background';

	/**
	 * Queue one job. Arguments are passed positionally to the WordPress hook.
	 *
	 * Store identifiers here, never submission data or secrets: AS retains job history.
	 *
	 * @param string       $hook SW Forms action hook (swf_ prefix required).
	 * @param array<mixed> $args Positional callback arguments.
	 * @param int          $timestamp Unix timestamp; zero means as soon as possible.
	 * @return int Action id.
	 * @throws InvalidArgumentException For invalid job parameters.
	 * @throws RuntimeException Before AS initialization or on persistence failure.
	 */
	public function schedule( string $hook, array $args = array(), int $timestamp = 0 ): int {
		$this->validate_hook( $hook );
		if ( $timestamp < 0 || ! array_is_list( $args ) ) {
			throw new InvalidArgumentException( 'Queue jobs require a nonnegative timestamp and positional arguments.' );
		}
		$this->assert_ready();
		$id = 0 === $timestamp
			? as_enqueue_async_action( $hook, $args, self::GROUP )
			: as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
		if ( 0 === $id ) {
			throw new RuntimeException( 'Unable to persist SW Forms background job.' );
		}
		return $id;
	}

	/**
	 * Cancel pending jobs matching exactly this hook and argument list.
	 *
	 * @param string       $hook SW Forms hook.
	 * @param array<mixed> $args Positional callback arguments.
	 */
	public function cancel( string $hook, array $args = array() ): void {
		$this->validate_hook( $hook );
		$this->assert_ready();
		as_unschedule_all_actions( $hook, $args, self::GROUP );
	}

	private function validate_hook( string $hook ): void {
		if ( ! preg_match( '/^swf_[a-z0-9_]+$/D', $hook ) ) {
			throw new InvalidArgumentException( 'Queue hooks must use the swf_ prefix.' );
		}
	}

	private function assert_ready(): void {
		if ( ! class_exists( 'ActionScheduler' ) || ! \ActionScheduler::is_initialized() ) {
			throw new RuntimeException( 'SW Forms queue is available after action_scheduler_init.' );
		}
	}
}
