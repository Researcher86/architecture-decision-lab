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
    name: 'database:shared-per-service',
    description: 'turn a one-query traveler report into service calls and a saga',
    supports: ['bookings', 'capacity', 'seed', 'failure-step', 'failure-after', 'fail-every'],
    run: static function (Options $options, Output $output): void {
        $bookings = $options->int('bookings', 20);
        $capacity = $options->int('capacity', $bookings + 10);
        $seed = $options->string('seed', 'database');
        $step = $options->string('failure-step', 'payment.authorize');
        $after = $options->int('failure-after', 0) === 1;
        $failEvery = $options->int('fail-every', 10);

        $base = [
            'shape' => DeploymentShape::Microservices,
            'processing' => ProcessingMode::Sync,
            'storage' => StorageMode::Sql,
            'delivery' => DeliveryMode::Outbox,
            'consistency' => ConsistencyMode::Strong,
            'seed' => $seed,
            'databaseDirectory' => 'var/databases',
        ];

        $cases = [
            'shared-local' => new LabConfig(
                ...$base,
                database: DatabaseMode::Shared,
                transaction: TransactionMode::Local,
            ),
            'per-service-saga' => new LabConfig(
                ...$base,
                database: DatabaseMode::PerService,
                transaction: TransactionMode::Saga,
            ),
        ];

        $output->heading('Database per service', sprintf(
            'bookings=%d capacity=%d failure=%s every %d',
            $bookings,
            $capacity,
            $step,
            $failEvery,
        ));
        $output->line(sprintf(
            '%-18s %8s %8s %7s %8s %10s %10s %9s %9s',
            'case',
            'confirmed',
            'cancelled',
            'comp',
            'report',
            'report sql',
            'report net',
            'avg ms',
            'avail',
        ));

        foreach ($cases as $name => $config) {
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
                    new TripRequest('traveler-1', 'flight-1', 'hotel-1', 'car-1'),
                    $index,
                );
                $latency += $result->clientLatencyMs;

                if ($result->status === BookingStatus::Confirmed) {
                    ++$confirmed;
                } else {
                    ++$cancelled;
                }

                $compensations += $result->compensations;
            }

            $lab->dispatchOutbox();
            $lab->drainProjection();
            $lab->resetCounters();
            $report = $lab->tripReport->forTraveler('traveler-1');
            $reportCounters = $counters->toArray();

            $output->line(sprintf(
                '%-18s %8d %8d %7d %8d %10d %10d %9.2f %9d',
                $name,
                $confirmed,
                $cancelled,
                $compensations,
                count($report),
                $reportCounters['sqlQueries'] ?? 0,
                $reportCounters['networkRequestBytes'] ?? 0,
                $latency / $bookings,
                $lab->sourceAvailability(ResourceType::Flight, 'flight-1'),
            ));

            $output->measure($name . '_confirmed', $confirmed);
            $output->measure($name . '_cancelled', $cancelled);
            $output->measure($name . '_compensations', $compensations);
            $output->measure($name . '_report_rows', count($report));
            $output->measure($name . '_report_sql_queries', $reportCounters['sqlQueries'] ?? 0);
            $output->measure($name . '_report_remote_calls', $reportCounters['remoteCalls'] ?? 0);
            $output->measure($name . '_report_network_bytes', $reportCounters['networkRequestBytes'] ?? 0);
            $output->measure($name . '_avg_client_latency_ms', $latency / $bookings);
            $output->measure($name . '_flight_available', $lab->sourceAvailability(ResourceType::Flight, 'flight-1'));
        }

        $output->line();
        $output->line('The shared database can answer the report with one join and can wrap the');
        $output->line('whole booking in one local transaction. With a database per service the same');
        $output->line('report needs the booking query plus three inventory calls per row, and the');
        $output->line('booking needs a saga because no local transaction spans the services.');
    },
);
