<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

final readonly class PaymentResult
{
    public function __construct(
        public bool $success,
        public PaymentStatus $status,
        public string $bookingId,
        public int $amountCents,
        public string $reason = '',
    ) {
    }

    public static function failed(string $bookingId, int $amountCents, string $reason): self
    {
        return new self(false, PaymentStatus::Declined, $bookingId, $amountCents, $reason);
    }
}
