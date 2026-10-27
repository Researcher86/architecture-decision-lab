<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

enum StorageMode: string
{
    case Sql = 'sql';
    case Document = 'document';
}
