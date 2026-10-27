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
        seed: 'transaction-bench',
        databaseDirectory: 'var/databases',
    ), new FaultInjector(counters: $counters), $counters);
    $lab->seed('flight-1', 'hotel-1', 'car-1', 1_000_000);

    return $lab;
};

$local = $make(DatabaseMode::Shared, TransactionMode::Local, DeploymentShape::ModularMonolith);
$saga = $make(DatabaseMode::PerService, TransactionMode::Saga, DeploymentShape::Microservices);
$twoPhase = $make(DatabaseMode::PerService, TransactionMode::TwoPhaseCommit, DeploymentShape::Microservices);

$localIndex = 0;
$sagaIndex = 0;
$twoPhaseIndex = 0;

return [
    new Benchmark(
        'transaction.local_booking',
        300,
        static function () use ($local, &$localIndex): void {
            ++$localIndex;
            $local->book(new TripRequest('local-' . $localIndex, 'flight-1', 'hotel-1', 'car-1'), $localIndex);
        },
    ),
    new Benchmark(
        'transaction.saga_booking',
        300,
        static function () use ($saga, &$sagaIndex): void {
            ++$sagaIndex;
            $saga->book(new TripRequest('saga-' . $sagaIndex, 'flight-1', 'hotel-1', 'car-1'), $sagaIndex);
        },
    ),
    new Benchmark(
        'transaction.two_phase_booking',
        300,
        static function () use ($twoPhase, &$twoPhaseIndex): void {
            ++$twoPhaseIndex;
            $twoPhase->book(new TripRequest('2pc-' . $twoPhaseIndex, 'flight-1', 'hotel-1', 'car-1'), $twoPhaseIndex);
        },
    ),
];
