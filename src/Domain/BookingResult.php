<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

/**
 * What one booking attempt did. `accepted` is the client-visible part: an
 * asynchronous request can be accepted while the final status is still
 * pending.
 */
final readonly class BookingResult
{
    /**
     * @param list<BookingLine> $lines
     */
    public function __construct(
        public string $bookingId,
        public BookingStatus $status,
        public bool $accepted,
        public int $clientLatencyMs,
        public int $completionLatencyMs,
        public int $compensations,
        public string $reason = '',
        public array $lines = [],
        public bool $eventPublished = true,
    ) {
    }

    public function withLatencies(int $clientLatencyMs, int $completionLatencyMs): self
    {
        return new self(
            $this->bookingId,
            $this->status,
            $this->accepted,
            $clientLatencyMs,
            $completionLatencyMs,
            $this->compensations,
            $this->reason,
            $this->lines,
            $this->eventPublished,
        );
    }
}
