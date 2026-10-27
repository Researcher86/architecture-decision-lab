<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Services;

use ArchitectureDecisionLab\Domain\PaymentResult;
use ArchitectureDecisionLab\Domain\PaymentStatus;
use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Foundation\VirtualClock;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Storage\SqliteDatabase;

/**
 * A deterministic payment service. `authorize` is a hold, `capture` turns it
 * into a charge and `void` is the compensation. Idempotency is keyed by the
 * booking id, so a retried authorize returns the original decision.
 */
final class PaymentService
{
    public function __construct(
        private readonly SqliteDatabase $db,
        private readonly Transport $transport,
        private readonly Counters $counters,
        private readonly VirtualClock $clock,
        private readonly FaultInjector $faults,
    ) {
    }

    public function authorize(string $bookingId, int $amountCents): PaymentResult
    {
        return $this->transport->call(
            'payment',
            'authorize',
            fn (): PaymentResult => $this->authorizeLocal($bookingId, $amountCents, PaymentStatus::Authorized),
        );
    }

    public function prepare(string $bookingId, int $amountCents): PaymentResult
    {
        return $this->transport->call(
            'payment',
            'prepare',
            fn (): PaymentResult => $this->authorizeLocal($bookingId, $amountCents, PaymentStatus::Prepared),
        );
    }

    public function capture(string $bookingId): void
    {
        $this->transport->call('payment', 'capture', function () use ($bookingId): void {
            $status = $this->status($bookingId);

            if ($status === PaymentStatus::Captured) {
                $this->counters->increment('idempotentCaptures');

                return;
            }

            if ($status !== PaymentStatus::Authorized && $status !== PaymentStatus::Prepared) {
                throw new \RuntimeException(sprintf(
                    'Cannot capture payment %s in state %s',
                    $bookingId,
                    $status === null ? 'missing' : $status->value,
                ));
            }

            $this->db->execute(
                'UPDATE payments SET status = :status, updated_at = :now WHERE booking_id = :booking_id',
                ['status' => PaymentStatus::Captured->value, 'now' => $this->clock->now(), 'booking_id' => $bookingId],
            );
            $this->counters->increment('paymentsCaptured');
        });
    }

    /**
     * A void is a compensation. It is safe to call after a failed authorize
     * that never created a row, and safe to call twice.
     */
    public function void(string $bookingId): void
    {
        $this->transport->call('payment', 'void', function () use ($bookingId): void {
            $status = $this->status($bookingId);

            if ($status === null) {
                $this->counters->increment('paymentVoidNoops');

                return;
            }

            if ($status === PaymentStatus::Voided || $status === PaymentStatus::Declined) {
                $this->counters->increment('idempotentVoids');

                return;
            }

            $this->db->execute(
                'UPDATE payments SET status = :status, updated_at = :now WHERE booking_id = :booking_id',
                ['status' => PaymentStatus::Voided->value, 'now' => $this->clock->now(), 'booking_id' => $bookingId],
            );
            $this->counters->increment('paymentsVoided');
            $this->counters->increment('compensations');
        });
    }

    public function status(string $bookingId): ?PaymentStatus
    {
        $value = $this->db->scalar(
            'SELECT status FROM payments WHERE booking_id = :booking_id',
            ['booking_id' => $bookingId],
        );

        if (!is_string($value)) {
            return null;
        }

        return PaymentStatus::tryFrom($value);
    }

    public function amount(string $bookingId): int
    {
        $value = $this->db->scalar(
            'SELECT amount_cents FROM payments WHERE booking_id = :booking_id',
            ['booking_id' => $bookingId],
        );

        return $value === null ? 0 : (int) $value;
    }

    private function authorizeLocal(string $bookingId, int $amountCents, PaymentStatus $status): PaymentResult
    {
        if ($amountCents < 0) {
            throw new \InvalidArgumentException('amount cannot be negative');
        }

        $existing = $this->status($bookingId);

        if ($existing !== null) {
            if ($existing === PaymentStatus::Authorized || $existing === PaymentStatus::Captured || $existing === PaymentStatus::Prepared) {
                $this->counters->increment('idempotentPayments');

                return new PaymentResult(true, $existing, $bookingId, $this->amount($bookingId));
            }

            return PaymentResult::failed($bookingId, $amountCents, sprintf('payment is %s', $existing->value));
        }

        $this->faults->before('payment.write');

        $this->db->execute(
            'INSERT INTO payments (booking_id, amount_cents, status, created_at, updated_at)
             VALUES (:booking_id, :amount, :status, :now, :now)',
            [
                'booking_id' => $bookingId,
                'amount' => $amountCents,
                'status' => $status->value,
                'now' => $this->clock->now(),
            ],
        );

        $this->counters->increment('paymentsAuthorized');
        $this->faults->after('payment.write');

        return new PaymentResult(true, $status, $bookingId, $amountCents);
    }
}
