<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Foundation;

/**
 * Deterministic failure injection at a named boundary.
 *
 * A step name is something like `car.reserve`, `payment.authorize` or
 * `outbox.publish`. `before` models a call that never reached the remote side;
 * `after` models an effect that happened but whose response was lost. The
 * second case is the one that makes sagas and idempotent consumers necessary.
 */
final class FaultInjector
{
    private bool $fired = false;
    private ?int $bookingIndex = null;

    public function __construct(
        private readonly string $step = '',
        private readonly bool $after = false,
        private readonly int $failEvery = 0,
        private readonly bool $once = true,
        private readonly ?\ArchitectureDecisionLab\Instrumentation\Counters $counters = null,
    ) {
        if ($failEvery < 0) {
            throw new \InvalidArgumentException('failEvery cannot be negative');
        }
    }

    public static function none(): self
    {
        return new self();
    }

    public function forBooking(int $index): void
    {
        $this->bookingIndex = $index;
    }

    public function before(string $step): void
    {
        $this->maybeFail($step, false);
    }

    public function after(string $step): void
    {
        $this->maybeFail($step, true);
    }

    public function step(): string
    {
        return $this->step;
    }

    public function hasFired(): bool
    {
        return $this->fired;
    }

    private function maybeFail(string $step, bool $after): void
    {
        if ($this->step === '' || $this->step !== $step || $this->after !== $after) {
            return;
        }

        if ($this->once && $this->fired) {
            return;
        }

        if ($this->failEvery > 0) {
            if ($this->bookingIndex === null) {
                return;
            }

            if (($this->bookingIndex + 1) % $this->failEvery !== 0) {
                return;
            }
        }

        $this->fired = true;
        $this->counters?->increment('failuresInjected');
        $this->counters?->increment($after ? 'failuresAfterEffect' : 'failuresBeforeEffect');

        throw new InjectedFailure(sprintf('Injected failure at %s (%s)', $step, $after ? 'after effect' : 'before effect'));
    }
}
