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
$make = static function (ConsistencyMode $consistency): Lab {
    $counters = new Counters();
    $lab = LabFactory::create(new LabConfig(
        shape: DeploymentShape::ModularMonolith,
        processing: ProcessingMode::Sync,
        storage: StorageMode::Sql,
        database: DatabaseMode::Shared,
        delivery: DeliveryMode::Outbox,
        transaction: TransactionMode::Local,
        consistency: $consistency,
        seed: 'consistency-bench',
        projectionLagMs: 50,
        databaseDirectory: 'var/databases',
    ), new FaultInjector(counters: $counters), $counters);
    $lab->seed('flight-1', 'hotel-1', 'car-1', 1_000_000);

    return $lab;
};

$strong = $make(ConsistencyMode::Strong);
$eventual = $make(ConsistencyMode::Eventual);

$strongIndex = 0;
$eventualIndex = 0;

return [
    new Benchmark(
        'consistency.strong_roundtrip',
        300,
        static function () use ($strong, &$strongIndex): void {
            ++$strongIndex;
            $strong->book(new TripRequest('strong-' . $strongIndex, 'flight-1', 'hotel-1', 'car-1'), $strongIndex);
            $strong->dispatchOutbox();
            $strong->drainProjection();
        },
    ),
    new Benchmark(
        'consistency.eventual_roundtrip',
        300,
        static function () use ($eventual, &$eventualIndex): void {
            ++$eventualIndex;
            $eventual->book(new TripRequest('eventual-' . $eventualIndex, 'flight-1', 'hotel-1', 'car-1'), $eventualIndex);
            $eventual->dispatchOutbox();
            $eventual->drainProjection();
        },
    ),
];
