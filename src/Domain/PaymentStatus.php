<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

enum PaymentStatus: string
{
    case Prepared = 'prepared';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Declined = 'declined';
    case Voided = 'voided';
}
