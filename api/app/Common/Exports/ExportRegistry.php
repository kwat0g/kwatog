<?php

declare(strict_types=1);

namespace App\Common\Exports;

use InvalidArgumentException;

/**
 * WS-E.1 — Maps an exportable resource key to its builder class.
 *
 * Adding a new exportable resource is a one-liner here plus the
 * concrete builder class. The SPA's <ExportButton resource="..."/>
 * uses these keys verbatim.
 */
class ExportRegistry
{
    /** @var array<string, class-string<ExportBuilder>> */
    private array $map;

    public function __construct()
    {
        $this->map = [
            'hr.employees' => EmployeesExportBuilder::class,
        ];
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->map);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->map);
    }

    public function builder(string $key): ExportBuilder
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown export resource [{$key}].");
        }

        return app($this->map[$key]);
    }
}
