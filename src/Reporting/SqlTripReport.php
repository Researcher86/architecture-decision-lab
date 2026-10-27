<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Reporting;

use ArchitectureDecisionLab\Storage\SqliteDatabase;

/**
 * The report a single database makes easy: one query joins the booking to the
 * three inventory tables. This implementation is only valid when those tables
 * live in the same database.
 */
final readonly class SqlTripReport implements TripReport
{
    public function __construct(
        private SqliteDatabase $db,
    ) {
    }

    public function forTraveler(string $travelerId): array
    {
        $rows = $this->db->select(
            'SELECT b.id, b.status,
                    f.available AS flight_available,
                    h.available AS hotel_available,
                    c.available AS car_available
             FROM bookings b
             JOIN flights f ON f.id = b.flight_id
             JOIN hotels h ON h.id = b.hotel_id
             JOIN cars c ON c.id = b.car_id
             WHERE b.traveler_id = :traveler_id
             ORDER BY b.id',
            ['traveler_id' => $travelerId],
        );

        $summaries = [];

        foreach ($rows as $row) {
            $summaries[] = new TripSummary(
                $this->string($row['id']),
                $this->string($row['status']),
                (int) $row['flight_available'],
                (int) $row['hotel_available'],
                (int) $row['car_available'],
            );
        }

        return $summaries;
    }

    public function kind(): string
    {
        return 'sql-join';
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : (string) $value;
    }
}
