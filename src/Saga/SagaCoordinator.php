<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Saga;

use ArchitectureDecisionLab\Instrumentation\Counters;

/**
 * An orchestrated saga over local transactions.
 *
 * Every step commits on its own. When a step fails, the coordinator tries to
 * compensate the failed step first and then the completed steps in reverse.
 * The failed step is included because a lost response means its effect may
 * exist even though the caller saw an exception. Compensations are expected to
 * be idempotent.
 */
final readonly class SagaCoordinator
{
    public function __construct(
        private Counters $counters,
    ) {
    }

    /**
     * @param list<SagaStep> $steps
     */
    public function run(array $steps): SagaResult
    {
        $completed = [];

        foreach ($steps as $step) {
            try {
                ($step->action)();
                $completed[] = $step;
                $this->counters->increment('sagaSteps');
            } catch (\Throwable $throwable) {
                $this->counters->increment('sagaFailures');

                return $this->compensate($step, $completed, $throwable->getMessage());
            }
        }

        $this->counters->increment('sagaCompleted');

        return new SagaResult(true, '', array_map(static fn (SagaStep $step): string => $step->name, $completed), 0, 0);
    }

    /**
     * @param list<SagaStep> $completed
     */
    private function compensate(SagaStep $failed, array $completed, string $reason): SagaResult
    {
        $attempts = 0;
        $failures = 0;

        /** @var list<SagaStep> $candidates */
        $candidates = [$failed];

        foreach (array_reverse($completed) as $step) {
            $candidates[] = $step;
        }

        foreach ($candidates as $step) {
            ++$attempts;
            $this->counters->increment('compensationAttempts');

            try {
                ($step->compensation)();
                $this->counters->increment('compensations');
            } catch (\Throwable) {
                ++$failures;
                $this->counters->increment('compensationFailures');
            }
        }

        return new SagaResult(
            false,
            $failed->name,
            array_map(static fn (SagaStep $step): string => $step->name, $completed),
            $attempts,
            $failures,
            $reason,
        );
    }
}
