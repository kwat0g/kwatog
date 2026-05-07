<?php

declare(strict_types=1);

namespace App\Common\Enums;

enum ExportFormat: string
{
    case Csv  = 'csv';
    case Xlsx = 'xlsx';

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv  => 'text/csv; charset=utf-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }
}
