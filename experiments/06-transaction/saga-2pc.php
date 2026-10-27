<?php

declare(strict_types=1);

use ArchitectureDecisionLab\Domain\BookingStatus;
use ArchitectureDecisionLab\Domain\ConsistencyMode;
use ArchitectureDecisionLab\Domain\DatabaseMode;
use ArchitectureDecisionLab\Domain\DeliveryMode;
use ArchitectureDecisionLab\Domain\DeploymentShape;
use ArchitectureDecisionLab\Domain\ProcessingMode;
use ArchitectureDecisionLab\Domain\ResourceType;
use ArchitectureDecisionLab\Domain\StorageMode;
use ArchitectureDecisionLab\Domain\TransactionMode;
use ArchitectureDecisionLab\Domain\TripRequest;
use ArchitectureDecisionLab\Experiment\Experiment;
use ArchitectureDecisionLab\Experiment\Options;
use ArchitectureDecisionLab\Experiment\Output;
use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Integration\LabConfig;
use ArchitectureDecisionLab\Integration\LabFactory;

return new Experiment(
    name: 'transaction:saga-2pc',
    description: 'compare compensation with a blocking two-phase commit around a failure',
    supports: ['capacity', 'seed'],
    run: static function (Options $options, Output $output): void {
        $capacity = $options->int('capacity', 10);
        $seed = $options->string('seed', 'transaction');

        $base = [
            'shape' => DeploymentShape::Microservices,
            'processing' => ProcessingMode::Sync,
            'storage' => StorageMode::Sql,
            'database' => DatabaseMode::PerService,
            'delivery' => DeliveryMode::Outbox,
            'consistency' => ConsistencyMode::Strong,
            'seed' => $seed,
            'databaseDirectory' => 'var/databases',
        ];

        $saga = new LabConfig(...$base, transaction: TransactionMode::Saga);
        $twoPhase = new LabConfig(...$base, transaction: TransactionMode::TwoPhaseCommit);

        $cases = [
            'saga-lost-response' => [
                $saga,
                static fn (Counters $counters): FaultInjector => new FaultInjector(step: 'car.write', after: true, counters: $counters),
                'saga',
            ],
            'saga-payment-failure' => [
                $saga,
                static fn (Counters $counters): FaultInjector => new FaultInjector(step: 'payment.authorize', counters: $counters),
                'saga',
            ],
            '2pc-prepare-abort' => [
                $twoPhase,
                static fn (Counters $counters): FaultInjector => new FaultInjector(step: '2pc.prepare.car', counters: $counters),
                '2pc',
            ],
            '2pc-crash-commit' => [
                $twoPhase,
                static fn (Counters $counters): FaultInjector => new FaultInjector(step: '2pc.commit', counters: $counters),
                '2pc-commit',
            ],
            '2pc-crash-abort' => [
                $twoPhase,
                static fn (Counters $counters): FaultInjector => new FaultInjector(step: '2pc.commit', counters: $counters),
                '2pc-abort',
            ],
        ];

        $output->heading('Saga vs two-phase commit', sprintf('capacity=%d seed=%s', $capacity, $seed));
        $output->line(sprintf(
            '%-22s %-10s %6s %6s %6s %8s %-10s %7s',
            'case',
            'status',
            'comp',
            'holds',
            'avail',
            'elapsed',
            'payment',
            'outbox',
        ));

        foreach ($cases as $name => [$config, $faultFactory, $mode]) {
            $counters = new Counters();
            $lab = LabFactory::create($config, $faultFactory($counters), $counters);
            $lab->seed('flight-1', 'hotel-1', 'car-1', $capacity);

            $result = $lab->book(new TripRequest('traveler-1', 'flight-1', 'hotel-1', 'car-1'), 0);
            $holds = $counters->get('twoPhaseHoldsGauge');

            if ($mode === '2pc-commit' || $mode === '2pc-abort') {
                $booking = $lab->store->get($result->bookingId);
                $result = $lab->workflow->recoverTwoPhase(
                    $booking ?? throw new RuntimeException('booking missing'),
                    $mode === '2pc-commit' ? 'commit' : 'abort',
                );
            }

            $lab->dispatchOutbox();
            $lab->drainProjection();
            $payment = $lab->payment->status($result->bookingId);

            $output->line(sprintf(
                '%-22s %-10s %6d %6d %6d %8s %-10s %7d',
                $name,
                $result->status->value,
                $result->compensations,
                $holds,
                $lab->sourceAvailability(ResourceType::Flight, 'flight-1'),
                $result->clientLatencyMs . ' ms',
                $payment === null ? 'none' : $payment->value,
                $lab->outbox->countPending(),
            ));

            $output->measure($name . '_status', $result->status === BookingStatus::Confirmed ? 'confirmed' : $result->status->value);
            $output->measure($name . '_compensations', $result->compensations);
            $output->measure($name . '_holds', $holds);
            $output->measure($name . '_flight_available', $lab->sourceAvailability(ResourceType::Flight, 'flight-1'));
            $output->measure($name . '_client_latency_ms', $result->clientLatencyMs);
            $output->measure($name . '_outbox_pending', $lab->outbox->countPending());
        }

        $output->line();
        $output->line('A saga commits each step and pays for a failure with compensations. Two-phase');
        $output->line('commit holds every participant before the decision: a prepare failure aborts');
        $output->line('cleanly, but a crash after the decision leaves prepared rows that need an');
        $output->line('explicit recovery decision. The lab records the holds rather than hiding them.');
    },
);
