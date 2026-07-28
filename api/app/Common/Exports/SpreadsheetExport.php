<?php

declare(strict_types=1);

namespace App\Common\Exports;

use Illuminate\Support\Collection;

interface SpreadsheetExport
{
    /** @return Collection<int, mixed> */
    public function collection(): Collection;

    /** @return array<int, string> */
    public function headings(): array;

    /** @return array<int, mixed> */
    public function map(mixed $row): array;

    public function title(): string;
}
