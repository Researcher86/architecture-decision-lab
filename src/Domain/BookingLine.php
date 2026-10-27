<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

/**
 * One priced line inside a booking. The state is what the projection needs:
 * a released line must not reduce availability a second time.
 */
final readonly class BookingLine
{
    public function __construct(
        public ResourceType $resourceType,
        public string $resourceId,
        public int $units,
        public int $priceCents,
        public string $state,
    ) {
        if ($this->units < 1) {
            throw new \InvalidArgumentException('units must be at least 1');
        }

        if ($this->priceCents < 0) {
            throw new \InvalidArgumentException('price cannot be negative');
        }
    }

    /**
     * @return array{type: string, id: string, units: int, price_cents: int, state: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->resourceType->value,
            'id' => $this->resourceId,
            'units' => $this->units,
            'price_cents' => $this->priceCents,
            'state' => $this->state,
        ];
    }

    /**
     * @param array{type: string, id: string, units: int, price_cents: int, state: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ResourceType::from($data['type']),
            $data['id'],
            $data['units'],
            $data['price_cents'],
            $data['state'],
        );
    }
}
