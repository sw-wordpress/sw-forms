<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SWForms\WordPress\Support\Queue;

final class QueueTest extends TestCase
{
    /** @var list<int> */
    private array $actions = [];

    protected function tearDown(): void
    {
        foreach ($this->actions as $id) {
            \ActionScheduler::store()->delete_action($id);
        }
        remove_all_actions('swf_queue_test');
    }

    public function testAsyncJobPersistsAndExecutesWithPositionalArguments(): void
    {
        $received = [];
        add_action('swf_queue_test', static function ($entryId, $attempt) use (&$received): void {
            $received[] = [$entryId, $attempt];
        }, 10, 2);
        $id = (new Queue())->schedule('swf_queue_test', [42, 0]);
        $this->actions[] = $id;
        $action = \ActionScheduler::store()->fetch_action($id);
        self::assertSame(Queue::GROUP, $action->get_group());
        self::assertSame([42, 0], $action->get_args());
        self::assertSame([], $received, 'Scheduling must never execute inline.');
        \ActionScheduler::runner()->process_action($id, 'SW Forms test');
        self::assertSame([[42, 0]], $received);
        self::assertSame('complete', \ActionScheduler::store()->get_status($id));
    }

    public function testDelayedJobsKeepTimestampAndCancellationIsScoped(): void
    {
        $queue = new Queue();
        $timestamp = time() + 300;
        $id = $queue->schedule('swf_queue_test', [42, 1], $timestamp);
        $other = as_schedule_single_action($timestamp, 'swf_queue_test', [42, 1], 'another_plugin');
        $differentArgs = $queue->schedule('swf_queue_test', [43, 1], $timestamp);
        $this->actions = [$id, $other, $differentArgs];
        self::assertSame($timestamp, \ActionScheduler::store()->fetch_action($id)->get_schedule()->get_date()->getTimestamp());
        $queue->cancel('swf_queue_test', [42, 1]);
        self::assertSame('canceled', \ActionScheduler::store()->get_status($id));
        self::assertSame('pending', \ActionScheduler::store()->get_status($other));
        self::assertSame('pending', \ActionScheduler::store()->get_status($differentArgs));
    }

    public function testRejectedHooksCannotCancelAnotherPluginsJobs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Queue())->cancel('another_plugin_job');
    }

    public function testRejectsNamedArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Queue())->schedule('swf_queue_test', ['entryId' => 42]);
    }

    public function testRejectsNegativeTimestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Queue())->schedule('swf_queue_test', [], -1);
    }

    public function testPersistenceFailureIsReported(): void
    {
        $reject = static fn () => 0;
        add_filter('pre_as_enqueue_async_action', $reject);
        try {
            $this->expectException(\RuntimeException::class);
            (new Queue())->schedule('swf_queue_test', [42]);
        } finally {
            remove_filter('pre_as_enqueue_async_action', $reject);
        }
    }
}
