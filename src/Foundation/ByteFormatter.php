<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Foundation;

/**
 * Human-readable byte counts for reports. The underlying number stays an
 * integer; this class never rounds before the value is stored.
 */
final class ByteFormatter
{
    public static function format(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024.0 && $unit < count($units) - 1) {
            $value /= 1024.0;
            ++$unit;
        }

        return $unit === 0
            ? sprintf('%d B', $bytes)
            : sprintf('%.1f %s', $value, $units[$unit]);
    }
}
