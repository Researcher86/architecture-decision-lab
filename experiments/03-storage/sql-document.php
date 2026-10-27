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
use ArchitectureDecisionLab\Integration\Lab;
use ArchitectureDecisionLab\Integration\LabConfig;
use ArchitectureDecisionLab\Integration\LabFactory;

return new Experiment(
    name: 'storage:sql-document',
    description: 'compare one join with a document scan for the same booking aggregate',
    supports: ['bookings', 'travelers', 'capacity', 'seed'],
    run: static function (Options $options, Output $output): void {
        $bookings = $options->int('bookings', 100);
        $travelers = $options->int('travelers', 10);
        $capacity = $options->int('capacity', $bookings + 10);
        $seed = $options->string('seed', 'storage');

        $base = [
            'shape' => DeploymentShape::ModularMonolith,
            'processing' => ProcessingMode::Sync,
            'database' => DatabaseMode::Shared,
            'delivery' => DeliveryMode::Outbox,
            'transaction' => TransactionMode::Local,
            'consistency' => ConsistencyMode::Strong,
            'seed' => $seed,
            'databaseDirectory' => 'var/databases',
        ];

        $populate = static function (LabConfig $config) use ($bookings, $travelers, $capacity): Lab {
            $counters = new Counters();
            $lab = LabFactory::create($config, new FaultInjector(counters: $counters), $counters);
            $lab->seed('flight-1', 'hotel-1', 'car-1', $capacity);

            for ($index = 0; $index < $bookings; ++$index) {
                $lab->book(
                    new TripRequest('traveler-' . ($index % $travelers), 'flight-1', 'hotel-1', 'car-1'),
                    $index,
                );
            }

            $lab->dispatchOutbox();
            $lab->drainProjection();
            $lab->resetCounters();

            return $lab;
        };

        $labs = [
            'sql' => $populate(new LabConfig(...$base, storage: StorageMode::Sql)),
            'document' => $populate(new LabConfig(...$base, storage: StorageMode::Document)),
        ];

        $firstId = $labs['sql']->store->byTraveler('traveler-0')[0]->id;

        $output->heading('SQL vs document', sprintf(
            'bookings=%d travelers=%d capacity=%d',
            $bookings,
            $travelers,
            $capacity,
        ));
        $output->line(sprintf(
            '%-18s %-10s %8s %8s %10s %10s %8s',
            'operation',
            'store',
            'queries',
            'rows',
            'bytes',
            'scanned',
            'read',
        ));

        foreach (['sql', 'document'] as $name) {
            $lab = $labs[$name];

            foreach ([
                'point_read' => static fn (Lab $lab): int => $lab->store->get($firstId) === null ? 0 : 1,
                'traveler_query' => static function (Lab $lab) use ($travelers): int {
                    $found = 0;

                    for ($traveler = 0; $traveler < $travelers; ++$traveler) {
                        $found += count($lab->store->byTraveler('traveler-' . $traveler));
                    }

                    return $found;
                },
                'status_count' => static function (Lab $lab): int {
                    $counts = $lab->store->countByStatus();

                    return $counts[BookingStatus::Confirmed->value] ?? 0;
                },
            ] as $operation => $read) {
                $lab->resetCounters();
                $found = $read($lab);
                $counters = $lab->counters->toArray();

                $output->line(sprintf(
                    '%-18s %-10s %8d %8d %10d %10d %8d',
                    $operation,
                    $name,
                    $counters['sqlQueries'] ?? 0,
                    $counters['sqlRowsRead'] ?? 0,
                    $counters['sqlBytesRead'] ?? 0,
                    $counters['documentsScanned'] ?? 0,
                    $counters['documentsRead'] ?? 0,
                ));
                $output->measure(sprintf('%s_%s_queries', $name, $operation), $counters['sqlQueries'] ?? 0);
                $output->measure(sprintf('%s_%s_rows', $name, $operation), $counters['sqlRowsRead'] ?? 0);
                $output->measure(sprintf('%s_%s_bytes', $name, $operation), $counters['sqlBytesRead'] ?? 0);
                $output->measure(sprintf('%s_%s_scanned', $name, $operation), $counters['documentsScanned'] ?? 0);
                $output->measure(sprintf('%s_%s_read', $name, $operation), $counters['documentsRead'] ?? 0);
                $output->measure(sprintf('%s_%s_found', $name, $operation), $found);
            }
        }

        $output->line();
        $output->line('Both stores return the same aggregate; the document store pays for a point');
        $output->line('read with one row and for a traveler query with a full collection scan. The');
        $output->line('SQL store pays for a point read with two statements and for a traveler query');
        $output->line('with a join. Bytes are the JSON representation the adapter actually read.');
    },
);
