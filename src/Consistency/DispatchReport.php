<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

final readonly class DispatchReport
{
    public function __construct(
        public int $attempted,
        public int $delivered,
        public int $failed,
        public int $pending,
        public string $lastError = '',
    ) {
    }
}
