<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Saga;

use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Instrumentation\Counters;

/**
 * A logical two-phase commit coordinator.
 *
 * Prepare is a real hold: capacity is decremented and a payment authorization
 * is stored, so other bookings cannot use it. If every prepare succeeds, the
 * commit decision is made; a crash after that point leaves prepared rows that
 * need recovery. A crash before the decision can abort every attempted
 * participant. This models the protocol and its blocking window; SQLite is not
 * providing a distributed transaction.
 */
final readonly class TwoPhaseCommitCoordinator
{
    public function __construct(
        private Counters $counters,
        private FaultInjector $faults,
    ) {
    }

    /**
     * @param list<Participant> $participants
     */
    public function execute(array $participants): TwoPhaseResult
    {
        $prepared = [];
        $attempted = [];

        try {
            foreach ($participants as $participant) {
                $attempted[] = $participant;
                $this->faults->before('2pc.prepare.' . $participant->name);
                ($participant->prepare)();
                $prepared[] = $participant;
                $this->counters->increment('twoPhasePrepared');
                $this->faults->after('2pc.prepare.' . $participant->name);
            }
        } catch (\Throwable $throwable) {
            $aborted = $this->abort($attempted);
            $this->counters->increment('twoPhaseAborted');

            return new TwoPhaseResult(
                'aborted',
                array_map(static fn (Participant $p): string => $p->name, $prepared),
                [],
                $aborted,
                0,
                $throwable->getMessage(),
            );
        }

        try {
            $this->faults->before('2pc.commit');
        } catch (\Throwable $throwable) {
            $this->counters->increment('twoPhaseCrashedAfterPrepare');
            $this->counters->set('twoPhaseHoldsGauge', count($prepared));

            return new TwoPhaseResult(
                'prepared',
                array_map(static fn (Participant $p): string => $p->name, $prepared),
                [],
                [],
                count($prepared),
                $throwable->getMessage(),
            );
        }

        $committed = [];

        try {
            foreach ($participants as $participant) {
                ($participant->commit)();
                $committed[] = $participant;
                $this->counters->increment('twoPhaseCommitted');
            }
        } catch (\Throwable $throwable) {
            $this->counters->increment('twoPhaseCommitFailures');
            $this->counters->set('twoPhaseHoldsGauge', count($prepared) - count($committed));

            return new TwoPhaseResult(
                'partial-commit',
                array_map(static fn (Participant $p): string => $p->name, $prepared),
                array_map(static fn (Participant $p): string => $p->name, $committed),
                [],
                count($prepared) - count($committed),
                $throwable->getMessage(),
            );
        }

        $this->counters->set('twoPhaseHoldsGauge', 0);

        return new TwoPhaseResult(
            'committed',
            array_map(static fn (Participant $p): string => $p->name, $prepared),
            array_map(static fn (Participant $p): string => $p->name, $committed),
            [],
            0,
        );
    }

    /**
     * Recovery is an explicit decision. A real coordinator would persist it;
     * this lab makes the choice visible in the report.
     *
     * @param list<Participant> $participants
     */
    public function recover(array $participants, string $decision): TwoPhaseResult
    {
        if (!in_array($decision, ['commit', 'abort'], true)) {
            throw new \InvalidArgumentException('decision must be commit or abort');
        }

        if ($decision === 'abort') {
            $aborted = $this->abort($participants);
            $this->counters->increment('twoPhaseRecovered');
            $this->counters->set('twoPhaseHoldsGauge', 0);

            return new TwoPhaseResult('recovered-abort', [], [], $aborted, 0);
        }

        $committed = [];

        foreach ($participants as $participant) {
            ($participant->commit)();
            $committed[] = $participant;
            $this->counters->increment('twoPhaseCommitted');
        }

        $this->counters->increment('twoPhaseRecovered');
        $this->counters->set('twoPhaseHoldsGauge', 0);

        return new TwoPhaseResult('recovered-commit', [], array_map(static fn (Participant $p): string => $p->name, $committed), [], 0);
    }

    /**
     * @param list<Participant> $participants
     *
     * @return list<string>
     */
    private function abort(array $participants): array
    {
        $aborted = [];

        foreach (array_reverse($participants) as $participant) {
            try {
                ($participant->abort)();
                $aborted[] = $participant->name;
                $this->counters->increment('twoPhaseAbortedParticipants');
            } catch (\Throwable) {
                $this->counters->increment('twoPhaseAbortFailures');
            }
        }

        return array_reverse($aborted);
    }
}
