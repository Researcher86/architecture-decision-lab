<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

use ArchitectureDecisionLab\Instrumentation\Counters;

/**
 * A synchronous in-process bus. It is the delivery boundary of the lab, not a
 * broker: there is no network, no serialization between processes and no
 * consumer group. Those limitations are stated wherever the bus is measured.
 */
final class InMemoryEventBus
{
    /** @var array<string, list<EventListener>> */
    private array $listeners = [];

    public function __construct(
        private readonly Counters $counters,
    ) {
    }

    public function subscribe(string $type, EventListener $listener): void
    {
        $this->listeners[$type][] = $listener;
    }

    public function publish(EventEnvelope $event): void
    {
        $this->counters->increment('eventsPublished');
        $listeners = $this->listeners[$event->type] ?? [];

        foreach ($listeners as $listener) {
            $this->counters->increment('eventHandlerCalls');
            $listener->handle($event);
        }
    }

    public function listenerCount(): int
    {
        $count = 0;

        foreach ($this->listeners as $listeners) {
            $count += count($listeners);
        }

        return $count;
    }
}
