<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Storage;

use ArchitectureDecisionLab\Consistency\EventEnvelope;
use ArchitectureDecisionLab\Domain\BookingRecord;

interface BookingStore
{
    public function create(BookingRecord $booking): void;

    /**
     * Writes the booking and, when delivery is transactional, the outbox row in
     * the same database transaction.
     */
    public function save(BookingRecord $booking, ?EventEnvelope $event = null): void;

    public function get(string $id): ?BookingRecord;

    /**
     * @return list<BookingRecord>
     */
    public function byTraveler(string $travelerId): array;

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array;

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed;

    public function kind(): string;

    /**
     * @return list<\ArchitectureDecisionLab\Domain\BookingLine>
     */
    public function lines(string $bookingId): array;
}
