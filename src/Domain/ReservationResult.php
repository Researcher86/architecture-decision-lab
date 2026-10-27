<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

final readonly class ReservationResult
{
    public function __construct(
        public bool $success,
        public ReservationStatus $status,
        public string $resourceId,
        public ResourceType $resourceType,
        public int $units,
        public int $priceCents,
        public string $reason = '',
    ) {
    }

    public static function failed(ResourceType $type, string $resourceId, int $units, string $reason): self
    {
        return new self(false, ReservationStatus::Released, $resourceId, $type, $units, 0, $reason);
    }

    public function line(string $state = 'committed'): BookingLine
    {
        return new BookingLine($this->resourceType, $this->resourceId, $this->units, $this->priceCents, $state);
    }
}
