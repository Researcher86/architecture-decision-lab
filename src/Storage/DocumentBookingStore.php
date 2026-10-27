<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Storage;

use ArchitectureDecisionLab\Consistency\EventEnvelope;
use ArchitectureDecisionLab\Consistency\Outbox;
use ArchitectureDecisionLab\Domain\BookingRecord;
use ArchitectureDecisionLab\Instrumentation\Counters;

/**
 * A document-shaped booking aggregate. The booking and its lines are one JSON
 * document, so a point read is one row; a traveler query has to scan the
 * collection because this adapter has no secondary indexes. That is the
 * trade-off, not a hidden optimization.
 */
final readonly class DocumentBookingStore implements BookingStore
{
    private const COLLECTION = 'bookings';

    public function __construct(
        private SqliteDatabase $db,
        private Counters $counters,
        private Outbox $outbox,
    ) {
    }

    public function create(BookingRecord $booking): void
    {
        $this->write($booking);
        $this->counters->increment('bookingsCreated');
    }

    public function save(BookingRecord $booking, ?EventEnvelope $event = null): void
    {
        $this->db->transaction(function () use ($booking, $event): void {
            $this->write($booking);

            if ($event !== null) {
                $this->outbox->append($event);
            }

            $this->counters->increment('bookingWrites');
        });
    }

    public function get(string $id): ?BookingRecord
    {
        $rows = $this->db->select(
            'SELECT body FROM documents WHERE collection = :collection AND id = :id',
            ['collection' => self::COLLECTION, 'id' => $id],
        );

        if ($rows === []) {
            return null;
        }

        $this->counters->increment('documentsRead');

        return $this->decode($this->string($rows[0]['body']));
    }

    public function byTraveler(string $travelerId): array
    {
        $rows = $this->db->select(
            'SELECT body FROM documents WHERE collection = :collection ORDER BY id',
            ['collection' => self::COLLECTION],
        );

        $this->counters->add('documentsScanned', count($rows));
        $bookings = [];

        foreach ($rows as $row) {
            $booking = $this->decode($this->string($row['body']));

            if ($booking->travelerId === $travelerId) {
                $bookings[] = $booking;
            }
        }

        $this->counters->add('documentsRead', count($bookings));

        return $bookings;
    }

    public function countByStatus(): array
    {
        $rows = $this->db->select(
            'SELECT body FROM documents WHERE collection = :collection',
            ['collection' => self::COLLECTION],
        );

        $this->counters->add('documentsScanned', count($rows));
        $counts = [];

        foreach ($rows as $row) {
            $booking = $this->decode($this->string($row['body']));
            $key = $booking->status->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    public function transaction(callable $work): mixed
    {
        return $this->db->transaction($work);
    }

    public function kind(): string
    {
        return 'document';
    }

    public function lines(string $bookingId): array
    {
        $booking = $this->get($bookingId);

        return $booking === null ? [] : $booking->lines;
    }

    private function write(BookingRecord $booking): void
    {
        $body = json_encode($booking->toArray(), JSON_THROW_ON_ERROR);

        $this->db->execute(
            'INSERT INTO documents (collection, id, body, version, updated_at)
             VALUES (:collection, :id, :body, 1, :updated_at)
             ON CONFLICT(collection, id) DO UPDATE SET
                body = excluded.body,
                version = documents.version + 1,
                updated_at = excluded.updated_at',
            [
                'collection' => self::COLLECTION,
                'id' => $booking->id,
                'body' => $body,
                'updated_at' => $booking->updatedAt,
            ],
        );

        $this->counters->increment('documentsWritten');
    }

    private function decode(string $body): BookingRecord
    {
        /** @var array{id: string, traveler_id: string, flight_id: string, hotel_id: string, car_id: string, status: string, amount_cents: int, created_at: int, updated_at: int, lines: list<array{type: string, id: string, units: int, price_cents: int, state: string}>} $data */
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return BookingRecord::fromArray($data);
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : (string) $value;
    }
}
