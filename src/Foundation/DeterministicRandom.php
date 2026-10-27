<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Foundation;

/**
 * A deterministic pseudo-random sequence for generated workloads.
 *
 * The project needs repeatable data, not cryptographic randomness. A 32-bit
 * linear congruential generator is enough for choosing a failure position or a
 * resource id, and it never overflows a signed 64-bit PHP integer.
 */
final class DeterministicRandom
{
    private int $state;

    public function __construct(string $seed)
    {
        $state = crc32($seed);
        $this->state = $state === 0 ? 0x9E3779B9 : $state;
    }

    public function next(): int
    {
        $this->state = (1103515245 * $this->state + 12345) & 0x7FFFFFFF;

        return $this->state;
    }

    public function nextInt(int $maxExclusive): int
    {
        if ($maxExclusive < 1) {
            throw new \InvalidArgumentException('maxExclusive must be at least 1');
        }

        return $this->next() % $maxExclusive;
    }

    public function chance(int $numerator, int $denominator): bool
    {
        if ($numerator > $denominator) {
            throw new \InvalidArgumentException('numerator cannot exceed denominator');
        }

        return $this->nextInt($denominator) < $numerator;
    }
}
