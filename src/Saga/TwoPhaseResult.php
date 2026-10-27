<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Saga;

final readonly class TwoPhaseResult
{
    /**
     * @param list<string> $prepared
     * @param list<string> $committed
     * @param list<string> $aborted
     */
    public function __construct(
        public string $status,
        public array $prepared,
        public array $committed,
        public array $aborted,
        public int $holds,
        public string $reason = '',
    ) {
    }
}
