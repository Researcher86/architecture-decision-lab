<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Instrumentation;

/**
 * PHP allocator memory, measured in the current process.
 *
 * These values are memory_get_usage()/memory_get_peak_usage(), not RSS and not
 * native memory. Reports must keep that limitation visible.
 */
final class MemoryMeter
{
    /**
     * @return array{usage: int, real: int, peak: int, real_peak: int}
     */
    public function snapshot(): array
    {
        return [
            'usage' => memory_get_usage(false),
            'real' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(false),
            'real_peak' => memory_get_peak_usage(true),
        ];
    }

    public function resetPeak(): void
    {
        memory_reset_peak_usage();
    }

    public function delta(int $before, int $after): int
    {
        return $after - $before;
    }
}
