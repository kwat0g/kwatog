<?php

declare(strict_types=1);

namespace App\Common\Exports;

use App\Common\Services\Export\ExportColumnRegistry;
use App\Modules\Auth\Models\User;
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
        protected ?User $actor = null,
    ) {}

    /** Module key, e.g. "hr.employees". */
    abstract public function module(): string;

    /** Return the dataset to export — Eloquent collection or generic Collection. */
    abstract public function collection(): Collection;

    /** @return array<int, string> */
    public function headings(): array
    {
        $this->columns = ExportColumnRegistry::validateColumns($this->module(), $this->columns, $this->actor);
        $registry = ExportColumnRegistry::for($this->module());
        $headers = [];
        foreach ($this->columns as $key) {
            $headers[] = $registry[$key]['label'];
        }

        return $headers;
    }

    /**
     * @param  mixed  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        $this->columns = ExportColumnRegistry::validateColumns($this->module(), $this->columns, $this->actor);
        $registry = ExportColumnRegistry::for($this->module());
        $out = [];
        foreach ($this->columns as $key) {
            $def = $registry[$key] ?? null;
            if (! $def || ! isset($def['resolver']) || ! is_callable($def['resolver'])) {
                throw new \LogicException("Export column [{$key}] in module [{$this->module()}] has no resolver.");
            }
            $out[] = ($def['resolver'])($row);
        }

        return $out;
    }

    public function title(): string
    {
        return substr($this->module().' '.now()->format('Y-m-d'), 0, 31);
    }

}
