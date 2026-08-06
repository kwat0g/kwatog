<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Enums;

enum StockOutRisk: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Ok = 'ok';

    public function label(): string
    {
        return strtoupper($this->value) === 'OK' ? 'OK' : ucfirst($this->value);
    }
}
