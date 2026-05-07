<?php

declare(strict_types=1);

namespace App\Common\Exports;

use App\Common\Services\Export\ExportColumnRegistry;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Series E (Task E2) — base class for every "configurable columns" module
 * export. Subclasses override `query()` and `module()`. Headers, mapping,
 * styles, freeze pane all come for free from the registry.
 */
abstract class BaseModuleExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    WithStyles,
    WithEvents,
    WithTitle,
    ShouldAutoSize
{
    /**
     * @param  array<int, string>  $columns  Column keys, in order.
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        protected array $columns,
        protected array $filters = [],
    ) {}

    /** Module key, e.g. "hr.employees". */
    abstract public function module(): string;

    /** Return the dataset to export — Eloquent collection or generic Collection. */
    abstract public function collection();

    /** @return array<int, string> */
    public function headings(): array
    {
        $registry = ExportColumnRegistry::for($this->module());
        $headers = [];
        foreach ($this->columns as $key) {
            $headers[] = $registry[$key]['label'] ?? $this->humanize($key);
        }
        return $headers;
    }

    /**
     * @param  mixed  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        $registry = ExportColumnRegistry::for($this->module());
        $out = [];
        foreach ($this->columns as $key) {
            $def = $registry[$key] ?? null;
            if ($def && isset($def['resolver']) && is_callable($def['resolver'])) {
                $out[] = ($def['resolver'])($row);
            } else {
                // Default resolver: arrow-access (for arrays + models).
                $out[] = is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
            }
        }
        return $out;
    }

    public function title(): string
    {
        return substr($this->module() . ' ' . now()->format('Y-m-d'), 0, 31);
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            // Bold + light-gray header row
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => '111111']],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F4F4F5'],
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                // Freeze the header row.
                $sheet->freezePane('A2');
                // Subtle alternating-row fill (every other data row).
                $highest = $sheet->getHighestDataRow();
                $highestCol = $sheet->getHighestDataColumn();
                for ($row = 2; $row <= $highest; $row += 2) {
                    $sheet->getStyle("A{$row}:{$highestCol}{$row}")->getFill()->applyFromArray([
                        'fillType'   => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'FAFAFA'],
                    ]);
                }
            },
        ];
    }

    private function humanize(string $key): string
    {
        return ucwords(str_replace(['_', '.'], ' ', $key));
    }
}
