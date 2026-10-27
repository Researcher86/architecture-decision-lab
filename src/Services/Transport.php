<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Services;

use Closure;

/**
 * The boundary a service call crosses. A direct call is a method call, a
 * module call crosses an explicit public API, and a remote call is the model
 * of an in-process RPC: it adds logical latency and can lose its response
 * after the remote side has already committed.
 */
interface Transport
{
    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function call(string $service, string $operation, Closure $work): mixed;

    public function name(): string;
}
