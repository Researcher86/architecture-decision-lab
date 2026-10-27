<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Instrumentation;

/**
 * Named operation counts.
 *
 * Time says how fast this machine is; counters say what the code did. A
 * chapter claims "one SQL query instead of four" only when a counter records
 * it. Counter names are shared across chapters so tables can be compared.
 */
final class Counters
{
    /** @var array<string, int> */
    private array $values = [];

    public function increment(string $name, int $by = 1): void
    {
        $this->values[$name] = ($this->values[$name] ?? 0) + $by;
    }

    /**
     * Add a non-negative amount; a negative value would make a counter lie.
     */
    public function add(string $name, int $by): void
    {
        if ($by < 0) {
            throw new \InvalidArgumentException(sprintf('counter %s cannot decrease', $name));
        }

        $this->increment($name, $by);
    }

    /**
     * Set a gauge such as queue depth. A gauge is a current value, not an
     * accumulated count; reports still print it in the same measurement block.
     */
    public function set(string $name, int $value): void
    {
        $this->values[$name] = $value;
    }

    public function get(string $name): int
    {
        return $this->values[$name] ?? 0;
    }

    public function reset(): void
    {
        $this->values = [];
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        $values = $this->values;
        ksort($values);

        return $values;
    }
}
