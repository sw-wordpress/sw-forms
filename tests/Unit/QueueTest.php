<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SWForms\WordPress\Support\Queue;

final class QueueTest extends TestCase
{
    public function testSchedulingBeforeSchedulerInitializationFailsExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('after action_scheduler_init');
        (new Queue())->schedule('swf_queue_test', [42]);
    }

    public function testCancellationBeforeSchedulerInitializationFailsExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        (new Queue())->cancel('swf_queue_test', [42]);
    }
}
