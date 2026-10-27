<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

use ArchitectureDecisionLab\Instrumentation\Counters;

/**
 * Events waiting for a projection, with the logical time at which they become
 * visible. This is the lag that an eventual-consistency reader observes.
 */
final class ProjectionQueue
{
    /** @var list<array{event: EventEnvelope, due_at: int}> */
    private array $entries = [];

    public function __construct(
        private readonly Counters $counters,
    ) {
    }

    public function enqueue(EventEnvelope $event, int $dueAt): void
    {
        $this->entries[] = ['event' => $event, 'due_at' => $dueAt];
        usort($this->entries, static fn (array $a, array $b): int => $a['due_at'] <=> $b['due_at']);
        $this->counters->increment('projectionQueued');
    }

    /**
     * @return list<EventEnvelope>
     */
    public function due(int $now): array
    {
        $due = [];
        $remaining = [];

        foreach ($this->entries as $entry) {
            if ($entry['due_at'] > $now) {
                $remaining[] = $entry;

                continue;
            }

            $due[] = $entry['event'];
        }

        $this->entries = $remaining;

        return $due;
    }

    /**
     * @return array{event: EventEnvelope, due_at: int}|null
     */
    public function next(): ?array
    {
        return $this->entries[0] ?? null;
    }

    public function removeNext(): void
    {
        array_shift($this->entries);
    }

    public function size(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
