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
$make = static function (LabConfig $config): Lab {
    $counters = new Counters();
    $lab = LabFactory::create($config, new FaultInjector(counters: $counters), $counters);
    $lab->seed('flight-1', 'hotel-1', 'car-1', 1_000_000);

    return $lab;
};

$base = [
    'processing' => ProcessingMode::Sync,
    'storage' => StorageMode::Sql,
    'delivery' => DeliveryMode::Outbox,
    'consistency' => ConsistencyMode::Strong,
    'seed' => 'topology-bench',
    'databaseDirectory' => 'var/databases',
];

$monolith = $make(new LabConfig(
    ...$base,
    shape: DeploymentShape::Monolith,
    database: DatabaseMode::Shared,
    transaction: TransactionMode::Local,
));
$modular = $make(new LabConfig(
    ...$base,
    shape: DeploymentShape::ModularMonolith,
    database: DatabaseMode::Shared,
    transaction: TransactionMode::Local,
));
$remote = $make(new LabConfig(
    ...$base,
    shape: DeploymentShape::Microservices,
    database: DatabaseMode::PerService,
    transaction: TransactionMode::Saga,
));

$monolithIndex = 0;
$modularIndex = 0;
$remoteIndex = 0;

return [
    new Benchmark(
        'topology.monolith_booking',
        500,
        static function () use ($monolith, &$monolithIndex): void {
            ++$monolithIndex;
            $monolith->book(new TripRequest('monolith-' . $monolithIndex, 'flight-1', 'hotel-1', 'car-1'), $monolithIndex);
        },
    ),
    new Benchmark(
        'topology.modular_booking',
        500,
        static function () use ($modular, &$modularIndex): void {
            ++$modularIndex;
            $modular->book(new TripRequest('modular-' . $modularIndex, 'flight-1', 'hotel-1', 'car-1'), $modularIndex);
        },
    ),
    new Benchmark(
        'topology.microservices_booking',
        500,
        static function () use ($remote, &$remoteIndex): void {
            ++$remoteIndex;
            $remote->book(new TripRequest('remote-' . $remoteIndex, 'flight-1', 'hotel-1', 'car-1'), $remoteIndex);
        },
    ),
];
