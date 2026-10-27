<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Integration;

use ArchitectureDecisionLab\Domain\ConsistencyMode;
use ArchitectureDecisionLab\Domain\DatabaseMode;
use ArchitectureDecisionLab\Domain\DeliveryMode;
use ArchitectureDecisionLab\Domain\DeploymentShape;
use ArchitectureDecisionLab\Domain\ProcessingMode;
use ArchitectureDecisionLab\Domain\StorageMode;
use ArchitectureDecisionLab\Domain\TransactionMode;

/**
 * The seven decisions as one configuration. Every experiment changes one or
 * two fields; the report prints the whole object so a number cannot be read
 * under the wrong assumptions.
 */
final readonly class LabConfig
{
    public function __construct(
        public DeploymentShape $shape = DeploymentShape::ModularMonolith,
        public ProcessingMode $processing = ProcessingMode::Sync,
        public StorageMode $storage = StorageMode::Sql,
        public DatabaseMode $database = DatabaseMode::Shared,
        public DeliveryMode $delivery = DeliveryMode::Outbox,
        public TransactionMode $transaction = TransactionMode::Local,
        public ConsistencyMode $consistency = ConsistencyMode::Strong,
        public string $seed = 'book',
        public int $projectionLagMs = 50,
        public int $remoteLatencyMs = 2,
        public int $queueLimit = 1000,
        public string $databaseDirectory = 'var/databases',
    ) {
        if ($this->projectionLagMs < 0) {
            throw new \InvalidArgumentException('projection lag cannot be negative');
        }

        if ($this->remoteLatencyMs < 0) {
            throw new \InvalidArgumentException('remote latency cannot be negative');
        }

        if ($this->queueLimit < 1) {
            throw new \InvalidArgumentException('queue limit must be at least 1');
        }

        if ($this->transaction === TransactionMode::Local && $this->database === DatabaseMode::PerService) {
            throw new \InvalidArgumentException(
                'A local transaction cannot be atomic across databases; use saga or two-phase-commit',
            );
        }
    }

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return [
            'shape' => $this->shape->value,
            'processing' => $this->processing->value,
            'storage' => $this->storage->value,
            'database' => $this->database->value,
            'delivery' => $this->delivery->value,
            'transaction' => $this->transaction->value,
            'consistency' => $this->consistency->value,
            'seed' => $this->seed,
            'projection_lag_ms' => $this->projectionLagMs,
            'remote_latency_ms' => $this->remoteLatencyMs,
            'queue_limit' => $this->queueLimit,
        ];
    }

    public function variation(string $key, string $value): self
    {
        return new self(
            shape: $key === 'shape' ? DeploymentShape::from($value) : $this->shape,
            processing: $key === 'processing' ? ProcessingMode::from($value) : $this->processing,
            storage: $key === 'storage' ? StorageMode::from($value) : $this->storage,
            database: $key === 'database' ? DatabaseMode::from($value) : $this->database,
            delivery: $key === 'delivery' ? DeliveryMode::from($value) : $this->delivery,
            transaction: $key === 'transaction' ? TransactionMode::from($value) : $this->transaction,
            consistency: $key === 'consistency' ? ConsistencyMode::from($value) : $this->consistency,
            seed: $this->seed,
            projectionLagMs: $this->projectionLagMs,
            remoteLatencyMs: $this->remoteLatencyMs,
            queueLimit: $this->queueLimit,
            databaseDirectory: $this->databaseDirectory,
        );
    }
}
