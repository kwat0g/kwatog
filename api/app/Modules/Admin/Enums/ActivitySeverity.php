<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

enum ActivitySeverity: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';

    public function label(): string { return ucfirst($this->value); }
}
