<?php

declare(strict_types=1);

namespace App\Modules\DSS\Contracts;

final readonly class DetectorScanResult
{
    /**
     * @param list<DetectorEmission> $emissions
     */
    public function __construct(
        public array $emissions,
        public int $rowsScanned = 0,
        public bool $complete = true,
    ) {
        if ($rowsScanned < 0) {
            throw new \InvalidArgumentException('rowsScanned cannot be negative.');
        }
    }
}
