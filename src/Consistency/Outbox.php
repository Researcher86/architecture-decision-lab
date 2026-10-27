<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

use ArchitectureDecisionLab\Foundation\VirtualClock;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Storage\SqliteDatabase;

/**
 * A transactional outbox: an event row is written in the same database
 * transaction as the state change. A dispatcher may deliver it later, maybe
 * twice, but it cannot lose it in the gap between commit and publish.
 */
final class Outbox
{
    public function __construct(
        private readonly SqliteDatabase $db,
        private readonly Counters $counters,
        private readonly VirtualClock $clock,
    ) {
    }

    public function append(EventEnvelope $event): void
    {
        $this->db->execute(
            'INSERT INTO outbox (event_id, type, payload, status, attempts, occurred_at, published_at)
             VALUES (:event_id, :type, :payload, :status, 0, :occurred_at, NULL)',
            [
                'event_id' => $event->id,
                'type' => $event->type,
                'payload' => $event->toJson(),
                'status' => 'pending',
                'occurred_at' => $event->occurredAt,
            ],
        );

        $this->counters->increment('outboxAppends');
        $this->counters->set('outboxPendingGauge', $this->countPending());
    }

    /**
     * @return list<OutboxMessage>
     */
    public function pending(int $limit): array
    {
        $rows = $this->db->select(
            'SELECT sequence, event_id, type, payload, attempts
             FROM outbox
             WHERE status = :status
             ORDER BY sequence
             LIMIT :limit',
            ['status' => 'pending', 'limit' => $limit],
        );

        $messages = [];

        foreach ($rows as $row) {
            $messages[] = new OutboxMessage(
                (int) $row['sequence'],
                EventEnvelope::fromJson($this->string($row['payload'])),
                (int) $row['attempts'],
            );
        }

        return $messages;
    }

    public function registerAttempt(EventEnvelope $event): void
    {
        $this->db->execute(
            'UPDATE outbox SET attempts = attempts + 1 WHERE event_id = :event_id',
            ['event_id' => $event->id],
        );
        $this->counters->increment('outboxAttempts');
    }

    public function markPublished(EventEnvelope $event): void
    {
        $this->db->execute(
            'UPDATE outbox
             SET status = :status, published_at = :published_at
             WHERE event_id = :event_id',
            [
                'status' => 'published',
                'published_at' => $this->clock->now(),
                'event_id' => $event->id,
            ],
        );
        $this->counters->increment('outboxPublished');
        $this->counters->set('outboxPendingGauge', $this->countPending());
    }

    public function countPending(): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM outbox WHERE status = :status',
            ['status' => 'pending'],
        );
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = $this->db->select('SELECT status, COUNT(*) AS total FROM outbox GROUP BY status');
        $counts = [];

        foreach ($rows as $row) {
            $counts[$this->string($row['status'])] = (int) $row['total'];
        }

        return $counts;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : (string) $value;
    }
}
