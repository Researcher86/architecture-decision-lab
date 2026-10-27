<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Services;

use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Foundation\VirtualClock;
use ArchitectureDecisionLab\Instrumentation\Counters;

/**
 * A deterministic model of a remote call. It does not sleep: it advances the
 * logical clock and counts bytes. The failure it can inject after the closure
 * is the important part: the remote side committed, the caller never learned
 * that it did.
 */
final readonly class RemoteTransport implements Transport
{
    public function __construct(
        private Counters $counters,
        private FaultInjector $faults,
        private VirtualClock $clock,
        private int $latencyMs = 2,
        private int $requestBytes = 64,
        private int $responseBytes = 64,
    ) {
        if ($latencyMs < 0) {
            throw new \InvalidArgumentException('latency cannot be negative');
        }
    }

    public function call(string $service, string $operation, \Closure $work): mixed
    {
        $this->counters->increment('remoteCalls');
        $this->counters->add('networkRequestBytes', $this->requestBytes);
        $this->clock->advance($this->latencyMs);
        $this->counters->add('virtualLatencyMs', $this->latencyMs);
        $step = $service . '.' . $operation;
        $this->faults->before($step);
        $result = $work();
        $this->counters->add('networkResponseBytes', $this->responseBytes);
        $this->faults->after($step);

        return $result;
    }

    public function name(): string
    {
        return 'remote';
    }
}
