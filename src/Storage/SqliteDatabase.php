<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Storage;

use ArchitectureDecisionLab\Instrumentation\Counters;
use PDO;
use PDOStatement;

/**
 * A small SQLite wrapper that owns transactions and the SQL counters.
 *
 * Nested calls use savepoints, so a monolith can wrap a whole booking in one
 * transaction while each service still uses the same transaction helper. A
 * microservice composition opens separate connections and therefore gets real
 * independent commits.
 */
final class SqliteDatabase
{
    private PDO $pdo;
    private int $depth = 0;
    private int $savepoint = 0;

    public function __construct(
        private readonly string $path,
        private readonly Counters $counters,
    ) {
        if ($path !== ':memory:') {
            $directory = dirname($path);

            if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Cannot create database directory %s', $directory));
            }
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        if ($path !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<string, int|string|float|null> $parameters
     */
    public function execute(string $sql, array $parameters = []): int
    {
        $this->counters->increment('sqlQueries');
        $statement = $this->prepare($sql, $parameters);
        $affected = $statement->rowCount();
        $this->counters->add('sqlRowsWritten', max(0, $affected));

        return $affected;
    }

    /**
     * @param array<string, int|string|float|null> $parameters
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $parameters = []): array
    {
        $this->counters->increment('sqlQueries');
        $statement = $this->prepare($sql, $parameters);
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        $this->counters->add('sqlRowsRead', count($rows));
        $this->counters->add('sqlBytesRead', $this->bytes($rows));

        return $rows;
    }

    /**
     * @param array<string, int|string|float|null> $parameters
     */
    public function scalar(string $sql, array $parameters = []): mixed
    {
        $this->counters->increment('sqlQueries');
        $statement = $this->prepare($sql, $parameters);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $outer = $this->depth === 0;

        if ($outer) {
            $this->counters->increment('dbTransactions');
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            ++$this->savepoint;
            $this->counters->increment('dbSavepoints');
            $this->pdo->exec(sprintf('SAVEPOINT sp_%d', $this->savepoint));
        }

        ++$this->depth;

        try {
            $result = $work();
        } catch (\Throwable $throwable) {
            --$this->depth;

            if ($outer) {
                $this->pdo->exec('ROLLBACK');
                $this->counters->increment('dbRollbacks');
            } else {
                $this->pdo->exec(sprintf('ROLLBACK TO SAVEPOINT sp_%d', $this->savepoint));
                $this->pdo->exec(sprintf('RELEASE SAVEPOINT sp_%d', $this->savepoint));
                --$this->savepoint;
            }

            throw $throwable;
        }

        if ($outer) {
            $this->pdo->exec('COMMIT');
            $this->counters->increment('dbCommits');
        } else {
            $this->pdo->exec(sprintf('RELEASE SAVEPOINT sp_%d', $this->savepoint));
            --$this->savepoint;
        }

        return $result;
    }

    /**
     * @param array<string, int|string|float|null> $parameters
     */
    private function prepare(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        if ($statement === false) {
            throw new \RuntimeException(sprintf('Cannot prepare SQL: %s', $sql));
        }

        $statement->execute($parameters);

        return $statement;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function bytes(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $encoded = json_encode($rows);

        return is_string($encoded) ? strlen($encoded) : 0;
    }
}
