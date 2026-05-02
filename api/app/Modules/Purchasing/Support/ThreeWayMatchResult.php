<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

final class ThreeWayMatchResult
{
    /** @param array<int, array<string, mixed>> $lines */
    public function __construct(
        public readonly int $poId,
        public readonly string $poNumber,
        public readonly array $lines,
        public readonly string $overallStatus, // matched | has_variances | blocked
        public readonly array $tolerances,
    ) {}

    public function toArray(): array
    {
        return [
            'po_id'          => $this->poId,
            'po_number'      => $this->poNumber,
            'lines'          => $this->lines,
            'overall_status' => $this->overallStatus,
            'tolerances'     => $this->tolerances,
        ];
    }
}
