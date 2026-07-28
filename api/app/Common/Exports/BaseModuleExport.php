<?php

declare(strict_types=1);

namespace App\Common\Exports;

use App\Common\Services\Export\ExportColumnRegistry;
use Illuminate\Support\Collection;

/**
 * Series E (Task E2) — base class for every "configurable columns" module
 * export. Subclasses override `query()` and `module()`. Headers, mapping,
 * styles, freeze pane all come for free from the registry.
 */
abstract class BaseModuleExport implements SpreadsheetExport
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
    abstract public function collection(): Collection;

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
        return substr($this->module().' '.now()->format('Y-m-d'), 0, 31);
    }

    private function humanize(string $key): string
    {
        return ucwords(str_replace(['_', '.'], ' ', $key));
    }
}
