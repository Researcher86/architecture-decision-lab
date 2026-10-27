<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

/**
 * An immutable integration event. The same envelope is written to the outbox,
 * delivered to the bus, stored in the processed-event table and used as the
 * idempotency key of a listener.
 */
final readonly class EventEnvelope
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $payload,
        public int $occurredAt,
    ) {
    }

    /**
     * @return array{id: string, type: string, payload: array<string, mixed>, occurred_at: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt,
        ];
    }

    /**
     * @param array{id: string, type: string, payload: array<string, mixed>, occurred_at: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['type'], $data['payload'], $data['occurred_at']);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        /** @var array{id: string, type: string, payload: array<string, mixed>, occurred_at: int} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }
}
