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
    name: 'topology:deployment',
    description: 'one package booking under a monolith, a modular monolith and microservices',
    supports: ['bookings', 'capacity', 'seed', 'failure-step', 'failure-after', 'fail-every'],
    run: static function (Options $options, Output $output): void {
        $bookings = $options->int('bookings', 30);
        $capacity = $options->int('capacity', $bookings + 10);
        $seed = $options->string('seed', 'topology');
        $step = $options->string('failure-step', 'car.write');
        $after = $options->int('failure-after', 1) === 1;
        $failEvery = $options->int('fail-every', 0);

        $configs = [
            'monolith' => new LabConfig(
                shape: DeploymentShape::Monolith,
                processing: ProcessingMode::Sync,
                storage: StorageMode::Sql,
                database: DatabaseMode::Shared,
                delivery: DeliveryMode::Outbox,
                transaction: TransactionMode::Local,
                consistency: ConsistencyMode::Strong,
                seed: $seed,
                databaseDirectory: 'var/databases',
            ),
            'modular-monolith' => new LabConfig(
                shape: DeploymentShape::ModularMonolith,
                processing: ProcessingMode::Sync,
                storage: StorageMode::Sql,
                database: DatabaseMode::Shared,
                delivery: DeliveryMode::Outbox,
                transaction: TransactionMode::Local,
                consistency: ConsistencyMode::Strong,
                seed: $seed,
                databaseDirectory: 'var/databases',
            ),
            'microservices' => new LabConfig(
                shape: DeploymentShape::Microservices,
                processing: ProcessingMode::Sync,
                storage: StorageMode::Sql,
                database: DatabaseMode::PerService,
                delivery: DeliveryMode::Outbox,
                transaction: TransactionMode::Saga,
                consistency: ConsistencyMode::Strong,
                seed: $seed,
                databaseDirectory: 'var/databases',
            ),
        ];

        $output->heading('Topology', sprintf(
            'bookings=%d capacity=%d failure=%s(%s) seed=%s',
            $bookings,
            $capacity,
            $step,
            $after ? 'after' : 'before',
            $seed,
        ));
        $output->line(sprintf(
            '%-18s %7s %8s %8s %8s %8s %9s %9s',
            'shape',
            'ok',
            'cancelled',
            'comp',
            'remote',
            'sql',
            'avg ms',
            'avail',
        ));

        foreach ($configs as $name => $config) {
            $counters = new Counters();
            $faults = new FaultInjector(
                step: $step,
                after: $after,
                failEvery: $failEvery,
                counters: $counters,
            );
            $lab = LabFactory::create($config, $faults, $counters);
            $lab->seed('flight-1', 'hotel-1', 'car-1', $capacity);

            $confirmed = 0;
            $cancelled = 0;
            $compensations = 0;
            $latency = 0;

            for ($index = 0; $index < $bookings; ++$index) {
                $result = $lab->book(
                    new TripRequest('traveler-' . ($index % 10), 'flight-1', 'hotel-1', 'car-1'),
                    $index,
                );

                if ($result->status === BookingStatus::Confirmed) {
                    ++$confirmed;
                } else {
                    ++$cancelled;
                }

                $compensations += $result->compensations;
                $latency += $result->clientLatencyMs;
            }

            $lab->dispatchOutbox();
            $lab->drainProjection();

            $output->line(sprintf(
                '%-18s %7d %8d %8d %8d %8d %9.2f %9d',
                $name,
                $confirmed,
                $cancelled,
                $compensations,
                $counters->get('remoteCalls'),
                $counters->get('sqlQueries'),
                $latency / $bookings,
                $lab->sourceAvailability(ResourceType::Flight, 'flight-1'),
            ));

            $output->measure($name . '_confirmed', $confirmed);
            $output->measure($name . '_cancelled', $cancelled);
            $output->measure($name . '_compensations', $compensations);
            $output->measure($name . '_remote_calls', $counters->get('remoteCalls'));
            $output->measure($name . '_sql_queries', $counters->get('sqlQueries'));
            $output->measure($name . '_avg_client_latency_ms', $latency / $bookings);
            $output->measure($name . '_flight_available', $lab->sourceAvailability(ResourceType::Flight, 'flight-1'));
        }

        $output->line();
        $output->line('Monolith and modular monolith use one database and a local transaction;');
        $output->line('microservices use one database per service and a saga. The failure is a lost');
        $output->line('response from the car write: the effect exists, the caller does not know it.');
    },
);
