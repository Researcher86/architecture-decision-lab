<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

use ArchitectureDecisionLab\Domain\ResourceType;
use ArchitectureDecisionLab\Foundation\VirtualClock;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Storage\SqliteDatabase;

/**
 * The event-built read model: remaining availability per resource and the
 * booking status view. It is deliberately a projection, not a synchronous
 * copy of the write tables.
 */
final class Projection
{
    public function __construct(
        private readonly SqliteDatabase $db,
        private readonly Counters $counters,
        private readonly VirtualClock $clock,
    ) {
    }

    public function seed(ResourceType $type, string $id, int $available): void
    {
        $this->db->execute(
            'INSERT INTO availability_view (resource_type, resource_id, available, version, updated_at)
             VALUES (:type, :id, :available, 0, :now)
             ON CONFLICT(resource_type, resource_id) DO UPDATE SET
                available = excluded.available,
                version = availability_view.version + 1,
                updated_at = excluded.updated_at',
            [
                'type' => $type->value,
                'id' => $id,
                'available' => $available,
                'now' => $this->clock->now(),
            ],
        );
        $this->counters->increment('projectionSeeds');
    }

    public function apply(EventEnvelope $event): void
    {
        if (!in_array($event->type, ['booking.confirmed', 'booking.cancelled'], true)) {
            return;
        }

        $payload = $event->payload;
        $bookingId = is_string($payload['booking_id'] ?? null) ? $payload['booking_id'] : '';
        $travelerId = is_string($payload['traveler_id'] ?? null) ? $payload['traveler_id'] : '';
        $amount = is_int($payload['amount_cents'] ?? null) ? $payload['amount_cents'] : 0;
        $status = $event->type === 'booking.confirmed' ? 'confirmed' : 'cancelled';

        /** @var list<array{type: string, id: string, units: int, price_cents: int, state: string}> $lines */
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];

        foreach ($lines as $line) {
            $delta = $this->delta($event->type, $line);

            if ($delta === 0) {
                continue;
            }

            $type = ResourceType::from($line['type']);
            $updated = $this->db->execute(
                'UPDATE availability_view
                 SET available = available + :delta, version = version + 1, updated_at = :now
                 WHERE resource_type = :type AND resource_id = :id',
                [
                    'delta' => $delta,
                    'now' => $this->clock->now(),
                    'type' => $type->value,
                    'id' => $line['id'],
                ],
            );

            if ($updated === 0) {
                throw new \RuntimeException(sprintf(
                    'Projection has no seed for %s %s',
                    $type->value,
                    $line['id'],
                ));
            }

            $this->counters->increment('projectionAvailabilityUpdates');
        }

        $this->db->execute(
            'INSERT INTO trip_view (booking_id, status, traveler_id, amount_cents, version, updated_at)
             VALUES (:booking_id, :status, :traveler_id, :amount, 1, :now)
             ON CONFLICT(booking_id) DO UPDATE SET
                status = excluded.status,
                amount_cents = excluded.amount_cents,
                version = trip_view.version + 1,
                updated_at = excluded.updated_at',
            [
                'booking_id' => $bookingId,
                'status' => $status,
                'traveler_id' => $travelerId,
                'amount' => $amount,
                'now' => $this->clock->now(),
            ],
        );

        $this->counters->increment('projectionApplied');
    }

    public function availability(ResourceType $type, string $id): ?int
    {
        $value = $this->db->scalar(
            'SELECT available FROM availability_view
             WHERE resource_type = :type AND resource_id = :id',
            ['type' => $type->value, 'id' => $id],
        );

        return $value === null ? null : (int) $value;
    }

    public function tripStatus(string $bookingId): ?string
    {
        $value = $this->db->scalar(
            'SELECT status FROM trip_view WHERE booking_id = :id',
            ['id' => $bookingId],
        );

        return is_string($value) ? $value : null;
    }

    public function tripCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM trip_view');
    }

    /**
     * @param array{type: string, id: string, units: int, price_cents: int, state: string} $line
     */
    private function delta(string $eventType, array $line): int
    {
        if ($line['state'] !== 'committed') {
            return 0;
        }

        return $eventType === 'booking.confirmed' ? -$line['units'] : $line['units'];
    }
}
