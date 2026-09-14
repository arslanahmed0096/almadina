<?php

namespace App\Services\Targets;

use InvalidArgumentException;

class TargetAllocationService
{
    public function equally(float $total, int $warehouseCount, int $precision = 3): array
    {
        if ($total < 0 || $warehouseCount < 1) {
            throw new InvalidArgumentException('A non-negative total and at least one warehouse are required.');
        }
        $factor = 10 ** $precision;
        $minorTotal = (int) round($total * $factor);
        $each = intdiv($minorTotal, $warehouseCount);
        $remainder = $minorTotal - ($each * $warehouseCount);

        return array_map(fn ($index) => ($each + ($index < $remainder ? 1 : 0)) / $factor, range(0, $warehouseCount - 1));
    }
}
