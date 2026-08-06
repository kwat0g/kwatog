<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

enum ActivityType: string
{
    case Transaction = 'transaction';
    case Approval = 'approval';
    case Automation = 'automation';
    case Alert = 'alert';
    case Auth = 'auth';

    public function label(): string { return ucfirst($this->value); }
}
