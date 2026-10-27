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
use ArchitectureDecisionLab\Integration\Lab;
use ArchitectureDecisionLab\Integration\LabConfig;
use ArchitectureDecisionLab\Integration\LabFactory;

return new Experiment(
    name: 'consistency:projection-lag',
    description: 'measure stale reads and convergence with a strong and an eventual projection',
    supports: ['bookings', 'reads', 'lag', 'capacity', 'seed'],
    run: static function (Options $options, Output $output): void {
        $bookings = $options->int('bookings', 20);
        $reads = $options->int('reads', 100);
        $lag = $options->int('lag', 50);
        $capacity = $options->int('capacity', $bookings + 10);
        $seed = $options->string('seed', 'consistency');

        $base = [
            'shape' => DeploymentShape::ModularMonolith,
            'processing' => ProcessingMode::Sync,
            'storage' => StorageMode::Sql,
            'database' => DatabaseMode::Shared,
            'delivery' => DeliveryMode::Outbox,
            'transaction' => TransactionMode::Local,
            'seed' => $seed,
            'projectionLagMs' => $lag,
            'databaseDirectory' => 'var/databases',
        ];

        $cases = [
            'strong' => new LabConfig(...$base, consistency: ConsistencyMode::Strong),
            'eventual' => new LabConfig(...$base, consistency: ConsistencyMode::Eventual),
        ];

        $output->heading('Eventual consistency', sprintf(
            'bookings=%d reads=%d lag=%d capacity=%d',
            $bookings,
            $reads,
            $lag,
            $capacity,
        ));
        $output->line(sprintf(
            '%-10s %12s %11s %8s %9s %8s %7s %7s',
            'case',
            'stale before',
            'stale after',
            'queued',
            'lag ms',
            'applied',
            'source',
            'view',
        ));

        $staleReads = static function (Lab $lab, string $bookingId, int $reads): int {
            $stale = 0;

            for ($read = 0; $read < $reads; ++$read) {
                if ($lab->projection->tripStatus($bookingId) !== 'confirmed') {
                    ++$stale;
                }
            }

            return $stale;
        };

        foreach ($cases as $name => $config) {
            $counters = new Counters();
            $lab = LabFactory::create($config, new FaultInjector(counters: $counters), $counters);
            $lab->seed('flight-1', 'hotel-1', 'car-1', $capacity);

            $first = null;
            $confirmed = 0;

            for ($index = 0; $index < $bookings; ++$index) {
                $result = $lab->book(
                    new TripRequest('traveler-' . ($index % 10), 'flight-1', 'hotel-1', 'car-1'),
                    $index,
                );
                $first ??= $result->bookingId;

                if ($result->status === BookingStatus::Confirmed) {
                    ++$confirmed;
                }
            }

            $lab->dispatchOutbox();
            $queued = $lab->projectionRunner->pending();
            $staleBefore = $staleReads($lab, $first ?? '', $reads);
            $beforeDrain = $lab->clock->now();
            $applied = $lab->drainProjection();
            $lagObserved = $lab->clock->now() - $beforeDrain;
            $staleAfter = $staleReads($lab, $first ?? '', $reads);

            $output->line(sprintf(
                '%-10s %12d %11d %8d %9d %8d %7d %7d',
                $name,
                $staleBefore,
                $staleAfter,
                $queued,
                $lagObserved,
                $applied,
                $lab->sourceAvailability(ResourceType::Flight, 'flight-1'),
                $lab->projectedAvailability(ResourceType::Flight, 'flight-1') ?? -1,
            ));

            $output->measure($name . '_confirmed', $confirmed);
            $output->measure($name . '_stale_reads_before', $staleBefore);
            $output->measure($name . '_stale_reads_after', $staleAfter);
            $output->measure($name . '_queued_events', $queued);
            $output->measure($name . '_observed_lag_ms', $lagObserved);
            $output->measure($name . '_applied_events', $applied);
            $output->measure($name . '_flight_available_source', $lab->sourceAvailability(ResourceType::Flight, 'flight-1'));
            $output->measure($name . '_flight_available_view', $lab->projectedAvailability(ResourceType::Flight, 'flight-1') ?? -1);
        }

        $output->line();
        $output->line('The strong projection applies an event when it is published; the eventual');
        $output->line('one queues it for the configured lag. Both converge to the same source');
        $output->line('availability after the queue is drained, so the difference is the window of');
        $output->line('stale reads, not a permanent divergence.');
    },
);
