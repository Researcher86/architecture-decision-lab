<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Services;

use ArchitectureDecisionLab\Domain\ReservationResult;
use ArchitectureDecisionLab\Domain\ReservationStatus;
use ArchitectureDecisionLab\Domain\ResourceType;
use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Foundation\VirtualClock;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Storage\SqliteDatabase;

/**
 * One inventory service (flight, hotel or car). Capacity is a conditional
 * UPDATE, not a read followed by a write; the reservation row is the
 * idempotency key for a retried request.
 */
final class InventoryService
{
    public function __construct(
        private readonly SqliteDatabase $db,
        private readonly ResourceType $type,
        private readonly Transport $transport,
        private readonly Counters $counters,
        private readonly VirtualClock $clock,
        private readonly FaultInjector $faults,
    ) {
    }

    public function type(): ResourceType
    {
        return $this->type;
    }

    public function seed(string $id, int $capacity, int $priceCents): void
    {
        if ($capacity < 0) {
            throw new \InvalidArgumentException('capacity cannot be negative');
        }

        $this->db->execute(
            sprintf(
                'INSERT INTO %s (id, available, price_cents) VALUES (:id, :available, :price)
                 ON CONFLICT(id) DO UPDATE SET available = excluded.available, price_cents = excluded.price_cents',
                $this->type->table(),
            ),
            ['id' => $id, 'available' => $capacity, 'price' => $priceCents],
        );
        $this->counters->increment('seededResources');
    }

    public function reserve(string $bookingId, string $resourceId, int $units): ReservationResult
    {
        return $this->transport->call(
            $this->type->value,
            'reserve',
            fn (): ReservationResult => $this->reserveWithStatus($bookingId, $resourceId, $units, ReservationStatus::Committed),
        );
    }

    public function prepare(string $bookingId, string $resourceId, int $units): ReservationResult
    {
        return $this->transport->call(
            $this->type->value,
            'prepare',
            fn (): ReservationResult => $this->reserveWithStatus($bookingId, $resourceId, $units, ReservationStatus::Prepared),
        );
    }

    public function commit(string $bookingId, string $resourceId): void
    {
        $this->transport->call($this->type->value, 'commit', function () use ($bookingId, $resourceId): void {
            $status = $this->reservationStatus($bookingId, $resourceId);

            if ($status === ReservationStatus::Committed) {
                $this->counters->increment('idempotentCommits');

                return;
            }

            if ($status !== ReservationStatus::Prepared) {
                throw new \RuntimeException(sprintf(
                    'Cannot commit %s reservation %s/%s in state %s',
                    $this->type->value,
                    $bookingId,
                    $resourceId,
                    $status === null ? 'missing' : $status->value,
                ));
            }

            $this->db->execute(
                'UPDATE reservations SET status = :status, updated_at = :now
                 WHERE booking_id = :booking_id AND resource_type = :type AND resource_id = :id',
                [
                    'status' => ReservationStatus::Committed->value,
                    'now' => $this->clock->now(),
                    'booking_id' => $bookingId,
                    'type' => $this->type->value,
                    'id' => $resourceId,
                ],
            );
            $this->counters->increment('reservationCommits');
        });
    }

    /**
     * Compensation is idempotent: a missing reservation, a released one and a
     * prepared one that never committed are all safe to release.
     */
    public function release(string $bookingId, string $resourceId): void
    {
        $this->transport->call($this->type->value, 'release', function () use ($bookingId, $resourceId): void {
            $rows = $this->db->select(
                'SELECT units, status FROM reservations
                 WHERE booking_id = :booking_id AND resource_type = :type AND resource_id = :id',
                ['booking_id' => $bookingId, 'type' => $this->type->value, 'id' => $resourceId],
            );

            if ($rows === []) {
                $this->counters->increment('compensationNoops');

                return;
            }

            $status = $this->string($rows[0]['status']);

            if ($status === ReservationStatus::Released->value) {
                $this->counters->increment('idempotentReleases');

                return;
            }

            $units = (int) $rows[0]['units'];

            $this->db->transaction(function () use ($bookingId, $resourceId, $units): void {
                $this->db->execute(
                    'UPDATE reservations SET status = :status, updated_at = :now
                     WHERE booking_id = :booking_id AND resource_type = :type AND resource_id = :id',
                    [
                        'status' => ReservationStatus::Released->value,
                        'now' => $this->clock->now(),
                        'booking_id' => $bookingId,
                        'type' => $this->type->value,
                        'id' => $resourceId,
                    ],
                );
                $this->db->execute(
                    sprintf(
                        'UPDATE %s SET available = available + :units WHERE id = :id',
                        $this->type->table(),
                    ),
                    ['units' => $units, 'id' => $resourceId],
                );
            });

            $this->counters->increment('reservationsReleased');
        });
    }

    public function available(string $resourceId): int
    {
        $value = $this->transport->call($this->type->value, 'available', function () use ($resourceId): mixed {
            return $this->db->scalar(
                sprintf('SELECT available FROM %s WHERE id = :id', $this->type->table()),
                ['id' => $resourceId],
            );
        });

        return $value === null ? 0 : (int) $value;
    }

    public function price(string $resourceId): int
    {
        $value = $this->db->scalar(
            sprintf('SELECT price_cents FROM %s WHERE id = :id', $this->type->table()),
            ['id' => $resourceId],
        );

        return $value === null ? 0 : (int) $value;
    }

    public function reservationStatus(string $bookingId, string $resourceId): ?ReservationStatus
    {
        $value = $this->db->scalar(
            'SELECT status FROM reservations
             WHERE booking_id = :booking_id AND resource_type = :type AND resource_id = :id',
            ['booking_id' => $bookingId, 'type' => $this->type->value, 'id' => $resourceId],
        );

        if (!is_string($value)) {
            return null;
        }

        return ReservationStatus::tryFrom($value);
    }

    /**
     * @return list<array{booking_id: string, resource_id: string, units: int, status: string}>
     */
    public function reservations(): array
    {
        $rows = $this->db->select(
            'SELECT booking_id, resource_id, units, status FROM reservations
             WHERE resource_type = :type ORDER BY booking_id, resource_id',
            ['type' => $this->type->value],
        );

        $reservations = [];

        foreach ($rows as $row) {
            $reservations[] = [
                'booking_id' => $this->string($row['booking_id']),
                'resource_id' => $this->string($row['resource_id']),
                'units' => (int) $row['units'],
                'status' => $this->string($row['status']),
            ];
        }

        return $reservations;
    }

    private function reserveWithStatus(
        string $bookingId,
        string $resourceId,
        int $units,
        ReservationStatus $wanted,
    ): ReservationResult {
        if ($units < 1) {
            throw new \InvalidArgumentException('units must be at least 1');
        }

        $existing = $this->db->select(
            'SELECT units, status FROM reservations
             WHERE booking_id = :booking_id AND resource_type = :type AND resource_id = :id',
            ['booking_id' => $bookingId, 'type' => $this->type->value, 'id' => $resourceId],
        );

        if ($existing !== []) {
            $status = $this->string($existing[0]['status']);

            if ($status === ReservationStatus::Committed->value || $status === ReservationStatus::Prepared->value) {
                $this->counters->increment('idempotentReservations');

                return new ReservationResult(
                    true,
                    ReservationStatus::from($status),
                    $resourceId,
                    $this->type,
                    (int) $existing[0]['units'],
                    $this->price($resourceId),
                );
            }

            return ReservationResult::failed($this->type, $resourceId, $units, 'reservation was released');
        }

        $price = $this->price($resourceId);

        if ($price === 0) {
            return ReservationResult::failed($this->type, $resourceId, $units, 'unknown resource');
        }

        $this->faults->before($this->type->value . '.write');

        $result = $this->db->transaction(function () use ($bookingId, $resourceId, $units, $price, $wanted): ?ReservationResult {
            $updated = $this->db->execute(
                sprintf(
                    'UPDATE %s SET available = available - :units
                     WHERE id = :id AND available >= :units',
                    $this->type->table(),
                ),
                ['units' => $units, 'id' => $resourceId],
            );

            if ($updated === 0) {
                return null;
            }

            $this->db->execute(
                'INSERT INTO reservations
                    (booking_id, resource_type, resource_id, units, status, created_at, updated_at)
                 VALUES (:booking_id, :type, :resource_id, :units, :status, :now, :now)',
                [
                    'booking_id' => $bookingId,
                    'type' => $this->type->value,
                    'resource_id' => $resourceId,
                    'units' => $units,
                    'status' => $wanted->value,
                    'now' => $this->clock->now(),
                ],
            );

            return new ReservationResult(true, $wanted, $resourceId, $this->type, $units, $price);
        });

        if ($result === null) {
            return ReservationResult::failed($this->type, $resourceId, $units, 'sold out');
        }

        $this->counters->increment('reservations');
        $this->counters->increment('inventoryWrites');
        $this->faults->after($this->type->value . '.write');

        return $result;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : (string) $value;
    }
}
