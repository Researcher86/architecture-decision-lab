<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Foundation;

/**
 * Integer money. Every amount is stored in cents; there is no float path.
 */
final readonly class Money
{
    public function __construct(
        public int $cents,
    ) {
        if ($this->cents < 0) {
            throw new \InvalidArgumentException('money cannot be negative');
        }
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new \InvalidArgumentException('factor cannot be negative');
        }

        return new self($this->cents * $factor);
    }

    public function format(): string
    {
        return sprintf('%d.%02d', intdiv($this->cents, 100), $this->cents % 100);
    }
}
