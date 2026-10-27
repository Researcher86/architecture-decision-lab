<?php

declare(strict_types=1);

use ArchitectureDecisionLab\Domain\BookingStatus;
use ArchitectureDecisionLab\Domain\ConsistencyMode;
use ArchitectureDecisionLab\Domain\DatabaseMode;
use ArchitectureDecisionLab\Domain\DeliveryMode;
use ArchitectureDecisionLab\Domain\DeploymentShape;
use ArchitectureDecisionLab\Domain\ProcessingMode;
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
    name: 'outbox:delivery',
    description: 'compare a dual write with a transactional outbox under a crash',
    supports: ['bookings', 'capacity', 'seed'],
    run: static function (Options $options, Output $output): void {
        $bookings = $options->int('bookings', 20);
        $capacity = $options->int('capacity', $bookings + 10);
        $seed = $options->string('seed', 'outbox');

        $base = [
            'shape' => DeploymentShape::ModularMonolith,
            'processing' => ProcessingMode::Sync,
            'storage' => StorageMode::Sql,
            'database' => DatabaseMode::Shared,
            'transaction' => TransactionMode::Saga,
            'consistency' => ConsistencyMode::Strong,
            'seed' => $seed,
            'databaseDirectory' => 'var/databases',
        ];

        $output->heading('Outbox', sprintf('bookings=%d capacity=%d', $bookings, $capacity));
        $output->line(sprintf(
            '%-22s %8s %8s %8s %8s %8s %8s %8s',
            'case',
            'ok',
            'events',
            'trips',
            'lost',
            'pending',
            'dup',
            'failed',
        ));

        $cases = [
            'dual-write' => [
                new LabConfig(...$base, delivery: DeliveryMode::DualWrite),
                static fn (Counters $counters): FaultInjector => new FaultInjector(counters: $counters),
                $bookings,
                0,
            ],
            'outbox' => [
                new LabConfig(...$base, delivery: DeliveryMode::Outbox),
                static fn (Counters $counters): FaultInjector => new FaultInjector(counters: $counters),
                $bookings,
                1,
            ],
            'dual-write-crash' => [
                new LabConfig(...$base, delivery: DeliveryMode::DualWrite),
                static fn (Counters $counters): FaultInjector => new FaultInjector(step: 'dualwrite.publish', counters: $counters),
                1,
                0,
            ],
            'outbox-retry' => [
                new LabConfig(...$base, delivery: DeliveryMode::Outbox),
                static fn (Counters $counters): FaultInjector => new FaultInjector(step: 'outbox.publish', counters: $counters),
                1,
                2,
            ],
            'outbox-duplicate' => [
                new LabConfig(...$base, delivery: DeliveryMode::Outbox),
                static fn (Counters $counters): FaultInjector => new FaultInjector(step: 'outbox.publish', after: true, counters: $counters),
                1,
                2,
            ],
        ];

        foreach ($cases as $name => [$config, $faultFactory, $count, $dispatches]) {
            $counters = new Counters();
            $faults = $faultFactory($counters);
            $lab = LabFactory::create($config, $faults, $counters);
            $lab->seed('flight-1', 'hotel-1', 'car-1', $capacity);

            $confirmed = 0;
            $eventsPublished = 0;

            for ($index = 0; $index < $count; ++$index) {
                $result = $lab->book(
                    new TripRequest('traveler-' . ($index % 10), 'flight-1', 'hotel-1', 'car-1'),
                    $index,
                );

                if ($result->status === BookingStatus::Confirmed) {
                    ++$confirmed;
                }

                if ($result->eventPublished) {
                    ++$eventsPublished;
                }
            }

            for ($dispatch = 0; $dispatch < $dispatches; ++$dispatch) {
                $lab->dispatchOutbox($count + 1);
            }

            $lab->drainProjection();
            $trips = $lab->projection->tripCount();
            $pending = $lab->outbox->countPending();

            $output->line(sprintf(
                '%-22s %8d %8d %8d %8d %8d %8d %8d',
                $name,
                $confirmed,
                $eventsPublished,
                $trips,
                max(0, $confirmed - $trips),
                $pending,
                $counters->get('duplicateEvents'),
                $counters->get('eventPublishFailures') + $counters->get('outboxFailures'),
            ));

            $output->measure($name . '_confirmed', $confirmed);
            $output->measure($name . '_events_published', $eventsPublished);
            $output->measure($name . '_projection_trips', $trips);
            $output->measure($name . '_lost_events', max(0, $confirmed - $trips));
            $output->measure($name . '_outbox_pending', $pending);
            $output->measure($name . '_duplicates', $counters->get('duplicateEvents'));
            $output->measure($name . '_failures', $counters->get('eventPublishFailures') + $counters->get('outboxFailures'));
        }

        $output->line();
        $output->line('A dual write that fails before the publish has already committed the');
        $output->line('booking, so the event is lost. The outbox row survives the same crash and the');
        $output->line('next dispatch delivers it; a crash after the publish repeats the event, which');
        $output->line('the idempotent projector absorbs.');
    },
);
