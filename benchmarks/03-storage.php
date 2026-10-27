<?php

declare(strict_types=1);

use ArchitectureDecisionLab\Benchmark\Benchmark;
use ArchitectureDecisionLab\Domain\ConsistencyMode;
use ArchitectureDecisionLab\Domain\DatabaseMode;
use ArchitectureDecisionLab\Domain\DeliveryMode;
use ArchitectureDecisionLab\Domain\DeploymentShape;
use ArchitectureDecisionLab\Domain\ProcessingMode;
use ArchitectureDecisionLab\Domain\StorageMode;
use ArchitectureDecisionLab\Domain\TransactionMode;
use ArchitectureDecisionLab\Domain\TripRequest;
use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Integration\Lab;
use ArchitectureDecisionLab\Integration\LabConfig;
use ArchitectureDecisionLab\Integration\LabFactory;

/**
 * @return Lab
 */
$make = static function (StorageMode $storage): Lab {
    $counters = new Counters();
    $lab = LabFactory::create(new LabConfig(
        shape: DeploymentShape::ModularMonolith,
        processing: ProcessingMode::Sync,
        storage: $storage,
        database: DatabaseMode::Shared,
        delivery: DeliveryMode::Outbox,
        transaction: TransactionMode::Local,
        consistency: ConsistencyMode::Strong,
        seed: 'storage-bench',
        databaseDirectory: 'var/databases',
    ), new FaultInjector(counters: $counters), $counters);
    $lab->seed('flight-1', 'hotel-1', 'car-1', 10_000);

    for ($index = 0; $index < 100; ++$index) {
        $lab->book(new TripRequest('traveler-' . ($index % 10), 'flight-1', 'hotel-1', 'car-1'), $index);
    }

    $lab->dispatchOutbox();
    $lab->drainProjection();
    $lab->resetCounters();

    return $lab;
};

$sql = $make(StorageMode::Sql);
$document = $make(StorageMode::Document);
$bookingId = $sql->store->byTraveler('traveler-0')[0]->id;

return [
    new Benchmark(
        'storage.sql_point_read',
        5_000,
        static function () use ($sql, $bookingId): void {
            $sql->store->get($bookingId);
        },
    ),
    new Benchmark(
        'storage.document_point_read',
        5_000,
        static function () use ($document, $bookingId): void {
            $document->store->get($bookingId);
        },
    ),
    new Benchmark(
        'storage.sql_traveler_query',
        1_000,
        static function () use ($sql): void {
            $sql->store->byTraveler('traveler-0');
        },
    ),
    new Benchmark(
        'storage.document_traveler_query',
        1_000,
        static function () use ($document): void {
            $document->store->byTraveler('traveler-0');
        },
    ),
];
