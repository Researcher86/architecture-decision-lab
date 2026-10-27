<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Instrumentation\Counters;

/**
 * Delivers pending outbox rows. Delivery is at-least-once: a crash after the
 * bus accepted the event but before the row was marked published means the
 * next dispatch sends the same event again.
 */
final readonly class OutboxDispatcher
{
    public function __construct(
        private Outbox $outbox,
        private InMemoryEventBus $bus,
        private Counters $counters,
        private FaultInjector $faults,
    ) {
    }

    public function dispatch(int $limit = 100): DispatchReport
    {
        $messages = $this->outbox->pending($limit);
        $delivered = 0;
        $failed = 0;
        $lastError = '';

        foreach ($messages as $message) {
            $this->outbox->registerAttempt($message->event);

            try {
                $this->faults->before('outbox.publish');
                $this->bus->publish($message->event);
                $this->faults->after('outbox.publish');
                $this->outbox->markPublished($message->event);
                ++$delivered;
            } catch (\Throwable $throwable) {
                ++$failed;
                $lastError = $throwable->getMessage();
                $this->counters->increment('outboxFailures');

                break;
            }
        }

        return new DispatchReport(
            attempted: count($messages),
            delivered: $delivered,
            failed: $failed,
            pending: $this->outbox->countPending(),
            lastError: $lastError,
        );
    }
}
