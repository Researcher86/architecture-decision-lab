<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Integration;

use ArchitectureDecisionLab\Consistency\DispatchReport;
use ArchitectureDecisionLab\Consistency\InMemoryEventBus;
use ArchitectureDecisionLab\Consistency\Outbox;
use ArchitectureDecisionLab\Consistency\OutboxDispatcher;
use ArchitectureDecisionLab\Consistency\Projection;
use ArchitectureDecisionLab\Consistency\ProjectionRunner;
use ArchitectureDecisionLab\Domain\BookingResult;
use ArchitectureDecisionLab\Domain\BookingStatus;
use ArchitectureDecisionLab\Domain\ProcessingMode;
use ArchitectureDecisionLab\Domain\ResourceType;
use ArchitectureDecisionLab\Domain\TripRequest;
use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Foundation\VirtualClock;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Processing\AsyncBookingProcessor;
use ArchitectureDecisionLab\Processing\JobQueue;
use ArchitectureDecisionLab\Services\BookingWorkflow;
use ArchitectureDecisionLab\Services\InventoryService;
use ArchitectureDecisionLab\Services\PaymentService;
use ArchitectureDecisionLab\Storage\BookingStore;
use ArchitectureDecisionLab\Storage\SqliteDatabase;

/**
 * One assembled laboratory. The public surface is intentionally narrow: seed,
 * book, drain, dispatch, project, read. Experiments compose these calls; the
 * scenario does the same in one deterministic order.
 */
final class Lab
{
    public function __construct(
        public readonly LabConfig $config,
        public readonly Counters $counters,
        public readonly VirtualClock $clock,
        public readonly FaultInjector $faults,
        public readonly BookingStore $store,
        public readonly InventoryService $flight,
        public readonly InventoryService $hotel,
        public readonly InventoryService $car,
        public readonly PaymentService $payment,
        public readonly InMemoryEventBus $bus,
        public readonly Outbox $outbox,
        public readonly OutboxDispatcher $dispatcher,
        public readonly Projection $projection,
        public readonly ProjectionRunner $projectionRunner,
        public readonly BookingWorkflow $workflow,
        public readonly AsyncBookingProcessor $async,
        public readonly JobQueue $queue,
        public readonly SqliteDatabase $bookingDb,
        public readonly SqliteDatabase $reportingDb,
        public readonly string $directory,
    ) {
    }

    public function seed(
        string $flightId,
        string $hotelId,
        string $carId,
        int $capacity = 4,
        int $flightPrice = 12000,
        int $hotelPrice = 25000,
        int $carPrice = 8000,
    ): void {
        $this->seedResource(ResourceType::Flight, $flightId, $capacity, $flightPrice);
        $this->seedResource(ResourceType::Hotel, $hotelId, $capacity, $hotelPrice);
        $this->seedResource(ResourceType::Car, $carId, $capacity, $carPrice);
    }

    public function seedResource(ResourceType $type, string $id, int $capacity, int $priceCents): void
    {
        $this->service($type)->seed($id, $capacity, $priceCents);
        $this->projection->seed($type, $id, $capacity);
    }

    public function book(TripRequest $request, int $index = 0): BookingResult
    {
        if ($this->config->processing === ProcessingMode::Async) {
            return $this->async->submit($request, $index);
        }

        $booking = $this->workflow->submit($request);

        return $this->workflow->execute($booking, $index);
    }

    /**
     * @return list<BookingResult>
     */
    public function drain(int $workers = 1, int $batchSize = 10): array
    {
        return $this->async->drain($workers, $batchSize);
    }

    public function dispatchOutbox(int $limit = 100): DispatchReport
    {
        return $this->dispatcher->dispatch($limit);
    }

    public function drainProjection(): int
    {
        return $this->projectionRunner->drain();
    }

    public function advance(int $milliseconds): void
    {
        $this->clock->advance($milliseconds);
    }

    public function sourceAvailability(ResourceType $type, string $id): int
    {
        return $this->service($type)->available($id);
    }

    public function projectedAvailability(ResourceType $type, string $id): ?int
    {
        return $this->projection->availability($type, $id);
    }

    public function service(ResourceType $type): InventoryService
    {
        return match ($type) {
            ResourceType::Flight => $this->flight,
            ResourceType::Hotel => $this->hotel,
            ResourceType::Car => $this->car,
        };
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $statuses = $this->store->countByStatus();
        $outbox = $this->outbox->countByStatus();

        return [
            'bookings_pending' => $statuses[BookingStatus::Pending->value] ?? 0,
            'bookings_confirmed' => $statuses[BookingStatus::Confirmed->value] ?? 0,
            'bookings_cancelled' => $statuses[BookingStatus::Cancelled->value] ?? 0,
            'bookings_failed' => $statuses[BookingStatus::Failed->value] ?? 0,
            'reservations' => count($this->flight->reservations()) + count($this->hotel->reservations()) + count($this->car->reservations()),
            'outbox_pending' => $outbox['pending'] ?? 0,
            'outbox_published' => $outbox['published'] ?? 0,
            'projection_trips' => $this->projection->tripCount(),
            'queue_depth' => $this->queue->size(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function counterValues(): array
    {
        return $this->counters->toArray();
    }

    public function resetCounters(): void
    {
        $this->counters->reset();
    }
}
