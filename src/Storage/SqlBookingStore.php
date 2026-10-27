<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Storage;

use ArchitectureDecisionLab\Consistency\EventEnvelope;
use ArchitectureDecisionLab\Consistency\Outbox;
use ArchitectureDecisionLab\Domain\BookingLine;
use ArchitectureDecisionLab\Domain\BookingRecord;
use ArchitectureDecisionLab\Domain\BookingStatus;
use ArchitectureDecisionLab\Domain\ResourceType;
use ArchitectureDecisionLab\Instrumentation\Counters;

/**
 * A normalized booking aggregate: one row in bookings and one row per priced
 * line in booking_lines. A traveler query is one join; a point read is two
 * statements.
 */
final readonly class SqlBookingStore implements BookingStore
{
    public function __construct(
        private SqliteDatabase $db,
        private Counters $counters,
        private Outbox $outbox,
    ) {
    }

    public function create(BookingRecord $booking): void
    {
        $this->writeBooking($booking);
        $this->counters->increment('bookingsCreated');
    }

    public function save(BookingRecord $booking, ?EventEnvelope $event = null): void
    {
        $this->db->transaction(function () use ($booking, $event): void {
            $this->writeBooking($booking);
            $this->db->execute(
                'DELETE FROM booking_lines WHERE booking_id = :booking_id',
                ['booking_id' => $booking->id],
            );

            foreach ($booking->lines as $line) {
                $this->db->execute(
                    'INSERT INTO booking_lines
                        (booking_id, resource_type, resource_id, units, price_cents, state)
                     VALUES (:booking_id, :resource_type, :resource_id, :units, :price_cents, :state)',
                    [
                        'booking_id' => $booking->id,
                        'resource_type' => $line->resourceType->value,
                        'resource_id' => $line->resourceId,
                        'units' => $line->units,
                        'price_cents' => $line->priceCents,
                        'state' => $line->state,
                    ],
                );
                $this->counters->increment('bookingLinesWritten');
            }

            if ($event !== null) {
                $this->outbox->append($event);
            }

            $this->counters->increment('bookingWrites');
        });
    }

    public function get(string $id): ?BookingRecord
    {
        $rows = $this->db->select(
            'SELECT id, traveler_id, flight_id, hotel_id, car_id, status, amount_cents, created_at, updated_at
             FROM bookings WHERE id = :id',
            ['id' => $id],
        );

        if ($rows === []) {
            return null;
        }

        $booking = $this->booking($rows[0]);
        $lines = $this->db->select(
            'SELECT resource_type, resource_id, units, price_cents, state
             FROM booking_lines WHERE booking_id = :booking_id
             ORDER BY CASE resource_type WHEN \'flight\' THEN 0 WHEN \'hotel\' THEN 1 ELSE 2 END',
            ['booking_id' => $id],
        );

        return $booking->withLines($this->hydrateLines($lines), $booking->updatedAt);
    }

    public function byTraveler(string $travelerId): array
    {
        $rows = $this->db->select(
            'SELECT b.id, b.traveler_id, b.flight_id, b.hotel_id, b.car_id, b.status,
                    b.amount_cents, b.created_at, b.updated_at,
                    l.resource_type, l.resource_id, l.units, l.price_cents, l.state
             FROM bookings b
             LEFT JOIN booking_lines l ON l.booking_id = b.id
             WHERE b.traveler_id = :traveler_id
             ORDER BY b.id,
                CASE l.resource_type WHEN \'flight\' THEN 0 WHEN \'hotel\' THEN 1 ELSE 2 END',
            ['traveler_id' => $travelerId],
        );

        return $this->hydrateJoined($rows);
    }

    public function countByStatus(): array
    {
        $rows = $this->db->select('SELECT status, COUNT(*) AS total FROM bookings GROUP BY status');
        $counts = [];

        foreach ($rows as $row) {
            $counts[$this->string($row['status'])] = (int) $row['total'];
        }

        return $counts;
    }

    public function transaction(callable $work): mixed
    {
        return $this->db->transaction($work);
    }

    public function kind(): string
    {
        return 'sql';
    }

    public function lines(string $bookingId): array
    {
        $rows = $this->db->select(
            'SELECT resource_type, resource_id, units, price_cents, state
             FROM booking_lines WHERE booking_id = :booking_id
             ORDER BY CASE resource_type WHEN \'flight\' THEN 0 WHEN \'hotel\' THEN 1 ELSE 2 END',
            ['booking_id' => $bookingId],
        );

        return $this->hydrateLines($rows);
    }

    private function writeBooking(BookingRecord $booking): void
    {
        $this->db->execute(
            'INSERT INTO bookings
                (id, traveler_id, flight_id, hotel_id, car_id, status, amount_cents, created_at, updated_at)
             VALUES
                (:id, :traveler_id, :flight_id, :hotel_id, :car_id, :status, :amount_cents, :created_at, :updated_at)
             ON CONFLICT(id) DO UPDATE SET
                traveler_id = excluded.traveler_id,
                status = excluded.status,
                amount_cents = excluded.amount_cents,
                updated_at = excluded.updated_at',
            [
                'id' => $booking->id,
                'traveler_id' => $booking->travelerId,
                'flight_id' => $booking->flightId,
                'hotel_id' => $booking->hotelId,
                'car_id' => $booking->carId,
                'status' => $booking->status->value,
                'amount_cents' => $booking->amountCents,
                'created_at' => $booking->createdAt,
                'updated_at' => $booking->updatedAt,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function booking(array $row): BookingRecord
    {
        return new BookingRecord(
            $this->string($row['id']),
            $this->string($row['traveler_id']),
            $this->string($row['flight_id']),
            $this->string($row['hotel_id']),
            $this->string($row['car_id']),
            BookingStatus::from($this->string($row['status'])),
            (int) $row['amount_cents'],
            (int) $row['created_at'],
            (int) $row['updated_at'],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<BookingLine>
     */
    private function hydrateLines(array $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = new BookingLine(
                ResourceType::from($this->string($row['resource_type'])),
                $this->string($row['resource_id']),
                (int) $row['units'],
                (int) $row['price_cents'],
                $this->string($row['state']),
            );
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<BookingRecord>
     */
    private function hydrateJoined(array $rows): array
    {
        /** @var array<string, array{booking: BookingRecord, lines: list<BookingLine>}> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $id = $this->string($row['id']);

            if (!isset($grouped[$id])) {
                $grouped[$id] = ['booking' => $this->booking($row), 'lines' => []];
            }

            if ($row['resource_type'] !== null) {
                $grouped[$id]['lines'][] = new BookingLine(
                    ResourceType::from($this->string($row['resource_type'])),
                    $this->string($row['resource_id']),
                    (int) $row['units'],
                    (int) $row['price_cents'],
                    $this->string($row['state']),
                );
            }
        }

        $bookings = [];

        foreach ($grouped as $group) {
            $bookings[] = $group['booking']->withLines($group['lines'], $group['booking']->updatedAt);
        }

        return $bookings;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : (string) $value;
    }
}
