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
        $lineLabels = [
            'matched' => 'Matched',
            'qty_variance' => 'Qty variance',
            'price_variance' => 'Price variance',
            'both' => 'Qty + price',
            'grn_short' => 'GRN short',
        ];
        return [
            'po_id'          => $this->poId,
            'po_number'      => $this->poNumber,
            'lines'          => array_map(
                static fn (array $line): array => $line + ['status_label' => $lineLabels[$line['status'] ?? ''] ?? ($line['status'] ?? '')],
                $this->lines,
            ),
            'overall_status' => $this->overallStatus,
            'tolerances'     => $this->tolerances,
        ];
    }
}
