<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

interface PortalContractPdfProcessor
{
    /** @return list<array{name: string, type: string|null}> */
    public function discoverFields(string $pdfPath): array;

    /** @param array<string, string> $values PDF field name to scalar text value */
    public function fill(string $pdfPath, array $values): string;
}
