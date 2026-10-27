<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
