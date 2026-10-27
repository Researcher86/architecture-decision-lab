<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

enum ReservationStatus: string
{
    case Prepared = 'prepared';
    case Committed = 'committed';
    case Released = 'released';
}
