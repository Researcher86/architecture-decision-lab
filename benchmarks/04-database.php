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
$make = static function (DatabaseMode $database, TransactionMode $transaction, DeploymentShape $shape): Lab {
    $counters = new Counters();
    $lab = LabFactory::create(new LabConfig(
        shape: $shape,
        processing: ProcessingMode::Sync,
        storage: StorageMode::Sql,
        database: $database,
        delivery: DeliveryMode::Outbox,
        transaction: $transaction,
        consistency: ConsistencyMode::Strong,
        seed: 'database-bench',
        databaseDirectory: 'var/databases',
    ), new FaultInjector(counters: $counters), $counters);
    $lab->seed('flight-1', 'hotel-1', 'car-1', 10_000);

    for ($index = 0; $index < 30; ++$index) {
        $lab->book(new TripRequest('traveler-1', 'flight-1', 'hotel-1', 'car-1'), $index);
    }

    $lab->dispatchOutbox();
    $lab->drainProjection();
    $lab->resetCounters();

    return $lab;
};

$shared = $make(DatabaseMode::Shared, TransactionMode::Local, DeploymentShape::ModularMonolith);
$perService = $make(DatabaseMode::PerService, TransactionMode::Saga, DeploymentShape::Microservices);

return [
    new Benchmark(
        'database.shared_report',
        1_000,
        static function () use ($shared): void {
            $shared->tripReport->forTraveler('traveler-1');
        },
    ),
    new Benchmark(
        'database.per_service_report',
        1_000,
        static function () use ($perService): void {
            $perService->tripReport->forTraveler('traveler-1');
        },
    ),
];
