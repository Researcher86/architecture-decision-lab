<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

enum DeliveryMode: string
{
    case DualWrite = 'dual-write';
    case Outbox = 'outbox';
}
