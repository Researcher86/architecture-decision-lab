<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Processing;

final readonly class BookingJob
{
    public function __construct(
        public string $bookingId,
        public int $index,
        public int $submittedAt,
        public int $attempts = 0,
    ) {
    }

    public function retry(): self
    {
        return new self($this->bookingId, $this->index, $this->submittedAt, $this->attempts + 1);
    }
}
