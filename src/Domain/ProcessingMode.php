<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

enum ProcessingMode: string
{
    case Sync = 'sync';
    case Async = 'async';
}
