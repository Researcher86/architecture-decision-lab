<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Storage\SqliteDatabase;

/**
 * Applies an event and records its id in one reporting-database transaction.
 * A crash between the two is a rollback, not a half-applied projection with a
 * lost idempotency key.
 */
final readonly class IdempotentProjector
{
    public function __construct(
        private Projection $projection,
        private ProcessedEventStore $processed,
        private SqliteDatabase $db,
        private Counters $counters,
        private string $consumer = 'reporting',
    ) {
    }

    public function handle(EventEnvelope $event): void
    {
        if ($this->processed->isProcessed($this->consumer, $event->id)) {
            $this->counters->increment('eventHandlerSkips');
            $this->counters->increment('duplicateEvents');

            return;
        }

        $this->db->transaction(function () use ($event): void {
            $this->projection->apply($event);
            $this->processed->markProcessed($this->consumer, $event->id);
        });
    }
}
