<?php

declare(strict_types=1);

use ArchitectureDecisionLab\Domain\BookingStatus;
use ArchitectureDecisionLab\Domain\ConsistencyMode;
use ArchitectureDecisionLab\Domain\DatabaseMode;
use ArchitectureDecisionLab\Domain\DeliveryMode;
use ArchitectureDecisionLab\Domain\DeploymentShape;
use ArchitectureDecisionLab\Domain\ProcessingMode;
use ArchitectureDecisionLab\Domain\ResourceType;
use ArchitectureDecisionLab\Domain\StorageMode;
use ArchitectureDecisionLab\Domain\TransactionMode;
use ArchitectureDecisionLab\Domain\TripRequest;
use ArchitectureDecisionLab\Experiment\Experiment;
use ArchitectureDecisionLab\Experiment\Options;
use ArchitectureDecisionLab\Experiment\Output;
use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Integration\LabConfig;
use ArchitectureDecisionLab\Integration\LabFactory;

return new Experiment(
    name: 'processing:sync-async',
    description: 'compare a synchronous booking with a bounded asynchronous queue',
    supports: ['bookings', 'capacity', 'queue-limit', 'workers', 'batch-size', 'seed'],
    run: static function (Options $options, Output $output): void {
        $bookings = $options->int('bookings', 50);
        $capacity = $options->int('capacity', $bookings + 10);
        $queueLimit = $options->int('queue-limit', $bookings);
        $workers = $options->int('workers', 2);
        $batchSize = $options->int('batch-size', $bookings);
        $seed = $options->string('seed', 'processing');

        $base = [
            'shape' => DeploymentShape::ModularMonolith,
            'storage' => StorageMode::Sql,
            'database' => DatabaseMode::Shared,
            'delivery' => DeliveryMode::Outbox,
            'transaction' => TransactionMode::Local,
            'consistency' => ConsistencyMode::Strong,
            'seed' => $seed,
            'databaseDirectory' => 'var/databases',
        ];

        $cases = [
            'sync' => new LabConfig(
                ...$base,
                processing: ProcessingMode::Sync,
                queueLimit: $queueLimit,
            ),
            'async' => new LabConfig(
                ...$base,
                processing: ProcessingMode::Async,
                queueLimit: $queueLimit,
            ),
            'async-bounded' => new LabConfig(
                ...$base,
                processing: ProcessingMode::Async,
                queueLimit: min(10, $bookings),
            ),
        ];

        $output->heading('Sync vs async', sprintf(
            'bookings=%d capacity=%d queue_limit=%d workers=%d batch=%d',
            $bookings,
            $capacity,
            $queueLimit,
            $workers,
            $batchSize,
        ));
        $output->line(sprintf(
            '%-15s %8s %8s %8s %10s %12s %11s %8s',
            'case',
            'accepted',
            'rejected',
            'confirmed',
            'client ms',
            'completion ms',
            'queue max',
            'sql',
        ));

        foreach ($cases as $name => $config) {
            $counters = new Counters();
            $lab = LabFactory::create($config, new FaultInjector(counters: $counters), $counters);
            $lab->seed('flight-1', 'hotel-1', 'car-1', $capacity);

            $accepted = 0;
            $rejected = 0;
            $confirmed = 0;
            $clientLatency = 0;
            $completionLatency = 0;
            $completed = 0;
            $maxQueue = 0;

            if ($config->processing === ProcessingMode::Sync) {
                for ($index = 0; $index < $bookings; ++$index) {
                    $result = $lab->book(
                        new TripRequest('traveler-' . ($index % 10), 'flight-1', 'hotel-1', 'car-1'),
                        $index,
                    );
                    ++$accepted;
                    $clientLatency += $result->clientLatencyMs;
                    $completionLatency += $result->completionLatencyMs;
                    ++$completed;

                    if ($result->status === BookingStatus::Confirmed) {
                        ++$confirmed;
                    }
                }
            } else {
                for ($index = 0; $index < $bookings; ++$index) {
                    $result = $lab->book(
                        new TripRequest('traveler-' . ($index % 10), 'flight-1', 'hotel-1', 'car-1'),
                        $index,
                    );
                    $clientLatency += $result->clientLatencyMs;

                    if ($result->accepted) {
                        ++$accepted;
                    } else {
                        ++$rejected;
                    }

                    $maxQueue = max($maxQueue, $lab->queue->size());
                }

                $results = $lab->drain($workers, $batchSize);

                foreach ($results as $result) {
                    $completionLatency += $result->completionLatencyMs;
                    ++$completed;

                    if ($result->status === BookingStatus::Confirmed) {
                        ++$confirmed;
                    }
                }
            }

            $lab->dispatchOutbox();
            $lab->drainProjection();

            $output->line(sprintf(
                '%-15s %8d %8d %8d %10.2f %13.2f %11d %8d',
                $name,
                $accepted,
                $rejected,
                $confirmed,
                $accepted === 0 ? 0.0 : $clientLatency / $accepted,
                $completed === 0 ? 0.0 : $completionLatency / $completed,
                $maxQueue,
                $counters->get('sqlQueries'),
            ));

            $output->measure($name . '_accepted', $accepted);
            $output->measure($name . '_rejected', $rejected);
            $output->measure($name . '_confirmed', $confirmed);
            $output->measure($name . '_avg_client_latency_ms', $accepted === 0 ? 0.0 : $clientLatency / $accepted);
            $output->measure($name . '_avg_completion_latency_ms', $completed === 0 ? 0.0 : $completionLatency / $completed);
            $output->measure($name . '_max_queue_depth', $maxQueue);
            $output->measure($name . '_queue_rejections', $counters->get('queueRejections'));
            $output->measure($name . '_flight_available', $lab->sourceAvailability(ResourceType::Flight, 'flight-1'));
        }

        $output->line();
        $output->line('The async client latency is the submit cost, not the booking cost. The');
        $output->line('completion latency includes waiting for a worker; the bounded run shows what');
        $output->line('happens when the queue is full instead of growing without limit.');
    },
);
