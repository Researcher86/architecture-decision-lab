<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

enum ResourceType: string
{
    case Flight = 'flight';
    case Hotel = 'hotel';
    case Car = 'car';

    public function table(): string
    {
        return match ($this) {
            self::Flight => 'flights',
            self::Hotel => 'hotels',
            self::Car => 'cars',
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function fromTable(string $table): self
    {
        return match ($table) {
            'flights' => self::Flight,
            'hotels' => self::Hotel,
            'cars' => self::Car,
            default => throw new \InvalidArgumentException(sprintf('Unknown resource table: %s', $table)),
        };
    }
}
