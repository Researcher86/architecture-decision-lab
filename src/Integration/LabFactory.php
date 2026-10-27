<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Integration;

use ArchitectureDecisionLab\Consistency\IdempotentProjector;
use ArchitectureDecisionLab\Consistency\ImmediateProjectionListener;
use ArchitectureDecisionLab\Consistency\InMemoryEventBus;
use ArchitectureDecisionLab\Consistency\Outbox;
use ArchitectureDecisionLab\Consistency\OutboxDispatcher;
use ArchitectureDecisionLab\Consistency\ProcessedEventStore;
use ArchitectureDecisionLab\Consistency\Projection;
use ArchitectureDecisionLab\Consistency\ProjectionQueue;
use ArchitectureDecisionLab\Consistency\ProjectionRunner;
use ArchitectureDecisionLab\Consistency\QueuedProjectionListener;
use ArchitectureDecisionLab\Domain\ConsistencyMode;
use ArchitectureDecisionLab\Domain\DatabaseMode;
use ArchitectureDecisionLab\Domain\DeploymentShape;
use ArchitectureDecisionLab\Domain\ResourceType;
use ArchitectureDecisionLab\Domain\StorageMode;
use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Foundation\SequentialIdGenerator;
use ArchitectureDecisionLab\Foundation\VirtualClock;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Processing\AsyncBookingProcessor;
use ArchitectureDecisionLab\Processing\JobQueue;
use ArchitectureDecisionLab\Services\BookingWorkflow;
use ArchitectureDecisionLab\Services\DirectTransport;
use ArchitectureDecisionLab\Services\InventoryService;
use ArchitectureDecisionLab\Services\ModuleTransport;
use ArchitectureDecisionLab\Services\PaymentService;
use ArchitectureDecisionLab\Services\RemoteTransport;
use ArchitectureDecisionLab\Services\Transport;
use ArchitectureDecisionLab\Storage\DocumentBookingStore;
use ArchitectureDecisionLab\Storage\Schema;
use ArchitectureDecisionLab\Storage\SqlBookingStore;
use ArchitectureDecisionLab\Storage\SqliteDatabase;

/**
 * Assembles a lab from a decision set. Every variant uses the same domain and
 * the same workflow; only the boundaries change.
 */
final class LabFactory
{
    public static function create(
        LabConfig $config,
        ?FaultInjector $faults = null,
        ?Counters $counters = null,
    ): Lab {
        $counters ??= new Counters();
        $clock = new VirtualClock();
        $faults ??= new FaultInjector(counters: $counters);
        $directory = self::directory($config);
        $ids = new SequentialIdGenerator();

        if ($config->database === DatabaseMode::Shared) {
            $bookingDb = new SqliteDatabase($directory . '/shared.sqlite', $counters);
            $reportingDb = $bookingDb;
            $flightDb = $bookingDb;
            $hotelDb = $bookingDb;
            $carDb = $bookingDb;
            $paymentDb = $bookingDb;
            Schema::all($bookingDb);
        } else {
            $bookingDb = new SqliteDatabase($directory . '/booking.sqlite', $counters);
            $reportingDb = new SqliteDatabase($directory . '/reporting.sqlite', $counters);
            $flightDb = new SqliteDatabase($directory . '/flight.sqlite', $counters);
            $hotelDb = new SqliteDatabase($directory . '/hotel.sqlite', $counters);
            $carDb = new SqliteDatabase($directory . '/car.sqlite', $counters);
            $paymentDb = new SqliteDatabase($directory . '/payment.sqlite', $counters);
            Schema::booking($bookingDb);
            Schema::reporting($reportingDb);
            Schema::inventory($flightDb, ResourceType::Flight);
            Schema::inventory($hotelDb, ResourceType::Hotel);
            Schema::inventory($carDb, ResourceType::Car);
            Schema::payment($paymentDb);
        }

        $transport = self::transport($config, $counters, $faults, $clock);
        $outbox = new Outbox($bookingDb, $counters, $clock);
        $store = $config->storage === StorageMode::Document
            ? new DocumentBookingStore($bookingDb, $counters, $outbox)
            : new SqlBookingStore($bookingDb, $counters, $outbox);

        $flight = new InventoryService(
            $flightDb,
            ResourceType::Flight,
            $transport,
            $counters,
            $clock,
            $faults,
        );
        $hotel = new InventoryService(
            $hotelDb,
            ResourceType::Hotel,
            $transport,
            $counters,
            $clock,
            $faults,
        );
        $car = new InventoryService(
            $carDb,
            ResourceType::Car,
            $transport,
            $counters,
            $clock,
            $faults,
        );
        $payment = new PaymentService($paymentDb, $transport, $counters, $clock, $faults);

        $bus = new InMemoryEventBus($counters);
        $projection = new Projection($reportingDb, $counters, $clock);
        $processed = new ProcessedEventStore($reportingDb, $counters, $clock);
        $projector = new IdempotentProjector($projection, $processed, $reportingDb, $counters);
        $queue = new ProjectionQueue($counters);
        $runner = new ProjectionRunner($queue, $projector, $clock, $counters);
        $listener = $config->consistency === ConsistencyMode::Strong
            ? new ImmediateProjectionListener($projector)
            : new QueuedProjectionListener($queue, $clock, $config->projectionLagMs);
        $bus->subscribe('booking.confirmed', $listener);
        $bus->subscribe('booking.cancelled', $listener);

        $dispatcher = new OutboxDispatcher($outbox, $bus, $counters, $faults);
        $workflow = new BookingWorkflow(
            $store,
            $flight,
            $hotel,
            $car,
            $payment,
            $bus,
            $clock,
            $counters,
            $faults,
            $config->delivery,
            $config->transaction,
            $ids,
            $config->database === DatabaseMode::Shared,
        );

        $jobQueue = new JobQueue($config->queueLimit, $counters);
        $async = new AsyncBookingProcessor($workflow, $jobQueue, $store, $counters, $clock);

        return new Lab(
            $config,
            $counters,
            $clock,
            $faults,
            $store,
            $flight,
            $hotel,
            $car,
            $payment,
            $bus,
            $outbox,
            $dispatcher,
            $projection,
            $runner,
            $workflow,
            $async,
            $jobQueue,
            $bookingDb,
            $reportingDb,
            $directory,
        );
    }

    private static function transport(
        LabConfig $config,
        Counters $counters,
        FaultInjector $faults,
        VirtualClock $clock,
    ): Transport {
        return match ($config->shape) {
            DeploymentShape::Monolith => new DirectTransport($counters, $faults),
            DeploymentShape::ModularMonolith => new ModuleTransport($counters, $faults),
            DeploymentShape::Microservices => new RemoteTransport(
                $counters,
                $faults,
                $clock,
                $config->remoteLatencyMs,
            ),
        };
    }

    private static function directory(LabConfig $config): string
    {
        $signature = substr(hash('xxh3', json_encode($config->toArray(), JSON_THROW_ON_ERROR)), 0, 10);
        $directory = sprintf(
            '%s/%s-%s-%s',
            $config->databaseDirectory,
            $config->seed,
            $signature,
            bin2hex(random_bytes(3)),
        );

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create lab directory %s', $directory));
        }

        return $directory;
    }
}
