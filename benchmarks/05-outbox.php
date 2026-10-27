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
$make = static function (DeliveryMode $delivery): Lab {
    $counters = new Counters();
    $lab = LabFactory::create(new LabConfig(
        shape: DeploymentShape::ModularMonolith,
        processing: ProcessingMode::Sync,
        storage: StorageMode::Sql,
        database: DatabaseMode::Shared,
        delivery: $delivery,
        transaction: TransactionMode::Local,
        consistency: ConsistencyMode::Strong,
        seed: 'outbox-bench',
        databaseDirectory: 'var/databases',
    ), new FaultInjector(counters: $counters), $counters);
    $lab->seed('flight-1', 'hotel-1', 'car-1', 1_000_000);

    return $lab;
};

$dualWrite = $make(DeliveryMode::DualWrite);
$outbox = $make(DeliveryMode::Outbox);
$dispatch = $make(DeliveryMode::Outbox);

$dualIndex = 0;
$outboxIndex = 0;
$dispatchIndex = 0;

return [
    new Benchmark(
        'outbox.dual_write_booking',
        300,
        static function () use ($dualWrite, &$dualIndex): void {
            ++$dualIndex;
            $dualWrite->book(new TripRequest('dual-' . $dualIndex, 'flight-1', 'hotel-1', 'car-1'), $dualIndex);
        },
    ),
    new Benchmark(
        'outbox.outbox_booking',
        300,
        static function () use ($outbox, &$outboxIndex): void {
            ++$outboxIndex;
            $outbox->book(new TripRequest('outbox-' . $outboxIndex, 'flight-1', 'hotel-1', 'car-1'), $outboxIndex);
        },
    ),
    new Benchmark(
        'outbox.booking_and_dispatch',
        300,
        static function () use ($dispatch, &$dispatchIndex): void {
            ++$dispatchIndex;
            $dispatch->book(new TripRequest('dispatch-' . $dispatchIndex, 'flight-1', 'hotel-1', 'car-1'), $dispatchIndex);
            $dispatch->dispatchOutbox(10_000);
            $dispatch->drainProjection();
        },
    ),
];
