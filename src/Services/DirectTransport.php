<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Services;

use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Instrumentation\Counters;

final readonly class DirectTransport implements Transport
{
    public function __construct(
        private Counters $counters,
        private FaultInjector $faults,
    ) {
    }

    public function call(string $service, string $operation, \Closure $work): mixed
    {
        $this->counters->increment('directCalls');
        $step = $service . '.' . $operation;
        $this->faults->before($step);
        $result = $work();
        $this->faults->after($step);

        return $result;
    }

    public function name(): string
    {
        return 'direct';
    }
}
