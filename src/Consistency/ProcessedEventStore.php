<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

use ArchitectureDecisionLab\Foundation\VirtualClock;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Storage\SqliteDatabase;

/**
 * The consumer-side idempotency key. A duplicate delivery is not an error; it
 * is a fact of at-least-once delivery that the consumer must absorb.
 */
final class ProcessedEventStore
{
    public function __construct(
        private readonly SqliteDatabase $db,
        private readonly Counters $counters,
        private readonly VirtualClock $clock,
    ) {
    }

    public function isProcessed(string $consumer, string $eventId): bool
    {
        $value = $this->db->scalar(
            'SELECT 1 FROM processed_events WHERE consumer = :consumer AND event_id = :event_id',
            ['consumer' => $consumer, 'event_id' => $eventId],
        );

        return $value !== null;
    }

    public function markProcessed(string $consumer, string $eventId): void
    {
        $this->db->execute(
            'INSERT OR IGNORE INTO processed_events (consumer, event_id, processed_at)
             VALUES (:consumer, :event_id, :processed_at)',
            [
                'consumer' => $consumer,
                'event_id' => $eventId,
                'processed_at' => $this->clock->now(),
            ],
        );
        $this->counters->increment('processedEvents');
    }

    public function count(string $consumer): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM processed_events WHERE consumer = :consumer',
            ['consumer' => $consumer],
        );
    }
}
