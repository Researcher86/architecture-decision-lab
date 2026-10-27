<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Processing;

use ArchitectureDecisionLab\Domain\BookingResult;
use ArchitectureDecisionLab\Domain\BookingStatus;
use ArchitectureDecisionLab\Domain\TripRequest;
use ArchitectureDecisionLab\Foundation\VirtualClock;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Services\BookingWorkflow;
use ArchitectureDecisionLab\Storage\BookingStore;

/**
 * The asynchronous edge. `submit` returns a pending booking and a queue
 * position; `drain` is the worker that turns pending into a final status.
 * There is no real concurrency: workers are a deterministic loop, which is
 * enough to measure queueing, retries and client-visible latency.
 */
final readonly class AsyncBookingProcessor
{
    public function __construct(
        private BookingWorkflow $workflow,
        private JobQueue $queue,
        private BookingStore $store,
        private Counters $counters,
        private VirtualClock $clock,
        private int $maxAttempts = 3,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1');
        }
    }

    public function submit(TripRequest $request, int $index = 0): BookingResult
    {
        $startedAt = $this->clock->now();

        if ($this->queue->isFull()) {
            $this->counters->increment('queueRejections');
            $this->counters->increment('bookingsRejectedByQueue');
            $this->counters->set('queueDepthGauge', $this->queue->size());

            return new BookingResult(
                '',
                BookingStatus::Failed,
                false,
                $this->clock->now() - $startedAt,
                0,
                0,
                'queue is full',
            );
        }

        $booking = $this->workflow->submit($request);
        $job = new BookingJob($booking->id, $index, $startedAt, 0);

        if (!$this->queue->enqueue($job)) {
            $this->counters->increment('bookingsRejectedByQueue');
            $this->store->save($booking->withStatus(BookingStatus::Failed, $this->clock->now()));

            return new BookingResult(
                $booking->id,
                BookingStatus::Failed,
                false,
                $this->clock->now() - $startedAt,
                0,
                0,
                'queue is full',
            );
        }

        return new BookingResult(
            $booking->id,
            BookingStatus::Pending,
            true,
            $this->clock->now() - $startedAt,
            0,
            0,
            'accepted',
        );
    }

    /**
     * @return list<BookingResult>
     */
    public function drain(int $workers = 1, int $batchSize = 10): array
    {
        $results = [];
        $this->counters->increment('workerTicks');

        for ($worker = 0; $worker < $workers; ++$worker) {
            for ($item = 0; $item < $batchSize; ++$item) {
                $job = $this->queue->pop();

                if ($job === null) {
                    break 2;
                }

                $this->counters->increment('jobsProcessed');
                $booking = $this->store->get($job->bookingId);

                if ($booking === null) {
                    $this->counters->increment('jobsFailed');
                    continue;
                }

                try {
                    $result = $this->workflow->execute($booking, $job->index);
                } catch (\Throwable $throwable) {
                    if ($job->attempts + 1 < $this->maxAttempts) {
                        $this->counters->increment('jobsRetried');
                        $this->queue->enqueue($job->retry());
                        continue;
                    }

                    $this->counters->increment('jobsFailed');
                    $this->store->save($booking->withStatus(BookingStatus::Failed, $this->clock->now()));
                    $results[] = new BookingResult(
                        $booking->id,
                        BookingStatus::Failed,
                        false,
                        $this->clock->now() - $job->submittedAt,
                        $this->clock->now() - $job->submittedAt,
                        0,
                        $throwable->getMessage(),
                    );
                    continue;
                }

                $results[] = $result->withLatencies(
                    $result->clientLatencyMs,
                    $this->clock->now() - $job->submittedAt,
                );
            }
        }

        $this->counters->set('queueDepthGauge', $this->queue->size());

        return $results;
    }

    public function queueDepth(): int
    {
        return $this->queue->size();
    }
}
