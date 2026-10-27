<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

/**
 * One row in the outbox, with the event it carries and how many times delivery
 * has been attempted.
 */
final readonly class OutboxMessage
{
    public function __construct(
        public int $sequence,
        public EventEnvelope $event,
        public int $attempts,
    ) {
    }
}
