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
$make = static function (ProcessingMode $processing, int $queueLimit): Lab {
    $counters = new Counters();
    $lab = LabFactory::create(new LabConfig(
        shape: DeploymentShape::ModularMonolith,
        processing: $processing,
        storage: StorageMode::Sql,
        database: DatabaseMode::Shared,
        delivery: DeliveryMode::Outbox,
        transaction: TransactionMode::Local,
        consistency: ConsistencyMode::Strong,
        seed: 'processing-bench',
        queueLimit: $queueLimit,
        databaseDirectory: 'var/databases',
    ), new FaultInjector(counters: $counters), $counters);
    $lab->seed('flight-1', 'hotel-1', 'car-1', 1_000_000);

    return $lab;
};

$sync = $make(ProcessingMode::Sync, 1_000_000);
$async = $make(ProcessingMode::Async, 1_000_000);
$roundtrip = $make(ProcessingMode::Async, 1_000_000);

$syncIndex = 0;
$asyncIndex = 0;
$roundtripIndex = 0;

return [
    new Benchmark(
        'processing.sync_booking',
        500,
        static function () use ($sync, &$syncIndex): void {
            ++$syncIndex;
            $sync->book(new TripRequest('sync-' . $syncIndex, 'flight-1', 'hotel-1', 'car-1'), $syncIndex);
        },
    ),
    new Benchmark(
        'processing.async_submit',
        500,
        static function () use ($async, &$asyncIndex): void {
            ++$asyncIndex;
            $async->book(new TripRequest('async-' . $asyncIndex, 'flight-1', 'hotel-1', 'car-1'), $asyncIndex);
        },
    ),
    new Benchmark(
        'processing.async_roundtrip',
        500,
        static function () use ($roundtrip, &$roundtripIndex): void {
            ++$roundtripIndex;
            $roundtrip->book(new TripRequest('roundtrip-' . $roundtripIndex, 'flight-1', 'hotel-1', 'car-1'), $roundtripIndex);
            $roundtrip->drain(workers: 1, batchSize: 1);
        },
    ),
];
